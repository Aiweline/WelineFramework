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

// Build the git commit command safely
$escapedMessage = escapeshellarg($message);
$command = "git commit -m {$escapedMessage}";

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptorSpec, $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "Error: failed to start git commit process.\n");
    exit(1);
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($stdout !== '') {
    echo $stdout;
}
if ($stderr !== '') {
    fwrite(STDERR, $stderr);
}

exit($exitCode);

