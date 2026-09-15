# Render deployment

Wallos runs as a single paid Docker Web Service. Its Nginx server listens on
port 80, and Render checks `GET /health.php`.

## Persistent data

The service has one 1 GB Persistent Disk mounted at `/var/data`. The Render
environment sets `WALLOS_DATA_DIR=/var/data`, causing `startup.sh` to expose:

| Persistent location | Wallos path |
| --- | --- |
| `/var/data/db` | `/var/www/html/db` |
| `/var/data/logos` | `/var/www/html/images/uploads/logos` |

The startup adapter is idempotent. It never copies application defaults over a
non-empty persistent directory. `createdatabase.php` creates `wallos.db` only
when that file does not already exist, and migrations run on every startup.

Do not mount the disk at `/var/www/html`, because doing so would hide the
application files supplied by the Docker image.

## Scheduled tasks

The image runs `dcron` in the same container as Nginx and PHP-FPM. This is
intentional: Render disks are available to only one service, so a separate
Render Cron Job would operate without the live SQLite database. The schedules
are defined in `cronjobs` and use the `TZ=America/Vancouver` environment value.

## Configuration

`render.yaml` is the canonical service configuration:

- Docker runtime built from the repository `Dockerfile`
- Oregon region
- Starter paid compute plan
- automatic deployment from `main`
- `PORT=80`
- health check at `/health.php`
- 1 GB disk at `/var/data`

### Cloudflare Turnstile

Login and registration CAPTCHA protection is configured only through environment
variables so the Turnstile secret is never stored in SQLite or rendered in the
Admin UI:

| Variable | Value |
| --- | --- |
| `TURNSTILE_ENABLED` | `true` to protect both password login and registration |
| `TURNSTILE_SITE_KEY` | Public widget site key |
| `TURNSTILE_SECRET_KEY` | Secret server-side verification key |

Add the keys as secret environment variables in Render. Keep
`TURNSTILE_ENABLED=false` until both keys are present. If protection is enabled
with missing or invalid keys, Wallos fails closed and shows a configuration
message instead of accepting an unverified login or registration. OIDC login is
not wrapped in Turnstile because authentication occurs at the configured identity
provider.

Turnstile is required on every password login while enabled. This intentionally
avoids a session-only adaptive threshold that bots could bypass; durable login
rate limiting can be added separately without weakening CAPTCHA enforcement.

Validate the Blueprint with:

```sh
render blueprints validate render.yaml
```

The Render CLI can create and update services but cannot attach a Persistent
Disk in its service flags. Create/sync the Blueprint in the Render Dashboard,
or attach the disk through Render's supported API/Dashboard before treating the
deployment as production-ready.

## Verification

After each deployment, verify `/health.php`, the registration or login page,
and the startup logs. To prove persistence, create a harmless marker under
`/var/data`, restart the service, and confirm the marker and SQLite database
remain. Never replace an existing `/var/data/db/wallos.db` during a deploy.
