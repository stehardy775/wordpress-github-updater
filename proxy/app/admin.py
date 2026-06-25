"""
Admin UI (humans), mounted under /admin and protected by App Service Easy Auth
(Entra). See security.require_admin for the auth model.

  GET  /admin                   dashboard — recent requests + summaries from Log Analytics
  GET  /admin/settings          blocklist management
  POST /admin/settings/add      add a block rule
  POST /admin/settings/remove   remove a block rule
"""

from __future__ import annotations

from html import escape

from fastapi import APIRouter, Form, Request
from fastapi.responses import HTMLResponse, RedirectResponse, Response

from . import blocklist, queries
from .security import require_admin

router = APIRouter()


# ---------------------------------------------------------------------------
# Auth + CSRF helpers
# ---------------------------------------------------------------------------

def _guard(request: Request):
    """Returns (principal, None) or (None, Response) to short-circuit."""
    name, redirect = require_admin(request)
    if redirect is not None:
        return None, redirect
    if name is None:
        return None, HTMLResponse(_page("Forbidden", "<p>Your account is not permitted.</p>", None), status_code=403)
    return name, None


def _same_origin(request: Request) -> bool:
    host = request.headers.get("host", "")
    origin = request.headers.get("origin")
    if origin:
        return origin.split("://", 1)[-1] == host
    referer = request.headers.get("referer", "")
    return bool(host) and host in referer


# ---------------------------------------------------------------------------
# Routes
# ---------------------------------------------------------------------------

@router.get("/admin", response_class=HTMLResponse)
async def dashboard(request: Request) -> Response:
    principal, stop = _guard(request)
    if stop is not None:
        return stop

    if not queries.is_configured():
        body = "<p class='muted'>Log Analytics is not configured (set <code>LOGS_WORKSPACE_ID</code>).</p>"
        return HTMLResponse(_page("Dashboard", body, principal))

    recent = await queries.run(
        "GhuRequests_CL | project TimeGenerated, SiteDomain, Repo, Action, Tag, Status "
        "| order by TimeGenerated desc | take 50"
    )
    by_repo = await queries.run(
        "GhuRequests_CL | summarize Requests=count(), Sites=dcount(SiteDomain) by Repo "
        "| order by Requests desc | take 20"
    )
    by_domain = await queries.run(
        "GhuRequests_CL | summarize Requests=count() by SiteDomain "
        "| order by Requests desc | take 20"
    )

    if recent is None:
        body = "<p class='muted'>Could not query Log Analytics (check the managed identity's role and that data has been ingested).</p>"
        return HTMLResponse(_page("Dashboard", body, principal))

    body = (
        "<div class='grid'>"
        + _card("Top repositories (7d)", _table(by_repo, ["Repo", "Requests", "Sites"]))
        + _card("Top domains (7d)", _table(by_domain, ["SiteDomain", "Requests"]))
        + "</div>"
        + _card("Recent requests (50)", _table(recent, ["TimeGenerated", "SiteDomain", "Repo", "Action", "Tag", "Status"]))
    )
    return HTMLResponse(_page("Dashboard", body, principal))


@router.get("/admin/settings", response_class=HTMLResponse)
async def settings(request: Request) -> Response:
    principal, stop = _guard(request)
    if stop is not None:
        return stop

    if not blocklist.is_configured():
        body = "<p class='muted'>Blocklist storage is not configured (set <code>GHU_STORAGE_ACCOUNT</code>).</p>"
        return HTMLResponse(_page("Settings", body, principal))

    rules = await blocklist.get_rules(force=True)

    rows = ""
    for r in rules:
        rows += (
            "<tr>"
            f"<td>{escape(r['domain'])}</td>"
            f"<td>{escape(r['repo'])}</td>"
            f"<td>{escape(r.get('note', ''))}</td>"
            f"<td class='muted'>{escape(r.get('created_by', ''))}</td>"
            "<td><form method='post' action='/admin/settings/remove' class='inline'>"
            f"<input type='hidden' name='domain' value='{escape(r['domain'])}'>"
            f"<input type='hidden' name='repo' value='{escape(r['repo'])}'>"
            "<button class='danger' type='submit'>Remove</button></form></td>"
            "</tr>"
        )
    if not rows:
        rows = "<tr><td colspan='5' class='muted'>No block rules.</td></tr>"

    body = (
        _card(
            "Add block rule",
            "<form method='post' action='/admin/settings/add' class='form'>"
            "<label>Domain <input name='domain' placeholder='* (all) or site.example.com'></label>"
            "<label>Repo <input name='repo' placeholder='* (all) or owner/repo'></label>"
            "<label>Note <input name='note' placeholder='optional'></label>"
            "<button type='submit'>Add block</button>"
            "</form>"
            "<p class='muted'>Leave a field as <code>*</code> (or blank) to match all. "
            "A request is blocked when both fields match.</p>",
        )
        + _card(
            "Current blocks",
            "<table><thead><tr><th>Domain</th><th>Repo</th><th>Note</th><th>Added by</th><th></th></tr></thead>"
            f"<tbody>{rows}</tbody></table>",
        )
    )
    return HTMLResponse(_page("Settings", body, principal))


@router.post("/admin/settings/add")
async def settings_add(
    request: Request,
    domain: str = Form(""),
    repo: str = Form(""),
    note: str = Form(""),
) -> Response:
    principal, stop = _guard(request)
    if stop is not None:
        return stop
    if not _same_origin(request):
        return HTMLResponse("Bad origin", status_code=400)

    domain = (domain or "").strip() or "*"
    repo = (repo or "").strip() or "*"
    await blocklist.add_rule(domain, repo, (note or "").strip(), principal or "")
    return RedirectResponse(url="/admin/settings", status_code=303)


@router.post("/admin/settings/remove")
async def settings_remove(
    request: Request,
    domain: str = Form(""),
    repo: str = Form(""),
) -> Response:
    principal, stop = _guard(request)
    if stop is not None:
        return stop
    if not _same_origin(request):
        return HTMLResponse("Bad origin", status_code=400)

    await blocklist.remove_rule((domain or "").strip() or "*", (repo or "").strip() or "*")
    return RedirectResponse(url="/admin/settings", status_code=303)


# ---------------------------------------------------------------------------
# Rendering (all dynamic values escaped)
# ---------------------------------------------------------------------------

def _table(rows: list[dict] | None, columns: list[str]) -> str:
    head = "".join(f"<th>{escape(c)}</th>" for c in columns)
    if not rows:
        body = f"<tr><td colspan='{len(columns)}' class='muted'>No data.</td></tr>"
    else:
        body = ""
        for row in rows:
            cells = "".join(f"<td>{escape(str(row.get(c, '') if row.get(c) is not None else ''))}</td>" for c in columns)
            body += f"<tr>{cells}</tr>"
    return f"<table><thead><tr>{head}</tr></thead><tbody>{body}</tbody></table>"


def _card(title: str, inner: str) -> str:
    return f"<section class='card'><h2>{escape(title)}</h2>{inner}</section>"


def _page(title: str, body: str, principal: str | None) -> str:
    who = f"<span class='muted'>{escape(principal)}</span>" if principal else ""
    return (
        "<!doctype html><html lang='en'><head><meta charset='utf-8'>"
        f"<meta name='viewport' content='width=device-width, initial-scale=1'><title>{escape(title)} · GitHubUpdater proxy</title>"
        "<style>"
        ":root{color-scheme:light dark}"
        "body{font:14px/1.5 system-ui,sans-serif;margin:0;background:#f6f7f9;color:#111}"
        "@media(prefers-color-scheme:dark){body{background:#15171a;color:#e7e9ec}.card{background:#1d2024!important;border-color:#2a2e34!important}th{background:#23272d!important}}"
        "header{display:flex;align-items:center;gap:16px;padding:12px 20px;border-bottom:1px solid #d9dde2;background:#fff}"
        "@media(prefers-color-scheme:dark){header{background:#1d2024;border-color:#2a2e34}}"
        "header nav a{margin-right:14px;text-decoration:none;color:inherit;font-weight:600}"
        "main{max-width:1100px;margin:0 auto;padding:20px}"
        ".grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:760px){.grid{grid-template-columns:1fr}}"
        ".card{background:#fff;border:1px solid #e3e6ea;border-radius:10px;padding:16px;margin-bottom:16px;overflow-x:auto}"
        "h1{font-size:16px;margin:0}h2{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#667;margin:0 0 10px}"
        "table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:6px 10px;border-bottom:1px solid #eceef1;white-space:nowrap}"
        "th{background:#f2f4f7;font-size:12px;color:#556}"
        ".muted{color:#889}.form{display:flex;flex-wrap:wrap;gap:12px;align-items:end}.form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#667}"
        "input{padding:6px 8px;border:1px solid #c8cdd4;border-radius:6px;background:transparent;color:inherit}"
        "button{padding:7px 12px;border:0;border-radius:6px;background:#2b6cb0;color:#fff;font-weight:600;cursor:pointer}"
        "button.danger{background:#c53030}.inline{display:inline}"
        "</style></head><body>"
        "<header><h1>GitHubUpdater</h1>"
        "<nav><a href='/admin'>Dashboard</a><a href='/admin/settings'>Settings</a></nav>"
        f"<div style='margin-left:auto'>{who} &nbsp; <a href='/.auth/logout' class='muted'>Sign out</a></div>"
        "</header><main>"
        f"{body}"
        "</main></body></html>"
    )
