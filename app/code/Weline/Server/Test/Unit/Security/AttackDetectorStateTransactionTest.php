<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\AttackDetector;
use Weline\Server\Service\Security\SecurityPolicyStateStore;

final class AttackDetectorStateTransactionTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-attack-state-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        if (!\is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                @\unlink($path);
            } elseif ($entry->isDir()) {
                @\rmdir($path);
            }
        }
        @\rmdir($this->root);
    }

    public function testRulesSymlinkFailureLeavesForeignFileAndMemoryUnchanged(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link state validation is covered on POSIX.');
        }

        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$detector = \Weline\Server\Security\AttackDetector::getInstance();
$foreign = BP . 'foreign.json';
file_put_contents($foreign, 'foreign-state');
symlink($foreign, \Weline\Server\Security\AttackDetector::getRulesFilePath());
$error = '';
try {
    $detector->updateRules([
        'ip_whitelist' => [
            'enabled' => true,
            'ips' => ['198.51.100.10'],
        ],
    ]);
} catch (\Throwable $throwable) {
    $error = $throwable->getMessage();
}
$result = [
    'error' => $error,
    'foreign' => file_get_contents($foreign),
    'whitelisted' => $detector->isWhitelisted('198.51.100.10'),
];
PHP);

        self::assertNotSame('', $result['error'] ?? '');
        self::assertSame('foreign-state', $result['foreign'] ?? null);
        self::assertFalse($result['whitelisted'] ?? true);
    }

    public function testExplicitStaleRulesReceiptCannotOverwriteNewerRulesInSameProcess(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $base = $store->writeRules([
            'rate_limit' => ['enabled' => true, 'max_requests' => 100],
        ]);
        $detector = new AttackDetector($store, null, static function (): void {
        });

        $committed = $detector->updateRules(
            ['rate_limit' => ['enabled' => true, 'max_requests' => 200]],
            $base['generation'],
            $base['digest'],
        );
        self::assertSame(200, $committed['rules']['rate_limit']['max_requests'] ?? null);

        try {
            $detector->updateRules(
                ['rate_limit' => ['enabled' => true, 'max_requests' => 300]],
                $base['generation'],
                $base['digest'],
            );
            self::fail('A stale browser receipt overwrote a newer rules generation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('conflict', \strtolower($exception->getMessage()));
        }

        self::assertSame(200, $detector->getRules()['rate_limit']['max_requests'] ?? null);
        self::assertSame(200, $store->readRulesState()['rules']['rate_limit']['max_requests'] ?? null);
    }

    public function testPostCommitNotificationFailureReturnsCommittedReceipt(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $detector = new AttackDetector(
            $store,
            null,
            static function (): void {
                throw new \RuntimeException('notification transport unavailable');
            },
        );

        $receipt = $detector->updateRules(
            ['rate_limit' => ['enabled' => true, 'max_requests' => 321]],
            0,
            '',
        );

        self::assertSame(1, $receipt['generation'] ?? null);
        self::assertTrue($receipt['notification_pending'] ?? false);
        self::assertStringContainsString(
            'notification transport unavailable',
            (string)($receipt['notification_error'] ?? ''),
        );
        self::assertSame(321, $detector->getRules()['rate_limit']['max_requests'] ?? null);
        self::assertSame(321, $store->readRulesState()['rules']['rate_limit']['max_requests'] ?? null);
    }

    public function testRulesReloadUsesCommittedDigestWithoutLegacyFlag(): void
    {
        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$path = \Weline\Server\Security\AttackDetector::getRulesFilePath();
file_put_contents($path, json_encode(['rate_limit' => ['max_requests' => 10]]));
$detector = \Weline\Server\Security\AttackDetector::getInstance();
$before = $detector->getRules()['rate_limit']['max_requests'] ?? null;
$receipt = $detector->getRulesReceipt();
(new \Weline\Server\Service\Security\SecurityPolicyStateStore())->writeRules([
    'rate_limit' => ['max_requests' => 20],
], $receipt);
$after = $detector->getRules()['rate_limit']['max_requests'] ?? null;
$result = ['before' => $before, 'after' => $after];
PHP);

        self::assertSame(10, $result['before'] ?? null);
        self::assertSame(20, $result['after'] ?? null);
    }

    public function testPermanentBanWriteFailureDoesNotChangeMemoryOrForeignFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link state validation is covered on POSIX.');
        }

        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$detector = \Weline\Server\Security\AttackDetector::getInstance();
$foreign = BP . 'foreign-bans.json';
file_put_contents($foreign, json_encode(['ips' => []]));
symlink($foreign, \Weline\Server\Security\AttackDetector::getPermanentBannedFilePath());
$error = '';
try {
    $detector->detect('198.51.100.11', '/.env');
} catch (\Throwable $throwable) {
    $error = $throwable->getMessage();
}
$blocked = $detector->getBlockedIps();
$result = [
    'error' => $error,
    'foreign' => file_get_contents($foreign),
    'blocked' => isset($blocked['198.51.100.11']),
];
PHP);

        self::assertNotSame('', $result['error'] ?? '');
        self::assertSame('{"ips":[]}', $result['foreign'] ?? null);
        self::assertFalse($result['blocked'] ?? true);
    }

    public function testRuntimeCompilerRestoresOneValidRulesBackup(): void
    {
        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$path = \Weline\Server\Security\AttackDetector::getRulesFilePath();
file_put_contents($path, '{broken');
chmod($path, 0600);
$backup = $path . '.wls-backup-' . str_repeat('a', 16);
file_put_contents($backup, json_encode(['rate_limit' => ['max_requests' => 123]]));
chmod($backup, 0600);
$compiler = new \Weline\Server\Service\Policy\RuntimePolicyCompiler();
$method = new \ReflectionMethod($compiler, 'loadAttackRules');
$rules = $method->invoke($compiler);
$result = [
    'max_requests' => $rules['rate_limit']['max_requests'] ?? null,
    'backup_exists' => file_exists($backup),
    'target' => json_decode((string)file_get_contents($path), true),
];
PHP);

        self::assertSame(123, $result['max_requests'] ?? null);
        self::assertFalse($result['backup_exists'] ?? true);
        self::assertIsArray($result['target'] ?? null);
    }

    public function testRuntimeCompilerFailsClosedOnAmbiguousRulesBackups(): void
    {
        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$path = \Weline\Server\Security\AttackDetector::getRulesFilePath();
file_put_contents($path, '{broken');
chmod($path, 0600);
$backups = [];
foreach (['a', 'b'] as $hex) {
    $backup = $path . '.wls-backup-' . str_repeat($hex, 16);
    file_put_contents($backup, json_encode(['rate_limit' => ['max_requests' => 123]]));
    chmod($backup, 0600);
    $backups[] = $backup;
}
$compiler = new \Weline\Server\Service\Policy\RuntimePolicyCompiler();
$method = new \ReflectionMethod($compiler, 'loadAttackRules');
$error = '';
try {
    $method->invoke($compiler);
} catch (\Throwable $throwable) {
    $error = $throwable->getMessage() . ' ' . ($throwable->getPrevious()?->getMessage() ?? '');
}
$result = [
    'error' => $error,
    'target' => file_get_contents($path),
    'backups' => array_map('file_exists', $backups),
];
PHP);

        self::assertStringContainsString('ambiguous', \strtolower((string)($result['error'] ?? '')));
        self::assertSame('{broken', $result['target'] ?? null);
        self::assertSame([true, true], $result['backups'] ?? null);
    }

    public function testPanelReportsTransactionalRulesWriteFailure(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link state validation is covered on POSIX.');
        }

        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
$detector = \Weline\Server\Security\AttackDetector::getInstance();
$foreign = BP . 'panel-foreign.json';
file_put_contents($foreign, 'panel-foreign-state');
symlink($foreign, \Weline\Server\Security\AttackDetector::getRulesFilePath());
$attackLog = (new \ReflectionClass(\Weline\Server\Model\AttackLog::class))
    ->newInstanceWithoutConstructor();
$service = new \Weline\Server\Service\WlsPanelSecurityDataService($attackLog);
$response = $service->saveRulesJson((string)json_encode([
    'ip_whitelist' => [
        'enabled' => true,
        'ips' => ['198.51.100.12'],
    ],
]));
$result = [
    'response' => $response,
    'foreign' => file_get_contents($foreign),
    'whitelisted' => $detector->isWhitelisted('198.51.100.12'),
];
PHP);

        self::assertFalse($result['response']['success'] ?? true);
        self::assertNotSame('', $result['response']['message'] ?? '');
        self::assertSame('panel-foreign-state', $result['foreign'] ?? null);
        self::assertFalse($result['whitelisted'] ?? true);
    }

    public function testPanelServiceRejectsASecondSaveFromTheSameStaleReceipt(): void
    {
        $result = $this->runChild(<<<'PHP'
$server = BP . 'var' . DS . 'server';
mkdir($server, 0700, true);
if (!function_exists('__')) {
    function __(string $message, mixed ...$arguments): string {
        return $message;
    }
}
$detector = \Weline\Server\Security\AttackDetector::getInstance();
$receipt = $detector->getRulesReceipt();
$attackLog = (new \ReflectionClass(\Weline\Server\Model\AttackLog::class))
    ->newInstanceWithoutConstructor();
$service = new \Weline\Server\Service\WlsPanelSecurityDataService($attackLog);
$first = $service->saveRulesJson((string)json_encode([
    'rate_limit' => ['enabled' => true, 'max_requests' => 111],
]), [], $receipt);
$second = $service->saveRulesJson((string)json_encode([
    'rate_limit' => ['enabled' => true, 'max_requests' => 222],
]), [], $receipt);
$result = [
    'first' => $first,
    'second' => $second,
    'max_requests' => $detector->getRules()['rate_limit']['max_requests'] ?? null,
];
PHP);

        self::assertTrue(
            $result['first']['success'] ?? false,
            (string)\json_encode($result['first'] ?? [], JSON_UNESCAPED_SLASHES),
        );
        self::assertFalse($result['second']['success'] ?? true);
        self::assertStringContainsString(
            'conflict',
            \strtolower((string)($result['second']['message'] ?? '')),
        );
        self::assertSame(111, $result['max_requests'] ?? null);
    }

    public function testMalformedLegacyFlagCannotBlockRulesReload(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $rulesReceipt = $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => []],
        ]);
        $detector = new AttackDetector($store);
        self::assertFalse($detector->isWhitelisted('198.51.100.13'));

        self::assertNotFalse(\file_put_contents($store->legacyFlagPath(), 'invalid-flag'));
        self::assertTrue(\chmod($store->legacyFlagPath(), 0600));
        $detector->checkRulesUpdate(true);

        $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => ['198.51.100.13']],
        ], [
            'generation' => $rulesReceipt['generation'],
            'digest' => $rulesReceipt['digest'],
        ]);
        $detector->checkRulesUpdate(true);

        self::assertTrue($detector->isWhitelisted('198.51.100.13'));
    }

    public function testRuntimeRulesCheckDoesNotWaitForTheWriterLock(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => []],
        ]);
        $detector = new AttackDetector($store);
        $lock = \fopen($this->root . DIRECTORY_SEPARATOR . '.security-policy.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(\flock($lock, LOCK_EX | LOCK_NB));

        try {
            $started = \hrtime(true);
            $detector->checkRulesUpdate(false);
            $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
        } finally {
            \flock($lock, LOCK_UN);
            \fclose($lock);
        }

        self::assertLessThan(
            0.05,
            $elapsed,
            'Worker policy polling must retain its in-memory LKG instead of waiting on a writer.',
        );
    }

    public function testRulesPollingIntervalUsesAnInjectedMonotonicClock(): void
    {
        $clock = 100.0;
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $rulesReceipt = $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => []],
        ]);
        $detector = new AttackDetector(
            $store,
            static function () use (&$clock): float {
                return $clock;
            },
        );
        $detector->checkRulesUpdate(false);

        $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => ['203.0.113.77']],
        ], [
            'generation' => $rulesReceipt['generation'],
            'digest' => $rulesReceipt['digest'],
        ]);
        $clock = 104.999;
        $detector->checkRulesUpdate(false);
        self::assertFalse($detector->isWhitelisted('203.0.113.77'));

        $clock = 105.0;
        $detector->checkRulesUpdate(false);
        self::assertTrue($detector->isWhitelisted('203.0.113.77'));

        $method = new \ReflectionMethod(AttackDetector::class, 'checkRulesUpdate');
        $lines = (array)\file($method->getFileName());
        $body = \implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
        self::assertStringNotContainsString('\\time()', $body);
        self::assertStringContainsString('monotonic', $body);
    }

    /** @return array<string, mixed> */
    private function runChild(string $body): array
    {
        $autoload = $this->projectRoot() . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $script = 'define("BP", rtrim($argv[1], "/\\\\") . DIRECTORY_SEPARATOR);'
            . 'define("DS", DIRECTORY_SEPARATOR);'
            . 'require $argv[2];'
            . $body
            . 'echo "WLS_ATTACK_STATE_RESULT="'
            . '.base64_encode((string)json_encode($result, JSON_UNESCAPED_SLASHES));';
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, '-r', $script, $this->root, $autoload],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = (string)\stream_get_contents($pipes[1]);
        $stderr = (string)\stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);
        self::assertMatchesRegularExpression('/WLS_ATTACK_STATE_RESULT=([A-Za-z0-9+\/=]+)/', $stdout);
        \preg_match('/WLS_ATTACK_STATE_RESULT=([A-Za-z0-9+\/=]+)/', $stdout, $match);
        $decoded = \json_decode((string)\base64_decode((string)($match[1] ?? ''), true), true);
        self::assertIsArray($decoded, $stderr . "\n" . $stdout);

        return $decoded;
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR;
    }
}
