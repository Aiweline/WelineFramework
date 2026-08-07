<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Security;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Security\SecurityPolicyStateStore;

final class SecurityPolicyStateStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-security-policy-store-' . \bin2hex(\random_bytes(8));
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

    public function testRulesMutationUsesGenerationDigestCasAndSameDigestIsIdempotent(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $base = $store->writeRules([
            'rate_limit' => ['enabled' => true, 'max_requests' => 100],
        ]);
        $baseReceipt = [
            'generation' => $base['generation'],
            'digest' => $base['digest'],
        ];
        $committed = $store->writeRules([
            'rate_limit' => ['enabled' => true, 'max_requests' => 200],
        ], $baseReceipt);

        $idempotent = $store->writeRules([
            'rate_limit' => ['enabled' => true, 'max_requests' => 200],
        ], $baseReceipt);
        self::assertSame($committed['generation'], $idempotent['generation']);
        self::assertSame($committed['digest'], $idempotent['digest']);

        try {
            $store->writeRules([
                'rate_limit' => ['enabled' => true, 'max_requests' => 300],
            ], $baseReceipt);
            self::fail('A stale rules receipt overwrote a newer committed generation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('conflict', \strtolower($exception->getMessage()));
        }

        $current = $store->readRulesState();
        self::assertIsArray($current);
        self::assertSame($committed['generation'], $current['generation']);
        self::assertSame($committed['digest'], $current['digest']);
        self::assertSame(200, $current['rules']['rate_limit']['max_requests'] ?? null);
    }

    public function testRulesEnvelopePreservesIntegralFloatDigest(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);

        $state = $store->writeRules([
            'request_spike' => ['multiplier' => 2.0],
        ]);

        self::assertSame(2.0, $state['rules']['request_spike']['multiplier'] ?? null);
        self::assertSame($state['digest'], $store->readRulesState()['digest'] ?? null);
    }

    public function testPermanentBanMutationsRereadTheLatestCommittedSet(): void
    {
        $first = new SecurityPolicyStateStore($this->root, 0.2);
        $second = new SecurityPolicyStateStore($this->root, 0.2);
        self::assertSame([], $first->readPermanentBansState()['ips']);
        self::assertSame([], $second->readPermanentBansState()['ips']);

        $one = $first->addPermanentBan('198.51.100.1');
        $two = $second->addPermanentBan('198.51.100.2');
        $three = $first->removePermanentBan('198.51.100.1');
        $four = $second->clearPermanentBans();

        self::assertSame(['198.51.100.1'], $one['ips']);
        self::assertSame(['198.51.100.1', '198.51.100.2'], $two['ips']);
        self::assertSame(['198.51.100.2'], $three['ips']);
        self::assertSame([], $four['ips']);
        self::assertSame([1, 2, 3, 4], [
            $one['generation'],
            $two['generation'],
            $three['generation'],
            $four['generation'],
        ]);
    }

    public function testPermanentBansCanonicalizeIpAliasesAndRejectInvalidInput(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $first = $store->addPermanentBan('2001:0db8:0000:0000:0000:0000:0000:0001');
        $same = $store->addPermanentBan('2001:db8::1');

        self::assertSame(['2001:db8::1'], $first['ips']);
        self::assertSame($first['generation'], $same['generation']);

        $this->expectException(\InvalidArgumentException::class);
        $store->addPermanentBan('198.51.100.999');
    }

    public function testPermanentBansRecoverOneCommittedBackup(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $store->addPermanentBan('198.51.100.14');
        $target = $store->permanentBansPath();
        $backup = $target . '.wls-backup-' . \str_repeat('a', 16);
        self::assertNotFalse(\copy($target, $backup));
        self::assertTrue(\chmod($backup, 0600));
        self::assertNotFalse(\file_put_contents($target, '{broken'));
        self::assertTrue(\chmod($target, 0600));

        $state = $store->readPermanentBansState();

        self::assertSame(['198.51.100.14'], $state['ips']);
        self::assertSame(1, $state['generation']);
        self::assertFileDoesNotExist($backup);
    }

    public function testLegacyFlagOnlyWakesReadersAndIsNotTheCommittedSignal(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $written = $store->writeRules(['rate_limit' => ['enabled' => true]]);
        self::assertFileDoesNotExist($store->legacyFlagPath());
        $before = $store->snapshot();

        self::assertNotFalse(\file_put_contents($store->legacyFlagPath(), '1234567890'));
        self::assertTrue(\chmod($store->legacyFlagPath(), 0600));
        $after = $store->snapshot();

        self::assertSame($written['signal'], $before['rules']['signal'] ?? null);
        self::assertSame($before['rules']['signal'] ?? null, $after['rules']['signal'] ?? null);
        self::assertNotSame($before['signal'], $after['signal']);
        self::assertSame($written['rules'], $after['rules']['rules'] ?? null);
    }

    public function testMalformedRegularLegacyFlagIsOnlyAStableInvalidWakeSignal(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $written = $store->writeRules(['rate_limit' => ['enabled' => true]]);
        self::assertNotFalse(\file_put_contents($store->legacyFlagPath(), 'not-a-generation'));
        self::assertTrue(\chmod($store->legacyFlagPath(), 0600));

        $first = $store->snapshot();
        $second = $store->snapshot();

        self::assertSame($written['signal'], $first['rules']['signal'] ?? null);
        self::assertSame($first['signal'], $second['signal']);
        self::assertStringContainsString('legacy-flag:invalid:', $first['signal']);
    }

    public function testStableLockRejectsCaseAliasBeforeCreatingAnotherInode(): void
    {
        $alias = $this->root . DIRECTORY_SEPARATOR . '.Security-Policy.Lock';
        self::assertNotFalse(\file_put_contents($alias, 'foreign-lock'));
        self::assertTrue(\chmod($alias, 0600));

        try {
            (new SecurityPolicyStateStore($this->root, 0.05))->writeRules([
                'rate_limit' => ['enabled' => true],
            ]);
            self::fail('A case-aliased stable security state lock was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('case alias', \strtolower($exception->getMessage()));
        }

        self::assertSame('foreign-lock', (string)\file_get_contents($alias));
        $entries = \scandir($this->root);
        self::assertIsArray($entries);
        self::assertContains('.Security-Policy.Lock', $entries);
        self::assertNotContains('.security-policy.lock', $entries);
    }

    public function testStableLockInodeIsRetainedAcrossTransactions(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $rulesReceipt = $store->writeRules(['rate_limit' => ['enabled' => true]]);
        $lock = $this->root . DIRECTORY_SEPARATOR . '.security-policy.lock';
        $before = \lstat($lock);
        self::assertIsArray($before);

        $store->writeRules(['rate_limit' => ['enabled' => false]], [
            'generation' => $rulesReceipt['generation'],
            'digest' => $rulesReceipt['digest'],
        ]);
        $store->addPermanentBan('203.0.113.20');
        $after = \lstat($lock);

        self::assertIsArray($after);
        self::assertSame($before['dev'], $after['dev']);
        self::assertSame($before['ino'], $after['ino']);
        self::assertSame(1, $after['nlink']);
    }

    public function testHardLinkedRulesTargetIsRejectedWithoutMutatingForeignFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link identity validation is POSIX-specific.');
        }
        $foreign = $this->root . DIRECTORY_SEPARATOR . 'foreign-rules.json';
        $target = $this->root . DIRECTORY_SEPARATOR . 'security-rules.json';
        self::assertNotFalse(\file_put_contents($foreign, '{}'));
        self::assertTrue(\chmod($foreign, 0600));
        self::assertTrue(\link($foreign, $target));

        try {
            (new SecurityPolicyStateStore($this->root, 0.2))->readRulesState();
            self::fail('A hard-linked security rules target was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('non-linked', \strtolower($exception->getMessage()));
        }

        self::assertSame('{}', (string)\file_get_contents($foreign));
    }

    public function testValidTargetCollectsOnlyExactOldAndNewCrashArtifacts(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $store->writeRules(['rate_limit' => ['enabled' => true]]);
        $target = $store->rulesPath();
        $artifacts = [
            $target . '.tmp-' . \str_repeat('a', 24),
            $target . '.wls-backup-' . \str_repeat('b', 16),
            $target . '.tmp.12345.deadbeef',
        ];
        foreach ($artifacts as $artifact) {
            self::assertNotFalse(\file_put_contents($artifact, 'crash-evidence'));
            self::assertTrue(\chmod($artifact, 0600));
        }
        $unrelated = $this->root . DIRECTORY_SEPARATOR . 'unrelated.tmp-' . \str_repeat('c', 24);
        self::assertNotFalse(\file_put_contents($unrelated, 'keep'));

        self::assertNotNull($store->readRulesState());

        foreach ($artifacts as $artifact) {
            self::assertFileDoesNotExist($artifact);
        }
        self::assertSame('keep', (string)\file_get_contents($unrelated));
    }

    public function testMissingTargetWithOnlyStagingEvidenceFailsClosedAndPreservesIt(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $staging = $store->rulesPath() . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($staging, '{"rate_limit":{"enabled":true}}'));
        self::assertTrue(\chmod($staging, 0600));

        try {
            $store->readRulesState();
            self::fail('Uncommitted first-publication staging was activated.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('no unique committed backup', \strtolower($exception->getMessage()));
        }

        self::assertFileExists($staging);
        self::assertFileDoesNotExist($store->rulesPath());
    }

    public function testCorruptTargetWithMultipleBackupsFailsClosedAndPreservesEvidence(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        self::assertNotFalse(\file_put_contents($store->rulesPath(), '{broken'));
        self::assertTrue(\chmod($store->rulesPath(), 0600));
        $backups = [];
        foreach (['a', 'b'] as $hex) {
            $backup = $store->rulesPath() . '.wls-backup-' . \str_repeat($hex, 16);
            self::assertNotFalse(\file_put_contents($backup, '{"rate_limit":{"enabled":true}}'));
            self::assertTrue(\chmod($backup, 0600));
            $backups[] = $backup;
        }

        try {
            $store->readRulesState();
            self::fail('Ambiguous security rules backups were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ambiguous', \strtolower($exception->getMessage()));
        }

        self::assertSame('{broken', (string)\file_get_contents($store->rulesPath()));
        foreach ($backups as $backup) {
            self::assertFileExists($backup);
        }
    }

    public function testSemanticallyInvalidRulesTargetRecoversOneValidBackup(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $expected = $store->writeRules([
            'ip_whitelist' => ['enabled' => true, 'ips' => ['198.51.100.44']],
        ]);
        $target = $store->rulesPath();
        $backup = $target . '.wls-backup-' . \str_repeat('c', 16);
        self::assertTrue(\copy($target, $backup));
        self::assertTrue(\chmod($backup, 0600));
        self::assertNotFalse(\file_put_contents(
            $target,
            (string)\json_encode([\str_repeat('k', 1025) => true], JSON_THROW_ON_ERROR),
        ));
        self::assertTrue(\chmod($target, 0600));

        $recovered = $store->readRulesState();

        self::assertSame($expected, $recovered);
        self::assertFileDoesNotExist($backup);
    }

    public function testMalformedCaseAliasedReservedLeafFailsClosed(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $store->writeRules(['rate_limit' => ['enabled' => true]]);
        $alias = $store->rulesPath() . '.TMP-' . \str_repeat('A', 24);
        self::assertNotFalse(\file_put_contents($alias, 'alias'));

        $this->expectException(\RuntimeException::class);
        $store->readRulesState();
    }

    public function testArtifactQuotaFailsBeforeAnyEvidenceIsRemoved(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        $store->writeRules(['rate_limit' => ['enabled' => true]]);
        $artifacts = [];
        foreach (\str_split('abcdef01a') as $index => $hex) {
            $artifact = $store->rulesPath() . '.tmp-'
                . \str_pad($hex . \dechex($index), 24, $hex);
            self::assertNotFalse(\file_put_contents($artifact, 'evidence-' . $index));
            self::assertTrue(\chmod($artifact, 0600));
            $artifacts[] = $artifact;
        }

        try {
            $store->readRulesState();
            self::fail('An over-quota security rules artifact set was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('artifact quota', \strtolower($exception->getMessage()));
        }

        foreach ($artifacts as $artifact) {
            self::assertFileExists($artifact);
        }
    }

    public function testRulesStateRejectsNonObjectJsonAndOversizedDocuments(): void
    {
        $listDirectory = $this->root . DIRECTORY_SEPARATOR . 'list';
        self::assertTrue(\mkdir($listDirectory, 0700));
        $listStore = new SecurityPolicyStateStore($listDirectory, 0.2);
        self::assertNotFalse(\file_put_contents($listStore->rulesPath(), '[{"enabled":true}]'));
        self::assertTrue(\chmod($listStore->rulesPath(), 0600));
        try {
            $listStore->readRulesState();
            self::fail('A list-shaped security rules document was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('corrupt', \strtolower($exception->getMessage()));
        }

        $largeDirectory = $this->root . DIRECTORY_SEPARATOR . 'large';
        self::assertTrue(\mkdir($largeDirectory, 0700));
        $largeStore = new SecurityPolicyStateStore($largeDirectory, 0.2);
        self::assertNotFalse(\file_put_contents(
            $largeStore->rulesPath(),
            '{"value":"' . \str_repeat('x', 4_194_304) . '"}',
        ));
        self::assertTrue(\chmod($largeStore->rulesPath(), 0600));

        $this->expectException(\RuntimeException::class);
        $largeStore->readRulesState();
    }

    public function testNullRulesMetadataCannotDowngradeACommittedDocumentToLegacy(): void
    {
        $store = new SecurityPolicyStateStore($this->root, 0.2);
        self::assertNotFalse(\file_put_contents(
            $store->rulesPath(),
            '{"__wls_state":null,"rate_limit":{"enabled":false}}',
        ));
        self::assertTrue(\chmod($store->rulesPath(), 0600));

        try {
            $store->readRulesState();
            self::fail('Null WLS rules metadata was accepted as a legacy document.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('corrupt', \strtolower($exception->getMessage()));
        }
    }

    public function testDirectoryRawEntryQuotaFailsBeforePublication(): void
    {
        for ($index = 0; $index < 16_384; ++$index) {
            if (\file_put_contents(
                $this->root . DIRECTORY_SEPARATOR . 'ordinary-' . $index,
                '',
            ) !== 0) {
                self::fail('Unable to construct the directory quota fixture.');
            }
        }

        try {
            (new SecurityPolicyStateStore($this->root, 0.2))->writeRules([
                'rate_limit' => ['enabled' => true],
            ]);
            self::fail('An over-quota security state directory was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('raw entry quota', \strtolower($exception->getMessage()));
        }
    }

    public function testLockContentionFailsWithinBoundAndRetainsTheStableInode(): void
    {
        $lockPath = $this->root . DIRECTORY_SEPARATOR . '.security-policy.lock';
        $lock = \fopen($lockPath, 'x+b');
        self::assertIsResource($lock);
        self::assertTrue(\chmod($lockPath, 0600));
        self::assertTrue(\flock($lock, LOCK_EX));
        $before = \lstat($lockPath);
        self::assertIsArray($before);

        $autoload = \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        $script = 'define("DS", DIRECTORY_SEPARATOR);'
            . 'require ' . \var_export($autoload, true) . ';'
            . '$store=new \\Weline\\Server\\Service\\Security\\SecurityPolicyStateStore('
            . \var_export($this->root, true) . ',0.05);'
            . 'try{$store->writeRules(["rate_limit"=>["enabled"=>true]]);'
            . '$result=["saved"=>true,"error"=>""];}'
            . 'catch(\\Throwable $e){$result=["saved"=>false,"error"=>$e->getMessage()];}'
            . 'echo "WLS_LOCK_RESULT=".base64_encode((string)json_encode($result));';
        $pipes = [];
        $startedAt = \hrtime(true);
        $process = \proc_open(
            [PHP_BINARY, '-r', $script],
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
        $elapsed = (\hrtime(true) - $startedAt) / 1_000_000_000;
        \flock($lock, LOCK_UN);
        \fclose($lock);

        self::assertSame(0, $exitCode, $stderr . "\n" . $stdout);
        self::assertLessThan(0.75, $elapsed);
        self::assertMatchesRegularExpression('/WLS_LOCK_RESULT=([A-Za-z0-9+\/=]+)/', $stdout);
        \preg_match('/WLS_LOCK_RESULT=([A-Za-z0-9+\/=]+)/', $stdout, $match);
        $result = \json_decode((string)\base64_decode((string)($match[1] ?? ''), true), true);
        self::assertIsArray($result);
        self::assertFalse($result['saved'] ?? true);
        self::assertStringContainsString('security policy state lock', \strtolower((string)($result['error'] ?? '')));
        $after = \lstat($lockPath);
        self::assertIsArray($after);
        self::assertSame($before['dev'], $after['dev']);
        self::assertSame($before['ino'], $after['ino']);
    }

    public function testRulesValidationDoesNotRequireOptionalMbstringExtension(): void
    {
        $autoload = \dirname(__DIR__, 8) . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'autoload.php';
        self::assertFileExists($autoload);
        $directory = $this->root . DIRECTORY_SEPARATOR . 'no-mbstring';
        self::assertTrue(\mkdir($directory, 0700));
        $script = 'define("DS", DIRECTORY_SEPARATOR);'
            . 'require ' . \var_export($autoload, true) . ';'
            . '$store=new \\Weline\\Server\\Service\\Security\\SecurityPolicyStateStore('
            . \var_export($directory, true) . ',0.2);'
            . '$state=$store->writeRules(["rate_limit"=>["enabled"=>true]]);'
            . 'exit(($state["generation"]??0)===1?0:7);';
        $pipes = [];
        $process = \proc_open(
            [PHP_BINARY, '-n', '-r', $script],
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

        self::assertSame(0, \proc_close($process), $stderr . "\n" . $stdout);
    }
}
