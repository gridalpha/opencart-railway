<?php
// Renders both of OpenCart's config.php files on every boot. The stock installer
// writes them once, with the hostname and paths it was given; regenerating them
// here means a changed public domain, a new database password or a moved data
// directory all take effect on the next deploy instead of silently going stale.

declare(strict_types=1);

function oc_log(string $message): void {
    fwrite(STDERR, '[opencart][config] ' . $message . PHP_EOL);
}

function oc_env(string $key, string $default = ''): string {
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

$db_file = oc_env('OPENCART_DB_ENV_FILE', '/tmp/opencart-db.json');
$db = json_decode((string)@file_get_contents($db_file), true);

if (!is_array($db) || !isset($db['hostname'], $db['username'], $db['password'], $db['database'], $db['port'])) {
    oc_log('could not read database details from ' . $db_file);
    exit(1);
}

$web_root  = rtrim(oc_env('OPENCART_WEB_ROOT', '/var/www/html'), '/') . '/';
$data_dir  = rtrim(oc_env('OPENCART_DATA_DIR', '/data'), '/');
$admin_dir = trim(oc_env('OPENCART_ADMIN_DIRECTORY', 'admin'), '/');
$prefix    = oc_env('OPENCART_DB_PREFIX', 'oc_');

if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $admin_dir)) {
    oc_log('OPENCART_ADMIN_DIRECTORY must be 1-32 characters of [A-Za-z0-9_-]');
    exit(1);
}

$public_url = oc_env('OPENCART_PUBLIC_URL');

if ($public_url === '') {
    $domain = oc_env('RAILWAY_PUBLIC_DOMAIN');

    if ($domain === '') {
        oc_log('no public URL: set OPENCART_PUBLIC_URL, or generate a Railway domain for this service');
        exit(1);
    }

    $public_url = 'https://' . $domain;
}

$public_url = rtrim($public_url, '/') . '/';

// Cache engine. OpenCart reads the CACHE_ENGINE constant out of config.php;
// with no Redis reference it falls back to the file cache on the volume.
$cache_engine = oc_env('OPENCART_CACHE_ENGINE');
$cache = ['hostname' => '', 'port' => '6379', 'password' => '', 'prefix' => oc_env('OPENCART_CACHE_PREFIX', 'oc_cache')];
$redis_url = oc_env('REDIS_URL');

if ($redis_url !== '') {
    $parts = parse_url($redis_url);

    if (is_array($parts) && isset($parts['host'])) {
        $cache['hostname'] = $parts['host'];
        $cache['port']     = isset($parts['port']) ? (string)$parts['port'] : '6379';
        $cache['password'] = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
    } else {
        oc_log('REDIS_URL could not be parsed; falling back to the file cache');
    }
}

if ($cache_engine === '') {
    $cache_engine = $cache['hostname'] !== '' ? 'redis' : 'file';
}

if ($cache_engine === 'redis' && $cache['hostname'] === '') {
    oc_log('OPENCART_CACHE_ENGINE=redis but no usable REDIS_URL; falling back to the file cache');
    $cache_engine = 'file';
}

function oc_define(string $name, string $value): string {
    return "define('" . $name . "', '" . addcslashes($value, "\\'") . "');" . PHP_EOL;
}

function oc_raw(string $name, string $expression): string {
    return "define('" . $name . "', " . $expression . ");" . PHP_EOL;
}

/**
 * @param array<string, string> $db
 * @param array<string, string> $cache
 */
function oc_render(string $application, string $http_server, string $http_catalog, string $application_dir, string $web_root, string $data_dir, array $db, array $cache, string $cache_engine, string $prefix): string {
    $out  = '<?php' . PHP_EOL;
    $out .= '// Generated on every boot by docker/bin/configure.php. Edits are not kept.' . PHP_EOL . PHP_EOL;

    $out .= '// APPLICATION' . PHP_EOL;
    $out .= oc_define('APPLICATION', $application) . PHP_EOL;

    $out .= '// HTTP' . PHP_EOL;
    $out .= oc_define('HTTP_SERVER', $http_server);

    if ($http_catalog !== '') {
        $out .= oc_define('HTTP_CATALOG', $http_catalog);
    }

    $out .= PHP_EOL . '// DIR' . PHP_EOL;
    $out .= oc_define('DIR_OPENCART', $web_root);
    $out .= oc_define('DIR_APPLICATION', $web_root . $application_dir);
    $out .= oc_raw('DIR_SYSTEM', "DIR_OPENCART . 'system/'");
    $out .= oc_raw('DIR_EXTENSION', "DIR_OPENCART . 'extension/'");

    if ($application === 'Admin') {
        $out .= oc_raw('DIR_CATALOG', "DIR_OPENCART . 'catalog/'");
    }

    // Both of these live on the Railway volume: image/ is a symlink into it so
    // Apache can still serve the files, storage/ is addressed directly.
    $out .= oc_define('DIR_IMAGE', $data_dir . '/image/');
    $out .= oc_define('DIR_STORAGE', $data_dir . '/storage/');
    $out .= oc_raw('DIR_LANGUAGE', "DIR_APPLICATION . 'language/'");
    $out .= oc_raw('DIR_TEMPLATE', "DIR_APPLICATION . 'view/template/'");
    $out .= oc_raw('DIR_CONFIG', "DIR_SYSTEM . 'config/'");
    $out .= oc_raw('DIR_CACHE', "DIR_STORAGE . 'cache/'");
    $out .= oc_raw('DIR_DOWNLOAD', "DIR_STORAGE . 'download/'");
    $out .= oc_raw('DIR_LOGS', "DIR_STORAGE . 'logs/'");
    $out .= oc_raw('DIR_SESSION', "DIR_STORAGE . 'session/'");
    $out .= oc_raw('DIR_UPLOAD', "DIR_STORAGE . 'upload/'");

    $out .= PHP_EOL . '// DB' . PHP_EOL;
    $out .= oc_define('DB_DRIVER', 'mysqli');
    $out .= oc_define('DB_HOSTNAME', $db['hostname']);
    $out .= oc_define('DB_USERNAME', $db['username']);
    $out .= oc_define('DB_PASSWORD', $db['password']);
    $out .= oc_define('DB_DATABASE', $db['database']);
    $out .= oc_define('DB_PREFIX', $prefix);
    $out .= oc_define('DB_PORT', (string)$db['port']);

    $out .= PHP_EOL . '// Cache' . PHP_EOL;
    $out .= oc_define('CACHE_ENGINE', $cache_engine);

    if ($cache_engine === 'redis') {
        $out .= oc_define('CACHE_HOSTNAME', $cache['hostname']);
        $out .= oc_raw('CACHE_PORT', (string)(int)$cache['port']);
        $out .= oc_define('CACHE_PREFIX', $cache['prefix']);

        if ($cache['password'] !== '') {
            $out .= oc_define('CACHE_PASSWORD', $cache['password']);
        }
    }

    if ($application === 'Admin') {
        $out .= PHP_EOL . '// OpenCart API' . PHP_EOL;
        $out .= oc_define('OPENCART_SERVER', 'https://www.opencart.com/');
    }

    return $out;
}

$catalog_config = $web_root . 'config.php';
$admin_config   = $web_root . $admin_dir . '/config.php';

$written = [
    $catalog_config => oc_render('Catalog', $public_url, '', 'catalog/', $web_root, $data_dir, $db, $cache, $cache_engine, $prefix),
    $admin_config   => oc_render('Admin', $public_url . $admin_dir . '/', $public_url, $admin_dir . '/', $web_root, $data_dir, $db, $cache, $cache_engine, $prefix),
];

foreach ($written as $path => $contents) {
    if (@file_put_contents($path, $contents) === false) {
        oc_log('could not write ' . $path);
        exit(1);
    }

    // Apache's children run as www-data and must be able to read this; nothing
    // else should be able to.
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    @chmod($path, 0440);

    // A config file that does not parse crash-loops the container with nothing
    // useful in the log, so fail here instead.
    $check = [];
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $check, $status);

    if ($status !== 0) {
        oc_log('generated config did not parse: ' . implode(' ', $check));
        exit(1);
    }
}

oc_log('wrote config for ' . $public_url . ' (admin directory: ' . $admin_dir . ', cache: ' . $cache_engine . ')');
