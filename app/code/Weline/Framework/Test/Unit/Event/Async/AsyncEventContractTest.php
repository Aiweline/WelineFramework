<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\Async;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\CanonicalJson;
use Weline\Framework\Event\Async\ContextSnapshot;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangePayloadMapper;
use Weline\Framework\Env\WelineEnv;

/** Plan coverage: EVT02, RC01, ZERO01. */
final class AsyncEventContractTest extends TestCase
{
    public function testEvt02CanonicalJsonIsStableAndRejectsUnsafePayloads(): void
    {
        $canonical = new CanonicalJson();

        self::assertSame(
            '{"a":{"x":1,"y":2},"b":2,"list":[3,2,1]}',
            $canonical->encode([
                'b' => 2,
                'list' => [3, 2, 1],
                'a' => ['y' => 2, 'x' => 1],
            ]),
        );
        self::assertSame(
            $canonical->hash(['b' => 2, 'a' => 1]),
            $canonical->hash(['a' => 1, 'b' => 2]),
        );

        $invalidPayloads = [
            'object' => ['value' => new \stdClass()],
            'resource' => ['value' => fopen('php://memory', 'r')],
            'non_finite' => ['value' => INF],
            'sensitive_key' => ['api_token' => 'must-not-persist'],
            'oversized' => ['value' => str_repeat('x', CanonicalJson::MAX_BYTES)],
            'too_deep' => $this->nestedPayload(CanonicalJson::MAX_DEPTH + 1),
        ];

        foreach ($invalidPayloads as $label => $payload) {
            try {
                $canonical->encode($payload);
                self::fail($label . ' payload must be rejected before Outbox persistence.');
            } catch (AsyncEventValidationException) {
                self::assertTrue(true, $label);
            } finally {
                if ($label === 'resource' && is_resource($payload['value'])) {
                    fclose($payload['value']);
                }
            }
        }
    }

    public function testEvt02ContextAllowsZeroWebsiteButRejectsPrivilegeRestorationFields(): void
    {
        $snapshot = new ContextSnapshot();
        $context = $this->context();

        $snapshot->validate($context);
        self::assertSame(0, $context['website_id']);
        self::assertSame('system', $context['user']['type']);

        foreach (
            [
                'negative_website' => array_replace($context, ['website_id' => -1]),
                'unknown_root_field' => array_replace($context, ['csrf' => 'forbidden']),
                'privilege_field' => array_replace($context, [
                    'user' => ['type' => 'admin', 'id' => 1, 'permissions' => ['*']],
                ]),
            ] as $label => $invalid
        ) {
            try {
                $snapshot->validate($invalid);
                self::fail($label . ' context must be rejected.');
            } catch (AsyncEventValidationException) {
                self::assertTrue(true, $label);
            }
        }
    }

    public function testContextCaptureCanonicalizesRestTransportAreas(): void
    {
        $previousArea = WelineEnv::getArea();
        try {
            WelineEnv::setArea('rest_backend');
            WelineEnv::set('website_id', 0, 'test');
            WelineEnv::set('website_code', 'default', 'test');
            self::assertSame('backend', (new ContextSnapshot())->capture(0, 'default')['area']);

            WelineEnv::setArea('rest_frontend');
            self::assertSame('frontend', (new ContextSnapshot())->capture(0, 'default')['area']);
        } finally {
            WelineEnv::setArea($previousArea);
        }
    }

    public function testRc01ResourceChangeRoundTripsDefaultWebsiteAndDeleteTombstone(): void
    {
        $payload = $this->resourcePayload();
        $change = ResourceChange::fromArray($payload);
        $mapper = new ResourceChangePayloadMapper(new CanonicalJson(), new ContextSnapshot());

        self::assertSame(ResourceChange::EVENT_NAME, $mapper->eventName());
        self::assertSame(1, $mapper->schemaVersion());
        self::assertSame('website', $change->resourceType());
        self::assertSame('0', $change->resourceId());
        self::assertSame(0, $change->websiteId());
        self::assertSame('website:0', $change->coalesceKey());
        self::assertNull($change->toArray()['after']);
        self::assertSame($payload, $mapper->toPayload($change));
        self::assertSame($payload, $mapper->fromPayload($payload)->toArray());
    }

    public function testRc01RejectsIdentityTombstoneAndContextDrift(): void
    {
        $valid = $this->resourcePayload();
        $invalidPayloads = [];

        $negativeWebsite = $valid;
        $negativeWebsite['website']['id'] = -1;
        $invalidPayloads['negative_website'] = $negativeWebsite;

        $nonNullDelete = $valid;
        $nonNullDelete['after'] = ['code' => 'default'];
        $invalidPayloads['delete_after'] = $nonNullDelete;

        $contextDrift = $valid;
        $contextDrift['context']['website_id'] = 7;
        $invalidPayloads['context_drift'] = $contextDrift;

        $unknownField = $valid;
        $unknownField['resource']['class'] = 'Injected\\Handler';
        $invalidPayloads['unknown_resource_field'] = $unknownField;

        foreach ($invalidPayloads as $label => $payload) {
            try {
                ResourceChange::fromArray($payload);
                self::fail($label . ' ResourceChange must be rejected.');
            } catch (AsyncEventValidationException) {
                self::assertTrue(true, $label);
            }
        }
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'website_id' => 0,
            'website_code' => 'default',
            'lang' => 'zh_Hans_CN',
            'currency' => 'CNY',
            'area' => 'cli',
            'timezone' => 'UTC',
            'user' => ['type' => 'system', 'id' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function resourcePayload(): array
    {
        return [
            'schema_version' => 1,
            'event_id' => '0123456789abcdef0123456789abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => [
                'type' => 'website',
                'id' => '0',
                'action' => 'delete',
                'revision' => 3,
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => 'legacy-default',
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => [],
                'previous_namespaces' => ['website/default', 'website/legacy-default'],
                'urls' => [],
                'previous_urls' => ['https://example.test/'],
            ],
            'changed_fields' => ['code', 'url'],
            'before' => ['code' => 'default', 'url' => 'https://example.test/'],
            'after' => null,
            'origin' => [
                'area' => 'backend',
                'entry' => 'website.deleteDelete',
                'request_id' => 'request-1',
                'instance' => 'unit-test',
                'trigger_by' => ['type' => 'admin', 'id' => 1],
            ],
            'context' => $this->context(),
        ];
    }

    /** @return array<string, mixed> */
    private function nestedPayload(int $depth): array
    {
        $payload = ['value' => 'leaf'];
        for ($i = 0; $i < $depth; $i++) {
            $payload = ['level_' . $i => $payload];
        }
        return $payload;
    }
}
