<?php
// Blocks until the web tier's installer has created OpenCart's tables. Railway
// has no service ordering, so on a fresh project the cron worker starts before
// the store exists; without this it would run against an empty database and log
// a stack trace every cycle.

declare(strict_types=1);

function oc_log(string $message): void {
    fwrite(STDERR, '[opencart][wait] ' . $message . PHP_EOL);
}

function oc_env(string $key, string $default = ''): string {
    $value = getenv($key);

    return ($value === false || $value === '') ? $default : $value;
}

$db_file = oc_env('OPENCART_DB_ENV_FILE', '/tmp/opencart-db.json');
$db = json_decode((string)@file_get_contents($db_file), true);

if (!is_array($db)) {
    oc_log('could not read database details from ' . $db_file);
    exit(1);
}

$prefix = oc_env('OPENCART_DB_PREFIX', 'oc_');
$attempts = (int)oc_env('OPENCART_WAIT_ATTEMPTS', '240');

mysqli_report(MYSQLI_REPORT_OFF);

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $link = @mysqli_connect($db['hostname'], $db['username'], $db['password'], $db['database'], (int)$db['port']);

    if ($link) {
        $result = @mysqli_query($link, 'SELECT COUNT(*) AS `n` FROM `' . mysqli_real_escape_string($link, $prefix) . 'setting`');

        if ($result && ($row = mysqli_fetch_assoc($result)) && (int)$row['n'] > 0) {
            mysqli_close($link);
            oc_log('store schema is present');
            exit(0);
        }

        mysqli_close($link);
    }

    if ($attempt === 1 || $attempt % 12 === 0) {
        oc_log('waiting for the web tier to install the store');
    }

    sleep(10);
}

oc_log('gave up waiting for the store schema');
exit(1);
