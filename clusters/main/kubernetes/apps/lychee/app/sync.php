<?php
// Nextcloud tags -> Lychee albums, run every 10 minutes by the lychee-sync CronJob.
// Photos above MAX_EDGE px (or not JPEG/PNG/GIF/WebP) become JPEG (JPEG_QUALITY) with all metadata kept;
// videos are uploaded as they are. Title/description come from the file's metadata. A new album gets the
// description its photos share. Uploads are recorded in Lychee's Postgres (schema ncsync): each file goes
// once per album, a file already in Lychee is copied into further albums, and failures are retried 3 times.
// Deleting or untagging in Nextcloud never deletes anything in Lychee.
declare(strict_types=1);

$cfg = [
    'nc_url'    => rtrim((string)getenv('NC_URL'), '/'),
    'nc_host'   => (string)getenv('NC_HOST'),
    'nc_user'   => (string)getenv('NC_USER'),
    'nc_pass'   => (string)getenv('NC_PASS'),
    'ly_url'    => rtrim((string)getenv('LYCHEE_URL'), '/'),
    'ly_token'  => (string)getenv('LYCHEE_TOKEN'),
    'db_host'   => (string)getenv('DB_HOST'),
    'db_name'   => (string)(getenv('DB_DATABASE') ?: 'lychee'),
    'db_user'   => (string)(getenv('DB_USERNAME') ?: 'lychee'),
    'db_pass'   => (string)getenv('DB_PASSWORD'),
    'max_edge'  => (int)(getenv('MAX_EDGE') ?: 3840),
    'quality'   => (int)(getenv('JPEG_QUALITY') ?: 85),
    'budget'    => (int)(getenv('TIME_BUDGET') ?: 480),
    'max_files' => (int)(getenv('MAX_FILES') ?: 0),
    'dry'       => getenv('DRY') === '1',
    'tmp'       => sys_get_temp_dir(),
];
const MAX_ATTEMPTS = 3;
$started = time();

function logm(string $s): void { fwrite(STDOUT, date('H:i:s') . " $s\n"); }

function http(string $method, string $url, array $opt): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opt + [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 600,
    ]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException("$method " . parse_url($url, PHP_URL_PATH) . ': ' . curl_error($ch));
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string)$body];
}

// --- Nextcloud (in-cluster service; the Host header makes it a trusted domain) ---
function nc(string $method, string $path, ?string $body = null, array $headers = [], array $opt = []): array {
    global $cfg;
    $opt += [
        CURLOPT_HTTPHEADER => array_merge(['Host: ' . $cfg['nc_host']], $headers),
        CURLOPT_USERPWD => $cfg['nc_user'] . ':' . $cfg['nc_pass'],
    ];
    if ($body !== null) $opt[CURLOPT_POSTFIELDS] = $body;
    return http($method, $cfg['nc_url'] . $path, $opt);
}

function dav_xml(string $xml): SimpleXMLElement {
    $x = new SimpleXMLElement($xml);
    $x->registerXPathNamespace('d', 'DAV:');
    $x->registerXPathNamespace('oc', 'http://owncloud.org/ns');
    return $x;
}

function nc_tags(): array {
    $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
          . '<oc:id/><oc:display-name/></d:prop></d:propfind>';
    [$code, $xml] = nc('PROPFIND', '/remote.php/dav/systemtags/', $body, ['Depth: 1', 'Content-Type: application/xml']);
    if ($code !== 207) throw new RuntimeException("Nextcloud tags: HTTP $code");
    $tags = [];
    foreach (dav_xml($xml)->xpath('//d:response') as $r) {
        $r->registerXPathNamespace('d', 'DAV:'); $r->registerXPathNamespace('oc', 'http://owncloud.org/ns');
        $id = (string)($r->xpath('.//oc:id')[0] ?? ''); $name = (string)($r->xpath('.//oc:display-name')[0] ?? '');
        if ($id !== '') $tags[(int)$id] = $name;
    }
    return $tags;
}

function nc_tagged_files(int $tagId): array {
    global $cfg;
    $body = '<?xml version="1.0"?><oc:filter-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop>'
          . '<oc:fileid/><d:getcontenttype/><d:getlastmodified/><d:resourcetype/></d:prop>'
          . "<oc:filter-rules><oc:systemtag>$tagId</oc:systemtag></oc:filter-rules></oc:filter-files>";
    [$code, $xml] = nc('REPORT', '/remote.php/dav/files/' . rawurlencode($cfg['nc_user']) . '/', $body, ['Content-Type: application/xml']);
    if ($code !== 207) throw new RuntimeException("Nextcloud tag $tagId files: HTTP $code");
    $files = [];
    foreach (dav_xml($xml)->xpath('//d:response') as $r) {
        $r->registerXPathNamespace('d', 'DAV:'); $r->registerXPathNamespace('oc', 'http://owncloud.org/ns');
        if ($r->xpath('.//d:resourcetype/d:collection')) continue; // tagged folders are not expanded
        $mime = (string)($r->xpath('.//d:getcontenttype')[0] ?? '');
        if (!preg_match('#^(image|video)/#', $mime)) continue;
        $files[] = [
            'fileid' => (int)($r->xpath('.//oc:fileid')[0] ?? 0),
            'href'   => (string)$r->xpath('.//d:href')[0],
            'mime'   => $mime,
            'mtime'  => strtotime((string)($r->xpath('.//d:getlastmodified')[0] ?? '')) ?: time(),
        ];
    }
    return $files;
}

// --- tag rules ---
function clean_path(string $p): string {
    $parts = preg_split('#[/\\\\]+#u', $p) ?: [];
    $parts = array_map(fn($x) => mb_substr(trim($x), 0, 100), $parts);
    return implode('/', array_values(array_filter($parts, fn($x) => $x !== '')));
}

function tag_rule(string $name): ?array {
    $n = trim($name);
    if (strcasecmp($n, 'lychee') === 0) return ['mirror', ''];
    if (preg_match('/^(insta|lychee)\s*:\s*(.+)$/iu', $n, $m) && ($path = clean_path($m[2])) !== '') {
        return strcasecmp($m[1], 'insta') === 0 ? ['path', "Instagram/$path"] : ['path', $path];
    }
    return null;
}

// Folder path inside the share: files/<user>/Shared/<share>/a/b/x.jpg -> a/b (or the share name at its root)
function mirror_path(string $href): string {
    global $cfg;
    $rel = rawurldecode($href);
    $prefix = '/remote.php/dav/files/' . $cfg['nc_user'] . '/';
    if (str_starts_with($rel, $prefix)) $rel = substr($rel, strlen($prefix));
    $parts = explode('/', trim($rel, '/'));
    array_pop($parts);
    $skip = ($parts[0] ?? '') === 'Shared' ? 2 : 1;
    $inside = array_slice($parts, $skip);
    return clean_path($inside ? implode('/', $inside) : (string)end($parts));
}

// --- Lychee API ---
function ly(string $method, string $path, $data = null, bool $multipart = false) {
    global $cfg;
    $headers = ['Authorization: Bearer ' . $cfg['ly_token'], 'Accept: application/json'];
    $opt = [];
    if ($multipart) {
        $opt[CURLOPT_POSTFIELDS] = $data;
    } else {
        $headers[] = 'Content-Type: application/json';
        if ($data !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    $opt[CURLOPT_HTTPHEADER] = $headers;
    [$code, $body] = http($method, $cfg['ly_url'] . '/api/v2/' . $path, $opt);
    $j = json_decode($body, true);
    if ($code >= 400) {
        $msg = is_array($j) ? (string)($j['message'] ?? '') : $body;
        throw new RuntimeException("Lychee $method " . strtok($path, '?') . ": HTTP $code " . mb_substr($msg, 0, 200));
    }
    // Some endpoints (POST Album: the new id) answer with plain text, not JSON
    return $j ?? trim($body, " \t\r\n\"");
}

$albums = [];   // path -> id, per run
$children = []; // parent id -> [title => id], per run
$created = [];  // paths of albums created in this run
function album_children(?string $parent): array {
    global $children;
    $key = $parent ?? '';
    if (isset($children[$key])) return $children[$key];
    if ($parent === null) {
        $list = ly('GET', 'Albums')['albums'] ?? [];
    } else {
        $list = []; $page = 1;
        do {
            $j = ly('GET', 'Album::albums?album_id=' . rawurlencode($parent) . "&page=$page");
            $list = array_merge($list, $j['data'] ?? []);
            $last = (int)($j['last_page'] ?? 1);
        } while (++$page <= $last);
    }
    $children[$key] = [];
    foreach ($list as $a) if (isset($a['title'], $a['id']) && !isset($children[$key][$a['title']])) $children[$key][$a['title']] = (string)$a['id'];
    return $children[$key];
}

function ensure_album(string $path): string {
    global $albums, $children, $created, $cfg;
    $parent = null; $acc = '';
    foreach (explode('/', $path) as $title) {
        $acc = $acc === '' ? $title : "$acc/$title";
        if (!isset($albums[$acc])) {
            $id = $parent === 'DRY' ? null : (album_children($parent)[$title] ?? null);
            if ($id === null) {
                if ($cfg['dry']) $id = 'DRY';
                else {
                    $id = ly('POST', 'Album', ['title' => $title, 'parent_id' => $parent]);
                    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{24}$/', $id)) {
                        throw new RuntimeException("album \"$acc\": unexpected id from Lychee");
                    }
                    $children[$parent ?? ''][$title] = $id;
                    $created[$acc] = true;
                    logm("album created: $acc");
                }
            }
            $albums[$acc] = $id;
        }
        $parent = $albums[$acc];
    }
    return $parent;
}

function set_album_description(string $id, string $title, string $desc): void {
    ly('PATCH', 'Album', [
        'album_id' => $id, 'title' => $title, 'description' => mb_substr($desc, 0, 1000), 'license' => 'none',
        'photo_sorting_column' => null, 'photo_sorting_order' => null,
        'album_sorting_column' => null, 'album_sorting_order' => null,
        'album_aspect_ratio' => null, 'photo_layout' => null, 'copyright' => null,
        'is_compact' => false, 'is_map_header' => false, 'is_pinned' => false,
        'header_id' => null, 'cover_id' => null, 'album_timeline' => null, 'photo_timeline' => null,
    ]);
}

// --- files ---
function run(array $cmd): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $out, trim($err)];
}

function file_meta(string $f): array {
    [, $out] = run(['exiftool', '-j', '-charset', 'UTF8', '-Title', '-ObjectName', '-Description', '-ImageDescription', '-Caption-Abstract', $f]);
    $j = json_decode($out, true)[0] ?? [];
    $pick = function (array $keys) use ($j): string {
        foreach ($keys as $k) if (isset($j[$k]) && is_scalar($j[$k]) && trim((string)$j[$k]) !== '') return trim((string)$j[$k]);
        return '';
    };
    return [mb_substr($pick(['Title', 'ObjectName']), 0, 100), mb_substr($pick(['Description', 'ImageDescription', 'Caption-Abstract']), 0, 1000)];
}

// Returns the file to upload: the original, or a JPEG copy when it's too big or an unusual format
function prepare(string $src, string $mime): string {
    global $cfg;
    if (str_starts_with($mime, 'video/')) return $src;
    $info = @getimagesize($src);
    $simple = $info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    if ($simple && max($info[0], $info[1]) <= $cfg['max_edge']) return $src;
    $im = new Imagick($src . '[0]');
    if (max($im->getImageWidth(), $im->getImageHeight()) > $cfg['max_edge']) {
        $im->getImageWidth() >= $im->getImageHeight()
            ? $im->resizeImage($cfg['max_edge'], 0, Imagick::FILTER_LANCZOS, 1)
            : $im->resizeImage(0, $cfg['max_edge'], Imagick::FILTER_LANCZOS, 1);
    }
    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality($cfg['quality']);
    $out = "$src.jpg";
    $im->writeImage($out);
    $im->clear();
    // Keep all metadata (dates, GPS, captions, ICC) from the original
    run(['exiftool', '-q', '-q', '-m', '-overwrite_original', '-tagsFromFile', $src, '-all:all', '-icc_profile',
         '--ExifImageWidth', '--ExifImageHeight', '--ThumbnailImage', '--PreviewImage', $out]);
    if (!$simple) { // HEIC/AVIF decoders already apply the rotation to the pixels
        run(['exiftool', '-q', '-q', '-m', '-overwrite_original', '-Orientation#=1', $out]);
    }
    return $out;
}

function upload(string $file, string $name, string $albumId, int $mtime, string $title, string $desc): string {
    if ($albumId === '') throw new RuntimeException('no album id'); // never upload into "Unsorted" by accident
    $chunk = 16 * 1024 * 1024;
    $total = max(1, (int)ceil(filesize($file) / $chunk));
    $fh = fopen($file, 'rb'); $uuid = ''; $ext = ''; $j = [];
    $part = "$file.part";
    for ($n = 1; $n <= $total; $n++) {
        file_put_contents($part, fread($fh, $chunk));
        $fields = [
            'album_id' => $albumId, 'file' => new CURLFile($part, 'application/octet-stream', $name),
            'file_name' => $name, 'uuid_name' => $uuid, 'extension' => $ext,
            'chunk_number' => (string)$n, 'total_chunks' => (string)$total, 'file_last_modified_time' => (string)($mtime * 1000),
        ];
        if ($n === $total) {
            if ($title !== '') $fields['title'] = $title;
            if ($desc !== '') $fields['description'] = $desc;
        }
        $j = ly('POST', 'Photo', $fields, true);
        $uuid = (string)($j['uuid_name'] ?? $uuid); $ext = (string)($j['extension'] ?? $ext);
    }
    fclose($fh); @unlink($part);
    if (($j['stage'] ?? '') === 'error') throw new RuntimeException('Lychee could not process the file');
    return (string)($j['expected_id'] ?? '');
}

// --- state (Lychee's Postgres, schema ncsync) ---
$pdo = new PDO("pgsql:host={$cfg['db_host']};port=5432;dbname={$cfg['db_name']}", $cfg['db_user'], $cfg['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hasSchema = (bool)$pdo->query("select 1 from information_schema.tables where table_schema='ncsync' and table_name='files'")->fetchColumn();
if (!$hasSchema && !$cfg['dry']) {
    $pdo->exec('CREATE SCHEMA IF NOT EXISTS ncsync');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ncsync.files (
        fileid bigint NOT NULL, album_path text NOT NULL, status text NOT NULL, photo_id text,
        attempts int NOT NULL DEFAULT 0, last_error text, href text,
        updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (fileid, album_path))');
    $hasSchema = true;
}
$state = []; $photoOf = [];
if ($hasSchema) {
    foreach ($pdo->query('select fileid, album_path, status, photo_id, attempts from ncsync.files') as $r) {
        $state[$r['fileid'] . '|' . $r['album_path']] = $r;
        if ($r['status'] === 'done' && $r['photo_id']) $photoOf[(int)$r['fileid']] = $r['photo_id'];
    }
}
function record(int $fileid, string $path, string $status, ?string $photo, ?string $error, string $href): void {
    global $pdo, $cfg;
    if ($cfg['dry']) return;
    $pdo->prepare('INSERT INTO ncsync.files (fileid, album_path, status, photo_id, attempts, last_error, href, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, now())
                   ON CONFLICT (fileid, album_path) DO UPDATE SET status = excluded.status, photo_id = excluded.photo_id,
                     attempts = ncsync.files.attempts + excluded.attempts, last_error = excluded.last_error,
                     href = excluded.href, updated_at = now()')
        ->execute([$fileid, $path, $status, $photo, $status === 'failed' ? 1 : 0, $error, $href]);
}

// --- main ---
$tags = nc_tags();
$work = []; // album path -> [file, ...]
$tagged = 0;
foreach ($tags as $id => $name) {
    if (!($rule = tag_rule($name))) continue;
    $files = nc_tagged_files($id);
    logm("tag \"$name\": " . count($files) . ' media file(s)');
    foreach ($files as $f) {
        $tagged++;
        $path = $rule[0] === 'mirror' ? mirror_path($f['href']) : $rule[1];
        if ($path === '') continue;
        $s = $state[$f['fileid'] . '|' . $path] ?? null;
        if ($s && ($s['status'] === 'done' || (int)$s['attempts'] >= MAX_ATTEMPTS)) continue;
        $work[$path][$f['fileid']] = $f;
    }
}
ksort($work);
$pending = array_sum(array_map('count', $work));
logm("tagged files: $tagged, pending uploads: $pending in " . count($work) . ' album(s)' . ($cfg['dry'] ? ' (dry run)' : ''));
if ($cfg['dry']) {
    foreach (array_slice($work, 0, 15, true) as $path => $files) logm("  would upload " . count($files) . " to \"$path\"");
}

$n = ['uploaded' => 0, 'copied' => 0, 'failed' => 0];
$stop = false;
foreach ($work as $path => $files) {
    if ($stop) break;
    try {
        $albumId = ensure_album($path);
    } catch (Throwable $e) {
        logm("album \"$path\": " . $e->getMessage());
        continue;
    }
    if ($cfg['dry']) continue;
    $descs = [];
    uasort($files, fn($a, $b) => strcmp($a['href'], $b['href']));
    foreach ($files as $f) {
        if (time() - $started > $cfg['budget'] || ($cfg['max_files'] && $n['uploaded'] + $n['copied'] >= $cfg['max_files'])) { $stop = true; break; }
        $tmp = $cfg['tmp'] . '/f' . $f['fileid'];
        try {
            if (isset($photoOf[$f['fileid']])) { // already in Lychee: copy into this album too
                ly('POST', 'Photo::copy', ['photo_ids' => [$photoOf[$f['fileid']]], 'album_id' => $albumId]);
                record($f['fileid'], $path, 'done', $photoOf[$f['fileid']], null, $f['href']);
                $n['copied']++;
                continue;
            }
            $fh = fopen($tmp, 'wb');
            [$code] = nc('GET', $f['href'], null, [], [CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $fh]);
            fclose($fh);
            if ($code !== 200) throw new RuntimeException("download: HTTP $code");
            [$title, $desc] = file_meta($tmp);
            $descs[] = $desc;
            $up = prepare($tmp, $f['mime']);
            $name = basename(rawurldecode($f['href']));
            if ($up !== $tmp) $name = preg_replace('/\.[^.]+$/', '', $name) . '.jpg';
            $photo = upload($up, $name, $albumId, $f['mtime'], $title, $desc);
            record($f['fileid'], $path, 'done', $photo, null, $f['href']);
            if ($photo !== '') $photoOf[$f['fileid']] = $photo;
            $n['uploaded']++;
        } catch (Throwable $e) {
            record($f['fileid'], $path, 'failed', null, mb_substr($e->getMessage(), 0, 300), $f['href']);
            logm('failed: ' . basename(rawurldecode($f['href'])) . " -> \"$path\": " . $e->getMessage());
            $n['failed']++;
        } finally {
            foreach (glob("$tmp*") ?: [] as $t) @unlink($t);
        }
    }
    // A new album gets the description all its new photos share (an Instagram caption)
    $descs = array_values(array_unique($descs));
    if (isset($created[$path]) && count($descs) === 1 && $descs[0] !== '') {
        try { set_album_description($albumId, basename($path), $descs[0]); }
        catch (Throwable $e) { logm("album description \"$path\": " . $e->getMessage()); }
    }
}
logm("done: uploaded {$n['uploaded']}, copied {$n['copied']}, failed {$n['failed']}, albums created " . count($created)
    . ($stop ? ', stopped at the time/file limit (continues next run)' : '') . ', ' . (time() - $started) . 's');
