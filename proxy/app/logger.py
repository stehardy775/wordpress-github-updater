"""
Best-effort request logging to a Log Analytics custom table via the Azure
Monitor Logs Ingestion API.

Authentication uses DefaultAzureCredential, so on Azure App Service it picks up
the web app's system-assigned managed identity with no secrets in config. The
identity needs the "Monitoring Metrics Publisher" role on the Data Collection
Rule.

Logging never raises into the request path: any failure (including no
configuration) is logged and swallowed, so an ingestion problem can't break an
update for a site.

This module is the only place the storage backend lives — swap it to change
where logs go; main.py just calls record().
"""

from __future__ import annotations

import logging
import os

from azure.core.exceptions import AzureError
from azure.identity.aio import DefaultAzureCredential
from azure.monitor.ingestion.aio import LogsIngestionClient

_client: LogsIngestionClient | None = None
_credential: DefaultAzureCredential | None = None
_initialised = False


def _get_client() -> LogsIngestionClient | None:
    global _client, _credential, _initialised

    if _initialised:
        return _client
    _initialised = True

    endpoint = os.getenv("LOGS_DCE_ENDPOINT", "")
    rule_id = os.getenv("LOGS_DCR_RULE_ID", "")
    if not endpoint or not rule_id:
        # Logging disabled — proxy still serves updates normally.
        return None

    _credential = DefaultAzureCredential()
    _client = LogsIngestionClient(endpoint=endpoint, credential=_credential)
    return _client


async def record(row: dict) -> None:
    """Uploads a single request record. Best-effort; never raises."""
    try:
        client = _get_client()
        if client is None:
            return

        rule_id = os.getenv("LOGS_DCR_RULE_ID", "")
        stream = os.getenv("LOGS_STREAM_NAME", "Custom-GhuRequests_CL")
        await client.upload(rule_id=rule_id, stream_name=stream, logs=[row])
    except (AzureError, Exception) as exc:  # noqa: BLE001 - logging must never break the request
        logging.warning("ghu-proxy logger: %s", exc)
