<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use ReflectionMethod;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\PixelEventService;

/**
 * A04：HTTP prepare 写入 session_id + 归因扁平列（不查库）。
 */
class PixelEventServicePrepareAttributionTest extends TestCore
{
    /**
     * @param array<string, mixed> $payload
     * @return array{post: array<string, mixed>, data: array<string, mixed>, event_id: string, received_at: int}
     */
    private function prepare(array $payload): array
    {
        /** @var PixelEventService $service */
        $service = ObjectManager::getInstance(PixelEventService::class);
        $method = new ReflectionMethod(PixelEventService::class, 'prepare');
        $method->setAccessible(true);

        /** @var array{post: array<string, mixed>, data: array<string, mixed>, event_id: string, received_at: int} $result */
        $result = $method->invoke($service, $payload);

        return $result;
    }

    public function testPrepareWritesSessionAndWchChannelCode(): void
    {
        $prepared = $this->prepare([
            'eventName' => 'page_view',
            'websiteId' => 930401,
            'url' => 'https://example.test/landing?wch=summer_ad&utm_source=google&utm_medium=cpc&utm_campaign=other',
            'referer' => 'https://google.com/',
            'additionalInfo' => [
                'environment' => [
                    'session_id' => 'wps-a04-session-1',
                    'page_path' => '/landing',
                ],
            ],
        ]);

        $data = $prepared['data'];
        self::assertSame('wps-a04-session-1', $data['session_id']);
        self::assertSame('summer_ad', $data['channel_code']);
        // B07/S4：无 campaign 行时展示「未登记」（表未 upgrade 时同）
        self::assertSame('未登记', $data['channel_name']);
        self::assertSame('paid', $data['traffic_type']);
        self::assertSame('google', $data['utm_source']);
        self::assertSame('cpc', $data['utm_medium']);
        self::assertSame('other', $data['utm_campaign']);
    }

    public function testPrepareStickyOverridesUrl(): void
    {
        $prepared = $this->prepare([
            'eventName' => 'page_view',
            'websiteId' => 930402,
            'url' => 'https://example.test/?wch=second&utm_medium=cpc',
            'session_id' => 'wps-a04-sticky',
            'sticky' => [
                'wch' => 'first',
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'welcome',
            ],
        ]);

        $data = $prepared['data'];
        self::assertSame('wps-a04-sticky', $data['session_id']);
        self::assertSame('first', $data['channel_code']);
        self::assertSame('email', $data['traffic_type']);
        self::assertSame('newsletter', $data['utm_source']);
        self::assertSame('email', $data['utm_medium']);
        self::assertSame('welcome', $data['utm_campaign']);
    }

    public function testPrepareDirectWhenNoSignals(): void
    {
        $prepared = $this->prepare([
            'eventName' => 'page_view',
            'websiteId' => 930403,
            'url' => 'https://example.test/home',
            'additionalInfo' => [
                'funnel' => ['session_id' => 'wps-a04-direct'],
            ],
        ]);

        $data = $prepared['data'];
        self::assertSame('wps-a04-direct', $data['session_id']);
        self::assertSame('', $data['channel_code']);
        self::assertSame('direct', $data['traffic_type']);
        self::assertSame('', $data['utm_source']);
        self::assertSame('', $data['utm_medium']);
        self::assertSame('', $data['utm_campaign']);
    }
}
