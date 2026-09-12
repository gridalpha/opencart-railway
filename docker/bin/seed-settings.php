<?php
// OpenCart keeps its store name and mail transport in the database rather than in
// configuration, so a fresh install would otherwise come up called "Your Store"
// and try to post mail through PHP's mail() — which no container has an MTA for,
// so every order confirmation and password reset would be silently dropped.
//
// Two modes, because the two concerns want different guards:
//   install  everything, once, behind the install marker
//   mail     the mail transport only, re-run by the entrypoint when the SMTP
//            variables change — so an operator who edits Settings > Mail in the
//            admin keeps their change until they change the variables instead.

declare(strict_types=1);

function oc_log(string $message): void {
    fwrite(STDERR, '[opencart][seed] ' . $message . PHP_EOL);
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
$mode = $argv[1] ?? 'install';

if (!in_array($mode, ['install', 'mail'], true)) {
    oc_log('unknown mode: ' . $mode);
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

$link = @mysqli_connect($db['hostname'], $db['username'], $db['password'], $db['database'], (int)$db['port']);

if (!$link) {
    oc_log('could not connect: ' . mysqli_connect_error());
    exit(1);
}

mysqli_set_charset($link, 'utf8mb4');

// key => [value, serialized]
$settings = [];

$store_name = $mode === 'install' ? oc_env('OPENCART_STORE_NAME') : '';

if ($store_name !== '') {
    $settings['config_name'] = [$store_name, 0];
    $settings['config_meta_title'] = [$store_name, 0];
    $settings['config_owner'] = [$store_name, 0];

    // The storefront's <title> comes from this per-language structure, not from
    // config_meta_title, so a store renamed without it still says "Your Store".
    $language_id = 1;
    $language_query = @mysqli_query($link, "SELECT `value` FROM `" . mysqli_real_escape_string($link, $prefix) . "setting` WHERE `store_id` = '0' AND `key` = 'config_language_id'");

    if ($language_query && ($row = mysqli_fetch_assoc($language_query))) {
        $language_id = (int)$row['value'];
    }

    $settings['config_description'] = [
        (string)json_encode([(string)$language_id => ['meta_title' => $store_name, 'meta_description' => '', 'meta_keyword' => '']]),
        1,
    ];
}

$smtp_host = oc_env('OPENCART_SMTP_HOST');

// A `${{service.RAILWAY_PRIVATE_DOMAIN}}` reference renders empty until that
// service owns a deployment, and on a template deploy everything starts at once.
if ($smtp_host === '' || $smtp_host[0] === ':') {
    $smtp_host = oc_env('OPENCART_SMTP_HOST_DEFAULT');
}

if ($smtp_host !== '') {
    $settings['config_mail_engine'] = ['smtp', 0];
    $settings['config_mail_smtp_hostname'] = [$smtp_host, 0];
    $settings['config_mail_smtp_port'] = [oc_env('OPENCART_SMTP_PORT', '1025'), 0];
    $settings['config_mail_smtp_username'] = [oc_env('OPENCART_SMTP_USERNAME', ''), 0];
    $settings['config_mail_smtp_password'] = [oc_env('OPENCART_SMTP_PASSWORD', ''), 0];
    $settings['config_mail_smtp_timeout'] = [oc_env('OPENCART_SMTP_TIMEOUT', '5'), 0];
}

if (!$settings) {
    oc_log('nothing to seed');
    mysqli_close($link);
    exit(0);
}

$table = mysqli_real_escape_string($link, $prefix) . 'setting';

foreach ($settings as $key => $pair) {
    [$value, $serialized] = $pair;

    $key_sql = mysqli_real_escape_string($link, $key);
    $value_sql = mysqli_real_escape_string($link, (string)$value);
    $serialized_sql = $serialized ? '1' : '0';

    if (!@mysqli_query($link, "DELETE FROM `{$table}` WHERE `store_id` = '0' AND `code` = 'config' AND `key` = '{$key_sql}'")) {
        oc_log("could not clear {$key}: " . mysqli_error($link));
        mysqli_close($link);
        exit(1);
    }

    if (!@mysqli_query($link, "INSERT INTO `{$table}` SET `store_id` = '0', `code` = 'config', `key` = '{$key_sql}', `value` = '{$value_sql}', `serialized` = '{$serialized_sql}'")) {
        oc_log("could not set {$key}: " . mysqli_error($link));
        mysqli_close($link);
        exit(1);
    }
}

mysqli_close($link);

oc_log('seeded ' . count($settings) . ' settings (' . $mode . ')');
