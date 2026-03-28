KNX Worker — Deployment & Cron
===============================

This document describes how to run the KNX push worker in production.

Overview
--------
- The worker processes provider rows (eg. `ntfy`) from the canonical queue table `y05_knx_driver_notifications`.
- There are two recommended ways to run the worker:
  1. System cron + WP‑CLI (recommended, reliable)
  2. WP‑Cron fallback (registered automatically by the plugin, less reliable)

1) System cron + WP‑CLI (recommended)
-------------------------------------
Add a crontab entry on your server to run the WP‑CLI command every minute (or desired frequency).

Example (every minute):

```
# Run the KNX worker every minute (adjust `wp` path and `--path` to your WordPress install)
/usr/bin/wp knx worker run --path=/var/www/html --allow-root >> /var/log/knx_worker.log 2>&1
```

Notes:
- Use the absolute path to `wp` if it's not in PATH.
- `--allow-root` may be required in some environments; avoid running as root where possible.
- Logs are appended to `/var/log/knx_worker.log` in the example — ensure the log path is writable by the cron user.

2) WP‑Cron fallback (installed automatically)
-------------------------------------------
The plugin registers a per‑minute WP‑Cron event `knx_dn_worker_cron` as a fallback. WP‑Cron depends on site traffic and may run delayed; prefer system cron for production.

Commands
--------
- Run manually:
  - `wp knx worker run --path=/path/to/wp --allow-root`
- Check status:
  - `wp knx worker status --path=/path/to/wp --allow-root`
- Cleanup failed/delivered rows older than retention (see options):
  - `wp knx worker cleanup --path=/path/to/wp --allow-root`
- Backfill driver preferences from WP usermeta into canonical `y05_knx_drivers` table:
  - `wp knx worker backfill-prefs --path=/path/to/wp --allow-root`

Configuration
-------------
- Max retry attempts for provider sends is configurable via `wp option`:
  - `knx_dn_max_attempts` (default: `3`)
  - Set via WP‑CLI: `wp option update knx_dn_max_attempts 5`
- Backoff tuning options (seconds):
  - `knx_dn_backoff_base_seconds` (default: `30`) — base backoff in seconds
  - `knx_dn_backoff_max_seconds` (default: `3600`) — cap for backoff
  - Update via WP‑CLI: `wp option update knx_dn_backoff_base_seconds 60`
- Failed rows retention (days):
  - `knx_dn_failed_retention_days` (default: `30`)
  - Update via WP‑CLI: `wp option update knx_dn_failed_retention_days 14`
- Delivered rows retention (days):
  - `knx_dn_delivered_retention_days` (default: `90`) — rows with `status = 'delivered'` older than this will be deleted when running the `cleanup` command
  - Update via WP‑CLI: `wp option update knx_dn_delivered_retention_days 180`

Locking
-------
Both the WP‑CLI command and the scheduled runner use a transient lock `knx_dn_worker_lock` (TTL 5 minutes) to avoid concurrent runs.

Operational notes
-----------------
- The `cleanup` command deletes older `failed` and `delivered` rows; test in staging before running in production.
- If you prefer archiving instead of deletion, request the `archive` behavior and I can add an `archived` state or a separate archive table.
- Run the `backfill-prefs` command in staging first and inspect `y05_knx_drivers` changes.
