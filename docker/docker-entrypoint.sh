#!/bin/bash
# OpenCart on Railway. Prepares the volume, provisions the database, runs
# OpenCart's own headless installer once, renders both config files, and hands
# over to Apache.
set -euo pipefail

log() { printf '[opencart] %s\n' "$*"; }

DATA_DIR="${OPENCART_DATA_DIR:-/data}"
WEB_ROOT="${OPENCART_WEB_ROOT:-/var/www/html}"
SEED_DIR=/opt/opencart-seed
ADMIN_DIR="${OPENCART_ADMIN_DIRECTORY:-admin}"
MARKER="${DATA_DIR}/.opencart-installed"
ROLE="${OPENCART_ROLE:-web}"

case "$ROLE" in
	web|cron) : ;;
	*) log "ERROR: OPENCART_ROLE must be 'web' or 'cron' (got '${ROLE}')."; exit 1 ;;
esac

export OPENCART_DATA_DIR OPENCART_WEB_ROOT
export OPENCART_DB_ENV_FILE="${OPENCART_DB_ENV_FILE:-/tmp/opencart-db.json}"

# ---------------------------------------------------------------------------
# Apache
# ---------------------------------------------------------------------------

if [ "$ROLE" = web ]; then

# php:*-apache ships mod_php, which needs the prefork MPM, but recent builds
# leave mpm_event enabled beside it and the container dies on
# "Configuration error: More than one MPM loaded". A build layer cannot fix it —
# the removed file comes back in the running container — so it is done here.
a2dismod mpm_event mpm_worker >/dev/null 2>&1 || true
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
a2enmod mpm_prefork >/dev/null 2>&1 || true

PORT="${PORT:-8080}"
sed -ri "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/__PORT__/${PORT}/; s#__DATA_DIR__#${DATA_DIR}#" /etc/apache2/sites-available/000-default.conf

# Apache's prefork default of 150 mod_php children would ask for far more memory
# than the container has. Size it from the cgroup so a resize retunes.
if [ -z "${APACHE_MAX_REQUEST_WORKERS:-}" ]; then
	mem_bytes=$(cat /sys/fs/cgroup/memory.max 2>/dev/null || echo max)
	case "$mem_bytes" in
		''|max|*[!0-9]*) APACHE_MAX_REQUEST_WORKERS=24 ;;
		*) APACHE_MAX_REQUEST_WORKERS=$(( mem_bytes / 1048576 / 128 )) ;;
	esac
	[ "$APACHE_MAX_REQUEST_WORKERS" -lt 8 ] && APACHE_MAX_REQUEST_WORKERS=8
	[ "$APACHE_MAX_REQUEST_WORKERS" -gt 64 ] && APACHE_MAX_REQUEST_WORKERS=64
fi

cat > /etc/apache2/mods-available/mpm_prefork.conf <<APACHECONF
<IfModule mpm_prefork_module>
	StartServers             2
	MinSpareServers          2
	MaxSpareServers          6
	MaxRequestWorkers        ${APACHE_MAX_REQUEST_WORKERS}
	MaxConnectionsPerChild   2000
</IfModule>
APACHECONF

log "apache: port ${PORT}, MaxRequestWorkers ${APACHE_MAX_REQUEST_WORKERS}"

fi

# ---------------------------------------------------------------------------
# Volume layout
# ---------------------------------------------------------------------------

mkdir -p "${DATA_DIR}/storage" "${DATA_DIR}/image"

for d in backup cache download logs marketplace session upload; do
	if [ ! -d "${DATA_DIR}/storage/${d}" ]; then
		cp -a "${SEED_DIR}/storage/${d}" "${DATA_DIR}/storage/${d}"
	fi
done

# vendor/ is code, not data: it belongs to whichever OpenCart release the image
# holds, so it is replaced rather than seeded.
rm -rf "${DATA_DIR}/storage/vendor"
cp -a "${SEED_DIR}/storage/vendor" "${DATA_DIR}/storage/vendor"

# Shipped placeholders and the demo catalogue, added but never overwritten.
cp -an "${SEED_DIR}/image/." "${DATA_DIR}/image/" 2>/dev/null || true
mkdir -p "${DATA_DIR}/image/cache" "${DATA_DIR}/image/catalog"

# Railway restores files a build layer deleted, so system/storage/ and image/ are
# back inside the document root at runtime even though the Dockerfile removed
# them. The stale storage tree also makes OpenCart's admin raise its "delete the
# previous storage directory" security warning on every dashboard load.
rm -rf "${WEB_ROOT}/system/storage"

# Railway volumes are 1:1, and OpenCart needs two writable trees. image/ has to
# stay under the document root because the browser fetches thumbnails from it,
# so it is a symlink; storage/ is addressed directly through DIR_STORAGE.
rm -rf "${WEB_ROOT}/image"
ln -s "${DATA_DIR}/image" "${WEB_ROOT}/image"

chown -R www-data:www-data "${DATA_DIR}"

log "volume ready at ${DATA_DIR}"

# ---------------------------------------------------------------------------
# Database
# ---------------------------------------------------------------------------

php /opt/opencart/bin/bootstrap-db.php

DB_HOSTNAME=$(php /opt/opencart/bin/dbval.php hostname)
DB_PORT=$(php /opt/opencart/bin/dbval.php port)
DB_USERNAME=$(php /opt/opencart/bin/dbval.php username)
DB_PASSWORD=$(php /opt/opencart/bin/dbval.php password)
DB_DATABASE=$(php /opt/opencart/bin/dbval.php database)

# ---------------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------------

if [ "$ROLE" = cron ]; then
	# The cron worker never installs anything: the installer drops every OpenCart
	# table before recreating it, and this role has no volume to remember that it
	# already ran.
	rm -rf "${WEB_ROOT}/install"

	php /opt/opencart/bin/wait-for-schema.php

	if [ "$ADMIN_DIR" != "admin" ] && [ -d "${WEB_ROOT}/admin" ]; then
		rm -rf "${WEB_ROOT:?}/${ADMIN_DIR}"
		mv "${WEB_ROOT}/admin" "${WEB_ROOT}/${ADMIN_DIR}"
	fi

	php /opt/opencart/bin/configure.php

	INTERVAL="${OPENCART_CRON_INTERVAL:-3600}"
	log "cron worker running OpenCart's scheduled tasks every ${INTERVAL}s"

	while true; do
		if ! php "${WEB_ROOT}/cron.php"; then
			# OpenCart writes cron failures to its own log rather than stdout,
			# so surface them here or the worker looks idle rather than broken.
			log "cron cycle failed; last lines of OpenCart's error log:"
			tail -n 20 "${DATA_DIR}/storage/logs/error.log" 2>/dev/null || true
		fi
		sleep "$INTERVAL"
	done
fi

if [ ! -f "$MARKER" ]; then
	ADMIN_USERNAME="${OPENCART_ADMIN_USERNAME:-admin}"
	ADMIN_PASSWORD="${OPENCART_ADMIN_PASSWORD:-}"
	ADMIN_EMAIL="${OPENCART_ADMIN_EMAIL:-admin@example.com}"

	if [ -z "$ADMIN_PASSWORD" ]; then
		log "ERROR: OPENCART_ADMIN_PASSWORD is not set and the store is not installed yet."
		exit 1
	fi

	# OpenCart's installer rejects these itself, but only after it has already
	# started dropping tables, so check first.
	if [ "${#ADMIN_USERNAME}" -lt 3 ] || [ "${#ADMIN_USERNAME}" -gt 20 ]; then
		log "ERROR: OPENCART_ADMIN_USERNAME must be 3-20 characters."
		exit 1
	fi

	if [ "${#ADMIN_PASSWORD}" -lt 5 ] || [ "${#ADMIN_PASSWORD}" -gt 20 ]; then
		log "ERROR: OPENCART_ADMIN_PASSWORD must be 5-20 characters."
		exit 1
	fi

	if [ ! -d "${WEB_ROOT}/install" ]; then
		log "ERROR: the installer is missing from the image and the store is not installed."
		exit 1
	fi

	PUBLIC_URL="${OPENCART_PUBLIC_URL:-}"

	if [ -z "$PUBLIC_URL" ]; then
		if [ -z "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
			log "ERROR: no public URL. Generate a Railway domain for this service, or set OPENCART_PUBLIC_URL."
			exit 1
		fi
		PUBLIC_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
	fi

	PUBLIC_URL="${PUBLIC_URL%/}/"

	# The installer refuses to run unless both config files exist and are writable.
	: > "${WEB_ROOT}/config.php"
	: > "${WEB_ROOT}/admin/config.php"
	chmod 666 "${WEB_ROOT}/config.php" "${WEB_ROOT}/admin/config.php"

	log "installing OpenCart ${OPENCART_VERSION:-} at ${PUBLIC_URL}"

	install_output=$(php "${WEB_ROOT}/install/cli_install.php" install \
		--username "$ADMIN_USERNAME" \
		--password "$ADMIN_PASSWORD" \
		--email "$ADMIN_EMAIL" \
		--http_server "$PUBLIC_URL" \
		--language "${OPENCART_LANGUAGE:-en-gb}" \
		--db_driver mysqli \
		--db_hostname "$DB_HOSTNAME" \
		--db_port "$DB_PORT" \
		--db_username "$DB_USERNAME" \
		--db_password "$DB_PASSWORD" \
		--db_database "$DB_DATABASE" \
		--db_prefix "${OPENCART_DB_PREFIX:-oc_}" 2>&1) || true

	printf '%s\n' "$install_output"

	# The installer exits 0 whatever happens, so its own success line is the gate.
	case "$install_output" in
		*"SUCCESS! OpenCart successfully installed"*) : ;;
		*)
			log "ERROR: installation failed."
			exit 1
			;;
	esac

	php /opt/opencart/bin/seed-settings.php install

	touch "$MARKER"
	chown www-data:www-data "$MARKER"
	log "installation complete"
else
	log "already installed; skipping the installer"
fi

# Mail transport lives in the database, so a changed SMTP variable would
# otherwise never reach the store. Re-seed only when the inputs actually change,
# which leaves an operator's own Settings > Mail edit alone.
MAIL_MARKER="${DATA_DIR}/.opencart-mail-seed"
MAIL_FINGERPRINT=$(printf '%s|%s|%s|%s|%s' \
	"${OPENCART_SMTP_HOST:-}" "${OPENCART_SMTP_HOST_DEFAULT:-}" \
	"${OPENCART_SMTP_PORT:-}" "${OPENCART_SMTP_USERNAME:-}" \
	"${OPENCART_SMTP_PASSWORD:-}" | sha256sum | cut -d" " -f1)

if [ "$(cat "$MAIL_MARKER" 2>/dev/null || true)" != "$MAIL_FINGERPRINT" ]; then
	php /opt/opencart/bin/seed-settings.php mail
	printf '%s\n' "$MAIL_FINGERPRINT" > "$MAIL_MARKER"
	chown www-data:www-data "$MAIL_MARKER"
fi

# The installer is single-use and destructive — it drops every OpenCart table
# before recreating it — so it never survives into a serving container.
rm -rf "${WEB_ROOT}/install"

# ---------------------------------------------------------------------------
# Admin directory and configuration
# ---------------------------------------------------------------------------

if [ "$ADMIN_DIR" != "admin" ]; then
	if [ -d "${WEB_ROOT}/admin" ]; then
		rm -rf "${WEB_ROOT:?}/${ADMIN_DIR}"
		mv "${WEB_ROOT}/admin" "${WEB_ROOT}/${ADMIN_DIR}"
	fi
	log "admin panel moved to /${ADMIN_DIR}/"
fi

php /opt/opencart/bin/configure.php

apache2ctl -t

log "starting: $*"
exec "$@"
