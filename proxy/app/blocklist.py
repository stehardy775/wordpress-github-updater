"""
Domain/repo blocklist, stored in Azure Table Storage.

A rule has a `domain` and a `repo`, either of which may be "*" (matches all).
A request (site_domain, repo) is blocked when any rule matches both fields.
Examples:
    domain="bad.example.com", repo="*"          → block that site entirely
    domain="*",              repo="org/private" → block that repo for everyone
    domain="bad.example.com", repo="org/plugin" → block just that combination

Rules are cached in-memory for a short TTL so the table isn't hit on every
request; changes from the settings page take effect within that window.

Auth uses DefaultAzureCredential (the web app's managed identity), which needs
the "Storage Table Data Contributor" role on the storage account. When
GHU_STORAGE_ACCOUNT is unset, the blocklist is disabled (nothing is blocked)
and the settings page reports it as unconfigured.
"""

from __future__ import annotations

import base64
import logging
import os
import time
from datetime import datetime, timezone

from azure.core.exceptions import ResourceExistsError, ResourceNotFoundError
from azure.data.tables.aio import TableServiceClient
from azure.identity.aio import DefaultAzureCredential

_TABLE = "ghublocks"
_PARTITION = "rule"
_CACHE_TTL = 30.0

_table_client = None
_initialised = False
_cache: dict = {"rules": [], "ts": 0.0}


def is_configured() -> bool:
    return bool(os.getenv("GHU_STORAGE_ACCOUNT", ""))


async def _client():
    global _table_client, _initialised
    if _initialised:
        return _table_client
    _initialised = True

    account = os.getenv("GHU_STORAGE_ACCOUNT", "")
    if not account:
        return None

    endpoint = f"https://{account}.table.core.windows.net"
    credential = DefaultAzureCredential()
    service = TableServiceClient(endpoint=endpoint, credential=credential)
    try:
        await service.create_table_if_not_exists(_TABLE)
    except ResourceExistsError:
        pass
    except Exception as exc:  # noqa: BLE001
        logging.warning("ghu blocklist: create table failed: %s", exc)

    _table_client = service.get_table_client(_TABLE)
    return _table_client


def _row_key(domain: str, repo: str) -> str:
    # Table keys disallow '/', '#', '?', '\\'; base64url keeps them deterministic.
    raw = f"{domain}\n{repo}".encode("utf-8")
    return base64.urlsafe_b64encode(raw).decode("ascii")


async def _load_rules() -> list[dict]:
    client = await _client()
    if client is None:
        return []

    rules: list[dict] = []
    try:
        async for e in client.list_entities():
            rules.append(
                {
                    "domain": e.get("Domain", "*"),
                    "repo": e.get("Repo", "*"),
                    "note": e.get("Note", ""),
                    "created_by": e.get("CreatedBy", ""),
                    "created_at": e.get("CreatedAt", ""),
                }
            )
    except Exception as exc:  # noqa: BLE001
        logging.warning("ghu blocklist: list failed: %s", exc)
    return rules


async def get_rules(force: bool = False) -> list[dict]:
    now = time.monotonic()
    if not force and (now - _cache["ts"]) < _CACHE_TTL:
        return _cache["rules"]

    rules = await _load_rules()
    _cache["rules"] = rules
    _cache["ts"] = now
    return rules


def _invalidate() -> None:
    _cache["ts"] = 0.0


async def is_blocked(domain: str, repo: str) -> bool:
    """Best-effort: on any error we fail OPEN (serve the update)."""
    try:
        for rule in await get_rules():
            rdomain = rule["domain"]
            rrepo = rule["repo"]
            if (rdomain == "*" or rdomain == domain) and (rrepo == "*" or rrepo == repo):
                return True
    except Exception as exc:  # noqa: BLE001
        logging.warning("ghu blocklist: match failed: %s", exc)
    return False


async def add_rule(domain: str, repo: str, note: str, by: str) -> None:
    client = await _client()
    if client is None:
        raise RuntimeError("Blocklist storage is not configured")

    entity = {
        "PartitionKey": _PARTITION,
        "RowKey": _row_key(domain, repo),
        "Domain": domain,
        "Repo": repo,
        "Note": note,
        "CreatedBy": by,
        "CreatedAt": datetime.now(timezone.utc).isoformat(),
    }
    await client.upsert_entity(entity)
    _invalidate()


async def remove_rule(domain: str, repo: str) -> None:
    client = await _client()
    if client is None:
        raise RuntimeError("Blocklist storage is not configured")
    try:
        await client.delete_entity(partition_key=_PARTITION, row_key=_row_key(domain, repo))
    except ResourceNotFoundError:
        pass
    _invalidate()
