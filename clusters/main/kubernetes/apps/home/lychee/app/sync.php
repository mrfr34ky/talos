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
    'max_bytes' => (int)(getenv('MAX_FILE_MB') ?: 2048) * 1024 * 1024,
    'share_hours' => (int)(getenv('SHARE_HOURS') ?: 24),
    'dry'       => getenv('DRY') === '1',
    'tmp'       => sys_get_temp_dir(),
];
const MAX_ATTEMPTS = 3;
const SKIP_ALBUM = '#skip'; // private sub-album for photos tagged #skip (guests never see it)
$started = time();

class SkipFile extends RuntimeException {} // not retried: too big or not a recognised media file
class ServiceUnavailable extends RuntimeException {} // Nextcloud, Lychee or the DB down: end the run, retry next time

// A restart of Nextcloud or Lychee ends the run cleanly (exit 0) instead of failing the job
set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ServiceUnavailable) {
        fwrite(STDOUT, date('H:i:s') . ' unavailable, skipping this run: ' . $e->getMessage() . "\n");
        exit(0);
    }
    fwrite(STDERR, (string)$e . "\n");
    exit(1);
});

function logm(string $s): void { fwrite(STDOUT, date('H:i:s') . " $s\n"); }

function http(string $method, string $url, array $opt): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opt + [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 600,
    ]);
    $body = curl_exec($ch);
    $where = "$method " . strtok((string)parse_url($url, PHP_URL_PATH), '?');
    if ($body === false) throw new ServiceUnavailable("$where: " . curl_error($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (in_array($code, [502, 503, 504], true)) throw new ServiceUnavailable("$where: HTTP $code");
    return [$code, (string)$body];
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

const DAV_PROPS = '<d:prop><oc:fileid/><d:getcontenttype/><d:getcontentlength/><d:getlastmodified/><d:getetag/><d:resourcetype/></d:prop>';

// One WebDAV response -> ['folder' => bool, fileid, href, mime, mtime, etag]
function dav_item(SimpleXMLElement $r): array {
    $r->registerXPathNamespace('d', 'DAV:'); $r->registerXPathNamespace('oc', 'http://owncloud.org/ns');
    return [
        'folder' => (bool)$r->xpath('.//d:resourcetype/d:collection'),
        'fileid' => (int)($r->xpath('.//oc:fileid')[0] ?? 0),
        'href'   => (string)$r->xpath('.//d:href')[0],
        'mime'   => (string)($r->xpath('.//d:getcontenttype')[0] ?? ''),
        'size'   => (int)($r->xpath('.//d:getcontentlength')[0] ?? 0),
        'mtime'  => strtotime((string)($r->xpath('.//d:getlastmodified')[0] ?? '')) ?: time(),
        'etag'   => trim((string)($r->xpath('.//d:getetag')[0] ?? ''), '"'),
    ];
}

function is_media(array $i): bool { return !$i['folder'] && (bool)preg_match('#^(image|video)/#', $i['mime']); }

// Tagged files and folders
function nc_tagged(int $tagId): array {
    global $cfg;
    $body = '<?xml version="1.0"?><oc:filter-files xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">' . DAV_PROPS
          . "<oc:filter-rules><oc:systemtag>$tagId</oc:systemtag></oc:filter-rules></oc:filter-files>";
    [$code, $xml] = nc('REPORT', '/remote.php/dav/files/' . rawurlencode($cfg['nc_user']) . '/', $body, ['Content-Type: application/xml']);
    if ($code !== 207) throw new RuntimeException("Nextcloud tag $tagId: HTTP $code");
    return array_map('dav_item', dav_xml($xml)->xpath('//d:response'));
}

// All media below a folder (Depth 1 per level: Nextcloud refuses Depth infinity)
function nc_folder_media(string $href): array {
    $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">' . DAV_PROPS . '</d:propfind>';
    $media = []; $queue = [$href];
    while ($queue) {
        $dir = array_shift($queue);
        [$code, $xml] = nc('PROPFIND', $dir, $body, ['Depth: 1', 'Content-Type: application/xml']);
        if ($code !== 207) throw new RuntimeException('list ' . rawurldecode($dir) . ": HTTP $code");
        foreach (dav_xml($xml)->xpath('//d:response') as $r) {
            $i = dav_item($r);
            if (rtrim(rawurldecode($i['href']), '/') === rtrim(rawurldecode($dir), '/')) continue; // the folder itself
            if ($i['folder']) $queue[] = $i['href'];
            elseif (is_media($i)) $media[] = $i;
        }
    }
    return $media;
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
        return ['path', $path];
    }
    return null;
}

// Plain "lychee" on a file: an album named after the file's folder
function folder_name(string $fileHref): string {
    return clean_path(basename(dirname(rawurldecode($fileHref))));
}

// Plain "lychee" on a folder: an album named after it, its subfolders as sub-albums
function folder_album(string $folderHref, string $fileHref): string {
    $dir = rtrim(rawurldecode($folderHref), '/');
    $file = rawurldecode($fileHref);
    $sub = str_starts_with($file, "$dir/") ? dirname(substr($file, strlen($dir) + 1)) : '.';
    return clean_path(basename($dir) . ($sub !== '.' ? "/$sub" : ''));
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

// Real type from the first bytes. ImageMagick must never guess: PostScript/PDF would reach Ghostscript.
function sniff(string $f): ?string {
    $h = (string)file_get_contents($f, false, null, 0, 16);
    if (strncmp($h, "\xFF\xD8\xFF", 3) === 0) return 'jpeg';
    if (strncmp($h, "\x89PNG", 4) === 0) return 'png';
    if (strncmp($h, 'GIF8', 4) === 0) return 'gif';
    if (substr($h, 0, 4) === 'RIFF' && substr($h, 8, 4) === 'WEBP') return 'webp';
    if (strncmp($h, "II*\0", 4) === 0 || strncmp($h, "MM\0*", 4) === 0) return 'tiff';
    if (substr($h, 4, 4) === 'ftyp') {
        $brand = substr($h, 8, 4);
        if (in_array($brand, ['heic', 'heix', 'heim', 'heis', 'mif1', 'msf1'], true)) return 'heic';
        if ($brand === 'avif') return 'avif';
        return 'video';
    }
    return null;
}

// Returns the file to upload, always without GPS: the download itself, or a JPEG copy when it's
// too big or not JPEG/PNG/GIF/WebP. Everything else in the metadata (dates, captions, ICC) is kept.
function prepare(string $src, string $mime): string {
    global $cfg;
    $type = sniff($src);
    if (str_starts_with($mime, 'video/')) {
        if ($type !== 'video') throw new SkipFile('not a recognised video file');
        return strip_gps($src);
    }
    if ($type === null || $type === 'video') throw new SkipFile('not a recognised image file');
    $info = @getimagesize($src);
    $simple = in_array($type, ['jpeg', 'png', 'gif', 'webp'], true) && $info;
    if ($simple && max($info[0], $info[1]) <= $cfg['max_edge']) return strip_gps($src);
    $im = new Imagick();
    $im->readImage("$type:$src" . '[0]'); // decoder forced by the sniffed type
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
    run(['exiftool', '-q', '-q', '-m', '-overwrite_original', '-tagsFromFile', $src, '-all:all', '-icc_profile',
         '--ExifImageWidth', '--ExifImageHeight', '--ThumbnailImage', '--PreviewImage', $out]);
    if (!$simple) { // HEIC/AVIF decoders already apply the rotation to the pixels
        run(['exiftool', '-q', '-q', '-m', '-overwrite_original', '-Orientation#=1', $out]);
    }
    return strip_gps($out);
}

// Location stays in Nextcloud only: remove every GPS tag from the copy sent to Lychee, then check
function strip_gps(string $f): string {
    [$rc, , $err] = run(['exiftool', '-q', '-q', '-m', '-overwrite_original', '-gps:all=', '-xmp:GPS*=',
        '-Keys:GPSCoordinates=', '-ItemList:GPSCoordinates=', '-UserData:GPSCoordinates=', $f]);
    if ($rc !== 0) throw new RuntimeException("removing GPS failed: $err");
    [, $left] = run(['exiftool', '-q', '-q', '-m', '-s3', '-n', '-GPSLatitude', '-GPSCoordinates', '-GPSPosition', $f]);
    if (trim($left) !== '') throw new RuntimeException('GPS still present after removal');
    return $f;
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
try {
    $pdo = new PDO("pgsql:host={$cfg['db_host']};port=5432;dbname={$cfg['db_name']}", $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    throw new ServiceUnavailable('database: ' . $e->getMessage());
}
$hasSchema = (bool)$pdo->query("select 1 from information_schema.tables where table_schema='ncsync' and table_name='files'")->fetchColumn();
if (!$hasSchema && !$cfg['dry']) {
    $pdo->exec('CREATE SCHEMA IF NOT EXISTS ncsync');
    $pdo->exec('CREATE TABLE IF NOT EXISTS ncsync.files (
        fileid bigint NOT NULL, album_path text NOT NULL, status text NOT NULL, photo_id text,
        attempts int NOT NULL DEFAULT 0, last_error text, href text,
        updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (fileid, album_path))');
    $hasSchema = true;
}
if ($hasSchema && !$cfg['dry']) {
    // Tagged folders: etag at the last complete pass, so unchanged folders aren't listed again
    $pdo->exec('CREATE TABLE IF NOT EXISTS ncsync.folders (
        fileid bigint NOT NULL, tag text NOT NULL, etag text NOT NULL,
        updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (fileid, tag))');
}
$state = []; $photoOf = []; $folderEtag = [];
if ($hasSchema) {
    foreach ($pdo->query('select fileid, album_path, status, photo_id, attempts from ncsync.files') as $r) {
        $state[$r['fileid'] . '|' . $r['album_path']] = $r;
        if ($r['status'] === 'done' && $r['photo_id']) $photoOf[(int)$r['fileid']] = $r['photo_id'];
    }
    if ($pdo->query("select to_regclass('ncsync.folders')")->fetchColumn()) {
        foreach ($pdo->query('select fileid, tag, etag from ncsync.folders') as $r) $folderEtag[$r['fileid'] . '|' . $r['tag']] = $r['etag'];
    }
}
function is_settled(int $fileid, string $path): bool {
    global $state;
    $s = $state["$fileid|$path"] ?? null;
    return $s && (in_array($s['status'], ['done', 'skipped'], true) || (int)$s['attempts'] >= MAX_ATTEMPTS);
}
function record(int $fileid, string $path, string $status, ?string $photo, ?string $error, string $href): void {
    global $pdo, $cfg, $state;
    $prev = $state["$fileid|$path"]['attempts'] ?? 0;
    $state["$fileid|$path"] = ['status' => $status, 'photo_id' => $photo, 'attempts' => (int)$prev + ($status === 'failed' ? 1 : 0)];
    if ($cfg['dry']) return;
    $pdo->prepare('INSERT INTO ncsync.files (fileid, album_path, status, photo_id, attempts, last_error, href, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, now())
                   ON CONFLICT (fileid, album_path) DO UPDATE SET status = excluded.status, photo_id = excluded.photo_id,
                     attempts = ncsync.files.attempts + excluded.attempts, last_error = excluded.last_error,
                     href = excluded.href, updated_at = now()')
        ->execute([$fileid, $path, $status, $photo, $status === 'failed' ? 1 : 0, $error, $href]);
}

// --- album tree (regular albums; tag/smart albums have no parent): id -> [parent, title, description, nsfw] ---
$albums = [];
foreach ($pdo->query('select a.id, a.parent_id, b.title, b.description, b.is_nsfw
                      from albums a join base_albums b on b.id = a.id') as $r)
    $albums[$r['id']] = [$r['parent_id'], (string)$r['title'], (string)$r['description'], (bool)$r['is_nsfw']];
$kids = []; // parent id -> [child ids]
foreach ($albums as $id => $a) if ($a[0] !== null) $kids[$a[0]][] = $id;

// #keep counts on the album itself or on any album above it
function kept(array $albums, string $id, string $desc): bool {
    if (preg_match('/^\s*#keep\b/i', $desc)) return true;
    for ($i = $albums[$id][0] ?? null; $i !== null && isset($albums[$i]); $i = $albums[$i][0])
        if (preg_match('/^\s*#keep\b/i', $albums[$i][2])) return true;
    return false;
}

// --- expiring shares: public albums go private SHARE_HOURS after they were shared, unless the
// album or a parent album description starts with #keep. Lychee stores share times in UTC.
$expire = $pdo->prepare("select b.id, b.title, b.description, b.is_nsfw, p.is_link_required, p.grants_download,
        p.grants_full_photo_access, p.created_at
    from access_permissions p join base_albums b on b.id = p.base_album_id
    where p.user_id is null and p.user_group_id is null
      and p.created_at < (now() at time zone 'UTC') - make_interval(hours => ?)");
$expire->execute([$cfg['share_hours']]);
foreach ($expire->fetchAll(PDO::FETCH_ASSOC) as $a) {
    if (kept($albums, $a['id'], (string)$a['description'])) continue;
    if ($cfg['dry']) { logm("would end share: \"{$a['title']}\" (shared {$a['created_at']} UTC)"); continue; }
    try {
        ly('POST', 'Album::updateProtectionPolicy', [
            'album_id' => $a['id'], 'is_public' => false, 'is_link_required' => (bool)$a['is_link_required'],
            'is_nsfw' => (bool)$a['is_nsfw'], 'grants_download' => (bool)$a['grants_download'],
            'grants_full_photo_access' => (bool)$a['grants_full_photo_access'], 'grants_upload' => false,
        ]);
        logm("share ended: \"{$a['title']}\" (shared {$a['created_at']} UTC)");
    } catch (ServiceUnavailable $e) {
        throw $e;
    } catch (Throwable $e) {
        logm("ending share \"{$a['title']}\": " . $e->getMessage());
    }
}

// --- inherited shares: sub-albums of a public album get the same share and share time (so they expire with it),
// except #skip albums and what is below them. A sub-album that already has its own share keeps it.
$shares = []; // album id -> public share
foreach ($pdo->query('select base_album_id, is_link_required, grants_download, grants_full_photo_access, created_at
                      from access_permissions where user_id is null and user_group_id is null') as $r)
    $shares[$r['base_album_id']] = $r;
$queue = array_keys(array_intersect_key($shares, $albums));
$seen = [];
while ($queue) {
    $id = array_shift($queue);
    foreach ($kids[$id] ?? [] as $kid) {
        if (isset($seen[$kid]) || $albums[$kid][1] === SKIP_ALBUM) continue;
        $seen[$kid] = true;
        if (!isset($shares[$kid])) {
            $s = $shares[$id];
            if ($cfg['dry']) {
                logm("would share \"{$albums[$kid][1]}\" (inherited from \"{$albums[$id][1]}\")");
            } else {
                try {
                    ly('POST', 'Album::updateProtectionPolicy', [
                        'album_id' => $kid, 'is_public' => true, 'is_link_required' => (bool)$s['is_link_required'],
                        'is_nsfw' => $albums[$kid][3], 'grants_download' => (bool)$s['grants_download'],
                        'grants_full_photo_access' => (bool)$s['grants_full_photo_access'], 'grants_upload' => false,
                    ]);
                    $pdo->prepare('update access_permissions set created_at = ?
                                   where base_album_id = ? and user_id is null and user_group_id is null')
                        ->execute([$s['created_at'], $kid]);
                    logm("shared \"{$albums[$kid][1]}\" (inherited from \"{$albums[$id][1]}\")");
                } catch (ServiceUnavailable $e) {
                    throw $e;
                } catch (Throwable $e) {
                    logm("sharing \"{$albums[$kid][1]}\": " . $e->getMessage());
                    continue;
                }
            }
            $shares[$kid] = $s;
        }
        $queue[] = $kid;
    }
}

// --- #skip: photos tagged #skip (or skip) move into a private "#skip" sub-album of their album, so guests of a
// shared album don't see them; without the tag they move back to the album above it.
function skip_album(array &$albums, array &$kids, string $parent): string {
    foreach ($kids[$parent] ?? [] as $kid) if ($albums[$kid][1] === SKIP_ALBUM) return $kid;
    $id = trim((string)ly('POST', 'Album', ['title' => SKIP_ALBUM, 'parent_id' => $parent]), " \"\n");
    $albums[$id] = [$parent, SKIP_ALBUM, '', false];
    $kids[$parent][] = $id;
    return $id;
}
$skipTagged = [];
foreach ($pdo->query("select pt.photo_id from photos_tags pt join tags t on t.id = pt.tag_id
                      where lower(t.name) in ('#skip', 'skip')") as $r)
    $skipTagged[$r['photo_id']] = true;
foreach ($pdo->query('select photo_id, album_id from photo_album')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    [$photo, $from] = [$r['photo_id'], $r['album_id']];
    if (!isset($albums[$from])) continue;
    $inSkip = $albums[$from][1] === SKIP_ALBUM;
    if (isset($skipTagged[$photo]) === $inSkip) continue;              // already where it belongs
    if ($inSkip && $albums[$from][0] === null) continue;                 // a top-level "#skip" album: leave it
    $label = $inSkip ? "back to \"{$albums[$albums[$from][0]][1]}\"" : "into \"{$albums[$from][1]}/" . SKIP_ALBUM . '"';
    if ($cfg['dry']) { logm("would move photo $photo $label"); continue; }
    try {
        $to = $inSkip ? $albums[$from][0] : skip_album($albums, $kids, $from);
        ly('POST', 'Photo::move', ['photo_ids' => [$photo], 'album_id' => $to, 'from_id' => $from]);
        logm("moved photo $photo $label");
    } catch (ServiceUnavailable $e) {
        throw $e;
    } catch (Throwable $e) {
        logm("moving photo $photo: " . $e->getMessage());
    }
}

// --- main ---
$tags = nc_tags();
$work = [];        // album path -> [fileid => file]
$folderKeys = [];  // "folderid|tag" -> [etag, ["fileid|path", ...]] for folders listed in this run
$tagged = 0;
foreach ($tags as $id => $name) {
    if (!($rule = tag_rule($name))) continue;
    $items = nc_tagged($id);
    $files = []; // [file, album path]
    foreach (array_filter($items, 'is_media') as $f) $files[] = [$f, $rule[0] === 'mirror' ? folder_name($f['href']) : $rule[1]];
    $folders = array_values(array_filter($items, fn($i) => $i['folder']));
    $listed = 0;
    foreach ($folders as $d) {
        $fkey = $d['fileid'] . '|' . $name;
        if ($d['etag'] !== '' && ($folderEtag[$fkey] ?? null) === $d['etag']) continue; // unchanged since the last complete pass
        if (time() - $started > $cfg['budget'] / 2) continue; // leave time for uploads; the rest is listed next run
        try {
            $media = nc_folder_media($d['href']);
        } catch (ServiceUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            logm('folder ' . rawurldecode($d['href']) . ': ' . $e->getMessage());
            continue;
        }
        $listed++;
        $folderKeys[$fkey] = [$d['etag'], []];
        foreach ($media as $m) {
            $path = $rule[0] === 'mirror' ? folder_album($d['href'], $m['href']) : $rule[1];
            $files[] = [$m, $path];
            $folderKeys[$fkey][1][] = $m['fileid'] . '|' . $path;
        }
    }
    logm("tag \"$name\": " . count(array_filter($items, 'is_media')) . ' file(s), ' . count($folders) . " folder(s), $listed listed");
    foreach ($files as [$f, $path]) {
        $tagged++;
        if ($path === '' || is_settled($f['fileid'], $path)) continue;
        $work[$path][$f['fileid']] = $f;
    }
}
ksort($work);
$pending = array_sum(array_map('count', $work));
logm("tagged files: $tagged, pending uploads: $pending in " . count($work) . ' album(s)' . ($cfg['dry'] ? ' (dry run)' : ''));
if ($cfg['dry']) {
    foreach (array_slice($work, 0, 15, true) as $path => $files) logm("  would upload " . count($files) . " to \"$path\"");
}

$n = ['uploaded' => 0, 'copied' => 0, 'skipped' => 0, 'failed' => 0];
$stop = false;
foreach ($work as $path => $files) {
    if ($stop) break;
    try {
        $albumId = ensure_album($path);
    } catch (ServiceUnavailable $e) {
        throw $e;
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
            if ($f['size'] > $cfg['max_bytes']) throw new SkipFile('larger than ' . ($cfg['max_bytes'] >> 20) . ' MB');
            $fh = fopen($tmp, 'wb');
            [$code] = nc('GET', $f['href'], null, [], [CURLOPT_RETURNTRANSFER => false, CURLOPT_FILE => $fh,
                CURLOPT_MAXFILESIZE_LARGE => $cfg['max_bytes']]);
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
        } catch (SkipFile $e) {
            record($f['fileid'], $path, 'skipped', null, $e->getMessage(), $f['href']);
            logm('skipped: ' . basename(rawurldecode($f['href'])) . ': ' . $e->getMessage());
            $n['skipped']++;
        } catch (ServiceUnavailable $e) {
            throw $e; // not this file's fault: no failed attempt recorded
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
// Remember the etag of folders whose media are all settled: they are skipped until their content changes
$saved = 0;
foreach ($folderKeys as $fkey => [$etag, $keys]) {
    if ($cfg['dry'] || $etag === '') continue;
    foreach ($keys as $k) { [$fid, $p] = explode('|', $k, 2); if (!is_settled((int)$fid, $p)) continue 2; }
    [$fid, $tag] = explode('|', $fkey, 2);
    $pdo->prepare('INSERT INTO ncsync.folders (fileid, tag, etag, updated_at) VALUES (?, ?, ?, now())
                   ON CONFLICT (fileid, tag) DO UPDATE SET etag = excluded.etag, updated_at = now()')
        ->execute([(int)$fid, $tag, $etag]);
    $saved++;
}
if ($folderKeys) logm("folders complete: $saved of " . count($folderKeys) . ' listed');
logm("done: uploaded {$n['uploaded']}, copied {$n['copied']}, skipped {$n['skipped']}, failed {$n['failed']}, albums created " . count($created)
    . ($stop ? ', stopped at the time/file limit (continues next run)' : '') . ', ' . (time() - $started) . 's');
