<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Observer\ResourceChanged;
use Weline\Cdn\Service\CachePurger;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;

/** Plan coverage: ZERO02 and QUEUE04 CDN consumer isolation/input scope. */
final class ResourceChangedZeroSiteTest extends TestCase
{
    public function testZero02DefaultWebsiteQueriesSiteZeroAndPurgesOnlyMatchingUrls(): void
    {
        $probe = (object)['where' => []];
        $item = $this->createStub(Domain::class);
        $item->method('getData')->willReturnCallback(static fn(string $field): mixed => match ($field) {
            Domain::schema_fields_DOMAIN_ID => 17,
            Domain::schema_fields_DOMAIN_NAME => 'example.test',
            default => null,
        });
        $domains = new class($probe, [$item]) extends Domain {
            public function __construct(private object $probe, private array $fakeItems)
            {
            }

            public function reset(): static
            {
                return $this;
            }

            public function where(mixed $field, mixed $value): static
            {
                $this->probe->where[] = [$field, $value];
                return $this;
            }

            public function select(): static
            {
                return $this;
            }

            public function fetch(): static
            {
                return $this;
            }

            public function getItems(): array
            {
                return $this->fakeItems;
            }
        };
        $purger = $this->createMock(CachePurger::class);
        $purger->expects(self::once())
            ->method('purge')
            ->with(17, 'urls', ['urls' => [
                'https://example.test/new',
                'https://cdn.example.test/old',
            ]])
            ->willReturn(['success' => true]);

        $observer = new ResourceChanged($domains, $purger);
        $event = new Event(ResourceChange::EVENT_NAME, ['data' => $this->change()]);
        $observer->execute($event);

        self::assertContains([Domain::schema_fields_SITE_ID, 0], $probe->where);
        self::assertContains([Domain::schema_fields_ENABLED, 1], $probe->where);
    }

    public function testQueue04ContractMismatchIsNonRetryableAndDoesNotPurge(): void
    {
        $purger = $this->createMock(CachePurger::class);
        $purger->expects(self::never())->method('purge');
        $domains = new class extends Domain {
            public function __construct()
            {
            }
        };
        $observer = new ResourceChanged($domains, $purger);
        $event = new Event(ResourceChange::EVENT_NAME, ['data' => ['website_id' => 0]]);

        $this->expectException(\Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException::class);
        $observer->execute($event);
    }

    private function change(): ResourceChange
    {
        return ResourceChange::fromArray([
            'schema_version' => 1,
            'event_id' => '1234567890abcdef1234567890abcdef',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => ['type' => 'website', 'id' => '0', 'action' => 'upsert', 'revision' => 5],
            'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0],
            'impact' => [
                'namespaces' => ['website/default'],
                'previous_namespaces' => [],
                'urls' => ['https://example.test/new', 'https://unrelated.test/skip'],
                'previous_urls' => ['https://cdn.example.test/old'],
            ],
            'changed_fields' => ['url'],
            'before' => ['url' => 'https://cdn.example.test/old'],
            'after' => ['url' => 'https://example.test/new'],
            'origin' => [
                'area' => 'backend',
                'entry' => 'website.edit',
                'request_id' => 'request-1',
                'instance' => 'unit-test',
                'trigger_by' => ['type' => 'admin', 'id' => 1],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'backend',
                'timezone' => 'UTC',
                'user' => ['type' => 'admin', 'id' => 1],
            ],
        ]);
    }
}
