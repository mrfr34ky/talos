// Cloudflare Worker "status": public, neutral address for the Gatus status page.
// Visitors use https://<worker>.workers.dev; the Worker forwards to Gatus. The Gatus origin lives only in the
// encrypted Worker secret ORIGIN (e.g. https://status.example.com), so the page never shows the domain.
// PROXY_KEY (Worker secret) is sent as X-Proxy-Key; a Cloudflare WAF rule blocks direct requests to the
// origin host without it, so only this Worker can reach Gatus from the internet.
// Deploy: Workers & Pages > status > Edit code (paste) > Deploy; Settings > Variables and Secrets.

// Response headers worth passing on; everything else (incl. anything that could name the origin) is dropped.
const PASS_HEADERS = ["content-type", "cache-control", "etag", "last-modified", "expires"];

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    // Read-only page; /metrics would expose Gatus internals
    if (!["GET", "HEAD"].includes(request.method) || url.pathname.startsWith("/metrics")) {
      return new Response("Not found", { status: 404 });
    }

    const upstream = await fetch(env.ORIGIN + url.pathname + url.search, {
      method: request.method,
      headers: {
        "User-Agent": "status-proxy",
        "Accept": request.headers.get("Accept") || "*/*",
        "X-Proxy-Key": env.PROXY_KEY,
      },
      redirect: "manual",
    });

    const headers = new Headers();
    for (const name of PASS_HEADERS) {
      const value = upstream.headers.get(name);
      if (value) headers.set(name, value);
    }
    // Keep redirects on the Worker's own address
    const location = upstream.headers.get("location");
    if (location) {
      const target = new URL(location, env.ORIGIN);
      headers.set("location", target.pathname + target.search);
    }

    return new Response(upstream.body, { status: upstream.status, headers });
  },
};
