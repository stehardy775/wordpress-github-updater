"""
GitHubUpdater proxy (FastAPI)

A thin proxy between WordPress sites running GitHubUpdater.php (in proxy mode)
and the GitHub API. It holds the GitHub token server-side so the token is never
embedded in the distributed plugin/theme, and logs which site pulled which
package to a Log Analytics custom table.

The HTTP contract matches the PHP proxy exactly, so the two are interchangeable
and the WordPress plugin needs no changes. A single entry point is routed by the
?ghu= query argument, so no URL rewriting is required on the host.

Endpoints (all GET, served from "/"):
    /?ghu=release&repo=owner/repo
    /?ghu=contents&repo=owner/repo&path=README.md
    /?ghu=download&repo=owner/repo&tag=v1.2.3[&asset=my-plugin.zip]

Configuration (environment variables / App Settings) — see .env.example for the
full list. Core keys:
    GITHUB_TOKEN        Required. Fine-grained PAT with Contents:Read on the repos.
    GHU_SHARED_SECRET   Recommended. Shared secret checked against X-GHU-Key.
    LOGS_*              Optional. Log Analytics ingestion (logging); skipped if unset.
    GHU_STORAGE_ACCOUNT Optional. Table Storage account for the blocklist.

Admin UI (dashboard + blocklist settings) is mounted under /admin by app.admin.
"""

from __future__ import annotations

import json
import logging
import os
import re
from datetime import datetime, timezone
from urllib.parse import quote, urlparse

import httpx
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse, Response, StreamingResponse
from starlette.background import BackgroundTask

from .admin import router as admin_router
from .blocklist import is_blocked
from .logger import record
from .security import shared_secret_ok

GITHUB_API = "https://api.github.com"
PROXY_UA = "GitHubUpdater-Proxy/1.0"
REPO_RE = re.compile(r"^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$")

app = FastAPI(title="GitHubUpdater proxy", docs_url=None, redoc_url=None)
app.include_router(admin_router)


# ---------------------------------------------------------------------------
# Routing
# ---------------------------------------------------------------------------

@app.get("/healthz")
async def healthz() -> Response:
    return JSONResponse({"ok": True})


@app.get("/")
async def root(request: Request) -> Response:
    # Gate 1: shared secret (machine callers).
    if not shared_secret_ok(request):
        return _err(401, "Unauthorized")

    if not _token():
        logging.error("ghu-proxy: GITHUB_TOKEN is not set")
        return _err(500, "Server not configured")

    q = request.query_params
    action = q.get("ghu", "")
    repo = q.get("repo", "")

    if not REPO_RE.match(repo):
        return _err(400, "Invalid or missing repo")

    # Gate 2: domain/repo blocklist.
    if await is_blocked(_site_host(request), repo):
        return _err(403, "Forbidden")

    if action == "release":
        return await _handle_release(request, repo)
    if action == "contents":
        return await _handle_contents(request, repo, q.get("path", ""))
    if action == "download":
        return await _handle_download(request, repo, q.get("tag", ""), q.get("asset", ""))
    return _err(400, "Unknown action")


# ---------------------------------------------------------------------------
# Handlers
# ---------------------------------------------------------------------------

async def _handle_release(request: Request, repo: str) -> Response:
    url = f"{GITHUB_API}/repos/{repo}/releases/latest"
    status, body = await _github_json(url, _gh_headers("application/vnd.github+json"))
    bg = BackgroundTask(record, _log_row(request, "release", repo, "", "", status))
    return _passthrough_json(status, body, bg)


async def _handle_contents(request: Request, repo: str, path: str) -> Response:
    path = path.lstrip("/")
    if path == "" or ".." in path:
        return _err(400, "Invalid path")

    encoded = "/".join(quote(seg, safe="") for seg in path.split("/"))
    url = f"{GITHUB_API}/repos/{repo}/contents/{encoded}"
    status, body = await _github_json(url, _gh_headers("application/vnd.github+json"))
    bg = BackgroundTask(record, _log_row(request, "contents", repo, "", "", status))
    return _passthrough_json(status, body, bg)


async def _handle_download(request: Request, repo: str, tag: str, asset: str) -> Response:
    if not tag:
        return _err(400, "Missing tag")

    tag_url = f"{GITHUB_API}/repos/{repo}/releases/tags/{quote(tag, safe='')}"
    status, body = await _github_json(tag_url, _gh_headers("application/vnd.github+json"))
    if status != 200:
        bg = BackgroundTask(record, _log_row(request, "download", repo, asset, tag, status))
        return JSONResponse({"error": "Could not resolve release"}, status_code=502, background=bg)

    release = json.loads(body or "{}")
    asset_url = ""
    filename = f"{repo.split('/')[-1]}-{tag}.zip"

    # Prefer the named asset; otherwise fall back to the source zipball.
    if asset and isinstance(release.get("assets"), list):
        for candidate in release["assets"]:
            if candidate.get("name") == asset:
                asset_url = candidate.get("url", "")
                filename = asset
                break

    is_asset = bool(asset_url)
    url = asset_url if is_asset else f"{GITHUB_API}/repos/{repo}/zipball/{quote(tag, safe='')}"
    accept = "application/octet-stream" if is_asset else "application/vnd.github+json"

    return await _stream_zip(request, url, _gh_headers(accept), filename, repo, asset, tag)


# ---------------------------------------------------------------------------
# GitHub helpers
# ---------------------------------------------------------------------------

async def _github_json(url: str, headers: dict) -> tuple[int, str]:
    try:
        async with httpx.AsyncClient(follow_redirects=True, timeout=15.0) as client:
            r = await client.get(url, headers=headers)
            return r.status_code, r.text
    except httpx.HTTPError as exc:
        logging.warning("ghu-proxy github_json: %s", exc)
        return 502, ""


async def _stream_zip(
    request: Request,
    url: str,
    headers: dict,
    filename: str,
    repo: str,
    asset: str,
    tag: str,
) -> Response:
    """Streams a release binary from GitHub straight through to the caller.

    httpx drops the Authorization header when GitHub redirects to its storage
    backend (a different origin), so the token is never leaked downstream.
    """
    client = httpx.AsyncClient(follow_redirects=True, timeout=httpx.Timeout(300.0, connect=15.0))
    try:
        req = client.build_request("GET", url, headers=headers)
        upstream = await client.send(req, stream=True)
    except httpx.HTTPError as exc:
        await client.aclose()
        logging.warning("ghu-proxy download: %s", exc)
        bg = BackgroundTask(record, _log_row(request, "download", repo, asset, tag, 502))
        return JSONResponse({"error": "Download failed"}, status_code=502, background=bg)

    if upstream.status_code != 200:
        status = upstream.status_code
        await upstream.aclose()
        await client.aclose()
        bg = BackgroundTask(record, _log_row(request, "download", repo, asset, tag, status))
        return JSONResponse({"error": "Download failed"}, status_code=502, background=bg)

    async def body_iter():
        try:
            async for chunk in upstream.aiter_bytes(65536):
                yield chunk
        finally:
            await upstream.aclose()
            await client.aclose()

    out_headers = {
        "Content-Disposition": f'attachment; filename="{_sanitize_filename(filename)}"',
        "X-Content-Type-Options": "nosniff",
    }
    content_length = upstream.headers.get("content-length")
    if content_length:
        out_headers["Content-Length"] = content_length

    bg = BackgroundTask(record, _log_row(request, "download", repo, asset, tag, 200))
    return StreamingResponse(
        body_iter(),
        media_type="application/zip",
        headers=out_headers,
        background=bg,
    )


# ---------------------------------------------------------------------------
# Utilities
# ---------------------------------------------------------------------------

def _token() -> str:
    return os.getenv("GITHUB_TOKEN", "")


def _gh_headers(accept: str) -> dict:
    return {
        "Authorization": f"Bearer {_token()}",
        "Accept": accept,
        "User-Agent": PROXY_UA,
        "X-GitHub-Api-Version": "2022-11-28",
    }


def _site_host(request: Request) -> str:
    site = request.headers.get("x-ghu-site", "")
    if not site:
        return ""
    return urlparse(site).hostname or site


def _log_row(request: Request, action: str, repo: str, asset: str, tag: str, status: int) -> dict:
    host = _site_host(request)

    return {
        "TimeGenerated": datetime.now(timezone.utc).isoformat(),
        "SiteDomain": host[:255] if host else None,
        "Repo": repo,
        "Action": action,
        "Asset": asset or None,
        "Tag": tag or None,
        "Status": status,
        "ClientIp": _client_ip(request),
        "UserAgent": (request.headers.get("user-agent", "")[:255]) or None,
    }


def _client_ip(request: Request) -> str | None:
    # Azure Web Apps front end sets X-Forwarded-For (often "ip:port").
    xff = request.headers.get("x-forwarded-for", "")
    if xff:
        first = re.sub(r":\d+$", "", xff.split(",")[0].strip())
        if first:
            return first[:64]
    return request.client.host if request.client else None


def _sanitize_filename(name: str) -> str:
    name = os.path.basename(name)
    name = re.sub(r"[^A-Za-z0-9._-]", "_", name)
    return name or "download.zip"


def _passthrough_json(status: int, body: str, bg: BackgroundTask) -> Response:
    if status == 0 or (status >= 500 and body == ""):
        return JSONResponse({"error": "Upstream error"}, status_code=502, background=bg)
    return Response(
        content=body,
        status_code=status,
        media_type="application/json",
        headers={"X-Content-Type-Options": "nosniff"},
        background=bg,
    )


def _err(status: int, message: str) -> Response:
    return JSONResponse({"error": message}, status_code=status)
