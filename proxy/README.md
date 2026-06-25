# GitHubUpdater Proxy (Python / FastAPI)

A thin proxy that lets WordPress sites update from **private** GitHub repos
without ever embedding the GitHub token in the distributed plugin/theme — plus
a small admin UI to review logs and block specific domain/repo combinations.

```
WordPress site ──(X-GHU-Site, X-GHU-Key)──► Proxy ──(token, server-side)──► GitHub API
                                              │  │
                                              │  └──► Log Analytics  (who pulled what)
                                              └─────► Table Storage  (blocklist + settings)

You (Entra login) ──► /admin  (dashboard + settings, behind App Service Easy Auth)
```

The token lives only on the proxy. The HTTP contract is identical to the PHP
variant, so the WordPress plugin needs no changes.

## Security model

| Surface | Who calls it | Gate |
|---|---|---|
| `/?ghu=…` (proxy) | WordPress sites (machines) | **Shared secret** (`X-GHU-Key` vs `GHU_SHARED_SECRET`), then the **blocklist** |
| `/admin/*` (UI) | You (a human) | **App Service Easy Auth** (Entra), optionally narrowed by `GHU_ADMINS` |

There is no per-site license key. The real bound on what the proxy can serve is
the **scope of `GITHUB_TOKEN`** — give it Contents:Read on only the repos you
intend to distribute. The shared secret stops arbitrary internet callers; the
blocklist lets you cut off a specific site or repo after the fact.

## Files

| File | Purpose |
|---|---|
| `app/main.py` | FastAPI app. Proxy endpoints routed by `?ghu=…`. |
| `app/security.py` | Shared-secret check; Easy Auth admin gate. |
| `app/blocklist.py` | Domain/repo blocklist in Table Storage (cached). |
| `app/logger.py` | Request logging to a Log Analytics custom table (write). |
| `app/queries.py` | Dashboard reads via the Logs Query API (read). |
| `app/admin.py` | `/admin` dashboard + settings UI. |
| `startup.sh` | Gunicorn/Uvicorn startup command (versioned in the repo). |
| `requirements.txt` | Python dependencies. |
| `infra/` | Custom-table columns + Data Collection Rule template. |
| `.env.example` | Documents every App Setting. |

## Run locally

```bash
cd proxy
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
export GITHUB_TOKEN=github_pat_xxx
export GHU_SHARED_SECRET=s3cret
# Leave LOGS_*, GHU_STORAGE_ACCOUNT unset to disable logging/blocklist locally.
bash startup.sh    # or: uvicorn app.main:app --reload --port 8000

curl -H "X-GHU-Key: s3cret" "http://localhost:8000/?ghu=release&repo=your-org/your-repo"
```

> The `/admin` pages rely on Easy Auth headers that only exist on Azure. Don't
> expose the proxy publicly without Easy Auth enabled — the admin gate trusts
> `X-MS-CLIENT-PRINCIPAL-NAME`, which only the platform can set safely.

## Deploy to Azure App Service (Python)

> **Runtime note:** the proxy runs on a **Python** App Service (Linux), tested on
> Python 3.14. If your existing web app is configured for PHP, change its runtime
> stack to Python (Configuration → General settings → Stack) or create a new
> Python app.

### 1. Log Analytics workspace + custom table (logging)

Easiest via the **portal wizard**, which creates the table, Data Collection
Endpoint (DCE) and Data Collection Rule (DCR) together:

1. Create (or pick) a **Log Analytics workspace**. Note its **Workspace ID** (GUID).
2. Workspace → **Settings → Tables → Create → New custom log (DCR-based)**.
3. Name the table `GhuRequests` (Log Analytics appends `_CL`).
4. Create a new **DCR** and **DCE** when prompted.
5. Define the schema from `infra/table-columns.txt` (keep a `TimeGenerated` column).

CLI alternative:

```bash
az monitor log-analytics workspace table create \
  --resource-group <rg> --workspace-name <workspace> --name GhuRequests_CL \
  --columns TimeGenerated=datetime SiteDomain=string Repo=string Action=string \
            Asset=string Tag=string Status=int ClientIp=string UserAgent=string

az monitor data-collection endpoint create \
  --resource-group <rg> --name ghu-dce --location <region> --public-network-access Enabled

# Fill placeholders in infra/dcr.json first: <REGION>, <DCE_RESOURCE_ID>, <WORKSPACE_RESOURCE_ID>
az monitor data-collection rule create \
  --resource-group <rg> --name ghu-dcr --location <region> --rule-file infra/dcr.json

# Collect the two values the proxy needs:
az monitor data-collection endpoint show -g <rg> -n ghu-dce --query logsIngestion.endpoint -o tsv  # LOGS_DCE_ENDPOINT
az monitor data-collection rule show     -g <rg> -n ghu-dcr --query immutableId -o tsv              # LOGS_DCR_RULE_ID
```

### 2. Storage account for the blocklist

```bash
az storage account create -g <rg> -n <storageacct> --location <region> --sku Standard_LRS
```

The proxy creates the `ghublocks` table automatically on first use.

### 3. Enable managed identity and assign roles

```bash
az webapp identity assign -g <rg> -n <app-name>
PID=$(az webapp identity show -g <rg> -n <app-name> --query principalId -o tsv)

DCR_ID=$(az monitor data-collection rule show -g <rg> -n ghu-dcr --query id -o tsv)
WS_ID=$(az monitor log-analytics workspace show -g <rg> -n <workspace> --query id -o tsv)
SA_ID=$(az storage account show -g <rg> -n <storageacct> --query id -o tsv)

az role assignment create --assignee "$PID" --role "Monitoring Metrics Publisher"   --scope "$DCR_ID"  # write logs
az role assignment create --assignee "$PID" --role "Log Analytics Reader"            --scope "$WS_ID"   # dashboard
az role assignment create --assignee "$PID" --role "Storage Table Data Contributor"  --scope "$SA_ID"   # blocklist
```

### 4. Turn on Easy Auth (Entra) for the admin UI

Web App → **Settings → Authentication → Add identity provider → Microsoft**.

- Create a new app registration (or reuse one).
- **Crucially, set "Restrict access" to _Allow unauthenticated access_.** This lets
  WordPress sites reach `/` while the app enforces login on `/admin/*` itself.
- Optionally set `GHU_ADMINS` (below) to restrict which signed-in users get in.

### 5. Set Application settings

Web App → **Settings → Environment variables** (see `.env.example`):

| Name | Value |
|---|---|
| `GITHUB_TOKEN` | Fine-grained PAT, `Contents: Read` on your repo(s). |
| `GHU_SHARED_SECRET` | A long random string (also configured in the plugin). |
| `LOGS_DCE_ENDPOINT` | DCE `logsIngestion.endpoint` from step 1. |
| `LOGS_DCR_RULE_ID` | DCR `immutableId` from step 1. |
| `LOGS_STREAM_NAME` | `Custom-GhuRequests_CL` |
| `LOGS_WORKSPACE_ID` | Workspace GUID (dashboard reads). |
| `GHU_STORAGE_ACCOUNT` | Storage account name (blocklist). |
| `GHU_ADMINS` | *(optional)* comma-separated admin emails. |

### 6. Startup command + build setting

Web App → **Settings → Configuration → Startup Command**:

```
bash startup.sh
```

Tell Oryx to install `requirements.txt` on each deploy:

```bash
az webapp config appsettings set -g <rg> -n <app-name> \
  --settings SCM_DO_BUILD_DURING_DEPLOYMENT=true
```

### 7. Deploy

**Auto-deploy (in place)** — linking the web app to this repo via the Azure
portal's **Deployment Center** generates a workflow
([`.github/workflows/main_dowo-wordpress-updates.yml`](../.github/workflows/main_dowo-wordpress-updates.yml))
and a publish-profile secret automatically. The generated file deploys the repo
*root* and builds at root — wrong here, since the root is the WordPress plugin
and `requirements.txt` lives in `proxy/`. The committed version is adapted to:

- deploy **only `proxy/`** (`package: proxy`), letting Oryx build `requirements.txt`;
- trigger only on `proxy/**` changes;
- drop the redundant client-side venv build.

It reuses the `AZUREAPPSERVICE_PUBLISHPROFILE_…` secret Deployment Center created,
so no extra auth setup is needed. After it deploys, every push to `main` that
touches `proxy/**` redeploys.

**Manual one-off** — if you just want to push without CI:

```bash
cd proxy
zip -r ../proxy.zip . -x '.env' '.git*' '.venv/*' '__pycache__/*'
az webapp deploy --resource-group <rg> --name <app-name> --src-path ../proxy.zip --type zip
```

### 8. Verify

```bash
curl -H "X-GHU-Key: <secret>" "https://<app-name>.azurewebsites.net/?ghu=release&repo=your-org/your-repo"
```

`401` = bad/missing secret · `403` = blocked combo · `500` = `GITHUB_TOKEN` unset.
Then open `https://<app-name>.azurewebsites.net/admin` in a browser — you'll be
sent through Entra login.

### 9. Point the plugin at it

```php
new GitHubUpdater( __FILE__, 'your-org/your-repo', [
    'proxy'  => 'https://<app-name>.azurewebsites.net',
    'secret' => '<same value as GHU_SHARED_SECRET>',
] );
```

## Admin UI

- **`/admin`** — dashboard: recent requests, top repos, top domains (from Log
  Analytics, last 7 days).
- **`/admin/settings`** — blocklist: add/remove rules. Each rule is a `domain`
  + `repo`, either of which may be `*` (all). A request is blocked when **both**
  match. Examples:
  - `domain=bad.example.com`, `repo=*` → block that site entirely
  - `domain=*`, `repo=org/private` → block that repo for everyone
  - `domain=bad.example.com`, `repo=org/plugin` → block just that combination

Block rules take effect within ~30s (the proxy caches them). The blocklist
**fails open**: if the store is unreachable, updates are served rather than
broken.

## Endpoints

| Request | Returns |
|---|---|
| `GET /?ghu=release&repo=owner/repo` | GitHub "latest release" JSON. |
| `GET /?ghu=contents&repo=owner/repo&path=README.md` | GitHub Contents API JSON. |
| `GET /?ghu=download&repo=owner/repo&tag=v1.2.3[&asset=my.zip]` | The asset (or zipball) streamed as a zip. |
| `GET /healthz` | Liveness check. |
| `GET /admin`, `/admin/settings` | Admin UI (Easy Auth). |

## Querying the log (KQL)

```kusto
GhuRequests_CL
| summarize sites = dcount(SiteDomain) by Repo
| order by sites desc
```

> Ingestion is asynchronous — records typically appear within a few minutes.

## Notes

- **Downloads stream** through the proxy; the GitHub token is never forwarded
  when GitHub redirects to its storage backend (httpx drops `Authorization` on
  cross-origin redirects).
- **Logging is non-blocking** (a background task after the response) and
  best-effort — missing config or an ingestion error never breaks an update.
- **Admin security depends on Easy Auth being enabled.** With it on, Azure
  strips client-supplied `X-MS-CLIENT-PRINCIPAL*` headers so they can't be
  spoofed. Don't deploy publicly without it.
