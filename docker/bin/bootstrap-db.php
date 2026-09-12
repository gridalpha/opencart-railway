<?php
// Waits for MySQL, then provisions OpenCart's own database and a least-privilege
// role for it. Railway's managed MySQL hands out the superuser and OpenCart's own
// INSTALL.md says not to run the store as it, so the app gets a scoped account
// instead. Every statement is idempotent, and ALTER USER keeps the role in step
// when the password file is regenerated on a fresh volume.

declare(strict_types=1);

function oc_log(string $message): void {
    fwrite(STDERR, '[opencart][db] ' . $message . PHP_EOL);
}

function oc_env(string $key, string $default = ''): string {
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

$admin_url = oc_env('MYSQL_ADMIN_URL');

if ($admin_url === '') {
    oc_log('MYSQL_ADMIN_URL is not set. Reference the MySQL service, e.g. MYSQL_ADMIN_URL=${{MySQL.MYSQL_URL}}');
    exit(1);
}

$parts = parse_url($admin_url);

if (!is_array($parts) || !isset($parts['host'])) {
    oc_log('MYSQL_ADMIN_URL could not be parsed as a URL');
    exit(1);
}

$admin = [
    'host'     => $parts['host'],
    'port'     => isset($parts['port']) ? (int)$parts['port'] : 3306,
    'user'     => isset($parts['user']) ? rawurldecode($parts['user']) : 'root',
    'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : '',
    'database' => isset($parts['path']) ? trim(rawurldecode($parts['path']), '/') : '',
];

$app_database = oc_env('OPENCART_DB_NAME', 'opencart');
$app_user     = oc_env('OPENCART_DB_USER', 'opencart');
$password_file = oc_env('OPENCART_DB_PASSWORD_FILE', '/data/.opencart-db-password');
$env_file      = oc_env('OPENCART_DB_ENV_FILE', '/tmp/opencart-db.json');

if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $app_database) || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $app_user)) {
    oc_log('OPENCART_DB_NAME and OPENCART_DB_USER must be 1-32 characters of [A-Za-z0-9_]');
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

// There is no service ordering on Railway, so the database may still be starting.
$link = null;

for ($attempt = 1; $attempt <= 60; $attempt++) {
    $link = @mysqli_connect($admin['host'], $admin['user'], $admin['password'], '', $admin['port']);

    if ($link) {
        break;
    }

    if ($attempt === 1 || $attempt % 10 === 0) {
        oc_log('waiting for MySQL at ' . $admin['host'] . ':' . $admin['port'] . ' (' . mysqli_connect_error() . ')');
    }

    sleep(5);
}

if (!$link) {
    oc_log('gave up waiting for MySQL');
    exit(1);
}

oc_log('connected to MySQL as ' . $admin['user']);

$use_scoped_role = true;

// A password the operator never has to supply. Preferred as a variable, because
// every role that talks to the database needs the same one and only the web tier
// owns a volume; the volume file is the fall-back for a single-service deploy.
$password = oc_env('OPENCART_DB_PASSWORD');

if ($password === '' && is_file($password_file)) {
    $password = trim((string)file_get_contents($password_file));
}

if ($password === '') {
    // Fixed prefix so the value satisfies a MEDIUM validate_password policy
    // (upper, lower, digit, symbol) if the server happens to have one enabled.
    $password = 'Oc1-' . substr(bin2hex(random_bytes(32)), 0, 28);

    if (@file_put_contents($password_file, $password . PHP_EOL) === false) {
        oc_log('could not write ' . $password_file . '; falling back to the administrative account');
        $use_scoped_role = false;
    } else {
        @chmod($password_file, 0600);
    }
}

if ($use_scoped_role) {
    $quoted_password = "'" . mysqli_real_escape_string($link, $password) . "'";

    $statements = [
        "CREATE DATABASE IF NOT EXISTS `{$app_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE USER IF NOT EXISTS '{$app_user}'@'%' IDENTIFIED BY {$quoted_password}",
        "ALTER USER '{$app_user}'@'%' IDENTIFIED BY {$quoted_password}",
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES ON `{$app_database}`.* TO '{$app_user}'@'%'",
        "FLUSH PRIVILEGES",
    ];

    foreach ($statements as $sql) {
        if (!@mysqli_query($link, $sql)) {
            oc_log('could not provision the scoped role (' . mysqli_error($link) . '); falling back to the administrative account');
            $use_scoped_role = false;
            break;
        }
    }
}

if ($use_scoped_role) {
    // Prove the grant rather than trusting it.
    $probe = @mysqli_connect($admin['host'], $app_user, $password, $app_database, $admin['port']);

    if (!$probe) {
        oc_log('the scoped role could not connect (' . mysqli_connect_error() . '); falling back to the administrative account');
        $use_scoped_role = false;
    } else {
        mysqli_close($probe);
        oc_log("using scoped role {$app_user}@% on database {$app_database}");
    }
}

if (!$use_scoped_role) {
    $app_user = $admin['user'];
    $password = $admin['password'];
    $app_database = $admin['database'] !== '' ? $admin['database'] : $app_database;

    if (!@mysqli_query($link, "CREATE DATABASE IF NOT EXISTS `{$app_database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        oc_log('could not create database ' . $app_database . ': ' . mysqli_error($link));
        exit(1);
    }

    oc_log('using the administrative account on database ' . $app_database);
}

mysqli_close($link);

// Written as JSON, not shell assignments: the fall-back administrative password
// is Railway's and can hold any character, which a sourced file would mangle.
$payload = json_encode([
    'hostname' => $admin['host'],
    'port'     => (string)$admin['port'],
    'username' => $app_user,
    'password' => $password,
    'database' => $app_database,
], JSON_UNESCAPED_SLASHES);

if (@file_put_contents($env_file, $payload . PHP_EOL) === false) {
    oc_log('could not write ' . $env_file);
    exit(1);
}

@chmod($env_file, 0600);

oc_log('database ready');
