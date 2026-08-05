<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\Module\Weline_Framework\Query\VisitorQueryProvider;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Service\OptimizationSnapshotService;
use Weline\Visitor\Service\PageBuilderOptimizationAttributionService;
use Weline\Visitor\Service\PixelEventPersistenceService;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\PixelHotBufferService;
use Weline\Visitor\Service\VisitorAnalyticsWorkerService;

final class VisitorAcceptanceFixtureProductionAttributionTest extends TestCore
{
    private const DYNAMIC_WEBSITE_ID = 113;

    /** @var list<int> */
    private array $pixelIds = [];

    /** @var list<string> */
    private array $requestKeys = [];

    protected function tearDown(): void
    {
        foreach ($this->pixelIds as $pixelId) {
            /** @var Pixel $pixel */
            $pixel = ObjectManager::getInstance(Pixel::class)->clear()->load($pixelId);
            if ($pixel->getId()) {
                $pixel->delete();
            }
        }
        foreach ($this->requestKeys as $requestKey) {
            $this->forgetFixtureReceipt($requestKey);
        }
        \putenv('WELINE_ACCEPTANCE_FIXTURES');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS');

        parent::tearDown();
    }

    public function testFixtureCanonicalizesLegacyBlockExposureIntoProductionPixelEnvelope(): void
    {
        $this->enableFixture(87);
        $requestKey = $this->requestKey('payload');
        $pixelService = new class extends PixelEventService {
            /** @var list<array<string,mixed>> */
            public array $tracked = [];

            public function __construct()
            {
            }

            public function track(array $payload): array
            {
                $this->tracked[] = $payload;

                return ['success' => true, 'data' => []];
            }

            public function persistAcceptanceFixtureEvent(array $payload): array
            {
                $this->tracked[] = $payload;

                return [
                    'success' => true,
                    'data' => [
                        'pixel_id' => \count($this->tracked),
                        'buffered' => false,
                    ],
                ];
            }

            public function cleanupAcceptanceFixtureEvents(
                int $websiteId,
                array $ownedEvents,
                string $requestKey
            ): array {
                return [
                    'contract' => 'visitor.acceptance_fixture_cleanup.v1',
                    'request_key' => $requestKey,
                    'website_id' => $websiteId,
                    'expected_event_count' => \count($ownedEvents),
                    'matched_event_count' => \count($ownedEvents),
                    'deleted_event_count' => \count($ownedEvents),
                    'deleted_pixel_ids' => [],
                    'missing_event_count' => 0,
                    'complete' => true,
                ];
            }
        };

        $receipt = $this->provider($pixelService)->execute(
            'analyticsAcceptanceFixture',
            $this->fixtureParams(87, $requestKey, [
                $this->manifestEvent('block_impression', '2026-06-10 12:01:00'),
            ])
        );

        self::assertSame('seeded', $receipt['status']);
        self::assertSame('ai_block_impression', $receipt['created_events'][0]['event']);
        self::assertCount(1, $pixelService->tracked);
        self::assertSame('ai_block_impression', $pixelService->tracked[0]['eventName']);
        self::assertSame(
            'pagebuilder_rendered_dom',
            $pixelService->tracked[0]['additionalInfo']['pagebuilder_attribution']['source']
        );
    }

    public function testDynamicWebsiteFixturePersistsProductionAttributionFeedsSnapshotAndCleansPrecisely(): void
    {
        $websiteId = self::DYNAMIC_WEBSITE_ID;
        $this->enableFixture($websiteId);
        $requestKey = $this->requestKey('persist');
        $fingerprint = \hash('sha256', $requestKey);
        $pixelService = $this->persistentPixelEventService();
        $provider = $this->provider($pixelService, new OptimizationSnapshotService());
        $params = $this->fixtureParams($websiteId, $requestKey, [
            $this->manifestEvent('page_view', '2026-06-10 12:00:00', $fingerprint),
            $this->manifestEvent('block_impression', '2026-06-10 12:01:00', $fingerprint),
            $this->manifestEvent('hero_cta_click', '2026-06-10 12:02:00', $fingerprint),
        ]);

        $receipt = $provider->execute('analyticsAcceptanceFixture', $params);
        self::assertSame('seeded', $receipt['status']);
        self::assertSame(
            ['page_view', 'ai_block_impression', 'hero_cta_click'],
            \array_column($receipt['created_events'], 'event')
        );

        $sessionId = (string)$receipt['created_events'][0]['session_id'];
        $rows = $this->fixtureRows($websiteId, $sessionId);
        self::assertCount(3, $rows);
        $this->rememberPixels($rows);
        $byEvent = [];
        foreach ($rows as $row) {
            $byEvent[(string)$row->getEvent()] = $row;
        }
        self::assertArrayHasKey('ai_block_impression', $byEvent);
        /** @var Pixel $impression */
        $impression = $byEvent['ai_block_impression'];
        self::assertSame('pagebuilder_ai_v1', (string)$impression->getAttributionVersion());
        self::assertSame('home_page', (string)$impression->getPageType());
        self::assertSame('hero', (string)$impression->getBlockKey());
        self::assertSame(127, (int)$impression->getPlanRevision());
        self::assertSame($fingerprint, (string)$impression->getContentFingerprint());
        $browserInfo = \json_decode((string)$impression->getBrowserInfo(), true);
        self::assertSame(
            'pagebuilder_rendered_dom',
            $browserInfo['additionalInfo']['pagebuilder_attribution']['source'] ?? null
        );

        $snapshot = $provider->execute('analyticsOptimizationSnapshot', [
            'website_id' => $websiteId,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'page_type' => 'home_page',
            'block_key' => 'hero',
            'plan_revision' => 127,
            'content_fingerprint' => $fingerprint,
            'target_event' => 'hero_cta_click',
        ]);
        self::assertTrue($snapshot['data_quality']['complete']);
        self::assertSame(1, $snapshot['summary']['page_views']);
        self::assertSame(1, $snapshot['summary']['block_impressions']);
        self::assertSame(1, $snapshot['summary']['target_events']);
        self::assertSame(1, $snapshot['summary']['conversion_denominator']);

        $replay = $provider->execute('analyticsAcceptanceFixture', $params);
        self::assertTrue($replay['replayed']);
        self::assertCount(3, $this->fixtureRows($websiteId, $sessionId));

        $unrelated = $pixelService->track(
            $this->unrelatedPixelPayload($websiteId, $sessionId, $fingerprint, $requestKey)
        );
        $unrelatedPixelId = (int)($unrelated['data']['pixel_id'] ?? 0);
        self::assertGreaterThan(0, $unrelatedPixelId);
        $this->pixelIds[] = $unrelatedPixelId;

        $cleaned = $provider->execute('analyticsAcceptanceFixture', [
            'action' => 'cleanup',
            'context' => 'server',
            'case_id' => 'ai-seo-v2-closed-loop',
            'request_key' => $requestKey,
            'website_id' => $websiteId,
        ]);
        self::assertSame('cleaned', $cleaned['status']);
        self::assertSame(3, $cleaned['cleanup']['result']['deleted_event_count']);
        self::assertCount(1, $this->fixtureRows($websiteId, $sessionId));
        /** @var Pixel $unrelatedAfterCleanup */
        $unrelatedAfterCleanup = ObjectManager::getInstance(Pixel::class)->clear()->load($unrelatedPixelId);
        self::assertSame($unrelatedPixelId, (int)$unrelatedAfterCleanup->getId());
    }

    public function testFixtureBypassesEnabledHotBufferAndCleanupLeavesNoDeferredOrphan(): void
    {
        $websiteId = self::DYNAMIC_WEBSITE_ID;
        $this->enableFixture($websiteId);
        $requestKey = $this->requestKey('buffer-bypass');
        $hotBuffer = new class extends PixelHotBufferService {
            /** @var list<array<string,mixed>> */
            public array $envelopes = [];

            public function __construct()
            {
            }

            public function buffer(array $envelope): ?array
            {
                $this->envelopes[] = $envelope;

                return ['buffered' => true, 'bucket' => 'acceptance-test'];
            }
        };
        $pixelService = $this->persistentPixelEventService($hotBuffer);
        $provider = $this->provider($pixelService, new OptimizationSnapshotService());
        $params = $this->fixtureParams($websiteId, $requestKey, [
            $this->manifestEvent(
                'ai_block_impression',
                '2026-06-10 13:00:00',
                \hash('sha256', $requestKey)
            ),
        ]);

        $receipt = $provider->execute('analyticsAcceptanceFixture', $params);
        self::assertSame('seeded', $receipt['status']);
        self::assertSame([], $hotBuffer->envelopes);
        self::assertCount(1, $receipt['created_event_ids']);
        self::assertIsInt($receipt['created_event_ids'][0]);
        self::assertGreaterThan(0, $receipt['created_event_ids'][0]);

        $sessionId = (string)$receipt['created_events'][0]['session_id'];
        $rows = $this->fixtureRows($websiteId, $sessionId);
        self::assertCount(1, $rows);
        $this->rememberPixels($rows);

        $cleaned = $provider->execute('analyticsAcceptanceFixture', [
            'action' => 'cleanup',
            'context' => 'server',
            'case_id' => 'ai-seo-v2-closed-loop',
            'request_key' => $requestKey,
            'website_id' => $websiteId,
        ]);
        self::assertSame('cleaned', $cleaned['status']);
        self::assertSame(1, $cleaned['cleanup']['result']['deleted_event_count']);
        self::assertSame([], $this->fixtureRows($websiteId, $sessionId));
        self::assertSame([], $hotBuffer->envelopes);
    }

    public function testFixtureRejectsAttributionFieldsProductionWouldStrip(): void
    {
        $websiteId = self::DYNAMIC_WEBSITE_ID;
        $this->enableFixture($websiteId);
        $pixelService = new class extends PixelEventService {
            /** @var list<array<string,mixed>> */
            public array $tracked = [];

            public function __construct()
            {
            }

            public function track(array $payload): array
            {
                $this->tracked[] = $payload;

                return ['success' => true, 'data' => []];
            }

            public function persistAcceptanceFixtureEvent(array $payload): array
            {
                $this->tracked[] = $payload;

                return [
                    'success' => true,
                    'data' => [
                        'pixel_id' => \count($this->tracked),
                        'buffered' => false,
                    ],
                ];
            }
        };
        $provider = $this->provider($pixelService);
        $base = $this->manifestEvent(
            'hero_cta_click',
            '2026-06-10 14:00:00',
            \str_repeat('b', 64)
        );
        $invalidRows = [
            'missing page owner' => \array_replace($base, ['page_type' => '']),
            'invalid page owner' => \array_replace($base, ['page_type' => 'home.page']),
            'missing block owner' => \array_replace($base, ['block_key' => '']),
            'invalid block owner' => \array_replace($base, ['block_key' => 'hero.block']),
            'query in canonical path' => \array_replace($base, ['canonical_path' => '/?preview=1']),
            'fragment in canonical path' => \array_replace($base, ['canonical_path' => '/#hero']),
            'control character in path' => \array_replace($base, ['canonical_path' => "/bad\npath"]),
            'invalid experiment owner' => \array_replace($base, ['experiment_id' => 'experiment.bad']),
            'oversized variant owner' => \array_replace($base, ['variant' => \str_repeat('v', 33)]),
            'invalid target event' => \array_replace($base, ['target_event' => 'made_up_conversion']),
        ];

        foreach ($invalidRows as $label => $invalidRow) {
            $requestKey = $this->requestKey('reject-' . \substr(\hash('sha256', $label), 0, 8));
            try {
                $provider->execute(
                    'analyticsAcceptanceFixture',
                    $this->fixtureParams($websiteId, $requestKey, [$invalidRow])
                );
                self::fail('Invalid production attribution input was accepted: ' . $label);
            } catch (\InvalidArgumentException $error) {
                self::assertStringStartsWith('VISITOR_ACCEPTANCE_', $error->getMessage(), $label);
            }
        }
        self::assertSame([], $pixelService->tracked);
    }

    public function testFixtureEventContractExactlyMatchesProductionAttribution(): void
    {
        $expected = [
            'page_view',
            'ai_block_impression',
            'hero_cta_click',
            'pricing_cta_click',
            'lead_submit',
            'signup_click',
            'contact_click',
            'download_click',
            'booking_click',
            'demo_request_click',
            'add_to_cart',
            'buy_now',
            'begin_checkout',
            'route_click',
            'view_item',
            'proof_badge_interaction',
        ];

        self::assertCount(16, PageBuilderOptimizationAttributionService::allowedEvents());
        self::assertSame($expected, PageBuilderOptimizationAttributionService::allowedEvents());
    }

    public function testAllProductionEventsSeedAndRemainObservableAsTargetMetrics(): void
    {
        $websiteId = self::DYNAMIC_WEBSITE_ID;
        $this->enableFixture($websiteId);
        $requestKey = $this->requestKey('all-production-events');
        $fingerprint = \hash('sha256', $requestKey);
        $events = PageBuilderOptimizationAttributionService::allowedEvents();
        $manifest = [];
        foreach ($events as $ordinal => $event) {
            $manifest[] = $this->manifestEvent(
                $event,
                \sprintf('2026-06-10 15:%02d:00', $ordinal),
                $fingerprint
            );
        }
        $pixelService = $this->persistentPixelEventService();
        $provider = $this->provider($pixelService, new OptimizationSnapshotService());

        $receipt = $provider->execute(
            'analyticsAcceptanceFixture',
            $this->fixtureParams($websiteId, $requestKey, $manifest)
        );
        self::assertSame('seeded', $receipt['status']);
        self::assertSame($events, \array_column($receipt['created_events'], 'event'));
        self::assertCount(16, $receipt['created_event_ids']);

        $sessionId = (string)$receipt['created_events'][0]['session_id'];
        $rows = $this->fixtureRows($websiteId, $sessionId);
        self::assertCount(16, $rows);
        $this->rememberPixels($rows);

        foreach ($events as $event) {
            $snapshot = $provider->execute('analyticsOptimizationSnapshot', [
                'website_id' => $websiteId,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-10',
                'page_type' => 'home_page',
                'block_key' => 'hero',
                'plan_revision' => 127,
                'content_fingerprint' => $fingerprint,
                'target_event' => $event,
            ]);
            self::assertTrue($snapshot['data_quality']['complete'], $event);
            self::assertSame(1, $snapshot['summary']['target_events'], $event);
            self::assertSame(1, $snapshot['summary']['event_counts'][$event] ?? 0, $event);
        }

        $cleaned = $provider->execute('analyticsAcceptanceFixture', [
            'action' => 'cleanup',
            'context' => 'server',
            'case_id' => 'ai-seo-v2-closed-loop',
            'request_key' => $requestKey,
            'website_id' => $websiteId,
        ]);
        self::assertSame('cleaned', $cleaned['status']);
        self::assertSame(16, $cleaned['cleanup']['result']['deleted_event_count']);
        self::assertSame([], $this->fixtureRows($websiteId, $sessionId));
    }

    private function provider(
        PixelEventService $pixelEventService,
        ?OptimizationSnapshotService $snapshotService = null
    ): VisitorQueryProvider {
        /** @var VisitorAnalyticsWorkerService $analyticsService */
        $analyticsService = ObjectManager::getInstance(VisitorAnalyticsWorkerService::class);

        return new VisitorQueryProvider(
            $pixelEventService,
            $analyticsService,
            null,
            null,
            null,
            null,
            $snapshotService ?? new OptimizationSnapshotService(
                static fn(): array => ['rows' => [], 'truncated' => false]
            )
        );
    }

    private function persistentPixelEventService(?PixelHotBufferService $hotBuffer = null): PixelEventService
    {
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);
        /** @var PixelEventPersistenceService $persistence */
        $persistence = ObjectManager::getInstance(PixelEventPersistenceService::class);
        $hotBuffer ??= new class extends PixelHotBufferService {
                public function __construct()
                {
                }

                public function buffer(array $envelope): ?array
                {
                    return null;
                }
            };

        return new PixelEventService($request, $persistence, $hotBuffer);
    }

    /** @param list<array<string,mixed>> $manifest @return array<string,mixed> */
    private function fixtureParams(int $websiteId, string $requestKey, array $manifest): array
    {
        return [
            'action' => 'seed',
            'context' => 'server',
            'case_id' => 'ai-seo-v2-closed-loop',
            'request_key' => $requestKey,
            'website_id' => $websiteId,
            'manifest' => $manifest,
        ];
    }

    /** @return array<string,mixed> */
    private function manifestEvent(
        string $event,
        string $createdAt,
        string $fingerprint = ''
    ): array {
        return [
            'event' => $event,
            'created_at' => $createdAt,
            'session_id' => 'visitor-dynamic',
            'page_type' => 'home_page',
            'block_key' => 'hero',
            'plan_revision' => 127,
            'content_fingerprint' => $fingerprint !== '' ? $fingerprint : \str_repeat('a', 64),
            'canonical_path' => '/',
            'target_event' => $event === 'block_impression' ? 'ai_block_impression' : $event,
        ];
    }

    /** @return list<Pixel> */
    private function fixtureRows(int $websiteId, string $sessionId): array
    {
        return Pixel::getPixelsByWebsiteId(
            $websiteId,
            [Pixel::schema_fields_SESSION_ID => $sessionId],
            100,
            0
        );
    }

    /** @param list<Pixel> $rows */
    private function rememberPixels(array $rows): void
    {
        foreach ($rows as $row) {
            $pixelId = (int)$row->getPixelId();
            if ($pixelId > 0) {
                $this->pixelIds[] = $pixelId;
            }
        }
    }

    /** @return array<string,mixed> */
    private function unrelatedPixelPayload(
        int $websiteId,
        string $sessionId,
        string $fingerprint,
        string $fixtureRequestKey
    ): array {
        $unrelatedRequestKey = $fixtureRequestKey . '-unrelated';

        return [
            'source' => 'worker',
            'module' => 'pagebuilder_ai_acceptance',
            'name' => 'ai_block_impression',
            'event' => 'ai_block_impression',
            'eventName' => 'ai_block_impression',
            'event_id' => 'unrelated-' . \substr(\hash('sha256', $unrelatedRequestKey), 0, 32),
            'url' => 'https://visitor-acceptance.invalid/',
            'websiteId' => $websiteId,
            'session_id' => $sessionId,
            'created_at' => '2026-06-10 12:03:00',
            'userAgent' => 'Weline-Visitor-Acceptance-Fixture-Test/1.0',
            'additionalInfo' => [
                'environment' => [
                    'page_path' => '/',
                    'website_id' => (string)$websiteId,
                    'session_id' => $sessionId,
                ],
                'pagebuilder_attribution' => [
                    'attribution_version' => 'pagebuilder_ai_v1',
                    'source' => 'pagebuilder_rendered_dom',
                    'surface' => 'published',
                    'analytics_consent' => 'granted',
                    'preview' => false,
                    'website_id' => (string)$websiteId,
                    'page_type' => 'home_page',
                    'block_key' => 'hero',
                    'plan_revision' => 127,
                    'content_fingerprint' => $fingerprint,
                    'canonical_path' => '/',
                ],
                'meta' => [
                    'acceptance_fixture' => [
                        'contract' => 'visitor.acceptance_fixture_marker.v1',
                        'case_id' => 'ai-seo-v2-closed-loop',
                        'request_key' => $unrelatedRequestKey,
                        'ordinal' => 1,
                    ],
                ],
            ],
        ];
    }

    private function enableFixture(int $websiteId): void
    {
        \putenv('WELINE_ACCEPTANCE_FIXTURES=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=' . $websiteId);
    }

    private function requestKey(string $suffix): string
    {
        $requestKey = 'ai-seo-production-' . $suffix . '-' . \bin2hex(\random_bytes(4));
        $this->requestKeys[] = $requestKey;

        return $requestKey;
    }

    private function forgetFixtureReceipt(string $requestKey): void
    {
        $path = BP . '/var/visitor/acceptance-fixtures/' . \hash('sha256', $requestKey) . '.json';
        @\unlink($path);
        @\unlink($path . '.lock');
    }
}
