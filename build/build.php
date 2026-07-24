<?php
declare(strict_types=1);

function buildFail(string $message): never {
    fwrite(STDERR, "Build failed: {$message}\n");
    exit(1);
}

function pluginVersion(string $mainFile): string {
    $source = file_get_contents($mainFile);
    if ($source === false || !preg_match('/^\s*\*\s*Version:\s*([0-9A-Za-z.+-]+)\s*$/m', $source, $match)) {
        buildFail('Could not determine the plugin version from warmpilot.php.');
    }
    return $match[1];
}

function releaseFiles(string $root, array $manifest): array {
    $files = [];
    foreach ($manifest as $entry) {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        if (is_file($absolute)) {
            $files[$entry] = $absolute;
            continue;
        }
        if (!is_dir($absolute)) {
            buildFail("Manifest entry does not exist: {$entry}");
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $files[$relative] = $file->getPathname();
            }
        }
    }
    ksort($files);
    return $files;
}

function ensureZipExtension(): void {
    if (class_exists(ZipArchive::class)) {
        return;
    }
    if (in_array('--zip-enabled', $GLOBALS['argv'], true)) {
        buildFail('The PHP ZIP extension could not be loaded.');
    }

    $extensionDir = (string) ini_get('extension_dir');
    if ($extensionDir !== '' && !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $extensionDir)) {
        $extensionDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . $extensionDir;
    }
    $zipLibraryName = PHP_OS_FAMILY === 'Windows'
        ? 'php_zip.dll'
        : 'zip.' . PHP_SHLIB_SUFFIX;
    $zipLibrary = $extensionDir . DIRECTORY_SEPARATOR . $zipLibraryName;
    if (!is_file($zipLibrary)) {
        buildFail('The PHP ZIP extension is required to create a release archive.');
    }

    $command = escapeshellarg(PHP_BINARY)
        . ' -d extension=zip '
        . escapeshellarg(__FILE__)
        . ' --zip-enabled';
    passthru($command, $exitCode);
    exit($exitCode);
}

function verifyArchive(string $archivePath, array $expected): void {
    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        buildFail('The generated archive cannot be reopened.');
    }
    $actual = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $name = $zip->getNameIndex($index);
        if ($name !== false && !str_ends_with($name, '/')) {
            $actual[] = $name;
        }
    }
    $zip->close();
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
        buildFail('The generated archive does not match the release manifest.');
    }
}

function verifyComposerRuntime(string $root): void {
    $autoloadFilesPath = $root . DIRECTORY_SEPARATOR . 'vendor'
        . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_files.php';
    if (!is_file($autoloadFilesPath)) {
        buildFail('The production Composer autoload file map is missing.');
    }

    $autoloadFiles = require $autoloadFilesPath;
    if (!is_array($autoloadFiles)) {
        buildFail('The production Composer autoload file map is invalid.');
    }

    foreach ($autoloadFiles as $file) {
        if (!is_file($file)) {
            buildFail('Composer references a missing runtime file: ' . $file);
        }
    }

    require $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (!class_exists(Pdp\Rules::class) || !function_exists('idn_to_ascii')) {
        buildFail('Required production dependencies cannot be autoloaded.');
    }
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    buildFail('Project root could not be resolved.');
}
ensureZipExtension();
verifyComposerRuntime($root);

$version = pluginVersion($root . DIRECTORY_SEPARATOR . 'warmpilot.php');
$manifest = require __DIR__ . '/manifest.php';
$files = releaseFiles($root, $manifest);
$dist = $root . DIRECTORY_SEPARATOR . 'dist';
if (!is_dir($dist) && !mkdir($dist, 0777, true) && !is_dir($dist)) {
    buildFail('Could not create the dist directory.');
}
$archivePath = $dist . DIRECTORY_SEPARATOR . "warmpilot-{$version}.zip";

$zip = new ZipArchive();
$result = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($result !== true) {
    buildFail("Could not create the ZIP archive (code {$result}).");
}
$expected = [];
foreach ($files as $relative => $absolute) {
    $archiveName = 'warmpilot/' . $relative;
    if (!$zip->addFile($absolute, $archiveName)) {
        $zip->close();
        buildFail("Could not add {$relative} to the archive.");
    }
    $expected[] = $archiveName;
}
if (!$zip->close()) {
    buildFail('Could not finalize the ZIP archive.');
}

verifyArchive($archivePath, $expected);

echo "Release archive: dist/warmpilot-{$version}.zip\n";
echo "Version: {$version}\n";
echo 'Files: ' . count($expected) . "\n";
echo 'Size: ' . number_format((int) filesize($archivePath)) . " bytes\n";
echo 'SHA-256: ' . hash_file('sha256', $archivePath) . "\n";
