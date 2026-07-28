<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$directories = ['includes', 'public', 'tests', 'tools'];
$failed = false;

foreach ($directories as $directory) {
    $path = $root . DIRECTORY_SEPARATOR . $directory;

    if (!is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $command = 'php -l ' . escapeshellarg($file->getPathname());
        exec($command, $output, $exitCode);

        foreach ($output as $line) {
            echo $line . PHP_EOL;
        }

        if ($exitCode !== 0) {
            $failed = true;
        }

        $output = [];
    }
}

exit($failed ? 1 : 0);
