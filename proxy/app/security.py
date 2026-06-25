"""
Two independent gates:

1. Proxy endpoints (machine callers / WordPress sites) — a shared secret sent
   as the X-GHU-Key header, compared in constant time. Configured via
   GHU_SHARED_SECRET; when unset the proxy is open (not recommended).

2. Admin endpoints (humans) — App Service Easy Auth (Entra). Run Easy Auth in
   "allow unauthenticated" mode so machine calls to "/" still pass, and enforce
   identity in-app on /admin/* using the principal headers Easy Auth injects.
   Easy Auth strips any client-supplied X-MS-CLIENT-PRINCIPAL* headers, so they
   cannot be spoofed — but this ONLY holds while Easy Auth is enabled.
"""

from __future__ import annotations

import hmac
import os
from urllib.parse import quote

from fastapi import Request
from fastapi.responses import RedirectResponse


def shared_secret_ok(request: Request) -> bool:
    """True when no secret is configured, or the X-GHU-Key header matches."""
    secret = os.getenv("GHU_SHARED_SECRET", "")
    if not secret:
        return True
    provided = request.headers.get("x-ghu-key", "")
    return hmac.compare_digest(provided, secret)


def admin_principal(request: Request) -> str | None:
    """The signed-in admin's name from Easy Auth, or None if not signed in."""
    name = request.headers.get("x-ms-client-principal-name")
    return name or None


def admin_allowed(name: str) -> bool:
    """When GHU_ADMINS is set, restrict admin access to the listed principals."""
    allow = os.getenv("GHU_ADMINS", "").strip()
    if not allow:
        return True
    return name.lower() in {a.strip().lower() for a in allow.split(",") if a.strip()}


def require_admin(request: Request) -> tuple[str | None, RedirectResponse | None]:
    """Returns (principal, None) when authorised, or (None, redirect) otherwise.

    Unauthenticated requests are bounced to the Easy Auth login; authenticated
    but non-allowlisted principals get None/None so the caller can 403.
    """
    name = admin_principal(request)
    if not name:
        target = quote(str(request.url.path), safe="/")
        return None, RedirectResponse(
            url=f"/.auth/login/aad?post_login_redirect_uri={target}",
            status_code=302,
        )
    if not admin_allowed(name):
        return None, None
    return name, None
