<?php

/**
 * Simple helper script to perform git commits via PHP.
 *
 * Usage:
 *   php bin/git-commit.php "your commit message"
 *
 * This keeps the commit message handling inside PHP to avoid
 * shell encoding issues on some platforms.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/git-commit.php \"your commit message\"\n");
    exit(1);
}

// Join all arguments after the script name as the commit message
$messageParts = array_slice($argv, 1);
$message = trim(implode(' ', $messageParts));

if ($message === '') {
    fwrite(STDERR, "Error: commit message cannot be empty.\n");
    exit(1);
}

// Ensure we run git in the project root (bin/ is under the root)
$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    fwrite(STDERR, "Error: could not resolve project root.\n");
    exit(1);
}

chdir($projectRoot);

// Use temporary file to avoid shell encoding issues
$tempFile = tempnam(sys_get_temp_dir(), 'git_commit_msg_');
if ($tempFile === false) {
    fwrite(STDERR, "Error: failed to create temporary file.\n");
    exit(1);
}

// Write message to temp file with UTF-8 encoding
file_put_contents($tempFile, $message, LOCK_EX);

// Build the git commit command using -F option to read from file
$escapedFile = escapeshellarg($tempFile);
$command = "git commit -F {$escapedFile} 2>&1";

// Execute command
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);

// Clean up temp file
unlink($tempFile);

$stdout = implode("\n", $output);

if ($stdout !== '') {
    echo $stdout . "\n";
}

exit($exitCode);

