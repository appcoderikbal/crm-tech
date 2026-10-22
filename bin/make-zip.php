<?php

declare(strict_types=1);

/**
 * Packs a staged plugin directory into a ZIP.
 *
 * Used by bin/build-release.sh when the `zip` binary is unavailable, which is
 * the normal case on Windows.
 *
 * This lives in its own file rather than inline in the shell script because
 * the path separator handling has to be exactly right: ZIP entries must use
 * forward slashes regardless of the host OS. Building the entry names inside
 * a nested shell quote got that wrong and produced archives whose paths
 * contained literal backslashes — which unpack into one file named
 * "includes\Core\Updater.php" on Linux instead of a directory tree.
 *
 *   php bin/make-zip.php <staging-dir> <output.zip>
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$stage = $argv[1] ?? '';
$output = $argv[2] ?? '';

if ($stage === '' || $output === '') {
    fwrite(STDERR, "Usage: php make-zip.php <staging-dir> <output.zip>\n");
    exit(1);
}

$base = realpath($stage);
if ($base === false || !is_dir($base)) {
    fwrite(STDERR, "Not a directory: {$stage}\n");
    exit(1);
}

$folder = basename($base);

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$output}\n");
    exit(1);
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$added = 0;

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    $absolute = $file->getPathname();

    // Relative to the staging directory, always with forward slashes.
    $relative = substr($absolute, strlen($base) + 1);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $relative = str_replace('\\', '/', $relative);

    $entry = $folder . '/' . $relative;

    if ($file->isDir()) {
        $zip->addEmptyDir($entry);
        continue;
    }

    if (!$zip->addFile($absolute, $entry)) {
        fwrite(STDERR, "Could not add {$entry}\n");
        $zip->close();
        exit(1);
    }

    $added++;
}

$zip->close();

// Re-open and confirm nothing carries a backslash, because an archive that
// looks fine on Windows can still be unusable on the server.
$verify = new ZipArchive();
if ($verify->open($output) !== true) {
    fwrite(STDERR, "Could not reopen the archive for verification\n");
    exit(1);
}

$bad = [];
for ($i = 0; $i < $verify->numFiles; $i++) {
    $name = (string) $verify->getNameIndex($i);
    if (str_contains($name, '\\')) {
        $bad[] = $name;
    }
}

$hasMainFile = $verify->locateName($folder . '/techzapp-crm.php') !== false;
$hasAutoload = $verify->locateName($folder . '/vendor/autoload.php') !== false;
$verify->close();

if ($bad !== []) {
    fwrite(STDERR, 'Backslashes in ' . count($bad) . " entry name(s), e.g. {$bad[0]}\n");
    exit(1);
}

if (!$hasMainFile) {
    fwrite(STDERR, "The archive has no {$folder}/techzapp-crm.php\n");
    exit(1);
}

if (!$hasAutoload) {
    fwrite(STDERR, "The archive has no {$folder}/vendor/autoload.php — it would not run\n");
    exit(1);
}

printf("    %d files archived, paths verified\n", $added);
