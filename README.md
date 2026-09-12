# OpenCart on Railway

A production container image for [OpenCart](https://www.opencart.com/), the
open-source PHP shopping cart, built for [Railway](https://railway.com).

OpenCart ships as a release zip rather than a container image, and its own
`Dockerfile` in the upstream repository does not build. This repository packages a
pinned release on `php:8.3-apache` and adds the boot-time work a platform
deployment needs: it provisions its own database and a least-privilege role, runs
OpenCart's headless installer once, keeps both `config.php` files in step with the
service's public domain on every deploy, and lays the two directories OpenCart
writes to onto the single Railway volume.

## What the image does at boot

1. Repairs the Apache MPM. `php:*-apache` ships `mod_php`, which needs
   `mpm_prefork`, while recent builds leave `mpm_event` enabled beside it; the
   container otherwise dies on *More than one MPM loaded*. Apache's worker count is
   then sized from the container's memory cgroup rather than the host.
2. Prepares the volume. `system/storage/` (downloads, uploads, backups, logs,
   marketplace archives) moves off the web root entirely and onto the volume, and
   `image/` is symlinked onto it so Apache can still serve product images.
   OpenCart's bundled `vendor/` tree is refreshed from the image on every boot,
   because it belongs to the release rather than to the store's data.
3. Provisions the database. Using the administrative connection it creates
   OpenCart's own database and a role holding only the privileges the application
   needs, then proves the grant by connecting with it.
4. Installs once, through OpenCart's own `install/cli_install.php`, and seeds the
   store name and SMTP transport, which OpenCart keeps in the database rather than
   in configuration. Guarded by a marker on the volume, so nothing an operator
   later changes in the admin is reverted. The installer directory is deleted
   afterwards.
5. Renders `config.php` and `admin/config.php` from the environment, on every
   boot, so a changed domain or a rotated database password takes effect on the
   next deploy instead of silently going stale.

## Services

| Role | `OPENCART_ROLE` | Notes |
|---|---|---|
| Storefront and admin | `web` (default) | public domain, volume at `/data` |
| Scheduled tasks | `cron` | runs `cron.php` on an interval; no volume, no public domain |

The `cron` role never installs anything and never writes to the store's volume. It
waits for the web tier to create the schema, then runs OpenCart's currency, GDPR
and subscription tasks on a loop.

## Environment variables

Everything has a working default except the first administrator's password.

| Variable | Default | Purpose |
|---|---|---|
| `MYSQL_ADMIN_URL` | — | administrative MySQL URL, e.g. `${{MySQL.MYSQL_URL}}` |
| `OPENCART_ADMIN_PASSWORD` | — | first administrator's password, 5–20 characters |
| `PORT` | `8080` | port Apache listens on |
| `REDIS_URL` | unset | when set, OpenCart's cache engine becomes Redis |
| `OPENCART_ROLE` | `web` | `web` or `cron` |
| `OPENCART_ADMIN_USERNAME` | `admin` | first administrator, 3–20 characters |
| `OPENCART_ADMIN_EMAIL` | `admin@example.com` | first administrator's address, also the store contact |
| `OPENCART_ADMIN_DIRECTORY` | `admin` | rename the admin panel's URL segment |
| `OPENCART_STORE_NAME` | unset | seeded as the store name and page title at install |
| `OPENCART_DB_NAME` | `opencart` | database the store is created in |
| `OPENCART_DB_USER` | `opencart` | least-privilege role the store connects as |
| `OPENCART_DB_PASSWORD` | generated | that role's password; must match across roles |
| `OPENCART_DB_PREFIX` | `oc_` | table prefix |
| `OPENCART_LANGUAGE` | `en-gb` | install language |
| `OPENCART_PUBLIC_URL` | from `RAILWAY_PUBLIC_DOMAIN` | override for a custom domain |
| `OPENCART_SMTP_HOST` | unset | mail relay; when set, the store is switched to SMTP |
| `OPENCART_SMTP_PORT` | `1025` | mail relay port |
| `OPENCART_CRON_INTERVAL` | `3600` | seconds between cron cycles |
| `OPENCART_DATA_DIR` | `/data` | volume mount path |
| `APACHE_MAX_REQUEST_WORKERS` | from the cgroup | override Apache's worker count |

## Health

`GET /healthz` opens the store's own database connection, counts the settings
table and checks that the storage directory is writable. It is anonymous and its
path contains no dots, which is what Railway's health check accepts.

## Version

`OPENCART_VERSION` is a build argument, pinned because OpenCart owns its database
schema and has no automatic migrator: a floating version would run new code
against an old schema after a redeploy. Raising it is a deliberate upgrade, and
OpenCart's own `UPGRADE.md` applies.

## Licence

OpenCart is GPL-3.0. This packaging is provided as-is.
