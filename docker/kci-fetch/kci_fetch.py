"""
kci-fetch: the one place that talks to kci.id.

Cloudflare in front of kci.id blocks clients by their TLS/HTTP2 fingerprint
(curl, PHP and Node are rejected even with browser headers). curl_cffi
impersonates a real browser fingerprint, so Laravel sends every KCI request
through this small service instead of calling kci.id itself:

    GET /fetch?url=<url-encoded https://www.kci.id/...>   (Authorization is forwarded)
    GET /health

Upstream status, body and the rate-limit headers are passed through unchanged,
so Laravel's retry / HTTP 429 handling keeps working. A Cloudflare block is
answered with HTTP 502 and a JSON error, never the HTML block page. Every error
the service answers itself carries an "X-Kci-Fetch-Error: <code>" header.

Environment:
    KCI_IMPERSONATE         first browser profile (default "chrome"); on a block
                            the next of chrome -> safari -> firefox is tried
    KCI_FETCH_TIMEOUT       upstream timeout in seconds (default 20)
    KCI_FETCH_ALLOWED_HOSTS comma-separated hosts that may be fetched (default kci.id)
    KCI_FETCH_PORT          listen port (default 8080)
"""

import json
import logging
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit

from curl_cffi import requests
from curl_cffi.requests.exceptions import RequestException

FALLBACK_PROFILES = ["chrome", "safari", "firefox"]
BLOCK_MARKERS = ("Attention Required", "Just a moment")
PASS_HEADERS = ("content-type", "retry-after", "x-ratelimit-limit", "x-ratelimit-remaining", "x-ratelimit-reset", "cf-ray")

TIMEOUT = float(os.environ.get("KCI_FETCH_TIMEOUT", "20"))
IMPERSONATE = os.environ.get("KCI_IMPERSONATE", "chrome").strip() or "chrome"
ALLOWED_HOSTS = [h.strip().lower() for h in os.environ.get("KCI_FETCH_ALLOWED_HOSTS", "kci.id").split(",") if h.strip()]

log = logging.getLogger("kci-fetch")


class KciBlocked(Exception):
    def __init__(self, status, cf_ray, profile):
        super().__init__(f"blocked by Cloudflare (HTTP {status}, impersonate={profile}, cf-ray={cf_ray or '-'})")
        self.status = status
        self.cf_ray = cf_ray


def profiles(first=IMPERSONATE):
    """The configured profile first, then the remaining fallbacks."""
    return [first] + [p for p in FALLBACK_PROFILES if p != first]


def is_blocked(response):
    """A Cloudflare challenge/block page instead of the API response."""
    if response.headers.get("cf-mitigated"):
        return True

    if response.status_code not in (403, 503):
        return False

    # A 403/503 from the API itself is JSON; Cloudflare answers with HTML.
    content_type = response.headers.get("content-type", "")
    head = response.text[:4000] if "json" not in content_type else ""

    return "json" not in content_type or any(marker in head for marker in BLOCK_MARKERS)


def fetch_kci(url, authorization=None):
    """
    GETs a KCI URL with a browser TLS fingerprint. Tries the configured
    impersonation profile, then the fallbacks when Cloudflare blocks it.

    Returns the curl_cffi response. Raises KciBlocked when every profile is
    blocked, curl_cffi's RequestException on connection errors / timeouts.
    """
    headers = {"Accept": "application/json"}
    if authorization:
        headers["Authorization"] = authorization

    blocked = None

    for profile in profiles():
        response = requests.get(url, headers=headers, impersonate=profile, timeout=TIMEOUT, allow_redirects=False)
        cf_ray = response.headers.get("cf-ray")

        if not is_blocked(response):
            if response.status_code >= 400:
                log.warning("HTTP %s from %s (impersonate=%s, cf-ray=%s)", response.status_code, url, profile, cf_ray or "-")
            return response

        blocked = KciBlocked(response.status_code, cf_ray, profile)
        log.warning("Cloudflare block for %s: HTTP %s, impersonate=%s, cf-ray=%s", url, response.status_code, profile, cf_ray or "-")

    raise blocked


def host_allowed(url):
    parts = urlsplit(url)
    host = (parts.hostname or "").lower()

    return parts.scheme == "https" and any(host == h or host.endswith("." + h) for h in ALLOWED_HOSTS)


class Handler(BaseHTTPRequestHandler):
    server_version = "kci-fetch"

    def do_GET(self):
        path = urlsplit(self.path)

        if path.path == "/health":
            return self.json(200, {"ok": True, "impersonate": profiles()})

        if path.path != "/fetch":
            return self.json(404, {"error": "not_found"})

        url = (parse_qs(path.query).get("url") or [""])[0]

        if not host_allowed(url):
            return self.json(400, {"error": "url_not_allowed", "message": f"Only https URLs on {', '.join(ALLOWED_HOSTS)} can be fetched."})

        try:
            response = fetch_kci(url, self.headers.get("Authorization"))
        except KciBlocked as e:
            return self.json(502, {"error": "cloudflare_blocked", "message": str(e), "status": e.status, "cf_ray": e.cf_ray})
        except RequestException as e:
            log.warning("Cannot reach %s: %s", url, e)
            return self.json(504, {"error": "upstream_unreachable", "message": f"cannot reach {url}: {e}"})

        body = response.content
        self.send_response(response.status_code)
        for name in PASS_HEADERS:
            if response.headers.get(name):
                self.send_header(name, response.headers[name])
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def json(self, status, payload):
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        if "error" in payload:
            self.send_header("X-Kci-Fetch-Error", payload["error"])
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        log.info("%s %s", self.address_string(), fmt % args)


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    port = int(os.environ.get("KCI_FETCH_PORT", "8080"))
    log.info("listening on :%s (impersonate %s, timeout %ss, hosts %s)", port, " -> ".join(profiles()), TIMEOUT, ALLOWED_HOSTS)
    ThreadingHTTPServer(("0.0.0.0", port), Handler).serve_forever()
