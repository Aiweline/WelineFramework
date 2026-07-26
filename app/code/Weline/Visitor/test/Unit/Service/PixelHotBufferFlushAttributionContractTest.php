<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelEventService;

/**
 * A05：热缓冲 flush 与 prepare 共用 hydratePreparedAttribution。
 */
class PixelHotBufferFlushAttributionContractTest extends TestCore
{
    public function testHydrateFillsLegacyBufferedRowWithoutFlatColumns(): void
    {
        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);

        $hydrated = $service->hydratePreparedAttribution(
            [
                'url' => 'https://example.test/x?wch=flush_ad&utm_source=google&utm_medium=cpc&utm_campaign=x',
                'referer' => 'https://google.com/',
                'session_id' => 'wps-a05-legacy',
                'additionalInfo' => [],
            ],
            [
                'url' => 'https://example.test/x?wch=flush_ad&utm_source=google&utm_medium=cpc&utm_campaign=x',
                'website_id' => 930501,
                'referer' => 'https://google.com/',
            ]
        );

        self::assertSame('wps-a05-legacy', $hydrated['session_id']);
        self::assertSame('flush_ad', $hydrated['channel_code']);
        self::assertSame('paid', $hydrated['traffic_type']);
        self::assertSame('google', $hydrated['utm_source']);
        self::assertSame('cpc', $hydrated['utm_medium']);
        self::assertSame('x', $hydrated['utm_campaign']);
    }

    public function testPrepareAndHydrateAgreeOnWch(): void
    {
        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $payload = [
            'eventName' => 'page_view',
            'websiteId' => 930503,
            'url' => 'https://example.test/p?wch=same_code&utm_source=x&utm_medium=cpc',
            'additionalInfo' => [
                'environment' => ['session_id' => 'wps-a05-agree'],
            ],
        ];

        $prepare = new ReflectionMethod(PixelEventService::class, 'prepare');
        $prepare->setAccessible(true);
        /** @var array{post: array<string, mixed>, data: array<string, mixed>} $prepared */
        $prepared = $prepare->invoke($service, $payload);

        $legacyData = [
            'url' => $prepared['data']['url'],
            'website_id' => $prepared['data']['website_id'],
            'referer' => $prepared['data']['referer'],
            'session_id' => $prepared['data']['session_id'],
        ];
        $hydrated = $service->hydratePreparedAttribution($prepared['post'], $legacyData);

        self::assertSame($prepared['data']['channel_code'], $hydrated['channel_code']);
        self::assertSame($prepared['data']['traffic_type'], $hydrated['traffic_type']);
        self::assertSame($prepared['data']['utm_source'], $hydrated['utm_source']);
        self::assertSame($prepared['data']['utm_medium'], $hydrated['utm_medium']);
        self::assertSame($prepared['data']['utm_campaign'], $hydrated['utm_campaign']);
        self::assertSame('same_code', $hydrated['channel_code']);
    }

    public function testHotBufferFlushSourceCallsHydrate(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Visitor/Service/PixelHotBufferService.php'
        );
        self::assertStringContainsString('hydratePreparedAttribution', $source);
        self::assertStringContainsString('persistPrepared($event[\'post\'], $data)', $source);
    }
}
