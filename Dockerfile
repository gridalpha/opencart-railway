FROM php:8.3-apache

# Pinned: OpenCart owns its own database schema and has no automatic migrator, so a
# floating version would silently run new code against an old schema on redeploy.
ARG OPENCART_VERSION=4.1.0.4
ENV OPENCART_VERSION=${OPENCART_VERSION}

# PHP extensions OpenCart's own pre-install check requires (mysqli, gd, curl,
# zlib, openssl) plus zip for the marketplace installer, redis for the cache
# engine and opcache for production throughput.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        unzip \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd; \
    docker-php-ext-install -j"$(nproc)" zip; \
    docker-php-ext-install -j"$(nproc)" mysqli; \
    docker-php-ext-install -j"$(nproc)" opcache; \
    yes '' | pecl install redis; \
    docker-php-ext-enable redis; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# OpenCart ships as a release zip, not a container image. `upload/` is the web root.
RUN set -eux; \
    curl -fsSL -o /tmp/opencart.zip \
        "https://github.com/opencart/opencart/releases/download/${OPENCART_VERSION}/opencart-${OPENCART_VERSION}.zip"; \
    mkdir -p /tmp/opencart; \
    unzip -q /tmp/opencart.zip -d /tmp/opencart; \
    rm -rf /var/www/html; \
    mkdir -p /var/www/html; \
    cp -a /tmp/opencart/upload/. /var/www/html/; \
    rm -rf /tmp/opencart.zip /tmp/opencart; \
    test -f /var/www/html/index.php; \
    test -f /var/www/html/system/storage/vendor/autoload.php; \
    mv /var/www/html/.htaccess.txt /var/www/html/.htaccess

# Both storage/ and image/ hold operator data and both must live on the single
# Railway volume, so keep a pristine copy of each outside the mount to seed from.
# storage/ leaves the web root entirely, which is what upstream recommends anyway.
RUN set -eux; \
    mkdir -p /opt/opencart-seed; \
    cp -a /var/www/html/system/storage /opt/opencart-seed/storage; \
    cp -a /var/www/html/image /opt/opencart-seed/image; \
    rm -rf /var/www/html/system/storage /var/www/html/image; \
    test -f /opt/opencart-seed/storage/vendor/autoload.php; \
    test -f /opt/opencart-seed/image/no_image.png

# Production defaults: OpenCart ships with full error display turned on and says
# so in INSTALL.md under "Going live".
RUN set -eux; \
    sed -i '/error_display/ s/true/false/' \
        /var/www/html/system/config/default.php \
        /var/www/html/system/config/admin.php; \
    ! grep -qE "error_display'\][[:space:]]*=[[:space:]]*true" \
        /var/www/html/system/config/default.php \
        /var/www/html/system/config/admin.php

# cron.php never loads the Composer autoloader that framework.php loads, so every
# scheduled task dies on `Class "Twig\Loader\FilesystemLoader" not found`. The
# grep is the control: if upstream fixes this or moves the line, the build fails
# here rather than the cron tier failing silently in production.
RUN set -eux; \
    grep -q "^require_once(DIR_SYSTEM . 'startup.php');$" /var/www/html/cron.php; \
    sed -i "s|^require_once(DIR_SYSTEM . 'startup.php');$|&\n\n// Added for Railway: cron.php omits the vendor autoloader framework.php loads.\nif (is_file(DIR_STORAGE . 'vendor/autoload.php')) {\n\trequire_once(DIR_STORAGE . 'vendor/autoload.php');\n}|" /var/www/html/cron.php; \
    grep -q "vendor/autoload.php" /var/www/html/cron.php; \
    php -l /var/www/html/cron.php

COPY docker/php/zz-opencart.ini /usr/local/etc/php/conf.d/zz-opencart.ini
COPY docker/apache/opencart.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/remoteip.conf /etc/apache2/conf-available/zz-remoteip.conf
COPY docker/health/ /var/www/health/
COPY docker/bin/ /opt/opencart/bin/
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN set -eux; \
    a2enmod rewrite headers remoteip expires; \
    a2enconf zz-remoteip; \
    chmod +x /usr/local/bin/docker-entrypoint.sh; \
    bash -n /usr/local/bin/docker-entrypoint.sh; \
    for f in /opt/opencart/bin/*.php /var/www/health/*.php; do php -l "$f"; done; \
    php -r 'foreach (["mysqli","gd","zip","curl","redis","Zend OPcache","openssl","zlib"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing PHP extension: $e\n"); exit(1); } }'

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
