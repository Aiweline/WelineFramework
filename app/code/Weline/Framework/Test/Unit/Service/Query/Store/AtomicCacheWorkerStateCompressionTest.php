<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query\Store;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\FreshCacheReadInterface;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Store\AtomicCacheFrontendWorkerStateStore;

interface CompressionTestAdapter extends AtomicCacheAdapterInterface, FreshCacheReadInterface {}

final class AtomicCacheWorkerStateCompressionTest extends TestCase
{
    public function testLargeStateHasSmallWireRepresentationAndExactResolvedValues(): void
    {
        [$store, $control] = $this->fixture(true);
        $state = ['records' => array_fill(0, 1500, ['scope' => str_repeat('scope-data-', 60), 'false' => false, 'null' => null, 'float' => 1.0])];
        $store->transaction(static function (array &$value) use ($state): void { $value = $state; });
        self::assertIsString($control->wire);
        self::assertLessThan(strlen(json_encode($state)) / 5, strlen($control->wire));
        $before = $control->wire;
        self::assertSame($state, $store->transaction(static fn (array &$value): array => $value));
        self::assertSame(1, $control->cas);
        self::assertSame($before, $control->wire);
        $store->transaction(static function (array &$value): void { $value['nonce'] = 'new'; });
        self::assertSame($before, $control->expected);
        self::assertSame('new', $store->transaction(static fn (array &$value): string => $value['nonce']));
    }

    public function testReaderDeploymentKeepsLegacyFormatButCanReadAndUpdatePackedFormat(): void
    {
        [$reader, $control] = $this->fixture(false);
        $original = ['large' => str_repeat('original', 20000)];
        $control->wire = $original;
        $reader->transaction(static function (array &$value): void { $value['changed'] = true; });
        self::assertIsArray($control->wire);
        $control->wire = 'weline-worker-state-gzip.v1:' . base64_encode(gzencode(json_encode($original), 1));
        $expected = $control->wire;
        $reader->transaction(static function (array &$value): void { $value['changed'] = true; });
        self::assertSame($expected, $control->expected);
        self::assertIsString($control->wire);
        self::assertTrue($reader->transaction(static fn (array &$value): bool => $value['changed']));
    }

    public function testExplicitCompactionCanConvertUnchangedLegacyState(): void
    {
        [$store, $control] = $this->fixture(true);
        $original = ['large' => str_repeat('original', 20000)];
        $control->wire = $original;
        $store->transaction(static fn (array &$value): int => 42);
        self::assertIsString($control->wire);
        self::assertSame($original, $control->expected);
        self::assertSame(1, $control->cas);
    }

    public function testCorruptPackedStateIsRejectedWithoutWrite(): void
    {
        [$store, $control] = $this->fixture(false);
        $control->wire = 'weline-worker-state-gzip.v1:' . base64_encode('not gzip');
        try {
            $store->transaction(static fn (array &$value): int => 42);
            self::fail('Corrupt authority must not become an empty state.');
        } catch (FrontendQueryException $error) {
            self::assertSame('worker_store_unavailable', $error->getErrorCode());
        }
        self::assertSame(0, $control->cas);
    }

    public function testCompressedStateStillConsumesNonceOnceAndRejectsReplay(): void
    {
        [$store, $control] = $this->fixture(true);
        $store->transaction(static function (array &$value): void { $value['padding'] = str_repeat('bounded-state', 10000); });
        $service = new FrontendWorkerSessionService($store);
        $session = $service->createSession('compression-test', 'compression-build');
        $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'compression-test', 'compression-build', 'once');
        $cas = $control->cas;
        try {
            $service->validateSessionAndConsumeNonce($session['worker_session_token'], 'compression-test', 'compression-build', 'once');
            self::fail('Replay must be rejected with packed authority.');
        } catch (FrontendQueryException $error) { self::assertSame('auth_error', $error->getErrorCode()); }
        self::assertSame($cas, $control->cas);
        self::assertIsString($control->wire);
    }

    public function testPackedExpansionBeyondExistingLimitNeverReachesCallback(): void
    {
        [$store, $control] = $this->fixture(false);
        $control->wire = 'weline-worker-state-gzip.v1:' . base64_encode(gzencode(json_encode(['huge' => str_repeat('x', 8388609)]), 1));
        $called = false;
        try {
            $store->transaction(static function (array &$value) use (&$called): void { $called = true; });
            self::fail('Packed data must not bypass the existing state limit.');
        } catch (FrontendQueryException $error) { self::assertSame('worker_store_unavailable', $error->getErrorCode()); }
        self::assertFalse($called);
        self::assertSame(0, $control->cas);
    }

    private function fixture(bool $compact): array
    {
        $control = (object)['wire' => null, 'expected' => null, 'cas' => 0];
        $adapter = $this->createMock(CompressionTestAdapter::class);
        $adapter->method('getFresh')->willReturnCallback(static fn () => $control->wire);
        $adapter->method('compareAndSet')->willReturnCallback(static function ($key, $expected, $value, $ttl) use ($control): bool {
            ++$control->cas; $control->expected = $expected;
            if ($control->wire !== $expected) { return false; }
            $control->wire = $value; return true;
        });
        return [new AtomicCacheFrontendWorkerStateStore($adapter, 'cache:wls_memory', 86400, true, $compact), $control];
    }
}
