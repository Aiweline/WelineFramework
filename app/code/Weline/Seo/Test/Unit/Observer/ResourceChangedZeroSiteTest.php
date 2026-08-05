<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Seo\Observer\ResourceChanged;
use Weline\Seo\Service\UrlSubmitService;

/** Plan coverage: ZERO02, WEB04, QUEUE04 consumer isolation contract. */
final class ResourceChangedZeroSiteTest extends TestCase
{
    public function testZero02DefaultWebsiteQueuesCurrentAndPreviousUrlsWithoutDroppingIdZero(): void
    {
        $service = new class extends UrlSubmitService {
            /** @var list<array{targets:array,scope:string,extra:array}> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function enqueueTargets(array $targets, string $scope, array $extra = []): array
            {
                $this->calls[] = compact('targets', 'scope', 'extra');
                return ['errors' => 0];
            }
        };
        $observer = new ResourceChanged($service);
        $event = new Event(ResourceChange::EVENT_NAME, ['data' => $this->change()]);

        $observer->execute($event);

        self::assertCount(2, $service->calls);
        self::assertSame([
            ['website_id' => 0, 'url' => 'https://new.example.test/'],
        ], $service->calls[0]['targets']);
        self::assertSame('upsert', $service->calls[0]['extra']['action']);
        self::assertSame(0, $service->calls[0]['extra']['subject_id']);
        self::assertSame([
            ['website_id' => 0, 'url' => 'https://old.example.test/'],
        ], $service->calls[1]['targets']);
        self::assertSame('delete', $service->calls[1]['extra']['action']);
        self::assertSame(4, $service->calls[1]['extra']['resource_revision']);
    }

    public function testWeb04ConsumerFailurePropagatesToCriticalDispatch(): void
    {
        $service = new class extends UrlSubmitService {
            public function __construct()
            {
            }

            public function enqueueTargets(array $targets, string $scope, array $extra = []): array
            {
                return ['errors' => 1, 'error' => 'forced'];
            }
        };
        $observer = new ResourceChanged($service);
        $event = new Event(ResourceChange::EVENT_NAME, ['data' => $this->change()]);

        $this->expectException(\RuntimeException::class);
        $observer->execute($event);
    }

    public function testWeb04RejectsMutableOrUnversionedPayload(): void
    {
        $service = new class extends UrlSubmitService {
            public int $calls = 0;

            public function __construct()
            {
            }

            public function enqueueTargets(array $targets, string $scope, array $extra = []): array
            {
                $this->calls++;
                return ['errors' => 0];
            }
        };
        $observer = new ResourceChanged($service);
        $event = new Event(ResourceChange::EVENT_NAME, ['data' => ['website_id' => 0]]);

        try {
            $observer->execute($event);
            self::fail('Mutable array payload must not reach the SEO consumer.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $service->calls);
        }
    }

    private function change(): ResourceChange
    {
        return ResourceChange::fromArray([
            'schema_version' => 1,
            'event_id' => 'abcdef0123456789abcdef0123456789',
            'event_name' => ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-07-23T03:16:00.123456Z',
            'resource' => [
                'type' => 'website',
                'id' => '0',
                'action' => 'upsert',
                'revision' => 4,
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => null,
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => ['website/default'],
                'previous_namespaces' => [],
                'urls' => ['https://new.example.test/', 'https://new.example.test/'],
                'previous_urls' => ['https://old.example.test/'],
            ],
            'changed_fields' => ['url'],
            'before' => ['url' => 'https://old.example.test/'],
            'after' => ['url' => 'https://new.example.test/'],
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
