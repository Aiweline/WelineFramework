<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;

final class GatewayControllerHistoricalMutableStateTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-controller-history-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir(
            $this->home . DIRECTORY_SEPARATOR . 'trust',
            0700,
            true,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->home);
        parent::tearDown();
    }

    /**
     * @dataProvider historicalFileProvider
     */
    public function testFreshBootstrapRejectsHistoricalFile(
        string $relative,
    ): void {
        $this->writeResidue($relative);

        self::assertFalse($this->freshInstallBootstrapAllowed());
    }

    /** @return iterable<string,array{string}> */
    public static function historicalFileProvider(): iterable
    {
        foreach ([
            'state/gateway-state.json',
            'state/lease-checkpoint.json',
            'state/security-ledger.json',
            'state/security-ledger.pending.json',
            'state/security-ledger.json.untrusted',
            'state/security-anchor.json',
            'state/wls-edge-2.initialized.json',
            'state/publication-current.json',
            'state/route-lkg.json',
            'state/journal.jsonl',
            'state/journal.jsonl.1',
            'state/nonce.wal',
            'state/control-endpoint.json',
            'state/disk-pressure.marker',
            'state/neutral-cert.pem',
            'state/neutral-key.pem',
            'state/emergency-revocations-v1.tsv',
            'runtime/conf/nginx.conf',
            'trust/security-anchor.json',
            'trust/wls-edge-2.initialized.json',
            'trust/journal.untrusted',
            'trust/snapshot-receipt.key',
            'trust/broker-enrollments.tsv',
            'trust/broker-security-v2.tsv',
            'trust/emergency-credentials-v1.tsv',
        ] as $relative) {
            yield $relative => [$relative];
        }
    }

    /**
     * @dataProvider historicalNamespaceProvider
     */
    public function testFreshBootstrapRejectsPopulatedHistoricalNamespace(
        string $relative,
    ): void {
        $this->writeResidue($relative . '/residue');

        self::assertFalse($this->freshInstallBootstrapAllowed());
    }

    /** @return iterable<string,array{string}> */
    public static function historicalNamespaceProvider(): iterable
    {
        foreach ([
            'runtime/conf',
            'state/lkg',
            'snapshots',
            'snapshot-candidates-v2',
            'trust/snapshot-receipts',
        ] as $relative) {
            yield $relative => [$relative];
        }
    }

    public function testFreshBootstrapAllowsOnlyEmptyHistoricalNamespaces(): void
    {
        foreach ([
            'runtime/conf',
            'state/lkg',
            'snapshots',
            'snapshot-candidates-v2',
            'trust/snapshot-receipts',
        ] as $relative) {
            $directory = $this->path($relative);
            self::assertTrue(\mkdir($directory, 0700, true));
        }

        self::assertTrue($this->freshInstallBootstrapAllowed());
    }

    public function testFreshBootstrapAllowsUnreferencedSealedSnapshotNamespace(): void
    {
        $this->writeResidue('snapshots-v2/' . str_repeat('a', 64) . '/manifest.json');

        self::assertTrue(
            $this->freshInstallBootstrapAllowed(),
            'A sealed snapshot has no bootstrap authority without state or receipt evidence.',
        );
    }

    public function testFreshBootstrapRejectsUnsafeHistoricalNamespacePath(): void
    {
        $this->writeResidue('snapshots-v2');

        self::assertFalse($this->freshInstallBootstrapAllowed());
    }

    public function testFreshBootstrapRejectsLinkedSealedSnapshotNamespace(): void
    {
        $target = $this->path('sealed-snapshot-target');
        self::assertTrue(\mkdir($target, 0700, true));
        self::assertTrue(\symlink($target, $this->path('snapshots-v2')));

        self::assertFalse($this->freshInstallBootstrapAllowed());
    }

    public function testFreshBootstrapRejectsInterruptedReceiptKeyCandidate(): void
    {
        $this->writeResidue(
            'trust/.SNAPSHOT-RECEIPT.KEY.CANDIDATE.1.0123456789abcdef',
        );

        self::assertFalse($this->freshInstallBootstrapAllowed());
    }

    private function freshInstallBootstrapAllowed(): bool
    {
        if (!\class_exists('WlsEdgeGatewayController', false)) {
            if (!\defined('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST')) {
                \define('WLS_GATEWAY_CONTROLLER_EMBEDDED_TEST', true);
            }
            require \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php';
        }
        $reflection = new \ReflectionClass('WlsEdgeGatewayController');
        $controller = $reflection->newInstanceWithoutConstructor();
        $home = $reflection->getProperty('home');
        $home->setAccessible(true);
        $home->setValue($controller, $this->home);
        $method = $reflection->getMethod('freshInstallBootstrapAllowed');
        $method->setAccessible(true);

        return (bool)$method->invoke($controller);
    }

    private function writeResidue(string $relative): void
    {
        $file = $this->path($relative);
        $directory = \dirname($file);
        if (!\is_dir($directory)) {
            self::assertTrue(\mkdir($directory, 0700, true));
        }
        self::assertSame(7, \file_put_contents($file, 'residue'));
    }

    private function path(string $relative): string
    {
        return $this->home . DIRECTORY_SEPARATOR . \str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relative,
        );
    }

    private function removeTree(string $path): void
    {
        if (\is_link($path) || \is_file($path)) {
            @\unlink($path);
            return;
        }
        if (!\is_dir($path)) {
            return;
        }
        $entries = \scandir($path);
        if (\is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @\rmdir($path);
    }
}
