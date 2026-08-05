<?php

declare(strict_types=1);

const WLS_WINDOWS_TEST_SERVICE = 'weline-wls-gateway-v2';

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

/** @return array{code:int,output:string} */
function wlsSc(array $arguments, float $timeoutSeconds = 20.0): array
{
    $systemRoot = (string)\getenv('SystemRoot');
    if ($systemRoot === '') {
        throw new \RuntimeException('SystemRoot is unavailable.');
    }
    return wlsRun(
        [$systemRoot . '\\System32\\sc.exe', ...$arguments],
        $timeoutSeconds,
    );
}

function wlsCheckedSc(array $arguments, float $timeoutSeconds = 20.0): string
{
    $result = wlsSc($arguments, $timeoutSeconds);
    if ($result['code'] !== 0) {
        throw new \RuntimeException(
            'sc.exe failed (' . $result['code'] . '): '
                . \implode(' ', $arguments) . "\n" . $result['output'],
        );
    }
    return $result['output'];
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
        '/\Aweline-wls2-ci-(?:path|scm)-[a-f0-9]{16}\z/D',
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

function wlsServiceState(): ?int
{
    $result = wlsSc(['queryex', WLS_WINDOWS_TEST_SERVICE]);
    if ($result['code'] !== 0) {
        return \str_contains($result['output'], '1060') ? null : -1;
    }
    return \preg_match('/STATE\s*:\s*([0-9]+)/i', $result['output'], $matches) === 1
        ? (int)$matches[1]
        : -1;
}

function wlsWaitServiceState(int $expected, float $timeoutSeconds): string
{
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    do {
        $result = wlsSc(['queryex', WLS_WINDOWS_TEST_SERVICE]);
        if ($result['code'] === 0
            && \preg_match('/STATE\s*:\s*([0-9]+)/i', $result['output'], $matches) === 1
            && (int)$matches[1] === $expected) {
            return $result['output'];
        }
        \usleep(100_000);
    } while (\hrtime(true) < $deadline);
    throw new \RuntimeException(
        'Service did not reach state ' . $expected . '. Last result: ' . $result['output'],
    );
}

function wlsWaitServiceDeleted(float $timeoutSeconds): void
{
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    do {
        if (wlsServiceState() === null) {
            return;
        }
        \usleep(100_000);
    } while (\hrtime(true) < $deadline);
    throw new \RuntimeException('Temporary Windows service was not deleted.');
}

function wlsStartCount(string $marker): int
{
    if (!\is_file($marker)) {
        return 0;
    }
    $lines = \file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return \is_array($lines) ? \count($lines) : 0;
}

function wlsWaitStarts(string $marker, int $minimum, float $timeoutSeconds): int
{
    $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
    do {
        $count = wlsStartCount($marker);
        if ($count >= $minimum) {
            return $count;
        }
        \usleep(100_000);
    } while (\hrtime(true) < $deadline);
    throw new \RuntimeException(
        'Broker start count did not reach ' . $minimum . '; last count=' . $count,
    );
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

function wlsPrepareHome(
    string $root,
    string $launcher,
    string $testBroker,
    string $secretKey,
): array
{
    $home = $root . '\\home';
    $run = $root . '\\run';
    $slot = $home . '\\slots\\A';
    $release = $slot . '\\release';
    $bin = $slot . '\\bin';
    $app = $slot . '\\app';
    $launcherBin = $root . '\\bin';
    $directories = [
        $home . '\\state',
        $home . '\\trust',
        $run,
        $release,
        $bin,
        $app,
        $launcherBin,
    ];
    foreach ($directories as $directory) {
        wlsMkdir($directory);
    }
    $installedLauncher = $launcherBin . '\\wls-gateway-launcher.exe';
    wlsCopy($launcher, $installedLauncher);
    foreach (\glob(\dirname($launcher) . '\\*.dll') ?: [] as $dll) {
        wlsCopy($dll, $launcherBin . '\\' . \basename($dll));
    }
    $componentFiles = [
        'bin/wls-gateway-broker.exe' => $testBroker,
        'bin/php.exe' => PHP_BINARY,
        'bin/nginx.exe' => $testBroker,
    ];
    foreach ($componentFiles as $relative => $source) {
        wlsCopy($source, $slot . '\\' . \str_replace('/', '\\', $relative));
    }
    wlsWrite($app . '\\controller.php', "<?php\n");
    $componentFiles['app/controller.php'] = $app . '\\controller.php';
    $components = [];
    foreach ($componentFiles as $relative => $source) {
        $installed = $slot . '\\' . \str_replace('/', '\\', $relative);
        $digest = \hash_file('sha256', $installed);
        $size = \filesize($installed);
        if (!\is_string($digest) || !\is_int($size)) {
            throw new \RuntimeException('Unable to inspect component ' . $installed);
        }
        $components[$relative] = ['sha256' => $digest, 'size' => $size, 'mode' => 0550];
    }
    $manifest = \json_encode([
        'schema_version' => 2,
        'version' => '2.0.0-windows-scm-test',
        'components' => $components,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    wlsWrite($release . '\\manifest.json', $manifest);
    wlsWrite(
        $release . '\\manifest.sig',
        \base64_encode(\sodium_crypto_sign_detached($manifest, $secretKey)) . PHP_EOL,
    );
    wlsWrite(
        $slot . '\\manifest.json',
        \json_encode([
            'schema_version' => 1,
            'role' => 'host_gateway',
            'runtime_generation' => \str_repeat('a', 64),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
    wlsWrite($home . '\\trust\\active-slot', "A\n");
    return [
        'home' => $home,
        'run' => $run,
        'launcher' => $installedLauncher,
        'marker' => $home . '\\state\\test-starts.log',
        'hold' => $home . '\\state\\test-hold',
    ];
}

function wlsServiceTest(array $arguments): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new \RuntimeException('The SCM integration test requires Windows.');
    }
    if ((string)\getenv('WLS_RUN_NATIVE_GATEWAY_WINDOWS_SERVICE_INTEGRATION') !== '1') {
        throw new \RuntimeException(
            'Set WLS_RUN_NATIVE_GATEWAY_WINDOWS_SERVICE_INTEGRATION=1 explicitly.',
        );
    }
    $keyFile = wlsOption($arguments, 'key-file');
    $launcher = wlsOption($arguments, 'launcher');
    $testBroker = wlsOption($arguments, 'test-broker');
    $secret = null;
    $root = '';
    $rootCreated = false;
    $createAttempted = false;
    $created = false;
    $serviceReferenceReleased = true;
    $fixture = [];
    $failure = null;
    try {
        foreach ([$keyFile, $launcher, $testBroker] as $required) {
            if (!\is_file($required)) {
                throw new \RuntimeException('Required fixture file is missing: ' . $required);
            }
        }
        $existing = wlsSc(['query', WLS_WINDOWS_TEST_SERVICE]);
        if ($existing['code'] === 0 || !\str_contains($existing['output'], '1060')) {
            throw new \RuntimeException(
                'Refusing to replace an existing or indeterminate service: ' . $existing['output'],
            );
        }
        $keyPayload = \file_get_contents($keyFile);
        if (!\is_string($keyPayload)) {
            throw new \RuntimeException('Unable to read the ephemeral signing key.');
        }
        try {
            $key = \json_decode($keyPayload, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            \sodium_memzero($keyPayload);
        }
        if (!\is_array($key)) {
            throw new \RuntimeException('The ephemeral signing key payload is invalid.');
        }
        $secret = \base64_decode((string)($key['secret_key_base64'] ?? ''), true);
        if (\is_string($key['secret_key_base64'] ?? null)) {
            \sodium_memzero($key['secret_key_base64']);
        }
        $public = (string)($key['public_key_hex'] ?? '');
        if (!\is_string($secret)
            || \strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES
            || !\preg_match('/\A[0-9a-f]{64}\z/D', $public)
            || !\hash_equals(
                $public,
                \bin2hex(\sodium_crypto_sign_publickey_from_secretkey($secret)),
            )) {
            throw new \RuntimeException('The ephemeral signing key is invalid.');
        }
        $programData = (string)\getenv('ProgramData');
        if ($programData === '') {
            throw new \RuntimeException('ProgramData is unavailable.');
        }
        wlsAssertPlainDirectory($programData);
        $root = $programData . '\\weline-wls2-ci-scm-' . \bin2hex(\random_bytes(8));
        wlsMkdirExclusive($root);
        $rootCreated = true;
        wlsRestrictFixtureRoot($root);
        $fixture = wlsPrepareHome($root, $launcher, $testBroker, $secret);
        $binaryPath = '"' . $fixture['launcher'] . '" --service --home "'
            . $fixture['home'] . '" --run "' . $fixture['run'] . '" --profile=default';
        $createAttempted = true;
        $serviceReferenceReleased = false;
        wlsCheckedSc([
            'create',
            WLS_WINDOWS_TEST_SERVICE,
            'binPath=',
            $binaryPath,
            'start=',
            'demand',
            'obj=',
            'LocalSystem',
            'DisplayName=',
            'Weline WLS Gateway v2 CI',
        ]);
        $created = true;
        wlsCheckedSc(['sidtype', WLS_WINDOWS_TEST_SERVICE, 'unrestricted']);
        wlsCheckedSc([
            'failure',
            WLS_WINDOWS_TEST_SERVICE,
            'reset=',
            '0',
            'actions=',
            'restart/1000/restart/1000/restart/1000',
        ]);
        wlsCheckedSc(['failureflag', WLS_WINDOWS_TEST_SERVICE, '1']);
        $icacls = wlsWindowsTool('icacls.exe');
        wlsChecked([
            $icacls,
            $root,
            '/grant:r',
            'NT SERVICE\\' . WLS_WINDOWS_TEST_SERVICE . ':(OI)(CI)(RX)',
            '/T',
            '/C',
            '/Q',
        ]);
        foreach ([$fixture['home'] . '\\state', $fixture['run']] as $mutable) {
            wlsChecked([
                $icacls,
                $mutable,
                '/grant:r',
                'NT SERVICE\\' . WLS_WINDOWS_TEST_SERVICE . ':(OI)(CI)(M)',
                '/T',
                '/C',
                '/Q',
            ]);
        }
        wlsWrite($fixture['hold'], "hold\n");
        wlsCheckedSc(['start', WLS_WINDOWS_TEST_SERVICE]);
        wlsWaitStarts($fixture['marker'], 2, 30.0);
        wlsWaitServiceState(4, 30.0);
        $beforeStop = wlsStartCount($fixture['marker']);
        wlsCheckedSc(['stop', WLS_WINDOWS_TEST_SERVICE]);
        $stopped = wlsWaitServiceState(1, 30.0);
        if (\preg_match('/WIN32_EXIT_CODE\s*:\s*([0-9]+)/i', $stopped, $win32) !== 1
            || \preg_match('/SERVICE_EXIT_CODE\s*:\s*([0-9]+)/i', $stopped, $specific) !== 1
            || (int)$win32[1] !== 0
            || (int)$specific[1] !== 0) {
            throw new \RuntimeException('Explicit stop did not report success: ' . $stopped);
        }
        \usleep(2_500_000);
        if (wlsServiceState() !== 1 || wlsStartCount($fixture['marker']) !== $beforeStop) {
            throw new \RuntimeException('Explicit SCM stop incorrectly triggered recovery.');
        }
        echo \json_encode([
            'service' => WLS_WINDOWS_TEST_SERVICE,
            'unexpected_clean_exit_restarted' => true,
            'explicit_stop_remained_stopped' => true,
            'broker_starts' => $beforeStop,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (\Throwable $exception) {
        $failure = $exception;
    } finally {
        if (\is_string($secret)) {
            \sodium_memzero($secret);
        }
        $cleanupErrors = [];
        if (!$created && $createAttempted && isset($fixture['launcher'])) {
            try {
                $definition = wlsSc(['qc', WLS_WINDOWS_TEST_SERVICE], 10.0);
                $created = $definition['code'] === 0
                    && \str_contains(
                        \strtolower($definition['output']),
                        \strtolower((string)$fixture['launcher']),
                    );
                if ($definition['code'] !== 0
                    && !\str_contains($definition['output'], '1060')
                ) {
                    throw new \RuntimeException(
                        'Temporary Windows service ownership is indeterminate: '
                        . $definition['output'],
                    );
                }
                if ($definition['code'] === 0 && !$created) {
                    throw new \RuntimeException(
                        'The temporary service name now belongs to another executable; '
                        . 'the fixture directory will be retained.',
                    );
                }
                if ($definition['code'] !== 0) {
                    $serviceReferenceReleased = true;
                }
            } catch (\Throwable $cleanupError) {
                $cleanupErrors[] = 'ownership: ' . $cleanupError->getMessage();
            }
        }
        if ($created) {
            if (isset($fixture['hold']) && \is_string($fixture['hold'])) {
                @\file_put_contents($fixture['hold'], "hold\n", LOCK_EX);
            }
            try {
                $state = wlsServiceState();
                if ($state !== null && $state !== 1) {
                    wlsSc(['stop', WLS_WINDOWS_TEST_SERVICE], 10.0);
                    wlsWaitServiceState(1, 15.0);
                }
            } catch (\Throwable $cleanupError) {
                $cleanupErrors[] = 'stop: ' . $cleanupError->getMessage();
            }
            try {
                $deleted = wlsSc(['delete', WLS_WINDOWS_TEST_SERVICE], 10.0);
                if ($deleted['code'] !== 0) {
                    throw new \RuntimeException(
                        'Temporary Windows service deletion failed: ' . $deleted['output'],
                    );
                }
                wlsWaitServiceDeleted(15.0);
                $serviceReferenceReleased = true;
            } catch (\Throwable $cleanupError) {
                $cleanupErrors[] = 'delete: ' . $cleanupError->getMessage();
            }
        }
        if ($rootCreated) {
            if ($serviceReferenceReleased) {
                wlsRemoveTree($root);
                if (\is_dir($root)) {
                    $cleanupErrors[] = 'Temporary Windows fixture directory was not removed.';
                }
            } else {
                $cleanupErrors[] = 'Temporary Windows fixture was retained because SCM may still reference it: '
                    . $root;
            }
        }
        if ($cleanupErrors !== []) {
            $message = \implode(' ', $cleanupErrors);
            if ($failure !== null) {
                $message = $failure->getMessage() . ' Cleanup: ' . $message;
            }
            throw new \RuntimeException($message, 0, $failure);
        }
    }
    if ($failure !== null) {
        throw $failure;
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
    } elseif ($mode === 'service-test') {
        wlsServiceTest(\array_slice($argv, 2));
    } else {
        throw new \InvalidArgumentException(
            'Usage: keygen --output=<file> | path-security-test --broker=<file> | '
                . 'bounded-command-test --helper=<file> | '
                . 'service-test --key-file=<file> --launcher=<file> --test-broker=<file>',
        );
    }
} catch (\Throwable $exception) {
    \fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
