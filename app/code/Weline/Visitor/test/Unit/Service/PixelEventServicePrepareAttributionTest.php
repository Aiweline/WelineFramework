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

    public function testPreparePersistsValidatedPageBuilderAttributionForDefaultWebsite(): void
    {
        $fingerprint = str_repeat('a', 64);
        $prepared = $this->prepare([
            'eventName' => 'hero_cta_click',
            'websiteId' => 0,
            'url' => 'https://default.weline.test/chess-club',
            'additionalInfo' => [
                'environment' => [
                    'website_id' => '0',
                    'session_id' => 'pb-v2-session-0',
                ],
                'pagebuilder_attribution' => [
                    'attribution_version' => 'pagebuilder_ai_v1',
                    'source' => 'pagebuilder_rendered_dom',
                    'surface' => 'published',
                    'analytics_consent' => 'granted',
                    'website_id' => 0,
                    'page_type' => 'home_page',
                    'block_key' => 'hero',
                    'plan_revision' => 7,
                    'content_fingerprint' => $fingerprint,
                    'experiment_id' => 'seo_experiment_01',
                    'variant' => 'candidate',
                    'canonical_path' => '/chess-club',
                ],
            ],
        ]);

        $data = $prepared['data'];
        self::assertSame('pagebuilder_ai_v1', $data['attribution_version']);
        self::assertSame(0, $data['website_id']);
        self::assertSame('home_page', $data['page_type']);
        self::assertSame('hero', $data['block_key']);
        self::assertSame(7, $data['plan_revision']);
        self::assertSame($fingerprint, $data['content_fingerprint']);
        self::assertSame('seo_experiment_01', $data['experiment_id']);
        self::assertSame('candidate', $data['variant']);
    }

    public function testPrepareRejectsPreviewOrDeniedPageBuilderAttribution(): void
    {
        $fingerprint = str_repeat('a', 64);
        foreach ([
            'preview' => ['preview' => true],
            'denied_consent' => ['analytics_consent' => 'denied'],
        ] as $case => $override) {
            $prepared = $this->prepare([
                'eventName' => 'hero_cta_click',
                'websiteId' => 0,
                'url' => 'https://default.weline.test/chess-club',
                'additionalInfo' => [
                    'environment' => ['website_id' => '0'],
                    'pagebuilder_attribution' => array_replace([
                        'attribution_version' => 'pagebuilder_ai_v1',
                        'source' => 'pagebuilder_rendered_dom',
                        'surface' => 'published',
                        'analytics_consent' => 'granted',
                        'preview' => false,
                        'website_id' => 0,
                        'page_type' => 'home_page',
                        'block_key' => 'hero',
                        'plan_revision' => 7,
                        'content_fingerprint' => $fingerprint,
                        'canonical_path' => '/chess-club',
                    ], $override),
                ],
            ]);

            $data = $prepared['data'];
            self::assertSame('', $data['attribution_version'], $case);
            self::assertSame('', $data['page_type'], $case);
            self::assertSame('', $data['block_key'], $case);
            self::assertSame(0, $data['plan_revision'], $case);
            self::assertSame('', $data['content_fingerprint'], $case);
            self::assertSame('', $data['experiment_id'], $case);
            self::assertSame('', $data['variant'], $case);
        }
    }
}
