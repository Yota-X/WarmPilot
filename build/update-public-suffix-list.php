<?php
declare(strict_types=1);

const WARMPILOT_PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

function pslFail(string $message): never {
    fwrite(STDERR, "PSL update failed: {$message}\n");
    exit(1);
}

$contents = @file_get_contents(WARMPILOT_PSL_URL);
if ($contents === false || !str_contains($contents, '// ===BEGIN ICANN DOMAINS===')) {
    pslFail('Could not download a valid Public Suffix List.');
}

$root = dirname(__DIR__);
$directory = $root . DIRECTORY_SEPARATOR . 'resources';
if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
    pslFail('Could not create the resources directory.');
}

$path = $directory . DIRECTORY_SEPARATOR . 'public_suffix_list.dat';
$temporary = $path . '.tmp';
if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
    @unlink($temporary);
    pslFail('Could not atomically replace the local Public Suffix List.');
}

echo 'Updated resources/public_suffix_list.dat' . PHP_EOL;
echo 'SHA-256: ' . hash('sha256', $contents) . PHP_EOL;
