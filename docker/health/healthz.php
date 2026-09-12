<?php
// Health probe for Railway. Anonymous, dot-free path, and a real dependency
// check: it opens the same MySQL connection the storefront uses and confirms the
// storage directory on the volume is writable. Railway's prober sends no
// credentials, so nothing here may require a session.

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$config = '/var/www/html/config.php';

if (!is_file($config) || filesize($config) === 0) {
    http_response_code(503);
    echo "not installed\n";
    exit;
}

require_once($config);

foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PORT', 'DIR_STORAGE'] as $constant) {
    if (!defined($constant)) {
        http_response_code(503);
        echo "config incomplete: {$constant}\n";
        exit;
    }
}

mysqli_report(MYSQLI_REPORT_OFF);

$link = @mysqli_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);

if (!$link) {
    http_response_code(503);
    echo 'database unreachable: ' . mysqli_connect_error() . "\n";
    exit;
}

$result = @mysqli_query($link, 'SELECT COUNT(*) AS `n` FROM `' . DB_PREFIX . 'setting`');

if (!$result) {
    http_response_code(503);
    echo 'database query failed: ' . mysqli_error($link) . "\n";
    mysqli_close($link);
    exit;
}

$row = mysqli_fetch_assoc($result);
mysqli_close($link);

if (!is_writable(DIR_STORAGE)) {
    http_response_code(503);
    echo 'storage not writable: ' . DIR_STORAGE . "\n";
    exit;
}

echo "ok settings=" . (int)$row['n'] . "\n";
