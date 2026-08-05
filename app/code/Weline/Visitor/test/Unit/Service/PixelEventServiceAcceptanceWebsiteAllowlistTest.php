<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\Module\Weline_Framework\Query\VisitorQueryProvider;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\VisitorAnalyticsWorkerService;

final class PixelEventServiceAcceptanceWebsiteAllowlistTest extends TestCore
{
    protected function tearDown(): void
    {
        \putenv('WELINE_ACCEPTANCE_FIXTURES');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS');
        parent::tearDown();
    }

    public function testDefaultsRemainAllowedAndUnlistedDynamicWebsiteIsRejected(): void
    {
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS');

        self::assertSame(0, PixelEventService::acceptanceWebsiteId(0));
        self::assertSame(87, PixelEventService::acceptanceWebsiteId('87'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED');
        PixelEventService::acceptanceWebsiteId(113);
    }

    public function testExactDynamicAllowlistAcceptsOnlyConfiguredWebsite(): void
    {
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=113,218');

        self::assertSame(113, PixelEventService::acceptanceWebsiteId(113));
        self::assertSame(218, PixelEventService::acceptanceWebsiteId('218'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED');
        PixelEventService::acceptanceWebsiteId(114);
    }

    /**
     * @dataProvider malformedAllowlistProvider
     */
    public function testMalformedAllowlistFailsClosedForDynamicWebsites(string $allowlist): void
    {
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=' . $allowlist);

        self::assertSame(0, PixelEventService::acceptanceWebsiteId(0));
        self::assertSame(87, PixelEventService::acceptanceWebsiteId(87));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED');
        PixelEventService::acceptanceWebsiteId(113);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAllowlistProvider(): iterable
    {
        yield 'empty member' => ['113,,218'];
        yield 'whitespace' => ['113, 218'];
        yield 'negative' => ['113,-1'];
        yield 'non numeric' => ['113,bad'];
        yield 'non canonical leading zero' => ['0113'];
        yield 'integer overflow' => ['113,999999999999999999999999999999999999'];
    }

    public function testQueryProviderUsesTheSameExactDynamicAllowlist(): void
    {
        \putenv('WELINE_ACCEPTANCE_FIXTURES=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=113');
        $requestKey = 'visitor-dynamic-113-' . \bin2hex(\random_bytes(4));
        $service = $this->fakePixelEventService();
        /** @var VisitorAnalyticsWorkerService $analyticsService */
        $analyticsService = ObjectManager::getInstance(VisitorAnalyticsWorkerService::class);
        $provider = new VisitorQueryProvider($service, $analyticsService);

        try {
            $receipt = $provider->execute(
                'analyticsAcceptanceFixture',
                $this->fixtureParams(113, $requestKey)
            );
            self::assertSame(113, $receipt['website_id']);
            self::assertSame(1, $receipt['created_event_count']);
            self::assertCount(1, $service->tracked);

            try {
                $provider->execute(
                    'analyticsAcceptanceFixture',
                    $this->fixtureParams(114, 'visitor-dynamic-114-' . \bin2hex(\random_bytes(4)))
                );
                self::fail('An unlisted dynamic website must remain fail-closed.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED', $error->getMessage());
            }
        } finally {
            $this->forgetFixtureReceipt($requestKey);
        }
    }

    public function testCleanupUsesTheSameDynamicAllowlistPolicy(): void
    {
        \putenv('WELINE_ACCEPTANCE_FIXTURES=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1');
        $service = new class extends PixelEventService {
            public function __construct()
            {
            }
        };

        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS');
        try {
            $service->cleanupAcceptanceFixtureEvents(113, [], 'visitor-cleanup-113');
            self::fail('Cleanup must reject an unlisted dynamic website.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED', $error->getMessage());
        }

        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=113');
        $cleanup = $service->cleanupAcceptanceFixtureEvents(113, [], 'visitor-cleanup-113');
        self::assertSame(113, $cleanup['website_id']);
        self::assertTrue($cleanup['complete']);

        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=113,bad');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VISITOR_ACCEPTANCE_WEBSITE_NOT_ALLOWED');
        $service->cleanupAcceptanceFixtureEvents(113, [], 'visitor-cleanup-113');
    }

    public function testDirectCleanupRequiresBothIndependentAcceptanceGates(): void
    {
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE_WEBSITE_IDS=113');
        $service = new class extends PixelEventService {
            public function __construct()
            {
            }
        };

        foreach ([[false, false], [true, false], [false, true]] as [$global, $visitor]) {
            $global
                ? \putenv('WELINE_ACCEPTANCE_FIXTURES=1')
                : \putenv('WELINE_ACCEPTANCE_FIXTURES');
            $visitor
                ? \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1')
                : \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE');
            try {
                $service->cleanupAcceptanceFixtureEvents(113, [], 'visitor-cleanup-gates-113');
                self::fail('Direct cleanup accepted incomplete fixture gates.');
            } catch (\RuntimeException $error) {
                self::assertSame('VISITOR_ACCEPTANCE_FIXTURE_DISABLED', $error->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function fixtureParams(int $websiteId, string $requestKey): array
    {
        return [
            'action' => 'seed',
            'context' => 'server',
            'case_id' => 'ai-seo-v2-closed-loop',
            'request_key' => $requestKey,
            'website_id' => $websiteId,
            'manifest' => [[
                'event' => 'page_view',
                'created_at' => '2026-06-10 12:00:00',
                'session_id' => 'visitor-dynamic-session',
                'page_type' => 'home_page',
                'block_key' => 'hero',
                'plan_revision' => 127,
                'content_fingerprint' => \str_repeat('a', 64),
                'canonical_path' => '/',
            ]],
        ];
    }

    private function fakePixelEventService(): PixelEventService
    {
        return new class extends PixelEventService {
            /** @var list<array<string, mixed>> */
            public array $tracked = [];

            public function __construct()
            {
            }

            public function track(array $payload): array
            {
                $this->tracked[] = $payload;
                return ['message' => 'ok', 'data' => []];
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
    }

    private function forgetFixtureReceipt(string $requestKey): void
    {
        $path = BP . '/var/visitor/acceptance-fixtures/' . \hash('sha256', $requestKey) . '.json';
        @\unlink($path);
        @\unlink($path . '.lock');
    }
}
