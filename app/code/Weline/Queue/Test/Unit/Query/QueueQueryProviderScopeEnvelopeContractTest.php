<?php

declare(strict_types=1);

namespace Weline\Queue\Test\Unit\Query;

// Prefer in-tree Queue model over a packaged vendor copy with the same namespace.
require_once dirname(__DIR__, 3) . '/Model/Queue.php';
require_once dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/QueueQueryProvider.php';

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Queue\Extends\Module\Weline_Framework\Query\QueueQueryProvider;
use Weline\Queue\Model\Queue;

/**
 * TEST-P1B-01 focused contract for scope_envelope producer shaping.
 * Uses reflection against QueryProvider helpers to avoid DB side effects.
 */
final class QueueQueryProviderScopeEnvelopeContractTest extends TestCase
{
    public function testDescriptorPublishesScopeEnvelopeOnCreateOperations(): void
    {
        $descriptor = $this->providerWithoutConstructor()->getDescriptor();
        $operations = [];
        foreach ($descriptor['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        foreach (['create', 'createIfAbsent'] as $name) {
            $params = [];
            foreach ($operations[$name]['params'] as $param) {
                $params[(string)$param['name']] = $param;
            }
            self::assertArrayHasKey('scope_envelope', $params, $name);
            self::assertFalse((bool)($params['scope_envelope']['required'] ?? false));
        }
    }

    public function testFourKindsExpandToExpectedNullabilityAndZeroChannelDiffersFromGlobal(): void
    {
        $provider = $this->providerWithoutConstructor();
        $apply = new \ReflectionMethod(QueueQueryProvider::class, 'applyScopeEnvelopeParam');
        $apply->setAccessible(true);
        $parse = new \ReflectionMethod(QueueQueryProvider::class, 'parseScopeEnvelopeParam');
        $parse->setAccessible(true);

        $cases = [
            'global' => [
                'envelope' => ScopeEnvelope::of(ScopeIdentity::global()),
                'kind' => 'global',
                'website_id' => null,
                'store_code' => null,
                'channel_code' => null,
                'store_mode' => null,
            ],
            'website' => [
                'envelope' => ScopeEnvelope::of(ScopeIdentity::website(0, 'default')),
                'kind' => 'website',
                'website_id' => 0,
                'store_code' => null,
                'channel_code' => null,
                'store_mode' => null,
            ],
            'store' => [
                'envelope' => ScopeEnvelope::of(ScopeIdentity::store(
                    1,
                    'site-a',
                    'main',
                    ScopeIdentity::MODE_NORMAL,
                )),
                'kind' => 'store',
                'website_id' => 1,
                'store_code' => 'main',
                'channel_code' => null,
                'store_mode' => 'normal',
            ],
            'channel' => [
                'envelope' => ScopeEnvelope::of(ScopeIdentity::channel(
                    0,
                    'default',
                    'default',
                    'default',
                    ScopeIdentity::MODE_NORMAL,
                )),
                'kind' => 'channel',
                'website_id' => 0,
                'store_code' => 'default',
                'channel_code' => 'default',
                'store_mode' => 'normal',
            ],
        ];

        foreach ($cases as $label => $case) {
            /** @var Queue $queue */
            $queue = (new \ReflectionClass(Queue::class))->newInstanceWithoutConstructor();
            $apply->invoke($provider, $queue, [
                'scope_envelope' => $case['envelope']->toArray(),
            ]);
            self::assertSame($case['kind'], $queue->getData(Queue::schema_fields_SCOPE_KIND), $label);
            self::assertSame($case['website_id'], $queue->getData(Queue::schema_fields_SCOPE_WEBSITE_ID), $label);
            self::assertSame($case['store_code'], $queue->getData(Queue::schema_fields_SCOPE_STORE_CODE), $label);
            self::assertSame($case['channel_code'], $queue->getData(Queue::schema_fields_SCOPE_CHANNEL_CODE), $label);
            self::assertSame($case['store_mode'], $queue->getData(Queue::schema_fields_SCOPE_STORE_MODE), $label);
            self::assertSame('v1', $queue->getData(Queue::schema_fields_SCOPE_ENVELOPE_VERSION), $label);
        }

        $globalKey = $parse->invoke($provider, $cases['global']['envelope']->toArray())->canonicalKey();
        $zeroChannelKey = $parse->invoke($provider, $cases['channel']['envelope']->toArray())->canonicalKey();
        self::assertNotSame($globalKey, $zeroChannelKey);
        self::assertStringContainsString('channel|0|', $zeroChannelKey);
        self::assertStringContainsString('global|', $globalKey);
    }

    public function testLooseScopeParamsAndProtocolContentKeysAreRejected(): void
    {
        $provider = $this->providerWithoutConstructor();
        $loose = new \ReflectionMethod(QueueQueryProvider::class, 'assertNoLooseScopeParams');
        $loose->setAccessible(true);
        $content = new \ReflectionMethod(QueueQueryProvider::class, 'assertNoScopeLeakInContent');
        $content->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $loose->invoke($provider, ['scope_kind' => 'global']);
    }

    public function testProtocolScopeKeysInsideContentAreRejected(): void
    {
        $provider = $this->providerWithoutConstructor();
        $content = new \ReflectionMethod(QueueQueryProvider::class, 'assertNoScopeLeakInContent');
        $content->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $content->invoke($provider, '{"scope_kind":"global","delivery_id":1}');
    }

    private function providerWithoutConstructor(): QueueQueryProvider
    {
        /** @var QueueQueryProvider $provider */
        $provider = (new \ReflectionClass(QueueQueryProvider::class))->newInstanceWithoutConstructor();

        return $provider;
    }
}
