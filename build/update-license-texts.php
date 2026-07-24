<?php
declare(strict_types=1);

$licenses = [
    dirname(__DIR__) . '/LICENSE.txt' => [
        'url' => 'https://raw.githubusercontent.com/spdx/license-list-data/main/text/GPL-2.0-or-later.txt',
        'marker' => 'GNU GENERAL PUBLIC LICENSE',
    ],
    dirname(__DIR__) . '/licenses/Composer-MIT.txt' => [
        'url' => 'https://raw.githubusercontent.com/composer/composer/main/LICENSE',
        'marker' => 'Copyright (c) Nils Adermann, Jordi Boggiano',
    ],
    dirname(__DIR__) . '/licenses/MPL-2.0.txt' => [
        'url' => 'https://www.mozilla.org/media/MPL/2.0/index.f75d2927d3c1.txt',
        'marker' => 'Mozilla Public License Version 2.0',
    ],
];

foreach ($licenses as $path => $source) {
    $contents = @file_get_contents($source['url']);
    if ($contents === false || !str_contains($contents, $source['marker'])) {
        fwrite(STDERR, "License update failed for {$source['url']}.\n");
        exit(1);
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "Could not create {$directory}.\n");
        exit(1);
    }

    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        fwrite(STDERR, "Could not atomically replace {$path}.\n");
        exit(1);
    }

    echo str_replace('\\', '/', substr($path, strlen(dirname(__DIR__)) + 1))
        . ': ' . hash('sha256', $contents) . PHP_EOL;
}
