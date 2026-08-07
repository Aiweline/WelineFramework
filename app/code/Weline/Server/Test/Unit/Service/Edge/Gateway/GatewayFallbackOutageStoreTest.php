<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Gateway\Agent;
use Weline\Server\Service\Edge\Gateway\GatewayFallbackOutageStore;

final class GatewayFallbackOutageStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-gateway-outage-' . \bin2hex(\random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach ((array)@\glob($this->directory . DIRECTORY_SEPARATOR . '*') as $file) {
            if (\is_string($file) && \is_file($file) && !\is_link($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($this->directory);
        parent::tearDown();
    }

    public function testSameAgentOutageRestoresOriginalMonotonicWindow(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);

        $launch = \str_repeat('a', 32);
        $outage = \str_repeat('1', 32);
        $evidence = \str_repeat('d', 64);
        self::assertSame([
            'down_since_monotonic' => 500.0,
            'elapsed_seconds' => 0.0,
            'restored' => false,
        ], $store->markDown(
            'project-a', 41, 7, $launch, $outage, $evidence, 500.0,
        ));
        foreach ([510.0, 520.0, 530.0, 540.0, 550.0, 560.0, 570.0, 580.0] as $now) {
            self::assertSame(500.0, $store->markDown(
                'project-a', 41, 7, $launch, $outage, $evidence, $now,
            )['down_since_monotonic']);
        }
        self::assertSame([
            'down_since_monotonic' => 500.0,
            'elapsed_seconds' => 89.0,
            'restored' => true,
        ], $store->markDown(
            'project-a', 41, 7, $launch, $outage, $evidence, 589.0,
        ));
    }

    public function testAgentRefreshesDurableOutageEvidenceAtHeartbeatCadence(): void
    {
        self::assertTrue(Agent::outagePersistenceDue(500.0, 0.0));
        self::assertFalse(Agent::outagePersistenceDue(509.999, 500.0));
        self::assertTrue(Agent::outagePersistenceDue(510.0, 500.0));
    }

    public function testOutageRecoveryBackupRequiresItsValidatedInstanceTarget(): void
    {
        foreach (['present', 'missing'] as $case) {
            $instance = 'project-' . $case;
            $store = new GatewayFallbackOutageStore($this->directory);
            $launch = \str_repeat($case === 'present' ? 'a' : 'b', 32);
            $outage = \str_repeat($case === 'present' ? '1' : '2', 32);
            $evidence = \str_repeat($case === 'present' ? 'c' : 'd', 64);
            $store->markDown($instance, 41, 7, $launch, $outage, $evidence, 500.0);
            $state = $this->directory . DIRECTORY_SEPARATOR
                . \substr(\hash('sha256', $instance), 0, 24) . '.json';
            $backup = $state . '.wls-backup-' . \str_repeat(
                $case === 'present' ? 'c' : 'd',
                16,
            );
            if ($case === 'present') {
                self::assertTrue(\copy($state, $backup));
                self::assertTrue($store->markDown(
                    $instance,
                    41,
                    7,
                    $launch,
                    $outage,
                    $evidence,
                    501.0,
                )['restored']);
                self::assertFileDoesNotExist($backup);
                continue;
            }
            self::assertTrue(\rename($state, $backup));
            try {
                $store->markDown(
                    $instance, 41, 7, $launch, $outage, $evidence, 501.0,
                );
                self::fail('Missing outage recovery target must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'paired target',
                    \strtolower($exception->getMessage()),
                );
            }
            self::assertFileExists($backup);
            self::assertFileDoesNotExist($state);
        }
    }

    public function testRecoveryRejectsWeaklyTypedOutageIdentity(): void
    {
        $instance = 'project-weak-type';
        $store = new GatewayFallbackOutageStore($this->directory);
        $launch = \str_repeat('e', 32);
        $outage = \str_repeat('5', 32);
        $evidence = \str_repeat('f', 64);
        $store->markDown($instance, 41, 7, $launch, $outage, $evidence, 500.0);
        $state = $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instance), 0, 24) . '.json';
        $backup = $state . '.wls-backup-' . \str_repeat('e', 16);
        self::assertTrue(\copy($state, $backup));
        $decoded = \json_decode(
            (string)\file_get_contents($state),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $decoded['master_pid'] = '41';
        self::assertNotFalse(\file_put_contents(
            $state,
            \json_encode($decoded, JSON_THROW_ON_ERROR),
        ));

        try {
            $store->markDown($instance, 41, 7, $launch, $outage, $evidence, 501.0);
            self::fail('A weakly typed outage identity must not authorize cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed', \strtolower($exception->getMessage()));
        }
        self::assertFileExists($backup);
    }

    public function testNewMasterStartsANewOutageWindowAndHealthyStateClearsIt(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        self::assertSame(500.0, $store->markDown(
            'project-a', 41, 7, \str_repeat('a', 32),
            \str_repeat('1', 32), \str_repeat('d', 64), 500.0,
        )['down_since_monotonic']);
        self::assertSame(589.0, $store->markDown(
            'project-a', 42, 8, \str_repeat('b', 32),
            \str_repeat('2', 32), \str_repeat('d', 64), 589.0,
        )['down_since_monotonic']);

        $store->clear('project-a');

        self::assertSame(600.0, $store->markDown(
            'project-a', 42, 8, \str_repeat('b', 32),
            \str_repeat('3', 32), \str_repeat('d', 64), 600.0,
        )['down_since_monotonic']);
    }

    public function testAgentRestartInheritsOnlyARecentlyObservedSameMasterOutage(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        $launch = \str_repeat('a', 32);
        $evidence = \str_repeat('d', 64);
        self::assertSame(500.0, $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('1', 32), $evidence, 500.0,
        )['down_since_monotonic']);

        foreach ([510.0, 520.0, 530.0, 540.0, 550.0, 560.0, 570.0, 580.0] as $now) {
            self::assertSame(500.0, $store->markDown(
                'project-a', 41, 7, $launch, \str_repeat('1', 32), $evidence, $now,
            )['down_since_monotonic']);
        }

        $restarted = $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('2', 32), $evidence, 609.0,
        );
        // The previous Agent had confirmed 80 seconds through t=580.  The
        // 29-second restart gap is unknown and is therefore paused, not
        // counted as failed-probe time.
        self::assertSame(529.0, $restarted['down_since_monotonic']);
        self::assertSame(80.0, $restarted['elapsed_seconds']);
        self::assertTrue($restarted['restored']);

        $staleGap = $store->markDown(
            'project-a', 41, 7, $launch, \str_repeat('3', 32), $evidence, 640.0,
        );
        self::assertSame(640.0, $staleGap['down_since_monotonic']);
        self::assertSame(0.0, $staleGap['elapsed_seconds']);
        self::assertFalse($staleGap['restored']);

        $differentEvidence = $store->markDown(
            'project-a',
            41,
            7,
            $launch,
            \str_repeat('4', 32),
            \str_repeat('e', 64),
            641.0,
        );
        self::assertSame(641.0, $differentEvidence['down_since_monotonic']);
        self::assertFalse($differentEvidence['restored']);

        $longUnobservedSameAgent = $store->markDown(
            'project-a',
            41,
            7,
            $launch,
            \str_repeat('4', 32),
            \str_repeat('e', 64),
            700.0,
        );
        self::assertSame(700.0, $longUnobservedSameAgent['down_since_monotonic']);
        self::assertFalse($longUnobservedSameAgent['restored']);
    }

    public function testObservationSequenceOverflowStartsANewCompleteWindow(): void
    {
        $instance = 'project-sequence-overflow';
        $store = new GatewayFallbackOutageStore($this->directory);
        $launch = \str_repeat('a', 32);
        $outage = \str_repeat('1', 32);
        $evidence = \str_repeat('d', 64);
        $store->markDown($instance, 41, 7, $launch, $outage, $evidence, 500.0);
        $stateFile = $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', $instance), 0, 24) . '.json';
        $state = \json_decode(
            (string)\file_get_contents($stateFile),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($state);
        $state['observation_sequence'] = PHP_INT_MAX;
        self::assertIsInt(\file_put_contents(
            $stateFile,
            \json_encode($state, JSON_THROW_ON_ERROR),
            LOCK_EX,
        ));

        $reset = $store->markDown(
            $instance,
            41,
            7,
            $launch,
            $outage,
            $evidence,
            501.0,
        );
        self::assertSame(501.0, $reset['down_since_monotonic']);
        self::assertSame(0.0, $reset['elapsed_seconds']);
        self::assertFalse($reset['restored']);
    }

    public function testFuturePersistedUpdateCannotShortenCurrentOutageWindow(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        $launch = \str_repeat('a', 32);
        $outage = \str_repeat('1', 32);
        $evidence = \str_repeat('d', 64);
        $store->markDown('project-a', 41, 7, $launch, $outage, $evidence, 500.0);

        $stateFile = $this->directory . DIRECTORY_SEPARATOR
            . \substr(\hash('sha256', 'project-a'), 0, 24) . '.json';
        $encoded = \file_get_contents($stateFile);
        self::assertIsString($encoded);
        $state = \json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        $state['updated_monotonic'] = 600.0;
        self::assertIsInt(\file_put_contents(
            $stateFile,
            \json_encode($state, JSON_THROW_ON_ERROR),
            LOCK_EX,
        ));

        $observation = $store->markDown(
            'project-a',
            41,
            7,
            $launch,
            $outage,
            $evidence,
            550.0,
        );

        self::assertSame(550.0, $observation['down_since_monotonic']);
        self::assertSame(0.0, $observation['elapsed_seconds']);
        self::assertFalse($observation['restored']);
    }

    public function testAgentOutagePersistenceCannotWaitPastItsTickDeadline(): void
    {
        $store = new GatewayFallbackOutageStore($this->directory);
        $deadline = (\hrtime(true) / 1_000_000_000) - 1.0;

        $failure = null;
        try {
            $store->markDown(
                'project-a',
                41,
                7,
                \str_repeat('a', 32),
                \str_repeat('1', 32),
                \str_repeat('d', 64),
                500.0,
                $deadline,
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertStringContainsString('deadline', $failure->getMessage());
        self::assertDirectoryDoesNotExist($this->directory);

        $source = (string)\file_get_contents(
            (string)(new \ReflectionClass(Agent::class))->getFileName(),
        );
        self::assertMatchesRegularExpression(
            '/->markDown\([\s\S]*?\$outageObservationDigest,\s*'
                . '\$now,\s*\$tickDeadline,\s*\)/',
            $source,
        );
        self::assertGreaterThanOrEqual(
            2,
            \preg_match_all(
                '/->clear\(\s*\$instanceName,\s*\$tickDeadline,?\s*\)/',
                $source,
            ),
        );
    }
}
