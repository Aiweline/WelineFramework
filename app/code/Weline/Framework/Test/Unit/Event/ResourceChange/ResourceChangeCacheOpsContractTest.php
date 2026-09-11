<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\ResourceChange;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/** Impact cache_ops optional contract + anti-storm sizing. */
final class ResourceChangeCacheOpsContractTest extends TestCase
{
    public function testCacheOpsOptionalAcceptsValidOps(): void
    {
        $payload = $this->basePayload();
        $payload['impact']['cache_ops'] = [
            ['pool' => 'system_config', 'keys' => ['system_config_rows_abc']],
            ['pool' => 'database', 'keys' => ['system_config_rows_abc']],
        ];
        $change = ResourceChange::fromArray($payload);
        self::assertSame(2, count($change->toArray()['impact']['cache_ops']));
    }

    public function testLegacyImpactWithoutCacheOpsStillValid(): void
    {
        $change = ResourceChange::fromArray($this->basePayload());
        self::assertArrayNotHasKey('cache_ops', $change->toArray()['impact']);
    }

    public function testRejectsEmptyKeysAndBadPoolAndKeyCap(): void
    {
        $emptyKeys = $this->basePayload();
        $emptyKeys['impact']['cache_ops'] = [['pool' => 'system_config', 'keys' => []]];
        $this->expectValidation($emptyKeys);

        $badPool = $this->basePayload();
        $badPool['impact']['cache_ops'] = [['pool' => 'Bad Pool', 'keys' => ['a']]];
        $this->expectValidation($badPool);

        $tooMany = $this->basePayload();
        $keys = [];
        for ($i = 0; $i < ResourceChange::CACHE_OPS_MAX_KEYS + 1; $i++) {
            $keys[] = 'k' . $i;
        }
        $tooMany['impact']['cache_ops'] = [['pool' => 'system_config', 'keys' => $keys]];
        $this->expectValidation($tooMany);
    }

    /** @param array<string,mixed> $payload */
    private function expectValidation(array $payload): void
    {
        try {
            ResourceChange::fromArray($payload);
            self::fail('expected AsyncEventValidationException');
        } catch (AsyncEventValidationException) {
            self::assertTrue(true);
        }
    }

    /** @return array<string,mixed> */
    private function basePayload(): array
    {
        return [
            'schema_version' => ResourceChange::SCHEMA_VERSION,
            'event_id' => '0123456789abcdef0123456789abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => [
                'type' => 'system_config',
                'id' => 'demo',
                'action' => 'upsert',
                'revision' => 1,
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => null,
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => ['global/storefront/config'],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            'changed_fields' => ['captcha/google/api_key'],
            'before' => [],
            'after' => ['ok' => true],
            'origin' => [
                'area' => 'backend',
                'entry' => 'system_config.save',
                'request_id' => 'request-1',
                'instance' => 'unit-test',
                'trigger_by' => ['type' => 'admin', 'id' => 1],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'cli',
                'timezone' => 'UTC',
                'user' => ['type' => 'system', 'id' => null],
            ],
        ];
    }
}
