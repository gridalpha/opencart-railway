<?php
// Prints one field of the database details the bootstrap step wrote. Used by the
// entrypoint so a password never has to travel through a sourced shell file.

declare(strict_types=1);

$file = getenv('OPENCART_DB_ENV_FILE');

if ($file === false || $file === '') {
    $file = '/tmp/opencart-db.json';
}

$data = json_decode((string)@file_get_contents($file), true);

if (!is_array($data) || !isset($argv[1]) || !array_key_exists($argv[1], $data)) {
    fwrite(STDERR, '[opencart][db] no such field: ' . ($argv[1] ?? '(none)') . PHP_EOL);
    exit(1);
}

echo $data[$argv[1]];
