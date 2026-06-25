#!/usr/bin/env bash
# Startup command for the GitHubUpdater proxy on Azure App Service (Python).
#
# Point the web app at this file:
#   Settings → Configuration → Startup Command:  bash startup.sh
#
# Keeping it in the repo means runtime changes (workers, timeout) are versioned
# rather than edited by hand in the portal.
set -euo pipefail

# Azure provides $PORT; default to 8000 for local runs.
PORT="${PORT:-8000}"

# Workers: small instances have little RAM/CPU — 2 is plenty for this volume.
WORKERS="${GUNICORN_WORKERS:-2}"

# --timeout 600 so large package downloads aren't killed mid-stream.
exec gunicorn \
  --workers "${WORKERS}" \
  --worker-class uvicorn.workers.UvicornWorker \
  --timeout 600 \
  --bind "0.0.0.0:${PORT}" \
  app.main:app
