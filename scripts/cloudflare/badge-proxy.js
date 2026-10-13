// Cloudflare Worker: public, neutral address for the kromgo README badges.
// shields.io fetches https://<worker>.workers.dev/badges/<id>?format=shields, and the Worker forwards it to
// kromgo. The kromgo origin lives only in the encrypted Worker secret ORIGIN (e.g. https://kromgo.example.com),
// so neither the README nor this file reveals the domain.
// Deploy: Workers & Pages > badges > Edit code (paste) > Deploy; Settings > Variables and Secrets > Secret ORIGIN.

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method !== "GET" || !url.pathname.startsWith("/badges/")) {
      return new Response("Not found", { status: 404 });
    }

    const upstream = await fetch(env.ORIGIN + url.pathname + url.search, {
      headers: { "User-Agent": "badge-proxy" },
    });

    // Return only the body, status and a content type: no upstream headers that could name the origin.
    return new Response(upstream.body, {
      status: upstream.status,
      headers: {
        "Content-Type": upstream.headers.get("Content-Type") || "application/json",
        "Cache-Control": "public, max-age=60",
      },
    });
  },
};
