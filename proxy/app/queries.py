"""
Read side for the admin dashboard: runs KQL against the Log Analytics
workspace via the Logs Query API.

Auth uses DefaultAzureCredential (the web app's managed identity), which needs
the "Log Analytics Reader" (or "Monitoring Reader") role on the workspace.
Configured via LOGS_WORKSPACE_ID (the workspace GUID). When unset, the
dashboard reports logging as unconfigured.
"""

from __future__ import annotations

import logging
import os
from datetime import timedelta

from azure.identity.aio import DefaultAzureCredential
from azure.monitor.query import LogsQueryStatus
from azure.monitor.query.aio import LogsQueryClient


def is_configured() -> bool:
    return bool(os.getenv("LOGS_WORKSPACE_ID", ""))


async def run(kql: str, hours: int = 168) -> list[dict] | None:
    """Runs a query and returns a list of row dicts, or None if unavailable."""
    workspace = os.getenv("LOGS_WORKSPACE_ID", "")
    if not workspace:
        return None

    try:
        credential = DefaultAzureCredential()
        client = LogsQueryClient(credential)
        async with client, credential:
            response = await client.query_workspace(
                workspace_id=workspace,
                query=kql,
                timespan=timedelta(hours=hours),
            )

        if response.status != LogsQueryStatus.SUCCESS or not response.tables:
            return []

        table = response.tables[0]
        return [dict(zip(table.columns, row)) for row in table.rows]
    except Exception as exc:  # noqa: BLE001
        logging.warning("ghu dashboard query: %s", exc)
        return None
