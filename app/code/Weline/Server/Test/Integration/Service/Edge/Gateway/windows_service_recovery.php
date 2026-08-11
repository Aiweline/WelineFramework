<?php

declare(strict_types=1);

/** @return array{code:int,output:string} */
function wlsRun(array $command, float $timeoutSeconds = 20.0): array
{
    $process = \proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!\is_resource($process)) {
        throw new \RuntimeException('Unable to start: ' . \implode(' ', $command));
    }
    foreach ($pipes as $pipe) {
        \stream_set_blocking($pipe, false);
    }
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    $output = '';
    $exitCode = -1;
    for (;;) {
        $status = \proc_get_status($process);
        foreach ($pipes as $pipe) {
            $chunk = \stream_get_contents($pipe);
            if (\is_string($chunk)) {
                $output .= $chunk;
            }
        }
        if (!(bool)($status['running'] ?? false)) {
            $exitCode = (int)($status['exitcode'] ?? -1);
            break;
        }
        if (\hrtime(true) >= $deadline) {
            @\proc_terminate($process);
            $terminateDeadline = \hrtime(true) + 2_000_000_000;
            do {
                $status = \proc_get_status($process);
                foreach ($pipes as $pipe) {
                    $chunk = \stream_get_contents($pipe);
                    if (\is_string($chunk)) {
                        $output .= $chunk;
                    }
                }
                if (!(bool)($status['running'] ?? false)) {
                    break;
                }
                \usleep(50_000);
            } while (\hrtime(true) < $terminateDeadline);
            if ((bool)($status['running'] ?? false)) {
                @\proc_terminate($process, 9);
                $killDeadline = \hrtime(true) + 2_000_000_000;
                do {
                    $status = \proc_get_status($process);
                    if (!(bool)($status['running'] ?? false)) {
                        break;
                    }
                    \usleep(50_000);
                } while (\hrtime(true) < $killDeadline);
            }
            foreach ($pipes as $pipe) {
                @\fclose($pipe);
            }
            if (!(bool)($status['running'] ?? false)) {
                @\proc_close($process);
            }
            throw new \RuntimeException(
                'Command timed out: ' . \implode(' ', $command) . "\n" . $output,
            );
        }
        \usleep(50_000);
    }
    foreach ($pipes as $pipe) {
        $chunk = \stream_get_contents($pipe);
        if (\is_string($chunk)) {
            $output .= $chunk;
        }
        \fclose($pipe);
    }
    $closed = \proc_close($process);
    if ($exitCode < 0) {
        $exitCode = $closed;
    }
    return ['code' => $exitCode, 'output' => \trim($output)];
}

function wlsChecked(array $command, float $timeoutSeconds = 20.0): string
{
    $result = wlsRun($command, $timeoutSeconds);
    if ($result['code'] !== 0) {
        throw new \RuntimeException(
            'Command failed (' . $result['code'] . '): '
                . \implode(' ', $command) . "\n" . $result['output'],
        );
    }
    return $result['output'];
}

/** Run a command without creating PHP-managed output pipes on Windows. */
function wlsRunWithNullOutput(array $command, float $timeoutSeconds = 15.0): int
{
    $process = \proc_open(
        $command,
        [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'a'],
            2 => ['file', 'NUL', 'a'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!\is_resource($process)) {
        throw new \RuntimeException('Unable to start null-output command.');
    }
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    $exitCode = -1;
    for (;;) {
        $status = \proc_get_status($process);
        if (!(bool)($status['running'] ?? false)) {
            $exitCode = (int)($status['exitcode'] ?? -1);
            break;
        }
        if (\hrtime(true) >= $deadline) {
            @\proc_terminate($process);
            $killDeadline = \hrtime(true) + 2_000_000_000;
            do {
                \usleep(25_000);
                $status = \proc_get_status($process);
            } while ((bool)($status['running'] ?? false) && \hrtime(true) < $killDeadline);
            if ((bool)($status['running'] ?? false)) {
                @\proc_terminate($process, 9);
            }
            @\proc_close($process);
            throw new \RuntimeException('Null-output command exceeded its watchdog.');
        }
        \usleep(10_000);
    }
    $closed = \proc_close($process);
    return $exitCode >= 0 ? $exitCode : $closed;
}

function wlsWindowsTool(string $name): string
{
    $systemRoot = (string)\getenv('SystemRoot');
    $path = $systemRoot . '\\System32\\' . $name;
    if ($systemRoot === '' || !\is_file($path)) {
        throw new \RuntimeException('Required Windows tool is unavailable: ' . $name);
    }
    return $path;
}

function wlsAssertPlainDirectory(string $path): void
{
    if (!\is_dir($path) || \is_link($path)) {
        throw new \RuntimeException('Directory is missing or is a link: ' . $path);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }
    $powershell = wlsWindowsTool('WindowsPowerShell\\v1.0\\powershell.exe');
    $script = '$item = Get-Item -LiteralPath $args[0] -Force -ErrorAction Stop; '
        . 'if (-not $item.PSIsContainer) { exit 20 }; '
        . 'if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 21 }';
    $result = wlsRun([
        $powershell,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-Command',
        $script,
        $path,
    ], 10.0);
    if ($result['code'] !== 0) {
        throw new \RuntimeException(
            'Directory reparse validation failed (' . $result['code'] . '): '
                . $path . "\n" . $result['output'],
        );
    }
}

function wlsOption(array $arguments, string $name): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (\str_starts_with($argument, $prefix)) {
            return \substr($argument, \strlen($prefix));
        }
    }
    throw new \InvalidArgumentException('Missing required option ' . $prefix . '<value>.');
}

function wlsWrite(string $path, string $contents): void
{
    if (\file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new \RuntimeException('Unable to write ' . $path);
    }
}

function wlsWriteExclusive(string $path, string $contents): void
{
    $file = @\fopen($path, 'x+b');
    if (!\is_resource($file)) {
        throw new \RuntimeException('Refusing to replace existing file ' . $path);
    }
    $failure = null;
    try {
        if (!\flock($file, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock ' . $path);
        }
        $offset = 0;
        $length = \strlen($contents);
        while ($offset < $length) {
            $written = \fwrite($file, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Unable to write ' . $path);
            }
            $offset += $written;
        }
        if (!\fflush($file)) {
            throw new \RuntimeException('Unable to flush ' . $path);
        }
    } catch (\Throwable $exception) {
        $failure = $exception;
    } finally {
        @\flock($file, LOCK_UN);
        @\fclose($file);
    }
    if ($failure !== null) {
        @\unlink($path);
        throw $failure;
    }
}

function wlsMkdir(string $path): void
{
    if (!\is_dir($path) && !\mkdir($path, 0700, true) && !\is_dir($path)) {
        throw new \RuntimeException('Unable to create directory ' . $path);
    }
}

function wlsMkdirExclusive(string $path): void
{
    if (\file_exists($path) || \is_link($path) || !\mkdir($path, 0700, false)) {
        throw new \RuntimeException('Refusing to reuse temporary directory ' . $path);
    }
    wlsAssertPlainDirectory($path);
}

function wlsRestrictFixtureRoot(string $path): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }
    wlsChecked([
        wlsWindowsTool('icacls.exe'),
        $path,
        '/inheritance:r',
        '/grant:r',
        '*S-1-5-32-544:(OI)(CI)(F)',
        '*S-1-5-18:(OI)(CI)(F)',
    ]);
}

function wlsCopy(string $source, string $destination): void
{
    if (!\is_file($source) || !\copy($source, $destination)) {
        throw new \RuntimeException('Unable to copy ' . $source . ' to ' . $destination);
    }
}

function wlsRemoveTree(string $root): void
{
    if (!\file_exists($root) && !\is_link($root)) {
        return;
    }
    if (\preg_match(
        '/\Aweline-wls2-ci-(?:path|bounded)-[a-f0-9]{16}\z/D',
        \basename($root),
    ) !== 1) {
        throw new \RuntimeException('Refusing unsafe fixture cleanup target: ' . $root);
    }
    $programData = \realpath((string)\getenv('ProgramData'));
    $parent = \realpath(\dirname($root));
    if (!\is_string($programData) || !\is_string($parent)
        || \strcasecmp($programData, $parent) !== 0
    ) {
        throw new \RuntimeException('Fixture cleanup escaped ProgramData: ' . $root);
    }
    wlsAssertPlainDirectory($root);
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
    }
    @\rmdir($root);
}

function wlsKeygen(array $arguments): void
{
    if (!\function_exists('sodium_crypto_sign_keypair')) {
        throw new \RuntimeException('The sodium extension is required.');
    }
    $output = wlsOption($arguments, 'output');
    $parent = \dirname($output);
    if (!\is_dir($parent)) {
        throw new \RuntimeException('Key output parent does not exist: ' . $parent);
    }
    $pair = \sodium_crypto_sign_keypair();
    $public = \sodium_crypto_sign_publickey($pair);
    $secret = \sodium_crypto_sign_secretkey($pair);
    $encodedSecret = '';
    $payload = '';
    $created = false;
    try {
        $encodedSecret = \base64_encode($secret);
        $payload = \json_encode([
                'public_key_hex' => \bin2hex($public),
                'secret_key_base64' => $encodedSecret,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        wlsWriteExclusive($output, $payload);
        $created = true;
        if (PHP_OS_FAMILY === 'Windows') {
            wlsChecked([
                wlsWindowsTool('icacls.exe'),
                $output,
                '/inheritance:r',
                '/grant:r',
                '*S-1-5-32-544:(F)',
                '*S-1-5-18:(F)',
            ]);
        } elseif (!\chmod($output, 0600)) {
            throw new \RuntimeException('Unable to restrict key permissions: ' . $output);
        }
    } catch (\Throwable $exception) {
        if ($created) {
            @\unlink($output);
        }
        throw $exception;
    } finally {
        if ($payload !== '') {
            \sodium_memzero($payload);
        }
        if ($encodedSecret !== '') {
            \sodium_memzero($encodedSecret);
        }
        \sodium_memzero($secret);
        \sodium_memzero($pair);
    }
    echo 'key_file=' . $output . PHP_EOL;
    echo 'public_key_hex=' . \bin2hex($public) . PHP_EOL;
}

/** @return array{code:int,output:string} */
function wlsSnapshot(
    string $broker,
    string $operation,
    string $sourceRoot,
    string $sourceRelative,
    string $destinationRoot,
    string $destinationRelative,
): array
{
    return wlsRun([
        $broker,
        $operation,
        '--source-root',
        $sourceRoot,
        '--source-relative',
        $sourceRelative,
        '--destination-root',
        $destinationRoot,
        '--destination-relative',
        $destinationRelative,
    ]);
}

function wlsPathSecurityTest(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new \RuntimeException('The path-security integration test requires Windows.');
    }
    if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_WINDOWS_PATH_INTEGRATION') !== '1') {
        throw new \RuntimeException(
            'Set WLS_RUN_NATIVE_GATEWAY_WINDOWS_PATH_INTEGRATION=1 explicitly.',
        );
    }
    $broker = wlsOption($arguments, 'broker');
    if (!\is_file($broker)) {
        throw new \RuntimeException('Native Broker fixture is missing: ' . $broker);
    }
    $programData = (string)\getenv('ProgramData');
    if ($programData === '') {
        throw new \RuntimeException('ProgramData is unavailable.');
    }
    wlsAssertPlainDirectory($programData);
    $root = $programData . '\\weline-wls2-ci-path-' . \bin2hex(\random_bytes(8));
    $source = $root . '\\source';
    $destination = $root . '\\destination';
    $outside = $root . '\\outside';
    $rootCreated = false;
    $sourceLink = '';
    $destinationLink = '';
    try {
        wlsMkdirExclusive($root);
        $rootCreated = true;
        wlsRestrictFixtureRoot($root);
        foreach ([$source, $destination, $outside] as $directory) {
            wlsMkdir($directory);
        }
        $public = $source . '\\public.pem';
        $safePrivate = $source . '\\safe-key.pem';
        $unsafePrivate = $source . '\\unsafe-key.pem';
        $unrelatedPrivate = $source . '\\unrelated-key.pem';
        $writablePrivate = $source . '\\writable-key.pem';
        $emptySource = $source . '\\empty.pem';
        $oversizedSource = $source . '\\oversized.pem';
        $external = $outside . '\\external.pem';
        wlsWrite($public, "public-certificate\n");
        wlsWrite($safePrivate, "safe-private-key\n");
        wlsWrite($unsafePrivate, "unsafe-private-key\n");
        wlsWrite($unrelatedPrivate, "unrelated-private-key\n");
        wlsWrite($writablePrivate, "writable-private-key\n");
        wlsWrite($emptySource, '');
        wlsWrite($oversizedSource, \str_repeat('x', 1024 * 1024 + 1));
        wlsWrite($external, "external-secret\n");
        $icacls = wlsWindowsTool('icacls.exe');
        wlsChecked([
            $icacls,
            $safePrivate,
            '/inheritance:r',
            '/grant:r',
            '*S-1-5-32-544:(R)',
            '*S-1-5-18:(F)',
        ]);
        wlsChecked([
            $icacls,
            $unrelatedPrivate,
            '/inheritance:r',
            '/grant:r',
            '*S-1-5-19:(R)',
            '*S-1-5-32-544:(R)',
            '*S-1-5-18:(F)',
        ]);
        wlsChecked([
            $icacls,
            $unsafePrivate,
            '/inheritance:r',
            '/grant:r',
            '*S-1-5-11:(R)',
            '*S-1-5-32-544:(R)',
            '*S-1-5-18:(F)',
        ]);
        wlsChecked([
            $icacls,
            $writablePrivate,
            '/inheritance:r',
            '/grant:r',
            '*S-1-5-19:(W)',
            '*S-1-5-32-544:(R)',
            '*S-1-5-18:(F)',
        ]);

        $publicSnapshot = wlsSnapshot(
            $broker,
            '--snapshot',
            $source,
            'public.pem',
            $destination,
            'public-copy.pem',
        );
        if ($publicSnapshot['code'] !== 0
            || !\is_file($destination . '\\public-copy.pem')
            || (string)\file_get_contents($destination . '\\public-copy.pem')
                !== "public-certificate\n") {
            throw new \RuntimeException(
                'Ordinary no-follow snapshot failed: ' . $publicSnapshot['output'],
            );
        }
        $privateSnapshot = wlsSnapshot(
            $broker,
            '--snapshot-private-test',
            $source,
            'safe-key.pem',
            $destination,
            'safe-key-copy.pem',
        );
        if ($privateSnapshot['code'] !== 0
            || !\is_file($destination . '\\safe-key-copy.pem')
            || (string)\file_get_contents($destination . '\\safe-key-copy.pem')
                !== "safe-private-key\n") {
            throw new \RuntimeException(
                'Private ACL-safe snapshot failed: ' . $privateSnapshot['output'],
            );
        }
        $unsafeSnapshot = wlsSnapshot(
            $broker,
            '--snapshot-private-test',
            $source,
            'unsafe-key.pem',
            $destination,
            'unsafe-key-copy.pem',
        );
        if ($unsafeSnapshot['code'] === 0
            || \file_exists($destination . '\\unsafe-key-copy.pem')) {
            throw new \RuntimeException('Broad-readable private key was accepted.');
        }
        $unrelatedSnapshot = wlsSnapshot(
            $broker,
            '--snapshot-private-test',
            $source,
            'unrelated-key.pem',
            $destination,
            'unrelated-key-copy.pem',
        );
        if ($unrelatedSnapshot['code'] === 0
            || \file_exists($destination . '\\unrelated-key-copy.pem')) {
            throw new \RuntimeException('Unrelated readable SID on a private key was accepted.');
        }
        $writableSnapshot = wlsSnapshot(
            $broker,
            '--snapshot-private-test',
            $source,
            'writable-key.pem',
            $destination,
            'writable-key-copy.pem',
        );
        if ($writableSnapshot['code'] === 0
            || \file_exists($destination . '\\writable-key-copy.pem')) {
            throw new \RuntimeException('Unrelated writable SID on a private key was accepted.');
        }
        foreach ([
            ['empty.pem', 'empty-copy.pem'],
            ['oversized.pem', 'oversized-copy.pem'],
        ] as [$sourceLeaf, $destinationLeaf]) {
            $invalidSize = wlsSnapshot(
                $broker,
                '--snapshot',
                $source,
                $sourceLeaf,
                $destination,
                $destinationLeaf,
            );
            if ($invalidSize['code'] === 0
                || \file_exists($destination . '\\' . $destinationLeaf)) {
                throw new \RuntimeException('Invalid-sized snapshot source was accepted.');
            }
        }

        $sourceLink = $source . '\\linked-key.pem';
        if (!\symlink($external, $sourceLink)) {
            throw new \RuntimeException('Unable to create the source reparse-point fixture.');
        }
        $sourceReparse = wlsSnapshot(
            $broker,
            '--snapshot',
            $source,
            'linked-key.pem',
            $destination,
            'linked-key-copy.pem',
        );
        if ($sourceReparse['code'] === 0
            || \file_exists($destination . '\\linked-key-copy.pem')) {
            throw new \RuntimeException('Source reparse point was followed.');
        }

        $outsideDestination = $outside . '\\destination';
        wlsMkdir($outsideDestination);
        $destinationLink = $destination . '\\linked';
        if (!\symlink($outsideDestination, $destinationLink)) {
            throw new \RuntimeException('Unable to create the destination reparse-point fixture.');
        }
        $destinationReparse = wlsSnapshot(
            $broker,
            '--snapshot',
            $source,
            'public.pem',
            $destination,
            'linked\\escaped.pem',
        );
        if ($destinationReparse['code'] === 0
            || \file_exists($outsideDestination . '\\escaped.pem')) {
            throw new \RuntimeException('Destination reparse point was followed.');
        }
        echo \json_encode([
            'ordinary_snapshot' => true,
            'private_acl_safe' => true,
            'broad_private_acl_rejected' => true,
            'unrelated_private_acl_rejected' => true,
            'writable_private_acl_rejected' => true,
            'invalid_size_rejected' => true,
            'source_reparse_rejected' => true,
            'destination_reparse_rejected' => true,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        if ($sourceLink !== '' && \is_link($sourceLink)) {
            @\unlink($sourceLink);
        }
        if ($destinationLink !== '' && \is_link($destinationLink)) {
            @\unlink($destinationLink);
        }
        if ($rootCreated) {
            wlsRemoveTree($root);
            if (\is_dir($root)) {
                throw new \RuntimeException('Temporary Windows path fixture was not removed.');
            }
        }
    }
}

/** @return array<string,mixed> */
function wlsBoundedResult(string $directory): array
{
    $resultFile = $directory . '\\result.json';
    $bytes = @\file_get_contents($resultFile);
    if (!\is_string($bytes) || \strlen($bytes) > 4096) {
        throw new \RuntimeException('Bounded-command result was not published safely.');
    }
    $result = \json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
    if (!\is_array($result)
        || ($result['schema'] ?? '') !== 'wls-bounded-command-result/1'
    ) {
        throw new \RuntimeException('Bounded-command result schema is invalid.');
    }
    foreach (['stdout', 'stderr'] as $channel) {
        $file = $directory . '\\' . $channel . '.bin';
        $payload = @\file_get_contents($file);
        if (!\is_string($payload)
            || (int)($result[$channel . '_bytes'] ?? -1) !== \strlen($payload)
            || !\hash_equals(
                (string)($result[$channel . '_sha256'] ?? ''),
                \hash('sha256', $payload),
            )
        ) {
            throw new \RuntimeException('Bounded-command ' . $channel . ' evidence is invalid.');
        }
    }
    return $result;
}

function wlsCrossProcessPipeOrphanReaper(
    string $autoload,
    string $helper,
): void {
    $leaf = 'pipe-' . \bin2hex(\random_bytes(16));
    $ready = \sys_get_temp_dir() . '\\wls-pipe-orphan-ready-'
        . \bin2hex(\random_bytes(8));
    $producerCode = <<<'PHP'
require $argv[1];
$class = 'Weline\\Server\\Service\\Edge\\Gateway\\GatewayBoundedCommandRunner';
$parentMethod = new ReflectionMethod($class, 'windowsResultParent');
$parent = $parentMethod->invoke(null);
$directory = $parent . DIRECTORY_SEPARATOR . $argv[3];
$process = proc_open(
    [$argv[2], '--pipe-prepare', '--transaction-dir=' . $directory],
    [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'a'], 2 => ['file', 'NUL', 'a']],
    $pipes,
    null,
    null,
    ['bypass_shell' => true],
);
if (!is_resource($process)) { exit(31); }
$status = proc_get_status($process);
while (($status['running'] ?? false) === true) {
    usleep(10000);
    $status = proc_get_status($process);
}
$code = (int)($status['exitcode'] ?? -1);
$closed = (int)proc_close($process);
if (($code >= 0 ? $code : $closed) !== 0 || !touch($directory, time() - 120)) {
    exit(32);
}
$ready = $directory . "\n";
if (file_put_contents($argv[4], $ready, LOCK_EX) !== strlen($ready)) { exit(33); }
sleep(60);
PHP;
    $producer = \proc_open(
        [\PHP_BINARY, '-r', $producerCode, $autoload, $helper, $leaf, $ready],
        [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'a'],
            2 => ['file', 'NUL', 'a'],
        ],
        $producerPipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!\is_resource($producer)) {
        throw new \RuntimeException('Unable to launch pipe orphan producer PHP.');
    }
    $directory = '';
    $nextDirectory = '';
    try {
        $readyDeadline = \hrtime(true) + 10_000_000_000;
        while (!\is_file($ready)) {
            $status = \proc_get_status($producer);
            if (!(bool)($status['running'] ?? false)
                || \hrtime(true) >= $readyDeadline) {
                throw new \RuntimeException(
                    'Pipe orphan producer did not publish its durable residue.',
                );
            }
            \usleep(10_000);
        }
        $publishedDirectory = \trim((string)\file_get_contents($ready));
        if ($publishedDirectory === ''
            || !\str_ends_with($publishedDirectory, '\\' . $leaf)
            || \is_link($publishedDirectory)
            || !\is_dir($publishedDirectory)) {
            throw new \RuntimeException('Pipe orphan producer published an unsafe path.');
        }
        $directory = $publishedDirectory;
        if (!@\proc_terminate($producer, 9)) {
            throw new \RuntimeException('Unable to kill pipe orphan producer PHP.');
        }
        $exitDeadline = \hrtime(true) + 5_000_000_000;
        do {
            \usleep(10_000);
            $status = \proc_get_status($producer);
        } while ((bool)($status['running'] ?? false)
            && \hrtime(true) < $exitDeadline);
        if ((bool)($status['running'] ?? false)) {
            throw new \RuntimeException('Killed pipe orphan producer PHP remained alive.');
        }
        @\proc_close($producer);
        $producer = null;

        $reaperCode = <<<'PHP'
require $argv[1];
$path = realpath($argv[2]);
if (!is_string($path)) { exit(41); }
$proof = [
    'path' => $path,
    'size' => filesize($path),
    'sha256' => hash_file('sha256', $path),
    'source' => 'windows-integration',
];
$method = new ReflectionMethod(
    'Weline\\Server\\Service\\Edge\\Gateway\\GatewayBoundedCommandRunner',
    'reapWindowsPipeOrphans',
);
$ok = $method->invoke(null, $proof, (hrtime(true) / 1000000000) + 15.0);
echo $ok ? 'reaped' : 'failed';
exit($ok ? 0 : 42);
PHP;
        $reaped = wlsRun([
            \PHP_BINARY,
            '-r',
            $reaperCode,
            $autoload,
            $helper,
        ], 20.0);
        if ($reaped['code'] !== 0 || $reaped['output'] !== 'reaped') {
            throw new \RuntimeException(
                'A new PHP process did not converge the killed parent residue: '
                    . $reaped['output'],
            );
        }

        $parentCode = <<<'PHP'
require $argv[1];
$method = new ReflectionMethod(
    'Weline\\Server\\Service\\Edge\\Gateway\\GatewayBoundedCommandRunner',
    'windowsResultParent',
);
echo $method->invoke(null);
PHP;
        $parentResult = wlsRun([
            \PHP_BINARY, '-r', $parentCode, $autoload,
        ], 10.0);
        if ($parentResult['code'] !== 0 || $parentResult['output'] === '') {
            throw new \RuntimeException('Unable to resolve the pipe result parent.');
        }
        $directory = $parentResult['output'] . '\\' . $leaf;
        if (\file_exists($directory) || \is_link($directory)) {
            throw new \RuntimeException('Cross-process pipe orphan was retained.');
        }

        $nextLeaf = 'pipe-' . \bin2hex(\random_bytes(16));
        $nextDirectory = $parentResult['output'] . '\\' . $nextLeaf;
        if (wlsRunWithNullOutput([
            $helper,
            '--pipe-prepare',
            '--transaction-dir=' . $nextDirectory,
        ]) !== 0 || !@\touch($nextDirectory, \time() - 120)) {
            throw new \RuntimeException(
                'The request after orphan convergence could not be prepared.',
            );
        }
        $nextReaped = wlsRun([
            \PHP_BINARY,
            '-r',
            $reaperCode,
            $autoload,
            $helper,
        ], 20.0);
        if ($nextReaped['code'] !== 0
            || \file_exists($nextDirectory)
            || \is_link($nextDirectory)) {
            throw new \RuntimeException('Next-request pipe fixture did not cleanly converge.');
        }
    } finally {
        if (\is_resource($producer)) {
            @\proc_terminate($producer, 9);
            @\proc_close($producer);
        }
        @\unlink($ready);
        if ($directory !== '' && \is_dir($directory)) {
            wlsRemoveTree($directory);
        }
        if ($nextDirectory !== '' && \is_dir($nextDirectory)) {
            wlsRemoveTree($nextDirectory);
        }
    }
}

function wlsBoundedCommandTest(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new \RuntimeException('The bounded-command integration test requires Windows.');
    }
    if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_WINDOWS_BOUNDED_COMMAND_INTEGRATION') !== '1') {
        throw new \RuntimeException(
            'Set WLS_RUN_NATIVE_GATEWAY_WINDOWS_BOUNDED_COMMAND_INTEGRATION=1 explicitly.',
        );
    }
    $helperInput = wlsOption($arguments, 'helper');
    $helper = \realpath($helperInput);
    if (!\is_string($helper) || !\is_file($helper)) {
        throw new \RuntimeException('Bounded-command helper is missing: ' . $helperInput);
    }
    $autoload = \realpath(\dirname(__DIR__, 9) . '/vendor/autoload.php');
    if (!\is_string($autoload) || !\is_file($autoload)) {
        throw new \RuntimeException('WLS autoload is unavailable for cross-process reaping.');
    }
    $programData = (string)\getenv('ProgramData');
    if ($programData === '') {
        throw new \RuntimeException('ProgramData is unavailable.');
    }
    $root = $programData . '\\weline-wls2-ci-bounded-' . \bin2hex(\random_bytes(8));
    $rootCreated = false;
    try {
        wlsMkdirExclusive($root);
        $rootCreated = true;
        wlsRestrictFixtureRoot($root);

        $separate = $root . '\\separate';
        $exit = wlsRunWithNullOutput([
            $helper,
            '--result-dir=' . $separate,
            '--timeout-ms=5000',
            '--cwd=' . $root,
            '--',
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT,"stdout-evidence");fwrite(STDERR,"stderr-evidence");',
        ]);
        if ($exit !== 0) {
            throw new \RuntimeException('Bounded-command separation fixture failed: ' . $exit);
        }
        $separateResult = wlsBoundedResult($separate);
        if ((int)$separateResult['exit_code'] !== 0
            || ($separateResult['timed_out'] ?? true) !== false
            || ($separateResult['truncated'] ?? true) !== false
            || \file_get_contents($separate . '\\stdout.bin') !== 'stdout-evidence'
            || \file_get_contents($separate . '\\stderr.bin') !== 'stderr-evidence'
        ) {
            throw new \RuntimeException('Bounded-command channel separation contract failed.');
        }

        $truncated = $root . '\\truncated';
        $exit = wlsRunWithNullOutput([
            $helper,
            '--result-dir=' . $truncated,
            '--timeout-ms=5000',
            '--cwd=' . $root,
            '--',
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT,str_repeat("o",200000));fwrite(STDERR,str_repeat("e",200000));',
        ]);
        $truncatedResult = wlsBoundedResult($truncated);
        if ($exit !== 0
            || ($truncatedResult['truncated'] ?? false) !== true
            || (int)$truncatedResult['stdout_bytes'] + (int)$truncatedResult['stderr_bytes']
                !== 262144
        ) {
            throw new \RuntimeException('Bounded-command output cap contract failed.');
        }

        $started = $root . '\\grandchild-started';
        $leaked = $root . '\\grandchild-leaked';
        $timeout = $root . '\\timeout';
        $grandchild = 'file_put_contents($argv[1],"started");'
            . 'usleep(3000000);file_put_contents($argv[2],"leaked");sleep(30);';
        $parent = '$p=proc_open([PHP_BINARY,"-r",$argv[1],$argv[2],$argv[3]],'
            . '[0=>["file","NUL","r"],1=>["file","NUL","a"],2=>["file","NUL","a"]],$pipes);'
            . 'if(!is_resource($p)){exit(91);}sleep(30);';
        $exit = wlsRunWithNullOutput([
            $helper,
            '--result-dir=' . $timeout,
            '--timeout-ms=1500',
            '--cwd=' . $root,
            '--',
            PHP_BINARY,
            '-r',
            $parent,
            $grandchild,
            $started,
            $leaked,
        ]);
        $timeoutResult = wlsBoundedResult($timeout);
        if ($exit !== 0
            || (int)$timeoutResult['exit_code'] !== 124
            || ($timeoutResult['timed_out'] ?? false) !== true
            || !\is_file($started)
        ) {
            throw new \RuntimeException('Bounded-command timeout fixture did not start correctly.');
        }
        \usleep(3_500_000);
        if (\file_exists($leaked) || \is_link($leaked)) {
            throw new \RuntimeException('Timeout left a descendant alive outside the Job boundary.');
        }

        $watchdogStarted = $root . '\\watchdog-grandchild-started';
        $watchdogLeaked = $root . '\\watchdog-grandchild-leaked';
        $watchdogResult = $root . '\\watchdog-result';
        $watchdogProcess = \proc_open(
            [
                $helper,
                '--result-dir=' . $watchdogResult,
                '--timeout-ms=10000',
                '--cwd=' . $root,
                '--',
                PHP_BINARY,
                '-r',
                $parent,
                $grandchild,
                $watchdogStarted,
                $watchdogLeaked,
            ],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'a'],
                2 => ['file', 'NUL', 'a'],
            ],
            $watchdogPipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($watchdogProcess)) {
            throw new \RuntimeException('Unable to start the watchdog-kill fixture.');
        }
        $startedDeadline = \hrtime(true) + 3_000_000_000;
        while (!\is_file($watchdogStarted)) {
            $watchdogStatus = \proc_get_status($watchdogProcess);
            if (!(bool)($watchdogStatus['running'] ?? false)
                || \hrtime(true) >= $startedDeadline
            ) {
                @\proc_terminate($watchdogProcess, 9);
                @\proc_close($watchdogProcess);
                throw new \RuntimeException('Watchdog-kill descendant never became ready.');
            }
            \usleep(10_000);
        }
        if (!@\proc_terminate($watchdogProcess)) {
            @\proc_terminate($watchdogProcess, 9);
            @\proc_close($watchdogProcess);
            throw new \RuntimeException('Unable to terminate the bounded helper watchdog fixture.');
        }
        $watchdogDeadline = \hrtime(true) + 2_000_000_000;
        do {
            \usleep(10_000);
            $watchdogStatus = \proc_get_status($watchdogProcess);
        } while ((bool)($watchdogStatus['running'] ?? false)
            && \hrtime(true) < $watchdogDeadline);
        if ((bool)($watchdogStatus['running'] ?? false)) {
            @\proc_terminate($watchdogProcess, 9);
            @\proc_close($watchdogProcess);
            throw new \RuntimeException('Bounded helper ignored the external watchdog termination.');
        }
        @\proc_close($watchdogProcess);
        \usleep(3_500_000);
        if (\file_exists($watchdogLeaked) || \is_link($watchdogLeaked)) {
            throw new \RuntimeException('KILL_ON_CLOSE left a descendant alive after helper death.');
        }

        wlsCrossProcessPipeOrphanReaper($autoload, $helper);

        $existing = $root . '\\existing';
        wlsMkdirExclusive($existing);
        if (wlsRunWithNullOutput([
            $helper,
            '--result-dir=' . $existing,
            '--timeout-ms=1000',
            '--',
            PHP_BINARY,
            '-r',
            'exit(0);',
        ]) === 0 || \file_exists($existing . '\\result.json')) {
            throw new \RuntimeException('Existing result directory was accepted or overwritten.');
        }
    } finally {
        if ($rootCreated) {
            wlsRemoveTree($root);
            if (\is_dir($root)) {
                throw new \RuntimeException('Temporary bounded-command fixture was not removed.');
            }
        }
    }
}

/** @return array<string,mixed> */
function wlsCapacityJson(array $result, string $label): array
{
    if ($result['code'] !== 0) {
        throw new \RuntimeException(
            $label . ' failed (' . $result['code'] . '): ' . $result['output'],
        );
    }
    $decoded = \json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
    if (!\is_array($decoded) || \array_is_list($decoded)) {
        throw new \RuntimeException($label . ' did not return a JSON object.');
    }
    return $decoded;
}

/** @return array{code:int,output:string} */
function wlsCapacityCommand(
    string $launcher,
    array $common,
    string $operation,
    array $extra = [],
    float $timeoutSeconds = 180.0,
): array {
    return wlsRun([
        $launcher,
        '--capacity-reserve=' . $operation,
        ...$common,
        ...$extra,
    ], $timeoutSeconds);
}

/** @return array{schema:string,state:string} */
function wlsCapacityInspect(string $launcher, array $common): array
{
    $inspect = wlsCapacityJson(
        wlsCapacityCommand($launcher, $common, 'inspect'),
        'Windows capacity inspect',
    );
    if (\array_keys($inspect) !== ['schema', 'state']
        || ($inspect['schema'] ?? null) !== 'wls-capacity-inspect/1'
        || !\in_array(
            $inspect['state'] ?? null,
            ['NONE', 'ALLOCATING', 'HELD', 'RELEASING'],
            true,
        )) {
        throw new \RuntimeException('Windows capacity inspect response drifted.');
    }
    /** @var array{schema:string,state:string} $inspect */
    return $inspect;
}

function wlsCapacityFailpointMarker(string $definition, string $nonce): string
{
    if (\preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
        throw new \RuntimeException('Capacity failpoint nonce is invalid.');
    }
    return \dirname($definition) . '\\' . $nonce
        . '.platform.reserve.failpoint';
}

function wlsCapacityRemoveFailpointMarker(
    string $marker,
    string $definition,
    string $nonce,
): void {
    $expected = wlsCapacityFailpointMarker($definition, $nonce);
    if (\strcasecmp($marker, $expected) !== 0
        || !\is_file($marker) || \is_link($marker)) {
        throw new \RuntimeException('Refusing unsafe capacity failpoint marker.');
    }
    $powershell = wlsWindowsTool('WindowsPowerShell\\v1.0\\powershell.exe');
    $plain = wlsRun([
        $powershell,
        '-NoLogo',
        '-NoProfile',
        '-NonInteractive',
        '-ExecutionPolicy',
        'Bypass',
        '-Command',
        '$item = Get-Item -LiteralPath $args[0] -Force -ErrorAction Stop; '
            . 'if ($item.PSIsContainer) { exit 20 }; '
            . 'if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { exit 21 }',
        $marker,
    ], 10.0);
    if ($plain['code'] !== 0 || !\unlink($marker)) {
        throw new \RuntimeException('Unable to remove exact capacity failpoint marker.');
    }
}

function wlsCapacityKillAtFailpoint(
    string $launcher,
    array $common,
    string $operation,
    array $extra,
    string $definition,
    string $nonce,
    string $failpoint,
    float $timeoutSeconds = 3600.0,
): void {
    if (!\in_array(
        $failpoint,
        [
            'allocation',
            'token-directory',
            'token-batch',
            'direct-seal',
            'rename',
            'begin',
            'control-token-partial',
            'release',
            'primary-token-partial',
        ],
        true,
    )) {
        throw new \RuntimeException('Unknown capacity test failpoint.');
    }
    $marker = wlsCapacityFailpointMarker($definition, $nonce);
    if (\file_exists($marker) || \is_link($marker)) {
        throw new \RuntimeException('Capacity failpoint marker was not reaped.');
    }
    $previous = \getenv('WLS_CAPACITY_TEST_FAILPOINT');
    if (!\putenv('WLS_CAPACITY_TEST_FAILPOINT=' . $failpoint)) {
        throw new \RuntimeException('Unable to arm capacity test failpoint.');
    }
    $process = null;
    try {
        $process = \proc_open(
            [
                $launcher,
                '--capacity-reserve=' . $operation,
                ...$common,
                ...$extra,
            ],
            [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'a'],
                2 => ['file', 'NUL', 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
    } finally {
        if ($previous === false) {
            \putenv('WLS_CAPACITY_TEST_FAILPOINT');
        } else {
            \putenv('WLS_CAPACITY_TEST_FAILPOINT=' . $previous);
        }
    }
    if (!\is_resource($process)) {
        throw new \RuntimeException('Unable to start capacity crash helper.');
    }
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    $killed = false;
    try {
        for (;;) {
            $status = \proc_get_status($process);
            if (!(bool)($status['running'] ?? false)) {
                throw new \RuntimeException(
                    'Capacity helper exited before publishing its durable failpoint marker.',
                );
            }
            \clearstatcache(true, $marker);
            if (\is_file($marker) && !\is_link($marker)) {
                break;
            }
            if (\hrtime(true) >= $deadline) {
                throw new \RuntimeException('Capacity failpoint marker timed out.');
            }
            \usleep(50_000);
        }
        if (!@\proc_terminate($process)) {
            throw new \RuntimeException('Unable to kill capacity helper at failpoint.');
        }
        $killDeadline = \hrtime(true) + 10_000_000_000;
        do {
            $status = \proc_get_status($process);
            if (!(bool)($status['running'] ?? false)) {
                $killed = true;
                break;
            }
            \usleep(50_000);
        } while (\hrtime(true) < $killDeadline);
        if (!$killed) {
            throw new \RuntimeException('Capacity helper resisted bounded termination.');
        }
        wlsCapacityRemoveFailpointMarker($marker, $definition, $nonce);
    } finally {
        if (!$killed) {
            @\proc_terminate($process);
            @\proc_terminate($process, 9);
        }
        @\proc_close($process);
    }
}

function wlsCapacityCandidate(string $home, string $source, string $nonce): string
{
    $bin = $home . '\\rebootstrap\\candidates\\' . $nonce . '\\bin';
    wlsMkdir($bin);
    $candidate = $bin . '\\wls-gateway-launcher.exe';
    wlsCopy($source, $candidate);
    foreach (new \FilesystemIterator(
        \dirname($source),
        \FilesystemIterator::SKIP_DOTS,
    ) as $dependency) {
        if ($dependency->isFile()
            && \preg_match('/\.dll\z/iD', $dependency->getFilename()) === 1
        ) {
            wlsCopy(
                $dependency->getPathname(),
                $bin . '\\' . $dependency->getFilename(),
            );
        }
    }
    return $candidate;
}

function wlsCapacityWriteDurableExclusive(string $path, string $contents): void
{
    $file = @\fopen($path, 'x+b');
    if (!\is_resource($file)) {
        throw new \RuntimeException('Refusing to replace durable capacity marker ' . $path);
    }
    $failure = null;
    try {
        if (!\flock($file, LOCK_EX)) {
            throw new \RuntimeException('Unable to lock durable capacity marker.');
        }
        $offset = 0;
        while ($offset < \strlen($contents)) {
            $written = \fwrite($file, \substr($contents, $offset));
            if (!\is_int($written) || $written < 1) {
                throw new \RuntimeException('Unable to write durable capacity marker.');
            }
            $offset += $written;
        }
        if (!\fflush($file)
            || !\function_exists('fsync')
            || !\fsync($file)) {
            throw new \RuntimeException('Unable to durably flush capacity marker.');
        }
    } catch (\Throwable $exception) {
        $failure = $exception;
    } finally {
        @\flock($file, LOCK_UN);
        @\fclose($file);
    }
    if ($failure !== null) {
        @\unlink($path);
        throw $failure;
    }
}

function wlsCapacityProductionMarker(string $home): string
{
    return $home . '\\rebootstrap\\capacity\\production-gate.marker.json';
}

function wlsCapacityWriteProductionMarker(
    string $home,
    string $nonce,
    string $definition,
): void {
    $marker = wlsCapacityProductionMarker($home);
    $payload = [
        'schema' => 'wls-capacity-production-gate/1',
        'nonce' => $nonce,
        'definition' => $definition,
    ];
    wlsCapacityWriteDurableExclusive(
        $marker,
        \json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n",
    );
}

/** @return array{schema:string,nonce:string,definition:string}|null */
function wlsCapacityReadProductionMarker(
    string $home,
    string $expectedDefinition,
): ?array {
    $marker = wlsCapacityProductionMarker($home);
    if (!\file_exists($marker) && !\is_link($marker)) {
        return null;
    }
    if (!\is_file($marker) || \is_link($marker)) {
        throw new \RuntimeException('Production capacity marker is not a plain file.');
    }
    $decoded = \json_decode(
        (string)\file_get_contents($marker),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (!\is_array($decoded) || \array_is_list($decoded)
        || \array_keys($decoded) !== ['schema', 'nonce', 'definition']
        || ($decoded['schema'] ?? null) !== 'wls-capacity-production-gate/1'
        || !\is_string($decoded['nonce'] ?? null)
        || \preg_match('/\A[a-f0-9]{32}\z/D', $decoded['nonce']) !== 1
        || !\is_string($decoded['definition'] ?? null)
        || \strcasecmp($decoded['definition'], $expectedDefinition) !== 0) {
        throw new \RuntimeException('Production capacity marker is malformed or foreign.');
    }
    /** @var array{schema:string,nonce:string,definition:string} $decoded */
    return $decoded;
}

function wlsCapacityRecoverDurableMarker(
    string $home,
    string $definition,
): void {
    $markerState = wlsCapacityReadProductionMarker($home, $definition);
    if ($markerState === null) {
        return;
    }
    $nonce = $markerState['nonce'];
    $candidate = $home . '\\rebootstrap\\candidates\\' . $nonce
        . '\\bin\\wls-gateway-launcher.exe';
    if (!\is_file($candidate) || \is_link($candidate)) {
        throw new \RuntimeException('Durable capacity recovery candidate is unavailable.');
    }
    $failpointMarker = wlsCapacityFailpointMarker($definition, $nonce);
    if (\file_exists($failpointMarker) || \is_link($failpointMarker)) {
        wlsCapacityRemoveFailpointMarker(
            $failpointMarker,
            $definition,
            $nonce,
        );
    }
    $common = [
        '--home=' . $home,
        '--nonce=' . $nonce,
        '--bytes=10737418240',
        '--inodes=65536',
        '--platform-definition=' . $definition,
        '--test-mode=0',
    ];
    $inspect = wlsCapacityInspect($candidate, $common);
    $capacity = $home . '\\rebootstrap\\capacity';
    $manifest = $capacity . '\\' . $nonce . '.held.json';
    $expectedManifest = '{"nonce":"' . $nonce
        . '","state":"HELD"}' . "\n";
    $binding = null;
    if ($inspect === [
        'schema' => 'wls-capacity-inspect/1',
        'state' => 'ALLOCATING',
    ]) {
        wlsCapacityJson(
            wlsCapacityCommand(
                $candidate,
                $common,
                'complete-release',
                ['--release-reason=cancel'],
                3600.0,
            ),
            'Windows production ALLOCATING crash recovery',
        );
    } elseif (($inspect['state'] ?? null) === 'HELD') {
        if (!\file_exists($manifest)) {
            wlsCapacityWriteDurableExclusive(
                $manifest,
                $expectedManifest,
            );
        }
        if (!\is_file($manifest) || \is_link($manifest)
            || \file_get_contents($manifest) !== $expectedManifest) {
            throw new \RuntimeException('Production capacity manifest is unsafe.');
        }
        $digest = \hash_file('sha256', $manifest);
        if (!\is_string($digest)) {
            throw new \RuntimeException('Unable to bind recovered production capacity manifest.');
        }
        $binding = '--expected-manifest-sha256=' . $digest;
        wlsCapacityJson(
            wlsCapacityCommand(
                $candidate,
                $common,
                'begin-release',
                ['--release-reason=cancel', $binding],
                3600.0,
            ),
            'Windows production HELD crash recovery',
        );
    } elseif (($inspect['state'] ?? null) === 'RELEASING') {
        if (!\is_file($manifest) || \is_link($manifest)
            || \file_get_contents($manifest) !== $expectedManifest) {
            throw new \RuntimeException(
                'RELEASING production capacity lacks its authenticated manifest.',
            );
        }
        $digest = \hash_file('sha256', $manifest);
        if (!\is_string($digest)) {
            throw new \RuntimeException('Unable to bind releasing capacity manifest.');
        }
        $binding = '--expected-manifest-sha256=' . $digest;
        $beginReplay = wlsCapacityCommand(
            $candidate,
            $common,
            'begin-release',
            ['--release-reason=cancel', $binding],
            3600.0,
        );
        if ($beginReplay['code'] === 0) {
            wlsCapacityJson(
                $beginReplay,
                'Windows production RELEASING begin replay',
            );
        }
    } elseif (($inspect['state'] ?? null) !== 'NONE') {
        throw new \RuntimeException('Unsupported production capacity recovery state.');
    }
    if ($binding !== null) {
        wlsCapacityJson(
            wlsCapacityCommand(
                $candidate,
                $common,
                'complete-release',
                ['--release-reason=cancel', $binding],
                3600.0,
            ),
            'Windows production capacity release recovery',
        );
    }
    if (wlsCapacityInspect($candidate, $common) !== [
        'schema' => 'wls-capacity-inspect/1',
        'state' => 'NONE',
    ]) {
        throw new \RuntimeException('Production capacity recovery did not converge to NONE.');
    }
    if (\file_exists($manifest)) {
        if (!\is_file($manifest) || \is_link($manifest)
            || \file_get_contents($manifest) !== $expectedManifest
            || !\unlink($manifest)) {
            throw new \RuntimeException(
                'Unable to remove exact recovered production manifest.',
            );
        }
    }
    $marker = wlsCapacityProductionMarker($home);
    if (!\is_file($marker) || \is_link($marker) || !\unlink($marker)) {
        throw new \RuntimeException('Unable to retire production capacity marker.');
    }
}

function wlsRemoveCapacityTree(string $root): void
{
    if (!\file_exists($root) && !\is_link($root)) {
        return;
    }
    if (\preg_match(
        '/\Aweline-wls2-capacity-[a-f0-9]{16}\z/D',
        \basename($root),
    ) !== 1 || \is_link($root)) {
        throw new \RuntimeException('Refusing unsafe capacity cleanup target: ' . $root);
    }
    $temporary = \realpath(\sys_get_temp_dir());
    $parent = \realpath(\dirname($root));
    if (!\is_string($temporary) || !\is_string($parent)
        || \strcasecmp($temporary, $parent) !== 0
    ) {
        throw new \RuntimeException('Capacity fixture cleanup escaped temporary storage.');
    }
    wlsAssertPlainDirectory($root);
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir() && !$item->isLink()) {
            if (!@\rmdir($path)) {
                throw new \RuntimeException('Unable to remove capacity directory ' . $path);
            }
        } elseif (!@\unlink($path)) {
            throw new \RuntimeException('Unable to remove capacity file ' . $path);
        }
    }
    if (!@\rmdir($root)) {
        throw new \RuntimeException('Unable to remove capacity fixture root ' . $root);
    }
}

function wlsWindowsCapacityTest(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new \RuntimeException('The native capacity integration test requires Windows.');
    }
    if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_WINDOWS_CAPACITY_INTEGRATION') !== '1') {
        throw new \RuntimeException(
            'Set WLS_RUN_NATIVE_GATEWAY_WINDOWS_CAPACITY_INTEGRATION=1 explicitly.',
        );
    }
    $sourceLauncher = \realpath(wlsOption($arguments, 'launcher'));
    if (!\is_string($sourceLauncher) || !\is_file($sourceLauncher)) {
        throw new \RuntimeException('Native launcher fixture is missing.');
    }
    $contract = wlsCapacityJson(
        wlsRun([$sourceLauncher, '--capacity-reserve-contract-self-test']),
        'Windows capacity contract self-test',
    );
    if ($contract !== [
        'production_inodes' => 65_536,
        'token_flush_batch' => 4_096,
        'production_volume_flushes' => 16,
        'test_volume_flushes' => 1,
    ]) {
        throw new \RuntimeException('Windows capacity batching contract changed.');
    }

    $temporaryRoot = \realpath(\sys_get_temp_dir());
    if (!\is_string($temporaryRoot)) {
        throw new \RuntimeException('Windows temporary directory is not canonical.');
    }
    $root = $temporaryRoot . '\\weline-wls2-capacity-'
        . \bin2hex(\random_bytes(8));
    $created = false;
    $failure = null;
    try {
        wlsMkdirExclusive($root);
        $created = true;
        wlsRestrictFixtureRoot($root);
        foreach ([
            'bin',
            'trust',
            'state',
            'runtime',
            'runtime\\conf',
            'runtime\\temp',
            'runtime\\shadow',
            'runtime\\run',
            'snapshots',
            'snapshots-v2',
            'snapshot-candidates-v2',
            'slots',
            'rebootstrap',
            'rebootstrap\\candidates',
            'rebootstrap\\backups',
            'rebootstrap\\capacity',
        ] as $relative) {
            wlsMkdir($root . '\\' . $relative);
        }
        $definition = $root . '\\state\\service-definition.test';
        wlsWriteExclusive($definition, "test-service\n");
        $capacity = $root . '\\rebootstrap\\capacity';
        $commonFor = static fn (string $nonce): array => [
            '--home=' . $root,
            '--nonce=' . $nonce,
            '--bytes=8388608',
            '--inodes=128',
            '--platform-definition=' . $definition,
            '--test-mode=1',
        ];

        $junctionNonce = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $junctionCandidate = wlsCapacityCandidate(
            $root,
            $sourceLauncher,
            $junctionNonce,
        );
        $junctionPath = $root . '\\runtime\\conf';
        $junctionTarget = $root . '\\runtime-conf-junction-target';
        if (!\rmdir($junctionPath)) {
            throw new \RuntimeException('Unable to stage the Windows derived-root junction fault.');
        }
        wlsMkdir($junctionTarget);
        $powershell = wlsWindowsTool('WindowsPowerShell\\v1.0\\powershell.exe');
        $junction = wlsRun([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            'New-Item -ItemType Junction -Path $args[0] -Target $args[1] '
                . '-ErrorAction Stop | Out-Null',
            $junctionPath,
            $junctionTarget,
        ], 15.0);
        if ($junction['code'] !== 0) {
            throw new \RuntimeException(
                'Unable to create the Windows derived-root junction fault: '
                    . $junction['output'],
            );
        }
        $junctionFailure = wlsCapacityCommand(
            $junctionCandidate,
            $commonFor($junctionNonce),
            'create',
        );
        if ($junctionFailure['code'] === 0
            || \is_dir($capacity . '\\' . $junctionNonce . '.held')
            || \is_dir($capacity . '\\' . $junctionNonce . '.allocating')
        ) {
            throw new \RuntimeException(
                'Windows derived-root junction/other-volume anchor failed open before HELD.',
            );
        }
        $junctionRemoval = wlsRun([
            $powershell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            'Remove-Item -LiteralPath $args[0] -Force -ErrorAction Stop',
            $junctionPath,
        ], 15.0);
        if ($junctionRemoval['code'] !== 0 || \is_dir($junctionPath)) {
            throw new \RuntimeException(
                'Unable to remove the Windows derived-root junction fault: '
                    . $junctionRemoval['output'],
            );
        }
        wlsMkdir($junctionPath);

        $nonce = '0123456789abcdef0123456789abcdef';
        $candidate = wlsCapacityCandidate($root, $sourceLauncher, $nonce);
        $common = $commonFor($nonce);
        $held = wlsCapacityJson(
            wlsCapacityCommand($candidate, $common, 'create'),
            'Windows physical reserve create',
        );
        if (($held['state'] ?? null) !== 'HELD'
            || ($held['inode_count'] ?? null) !== 128
            || !\is_int($held['physical_bytes'] ?? null)
            || $held['physical_bytes'] < 8_388_608
        ) {
            throw new \RuntimeException('Windows native reserve did not prove physical capacity.');
        }
        if (wlsCapacityInspect($candidate, $common) !== [
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'HELD',
        ]) {
            throw new \RuntimeException('Windows HELD inspect contract drifted.');
        }
        $directPrefix = \dirname($definition) . '\\' . $nonce
            . '.platform.reserve.';
        foreach ([0, 1] as $index) {
            $directCredit = $directPrefix . $index;
            if (!\is_file($directCredit) || \is_link($directCredit)
                || \filesize($directCredit) !== 2_097_152) {
                throw new \RuntimeException(
                    'Windows definition-parent direct capacity credit drifted.',
                );
            }
        }
        $foreignDirect = $directPrefix . 'foreign';
        wlsWriteExclusive($foreignDirect, 'foreign');
        $foreignInspect = wlsCapacityCommand(
            $candidate,
            $common,
            'inspect',
        );
        if ($foreignInspect['code'] !== 77 || !\unlink($foreignDirect)) {
            throw new \RuntimeException(
                'Windows inspect accepted a foreign definition-parent credit.',
            );
        }
        $conflictRoot = $capacity . '\\' . $nonce . '.allocating';
        wlsMkdir($conflictRoot);
        $conflictInspect = wlsCapacityCommand(
            $candidate,
            $common,
            'inspect',
        );
        if ($conflictInspect['code'] !== 78 || !\rmdir($conflictRoot)) {
            throw new \RuntimeException('Windows inspect failed its conflict exit contract.');
        }
        if (wlsCapacityCommand(
            $candidate,
            $common,
            'inspect',
            ['--release-reason=cancel'],
        )['code'] !== 64) {
            throw new \RuntimeException('Windows inspect accepted forbidden release arguments.');
        }
        $heldRoot = $capacity . '\\' . $nonce . '.held';
        $bytes = $heldRoot . '\\bytes.reserve';
        if (\filesize($bytes) !== 8_388_608) {
            throw new \RuntimeException('Windows byte reserve has the wrong logical size.');
        }
        $tokenNames = \array_values(\array_diff(
            \scandir($heldRoot . '\\tokens') ?: [],
            ['.', '..'],
        ));
        \sort($tokenNames, SORT_STRING);
        if (\count($tokenNames) !== 128) {
            throw new \RuntimeException('Windows inode reserve did not create 128 tokens.');
        }
        foreach ($tokenNames as $index => $leaf) {
            if ($leaf !== \sprintf('%08x.reserve', $index)
                || \filesize($heldRoot . '\\tokens\\' . $leaf) !== 0
            ) {
                throw new \RuntimeException('Windows inode token set is not canonical.');
            }
        }
        $manifest = $capacity . '\\' . $nonce . '.held.json';
        wlsWriteExclusive(
            $manifest,
            '{"nonce":"' . $nonce . '","state":"HELD"}' . "\n",
        );
        $manifestHash = \hash_file('sha256', $manifest);
        if (!\is_string($manifestHash)) {
            throw new \RuntimeException('Unable to hash Windows capacity manifest.');
        }
        $binding = '--expected-manifest-sha256=' . $manifestHash;
        $wrong = wlsCapacityCommand(
            $candidate,
            $common,
            'verify',
            ['--expected-manifest-sha256=' . \str_repeat('a', 64)],
        );
        if ($wrong['code'] === 0) {
            throw new \RuntimeException('Windows reserve accepted the wrong manifest binding.');
        }
        $verified = wlsCapacityJson(
            wlsCapacityCommand($candidate, $common, 'verify', [$binding]),
            'Windows capacity verify',
        );
        if ($verified !== $held) {
            throw new \RuntimeException('Windows capacity evidence changed after verification.');
        }
        $direct = wlsCapacityCommand(
            $candidate,
            $common,
            'complete-release',
            ['--release-reason=cancel', $binding],
        );
        if ($direct['code'] === 0 || !\is_dir($heldRoot)) {
            throw new \RuntimeException('Windows HELD reserve bypassed begin-release.');
        }
        $control = \fopen($heldRoot . '\\control.reserve', 'r+b');
        if (!\is_resource($control)
            || \fwrite($control, 'WLS-CAPACITY-REL') !== \strlen('WLS-CAPACITY-REL')
            || !\fflush($control)
        ) {
            if (\is_resource($control)) {
                \fclose($control);
            }
            throw new \RuntimeException('Unable to stage a torn Windows release marker.');
        }
        if (\function_exists('fsync') && !\fsync($control)) {
            \fclose($control);
            throw new \RuntimeException('Unable to flush a torn Windows release marker.');
        }
        if (!\fclose($control)) {
            throw new \RuntimeException('Unable to close a torn Windows release marker.');
        }
        if (wlsCapacityJson(
            wlsCapacityCommand($candidate, $common, 'verify', [$binding]),
            'Windows torn-marker capacity verify',
        ) !== $held) {
            throw new \RuntimeException('Windows torn release marker was not replayable.');
        }
        $release = ['--release-reason=forward', $binding];
        $releasing = wlsCapacityJson(
            wlsCapacityCommand($candidate, $common, 'begin-release', $release),
            'Windows begin-release',
        );
        $releasingRoot = $capacity . '\\' . $nonce . '.releasing';
        if (($releasing['state'] ?? null) !== 'RELEASING'
            || ($releasing['entry_set_sha256'] ?? null)
                !== ($held['entry_set_sha256'] ?? null)
            || \is_file($releasingRoot . '\\control.reserve')
            || \is_dir($releasingRoot . '\\control-tokens')
        ) {
            throw new \RuntimeException('Windows release transition lost its durable contract.');
        }
        \clearstatcache(true, $directPrefix . '0');
        \clearstatcache(true, $directPrefix . '1');
        if (wlsCapacityInspect($candidate, $common) !== [
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'RELEASING',
        ] || \file_exists($directPrefix . '0')
            || \file_exists($directPrefix . '1')) {
            throw new \RuntimeException(
                'Windows RELEASING inspect/direct-credit contract drifted.',
            );
        }
        if (wlsCapacityCommand($candidate, $common, 'verify', [$binding])['code'] === 0) {
            throw new \RuntimeException('Windows RELEASING reserve was accepted as HELD.');
        }
        if (wlsCapacityJson(
            wlsCapacityCommand($candidate, $common, 'begin-release', $release),
            'Windows begin-release replay',
        ) !== $releasing) {
            throw new \RuntimeException('Windows begin-release replay changed evidence.');
        }
        foreach ([1, 2] as $attempt) {
            $released = wlsCapacityJson(
                wlsCapacityCommand($candidate, $common, 'complete-release', $release),
                'Windows complete-release #' . $attempt,
            );
            if ($released !== ['state' => 'RELEASED']) {
                throw new \RuntimeException('Windows release did not reach RELEASED.');
            }
        }
        if (wlsCapacityInspect($candidate, $common) !== [
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'NONE',
        ]) {
            throw new \RuntimeException('Windows released inspect did not converge to NONE.');
        }

        $tamperNonce = 'fedcba9876543210fedcba9876543210';
        $tamperCandidate = wlsCapacityCandidate(
            $root,
            $sourceLauncher,
            $tamperNonce,
        );
        $tamperCommon = $commonFor($tamperNonce);
        wlsCapacityJson(
            wlsCapacityCommand($tamperCandidate, $tamperCommon, 'create'),
            'Windows tamper reserve create',
        );
        $tamperManifest = $capacity . '\\' . $tamperNonce . '.held.json';
        wlsWriteExclusive(
            $tamperManifest,
            '{"nonce":"' . $tamperNonce . '","state":"HELD"}' . "\n",
        );
        $tamperHash = \hash_file('sha256', $tamperManifest);
        if (!\is_string($tamperHash)) {
            throw new \RuntimeException('Unable to hash tamper manifest.');
        }
        $tamperBinding = '--expected-manifest-sha256=' . $tamperHash;
        $tamperHeld = $capacity . '\\' . $tamperNonce . '.held';
        $missingToken = $tamperHeld . '\\control-tokens\\00000000.reserve';
        if (!\unlink($missingToken)
            || wlsCapacityCommand(
                $tamperCandidate,
                $tamperCommon,
                'verify',
                [$tamperBinding],
            )['code'] === 0
            || wlsCapacityCommand(
                $tamperCandidate,
                $tamperCommon,
                'begin-release',
                ['--release-reason=cancel', $tamperBinding],
            )['code'] === 0
        ) {
            throw new \RuntimeException('Partial Windows control credits failed open.');
        }
        wlsWriteExclusive($missingToken, '');
        if (wlsCapacityCommand(
            $tamperCandidate,
            $tamperCommon,
            'verify',
            [$tamperBinding],
        )['code'] === 0) {
            throw new \RuntimeException(
                'Inherited-ACL replacement token was accepted as authoritative.',
            );
        }

        $controlNonce = 'cccccccccccccccccccccccccccccccc';
        $controlCandidate = wlsCapacityCandidate(
            $root,
            $sourceLauncher,
            $controlNonce,
        );
        $controlCommon = $commonFor($controlNonce);
        wlsCapacityJson(
            wlsCapacityCommand($controlCandidate, $controlCommon, 'create'),
            'Windows control-reserve tamper create',
        );
        $controlManifest = $capacity . '\\' . $controlNonce . '.held.json';
        wlsWriteExclusive(
            $controlManifest,
            '{"nonce":"' . $controlNonce . '","state":"HELD"}' . "\n",
        );
        $controlHash = \hash_file('sha256', $controlManifest);
        if (!\is_string($controlHash)) {
            throw new \RuntimeException('Unable to hash control-reserve manifest.');
        }
        $controlBinding = '--expected-manifest-sha256=' . $controlHash;
        $controlHeld = $capacity . '\\' . $controlNonce . '.held';
        if (!\unlink($controlHeld . '\\control.reserve')
            || wlsCapacityCommand(
                $controlCandidate,
                $controlCommon,
                'begin-release',
                ['--release-reason=cancel', $controlBinding],
            )['code'] === 0
            || wlsCapacityCommand(
                $controlCandidate,
                $controlCommon,
                'complete-release',
                ['--release-reason=cancel', $controlBinding],
            )['code'] === 0
        ) {
            throw new \RuntimeException('Missing Windows control reserve failed open.');
        }

        $primaryTamperNonce = 'dddddddddddddddddddddddddddddddd';
        $primaryTamperCandidate = wlsCapacityCandidate(
            $root,
            $sourceLauncher,
            $primaryTamperNonce,
        );
        $primaryTamperCommon = $commonFor($primaryTamperNonce);
        wlsCapacityJson(
            wlsCapacityCommand(
                $primaryTamperCandidate,
                $primaryTamperCommon,
                'create',
            ),
            'Windows primary-token tamper create',
        );
        $primaryTamperManifest = $capacity . '\\'
            . $primaryTamperNonce . '.held.json';
        wlsWriteExclusive(
            $primaryTamperManifest,
            '{"nonce":"' . $primaryTamperNonce . '","state":"HELD"}' . "\n",
        );
        $primaryTamperHash = \hash_file('sha256', $primaryTamperManifest);
        if (!\is_string($primaryTamperHash)) {
            throw new \RuntimeException('Unable to hash primary-token manifest.');
        }
        $primaryTamperHeld = $capacity . '\\' . $primaryTamperNonce . '.held';
        if (!\unlink($primaryTamperHeld . '\\tokens\\0000007f.reserve')
            || wlsCapacityCommand(
                $primaryTamperCandidate,
                $primaryTamperCommon,
                'complete-release',
                [
                    '--release-reason=cancel',
                    '--expected-manifest-sha256=' . $primaryTamperHash,
                ],
            )['code'] === 0
            || !\is_dir($primaryTamperHeld)) {
            throw new \RuntimeException(
                'Malformed HELD primary tokens were downgraded to removable state.',
            );
        }

        $allocatingNonce = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $allocatingCandidate = wlsCapacityCandidate(
            $root,
            $sourceLauncher,
            $allocatingNonce,
        );
        $allocating = $capacity . '\\' . $allocatingNonce . '.allocating';
        wlsCapacityKillAtFailpoint(
            $allocatingCandidate,
            $commonFor($allocatingNonce),
            'create',
            [],
            $definition,
            $allocatingNonce,
            'token-directory',
            30.0,
        );
        if (wlsCapacityInspect(
            $allocatingCandidate,
            $commonFor($allocatingNonce),
        ) !== [
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'ALLOCATING',
        ]) {
            throw new \RuntimeException('Windows ALLOCATING inspect contract drifted.');
        }
        $cancelled = wlsCapacityJson(
            wlsCapacityCommand(
                $allocatingCandidate,
                $commonFor($allocatingNonce),
                'complete-release',
                ['--release-reason=cancel'],
            ),
            'Windows allocating cancellation',
        );
        if ($cancelled !== ['state' => 'RELEASED'] || \is_dir($allocating)) {
            throw new \RuntimeException('Windows ALLOCATING cancellation did not converge.');
        }
        if (wlsCapacityInspect(
            $allocatingCandidate,
            $commonFor($allocatingNonce),
        ) !== [
            'schema' => 'wls-capacity-inspect/1',
            'state' => 'NONE',
        ]) {
            throw new \RuntimeException('Windows ALLOCATING cleanup did not inspect as NONE.');
        }

        echo \json_encode([
            'physical_bytes' => $held['physical_bytes'],
            'inode_count' => $held['inode_count'],
            'manifest_binding_rejected' => true,
            'derived_root_junction_rejected' => true,
            'release_replay' => true,
            'partial_control_rejected' => true,
            'allocating_cancelled' => true,
            'inspect_states' => ['NONE', 'ALLOCATING', 'HELD', 'RELEASING'],
            'definition_parent_direct_credits' => 2,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (\Throwable $exception) {
        $failure = $exception;
    } finally {
        if ($created) {
            try {
                wlsRemoveCapacityTree($root);
            } catch (\Throwable $cleanup) {
                if ($failure !== null) {
                    throw new \RuntimeException(
                        $failure->getMessage() . ' Cleanup: ' . $cleanup->getMessage(),
                        0,
                        $failure,
                    );
                }
                throw $cleanup;
            }
        }
    }
    if ($failure !== null) {
        throw $failure;
    }
}

/**
 * Dedicated lab-only production-shape capacity gate.  This deliberately uses
 * the native ProgramData authority and exact production reservation rather
 * than a temporary directory or the reduced test-mode fixture.
 */
function wlsWindowsProductionCapacityTest(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new \RuntimeException('The production capacity gate requires Windows.');
    }
    if ((string)\getenv(
        'WLS_RUN_NATIVE_GATEWAY_WINDOWS_PRODUCTION_CAPACITY_INTEGRATION',
    ) !== '1') {
        throw new \RuntimeException(
            'Set WLS_RUN_NATIVE_GATEWAY_WINDOWS_PRODUCTION_CAPACITY_INTEGRATION=1 explicitly.',
        );
    }
    $launcher = \realpath(wlsOption($arguments, 'launcher'));
    $home = \realpath(wlsOption($arguments, 'home'));
    $definition = \realpath(wlsOption($arguments, 'platform-definition'));
    if (!\is_string($launcher) || !\is_file($launcher)
        || !\is_string($home) || !\is_dir($home)
        || !\is_string($definition) || !\is_file($definition)) {
        throw new \RuntimeException(
            'Production capacity gate requires existing launcher, ProgramData home, and platform definition.',
        );
    }
    $authority = wlsCapacityJson(
        wlsRun([$launcher, '--programdata-authority'], 30.0),
        'Windows ProgramData authority proof',
    );
    if (($authority['authority'] ?? null) !== 'FOLDERID_ProgramData'
        || ($authority['ready'] ?? null) !== true
        || !\is_string($authority['home'] ?? null)
        || \strcasecmp(
            \str_replace('/', '\\', $home),
            \str_replace('/', '\\', $authority['home']),
        ) !== 0) {
        throw new \RuntimeException(
            'Production capacity gate home is not the native ProgramData authority.',
        );
    }

    $matrix = [];
    $lastHeld = null;
    wlsCapacityRecoverDurableMarker($home, $definition);
    try {
        foreach (
            [
                'allocation',
                'token-directory',
                'token-batch',
                'direct-seal',
                'rename',
                'begin',
                'control-token-partial',
                'release',
                'primary-token-partial',
            ]
            as $failpoint
        ) {
            wlsCapacityRecoverDurableMarker($home, $definition);
            $nonce = \bin2hex(\random_bytes(16));
            $candidate = wlsCapacityCandidate($home, $launcher, $nonce);
            $common = [
                '--home=' . $home,
                '--nonce=' . $nonce,
                '--bytes=10737418240',
                '--inodes=65536',
                '--platform-definition=' . $definition,
                '--test-mode=0',
            ];
            wlsCapacityWriteProductionMarker($home, $nonce, $definition);
            $operation = 'create';
            $extra = [];
            if (in_array($failpoint, [
                'begin',
                'control-token-partial',
                'release',
                'primary-token-partial',
            ], true)) {
                $held = wlsCapacityJson(
                    wlsCapacityCommand(
                        $candidate,
                        $common,
                        'create',
                        [],
                        3600.0,
                    ),
                    'Windows production capacity create before ' . $failpoint,
                );
                if (($held['state'] ?? null) !== 'HELD'
                    || ($held['inode_count'] ?? null) !== 65_536
                    || !\is_int($held['physical_bytes'] ?? null)
                    || $held['physical_bytes'] < 10_737_418_240) {
                    throw new \RuntimeException(
                        'Windows production capacity gate did not reserve 10GiB/65536 inodes.',
                    );
                }
                $lastHeld = $held;
                $manifest = $home . '\\rebootstrap\\capacity\\'
                    . $nonce . '.held.json';
                wlsCapacityWriteDurableExclusive(
                    $manifest,
                    '{"nonce":"' . $nonce . '","state":"HELD"}' . "\n",
                );
                $digest = \hash_file('sha256', $manifest);
                if (!\is_string($digest)) {
                    throw new \RuntimeException(
                        'Unable to bind production crash manifest.',
                    );
                }
                $operation = 'begin-release';
                $extra = [
                    '--release-reason=cancel',
                    '--expected-manifest-sha256=' . $digest,
                ];
                if ($failpoint === 'primary-token-partial') {
                    wlsCapacityJson(
                        wlsCapacityCommand(
                            $candidate,
                            $common,
                            'begin-release',
                            $extra,
                            3600.0,
                        ),
                        'Windows production begin before primary-token crash',
                    );
                    $operation = 'complete-release';
                }
            }
            wlsCapacityKillAtFailpoint(
                $candidate,
                $common,
                $operation,
                $extra,
                $definition,
                $nonce,
                $failpoint,
            );
            $inspect = wlsCapacityInspect($candidate, $common);
            $expected = match ($failpoint) {
                'allocation', 'token-directory', 'token-batch', 'direct-seal'
                    => 'ALLOCATING',
                'rename' => 'HELD',
                'begin', 'control-token-partial', 'release',
                'primary-token-partial' => 'RELEASING',
            };
            if (($inspect['state'] ?? null) !== $expected) {
                throw new \RuntimeException(
                    'Production crash failpoint did not publish expected durable state.',
                );
            }
            $matrix[$failpoint] = $expected;
            wlsCapacityRecoverDurableMarker($home, $definition);
        }
        echo \json_encode([
            'physical_bytes' => $lastHeld['physical_bytes'] ?? null,
            'inode_count' => $lastHeld['inode_count'] ?? null,
            'crash_matrix' => $matrix,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } finally {
        wlsCapacityRecoverDurableMarker($home, $definition);
    }
}

$mode = $argv[1] ?? '';
try {
    if ($mode === 'keygen') {
        wlsKeygen(\array_slice($argv, 2));
    } elseif ($mode === 'path-security-test') {
        wlsPathSecurityTest(\array_slice($argv, 2));
    } elseif ($mode === 'bounded-command-test') {
        wlsBoundedCommandTest(\array_slice($argv, 2));
    } elseif ($mode === 'capacity-test') {
        wlsWindowsCapacityTest(\array_slice($argv, 2));
    } elseif ($mode === 'capacity-production-test') {
        wlsWindowsProductionCapacityTest(\array_slice($argv, 2));
    } else {
        throw new \InvalidArgumentException(
            'Usage: keygen --output=<file> | path-security-test --broker=<file> | '
                . 'bounded-command-test --helper=<file> | '
                . 'capacity-test --launcher=<file> | '
                . 'capacity-production-test --launcher=<file> --home=<ProgramData home> '
                . '--platform-definition=<file>',
        );
    }
} catch (\Throwable $exception) {
    \fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
