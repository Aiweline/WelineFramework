<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\OptimizationSnapshotService;

final class OptimizationSnapshotServiceTest extends TestCore
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    private function service(array $rows, bool $unavailable = false): OptimizationSnapshotService
    {
        return new OptimizationSnapshotService(function (array $filters, string $start, string $end, string $event) use ($rows, $unavailable): array {
            if ($unavailable) {
                throw new \RuntimeException('Pixel source is unavailable.');
            }

            $result = [];
            foreach ($rows as $row) {
                if ((string)($row['attribution_version'] ?? '') !== 'pagebuilder_ai_v1') {
                    continue;
                }
                $createdAt = (string)($row['created_at'] ?? '');
                if ($createdAt < $start || $createdAt > $end) {
                    continue;
                }
                if ($event !== '' && (string)($row['event'] ?? '') !== $event) {
                    continue;
                }
                if ((int)($row['website_id'] ?? -1) !== (int)$filters['website_id']) {
                    continue;
                }
                if ($filters['plan_revision'] !== null
                    && (int)($row['plan_revision'] ?? -1) !== (int)$filters['plan_revision']) {
                    continue;
                }
                $matches = true;
                foreach (['page_type', 'block_key', 'content_fingerprint', 'experiment_id', 'variant'] as $filter) {
                    if ((string)$filters[$filter] !== ''
                        && (string)($row[$filter] ?? '') !== (string)$filters[$filter]) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $result[] = $row;
                }
            }

            return ['rows' => $result, 'truncated' => false];
        });
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function row(string $event, string $createdAt, string $session, array $overrides = []): array
    {
        return \array_replace([
            'attribution_version' => 'pagebuilder_ai_v1',
            'website_id' => 0,
            'page_type' => 'home',
            'block_key' => '',
            'plan_revision' => 7,
            'content_fingerprint' => '',
            'experiment_id' => '',
            'variant' => '',
            'event' => $event,
            'value' => 0,
            'session_id' => $session,
            'created_at' => $createdAt,
        ], $overrides);
    }

    public function testBuildsFixedContractFromAttributedPixelRowsForWebsiteZero(): void
    {
        $fingerprint = \str_repeat('a', 64);
        $service = $this->service([
            $this->row('page_view', '2026-06-08 12:00:00', 'previous-page'),
            $this->row('ai_block_impression', '2026-06-08 12:01:00', 'previous-page', [
                'block_key' => 'hero',
                'content_fingerprint' => $fingerprint,
            ]),
            $this->row('hero_cta_click', '2026-06-08 12:02:00', 'previous-page', [
                'block_key' => 'hero',
                'content_fingerprint' => $fingerprint,
                'value' => 3.5,
            ]),
            $this->row('page_view', '2026-06-10 10:00:00', 'current-one'),
            $this->row('ai_block_impression', '2026-06-10 10:01:00', 'current-one', [
                'block_key' => 'hero',
                'content_fingerprint' => $fingerprint,
            ]),
            $this->row('page_view', '2026-06-11 10:00:00', 'current-two'),
            $this->row('hero_cta_click', '2026-06-11 10:01:00', 'current-two', [
                'block_key' => 'hero',
                'content_fingerprint' => $fingerprint,
                'value' => 12.5,
            ]),
            $this->row('hero_cta_click', '2026-06-11 10:03:00', 'wrong-revision', [
                'block_key' => 'hero',
                'content_fingerprint' => $fingerprint,
                'plan_revision' => 8,
            ]),
        ]);

        $snapshot = $service->snapshot([
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'page_type' => 'home',
            'block_key' => 'hero',
            'plan_revision' => 7,
            'content_fingerprint' => $fingerprint,
            'target_event' => 'hero_cta_click',
            'page_views' => 999999,
            'target_events' => 999999,
        ]);

        self::assertSame(
            ['contract', 'status', 'filters', 'summary', 'comparison', 'data_quality'],
            \array_keys($snapshot)
        );
        self::assertSame(
            ['page_views', 'block_impressions', 'unique_anonymous_sessions', 'target_events', 'conversion_denominator', 'conversion_rate', 'value', 'event_counts', 'daily'],
            \array_keys($snapshot['summary'])
        );
        foreach (['attribution_version', 'thresholds', 'eligible', 'complete', 'reasons'] as $key) {
            self::assertArrayHasKey($key, $snapshot['data_quality']);
        }
        self::assertSame('visitor.optimization_snapshot.v1', $snapshot['contract']);
        self::assertSame('sample_insufficient', $snapshot['status']);
        self::assertSame(0, $snapshot['filters']['website_id']);
        self::assertSame('2026-06-10 00:00:00', $snapshot['filters']['start_date']);
        self::assertSame('2026-06-11 23:59:59', $snapshot['filters']['end_date']);
        self::assertSame(2, $snapshot['summary']['page_views']);
        self::assertSame(1, $snapshot['summary']['block_impressions']);
        self::assertSame(2, $snapshot['summary']['unique_anonymous_sessions']);
        self::assertSame(1, $snapshot['summary']['target_events']);
        self::assertSame(1, $snapshot['summary']['conversion_denominator']);
        self::assertSame(1.0, $snapshot['summary']['conversion_rate']);
        self::assertSame(12.5, $snapshot['summary']['value']);
        self::assertSame(1, $snapshot['comparison']['summary']['page_views']);
        self::assertSame(['sample_insufficient'], $snapshot['data_quality']['reasons']);
        self::assertTrue($snapshot['data_quality']['complete']);
        self::assertFalse($snapshot['data_quality']['eligible']);
        self::assertSame(500, $snapshot['data_quality']['thresholds']['page_views']);
        self::assertSame(30, $snapshot['data_quality']['thresholds']['target_events']);
    }

    public function testThresholdBoundaryControlsEligibilityWithoutCallerMetricOverrides(): void
    {
        $rows = [];
        for ($index = 0; $index < 500; $index++) {
            $rows[] = $this->row('page_view', '2026-06-10 12:00:00', 'session-' . $index);
        }
        for ($index = 0; $index < 30; $index++) {
            $rows[] = $this->row('lead_submit', '2026-06-10 12:01:00', 'conversion-' . $index);
        }

        $eligible = $this->service($rows)->snapshot([
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'target_event' => 'lead_submit',
            'min_page_views' => 1,
            'min_conversions' => 1,
        ]);

        self::assertSame(500, $eligible['summary']['page_views']);
        self::assertSame(30, $eligible['summary']['target_events']);
        self::assertSame('eligible', $eligible['status']);
        self::assertTrue($eligible['data_quality']['eligible']);

        \array_pop($rows);
        $insufficient = $this->service($rows)->snapshot([
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'target_event' => 'lead_submit',
        ]);

        self::assertSame('sample_insufficient', $insufficient['status']);
        self::assertSame(['sample_insufficient'], $insufficient['data_quality']['reasons']);
    }

    public function testAcceptanceCleanupDeletesOnlyReceiptOwnedPixelRows(): void
    {
        $requestKey = 'ai-seo-cleanup-' . \bin2hex(\random_bytes(4));
        $sessionId = 'ai-seo-' . \substr(\hash('sha256', $requestKey), 0, 40);
        $createdIds = [];
        $createPixel = static function (
            string $markerRequestKey,
            string $fixtureEventId
        ) use ($sessionId, &$createdIds): int {
            /** @var \Weline\Visitor\Model\Pixel $pixel */
            $pixel = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class)->clear();
            $pixel->setUrl('https://visitor-acceptance.invalid/')
                ->setModule('pagebuilder_ai_acceptance')
                ->setName('page_view')
                ->setEvent('page_view')
                ->setWebsiteId(87)
                ->setSessionId($sessionId)
                ->setReferer('')
                ->setSource('acceptance')
                ->setUserId(0)
                ->setUserAgent('AcceptanceFixtureTest')
                ->setIp('127.0.0.1')
                ->setLang('zh_Hans_CN')
                ->setCurrency('CNY')
                ->setValue(0)
                ->setCronDeal(0)
                ->setCreatedAt('2026-06-10 12:00:00')
                ->setBrowserInfo((string)\json_encode([
                    'additionalInfo' => [
                        'meta' => [
                            'acceptance_fixture' => [
                                'contract' => 'visitor.acceptance_fixture_marker.v1',
                                'case_id' => 'ai-seo-v2-closed-loop',
                                'request_key' => $markerRequestKey,
                                'fixture_event_id' => $fixtureEventId,
                                'ordinal' => 0,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES))
                ->save();
            $id = (int)$pixel->getPixelId();
            $createdIds[] = $id;
            return $id;
        };

        \putenv('WELINE_ACCEPTANCE_FIXTURES=1');
        \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1');
        try {
            $ownedId = $createPixel($requestKey, 'af_evt_owned');
            $forgedId = $createPixel($requestKey, 'af_evt_forged');
            $unrelatedId = $createPixel(
                'ai-seo-unrelated-' . \bin2hex(\random_bytes(4)),
                'af_evt_unrelated'
            );
            /** @var \Weline\Visitor\Service\PixelEventService $service */
            $service = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Service\PixelEventService::class);
            $result = $service->cleanupAcceptanceFixtureEvents(87, [[
                'fixture_event_id' => 'af_evt_owned',
                'ordinal' => 0,
                'event' => 'page_view',
                'session_id' => $sessionId,
                'created_at' => '2026-06-10 12:00:00',
            ]], $requestKey);

            self::assertTrue($result['complete']);
            self::assertSame(1, $result['deleted_event_count']);
            self::assertSame([$ownedId], $result['deleted_pixel_ids']);
            $owned = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class)->clear()->load($ownedId);
            self::assertEmpty($owned->getId());
            $forged = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class)->clear()->load($forgedId);
            self::assertSame($forgedId, (int)$forged->getId());
            $unrelated = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class)->clear()->load($unrelatedId);
            self::assertSame($unrelatedId, (int)$unrelated->getId());
        } finally {
            \putenv('WELINE_ACCEPTANCE_FIXTURES');
            \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE');
            foreach ($createdIds as $createdId) {
                $row = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Visitor\Model\Pixel::class)->clear()->load($createdId);
                if ($row->getId()) {
                    $row->delete();
                }
            }
        }
    }

    public function testReturnsStableEvidenceUnavailableForNoWindowRowsOrSourceFailures(): void
    {
        $empty = $this->service([])->snapshot([
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'target_event' => 'lead_submit',
        ]);
        self::assertSame('evidence_unavailable', $empty['status']);
        self::assertFalse($empty['data_quality']['complete']);
        self::assertSame(['evidence_unavailable'], $empty['data_quality']['reasons']);

        $missingTable = $this->service([], true)->snapshot([
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'target_event' => 'lead_submit',
        ]);
        self::assertSame('evidence_unavailable', $missingTable['status']);
        self::assertSame(['evidence_unavailable'], $missingTable['data_quality']['reasons']);
        self::assertSame([], $missingTable['summary']['daily']);
    }

    public function testRequiresWebsiteAndDateWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->snapshot([
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
        ]);
    }

    public function testRejectsNegativeWebsiteId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->snapshot([
            'website_id' => -1,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
        ]);
    }

    public function testRejectsInvalidDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([])->snapshot([
            'website_id' => 0,
            'start_date' => 'not-a-date',
            'end_date' => '2026-06-10',
        ]);
    }
}
