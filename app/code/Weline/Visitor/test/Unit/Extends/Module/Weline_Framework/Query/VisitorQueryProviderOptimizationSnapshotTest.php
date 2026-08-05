<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Extends\Module\Weline_Framework\Query\VisitorQueryProvider;
use Weline\Visitor\Service\OptimizationSnapshotService;
use Weline\Visitor\Service\PixelEventService;
use Weline\Visitor\Service\VisitorAnalyticsWorkerService;

final class VisitorQueryProviderOptimizationSnapshotTest extends TestCore
{
    private function provider(?PixelEventService $pixelEventService = null): VisitorQueryProvider
    {
        $snapshot = new OptimizationSnapshotService(
            static fn(array $filters, string $start, string $end, string $event): array => [
                'rows' => [],
                'truncated' => false,
            ]
        );

        if ($pixelEventService === null) {
            /** @var PixelEventService $pixelEventService */
            $pixelEventService = ObjectManager::getInstance(PixelEventService::class);
        }
        /** @var VisitorAnalyticsWorkerService $analyticsService */
        $analyticsService = ObjectManager::getInstance(VisitorAnalyticsWorkerService::class);

        return new VisitorQueryProvider(
            $pixelEventService,
            $analyticsService,
            null,
            null,
            null,
            null,
            $snapshot
        );
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
            'manifest' => [
                [
                    'event' => 'page_view',
                    'created_at' => '2026-06-10 12:00:00',
                    'session_id' => 'visitor-a',
                    'page_type' => 'home_page',
                    'block_key' => 'hero',
                    'plan_revision' => 127,
                    'content_fingerprint' => \str_repeat('a', 64),
                    'canonical_path' => '/',
                ],
                [
                    'event' => 'lead_submit',
                    'created_at' => '2026-06-10 12:01:00',
                    'session_id' => 'visitor-a',
                    'page_type' => 'home_page',
                    'block_key' => 'final_cta',
                    'plan_revision' => 127,
                    'content_fingerprint' => \str_repeat('a', 64),
                    'canonical_path' => '/',
                    'target_event' => 'lead_submit',
                ],
            ],
        ];
    }

    private function setFixtureGates(bool $global, bool $visitor): void
    {
        $global ? \putenv('WELINE_ACCEPTANCE_FIXTURES=1') : \putenv('WELINE_ACCEPTANCE_FIXTURES');
        $visitor ? \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE=1') : \putenv('WELINE_VISITOR_AI_SEO_ACCEPTANCE');
    }

    private function forgetFixtureReceipt(string $requestKey): void
    {
        $path = BP . '/var/visitor/acceptance-fixtures/' . \hash('sha256', $requestKey) . '.json';
        @\unlink($path);
        @\unlink($path . '.lock');
    }

    private function fakePixelEventService(): PixelEventService
    {
        return new class extends PixelEventService {
            /** @var list<array<string, mixed>> */
            public array $tracked = [];
            /** @var list<array<string, mixed>> */
            public array $cleaned = [];
            public int $cleanupCalls = 0;

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

            public function cleanupAcceptanceFixtureEvents(int $websiteId, array $ownedEvents, string $requestKey): array
            {
                $this->cleanupCalls++;
                $this->cleaned = $ownedEvents;
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

    public function testAcceptanceFixtureDescriptorIsServerOnly(): void
    {
        $operations = [];
        foreach ($this->provider()->getDescriptor()['operations'] as $operation) {
            $operations[(string)$operation['name']] = $operation;
        }

        self::assertArrayHasKey('analyticsAcceptanceFixture', $operations);
        self::assertFalse($operations['analyticsAcceptanceFixture']['frontend']);
        self::assertTrue($operations['analyticsAcceptanceFixture']['server_only']);
        self::assertSame('write', $operations['analyticsAcceptanceFixture']['mode']);
    }

    public function testAcceptanceFixtureRequiresBothIndependentGates(): void
    {
        $requestKey = 'ai-seo-gates-' . \bin2hex(\random_bytes(4));
        $service = $this->fakePixelEventService();
        try {
            foreach ([[false, false], [true, false], [false, true]] as [$global, $visitor]) {
                $this->setFixtureGates($global, $visitor);
                try {
                    $this->provider($service)->execute(
                        'analyticsAcceptanceFixture',
                        $this->fixtureParams(87, $requestKey)
                    );
                    self::fail('A single or missing gate must not enable acceptance mutation.');
                } catch (\RuntimeException $error) {
                    self::assertSame('VISITOR_ACCEPTANCE_FIXTURE_DISABLED', $error->getMessage());
                }
            }
            self::assertSame([], $service->tracked);
        } finally {
            $this->setFixtureGates(false, false);
            $this->forgetFixtureReceipt($requestKey);
        }
    }

    public function testAcceptanceFixtureRejectsBrowserOverridesInvalidWebsitesAndWrongCase(): void
    {
        $requestKey = 'ai-seo-reject-' . \bin2hex(\random_bytes(4));
        $service = $this->fakePixelEventService();
        $this->setFixtureGates(true, true);
        $invalid = [];
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['context' => 'browser']);
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['case_id' => 'manual-fixture']);
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['website_id' => -1]);
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['website_id' => 1]);
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['metrics' => ['page_views' => 600]]);
        $invalid[] = \array_replace($this->fixtureParams(87, $requestKey), ['raw_sql' => 'insert into w_pixel values (...)']);

        try {
            foreach ($invalid as $params) {
                try {
                    $this->provider($service)->execute('analyticsAcceptanceFixture', $params);
                    self::fail('Invalid acceptance input must be rejected.');
                } catch (\InvalidArgumentException|\RuntimeException $error) {
                    self::assertStringStartsWith('VISITOR_ACCEPTANCE_', $error->getMessage());
                }
            }
            self::assertSame([], $service->tracked);
        } finally {
            $this->setFixtureGates(false, false);
            $this->forgetFixtureReceipt($requestKey);
        }
    }

    public function testAcceptanceFixtureTracksManifestOnceForWebsiteZeroAndEightySevenAndCleansExactly(): void
    {
        $this->setFixtureGates(true, true);
        try {
            foreach ([0, 87] as $websiteId) {
                $requestKey = 'ai-seo-receipt-' . $websiteId . '-' . \bin2hex(\random_bytes(4));
                $service = $this->fakePixelEventService();
                $provider = $this->provider($service);
                try {
                    $params = $this->fixtureParams($websiteId, $requestKey);
                    $receipt = $provider->execute('analyticsAcceptanceFixture', $params);
                    self::assertSame('visitor.acceptance_fixture_receipt.v1', $receipt['contract']);
                    self::assertSame('seeded', $receipt['status']);
                    self::assertSame($websiteId, $receipt['website_id']);
                    self::assertSame(2, $receipt['created_event_count']);
                    self::assertCount(2, $receipt['created_event_ids']);
                    self::assertCount(2, $service->tracked);
                    self::assertSame('pagebuilder_ai_v1', $service->tracked[0]['additionalInfo']['pagebuilder_attribution']['attribution_version']);
                    self::assertSame($websiteId, $service->tracked[0]['websiteId']);

                    $replay = $provider->execute('analyticsAcceptanceFixture', $params);
                    self::assertTrue($replay['replayed']);
                    self::assertCount(2, $service->tracked);

                    $conflict = $params;
                    $conflict['manifest'][0]['event'] = 'view_item';
                    try {
                        $provider->execute('analyticsAcceptanceFixture', $conflict);
                        self::fail('Conflicting payload must not reuse a request key.');
                    } catch (\RuntimeException $error) {
                        self::assertSame('VISITOR_ACCEPTANCE_REQUEST_KEY_CONFLICT', $error->getMessage());
                    }

                    $cleanupParams = [
                        'action' => 'cleanup',
                        'context' => 'server',
                        'case_id' => 'ai-seo-v2-closed-loop',
                        'request_key' => $requestKey,
                        'website_id' => $websiteId,
                    ];
                    $cleaned = $provider->execute('analyticsAcceptanceFixture', $cleanupParams);
                    self::assertSame('cleaned', $cleaned['status']);
                    self::assertCount(2, $service->cleaned);
                    self::assertSame($receipt['created_events'], $service->cleaned);
                    self::assertSame(1, $service->cleanupCalls);

                    $cleanupReplay = $provider->execute('analyticsAcceptanceFixture', $cleanupParams);
                    self::assertTrue($cleanupReplay['replayed']);
                    self::assertSame(1, $service->cleanupCalls);
                } finally {
                    $this->forgetFixtureReceipt($requestKey);
                }
            }
        } finally {
            $this->setFixtureGates(false, false);
        }
    }

    public function testAcceptanceReceiptDoesNotAdvanceWithoutConfirmedDirectPersistence(): void
    {
        $this->setFixtureGates(true, true);
        try {
            foreach ([
                'buffered' => ['pixel_id' => null, 'buffered' => true],
                'missing pixel id' => ['pixel_id' => 0, 'buffered' => false],
            ] as $label => $persistedData) {
                $requestKey = 'ai-seo-unconfirmed-' . \substr(\hash('sha256', $label), 0, 12);
                $service = new class($persistedData) extends PixelEventService {
                    /** @param array<string,mixed> $persistedData */
                    public function __construct(private readonly array $persistedData)
                    {
                    }

                    public function persistAcceptanceFixtureEvent(array $payload): array
                    {
                        return ['success' => true, 'data' => $this->persistedData];
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

                try {
                    $this->provider($service)->execute(
                        'analyticsAcceptanceFixture',
                        $this->fixtureParams(87, $requestKey)
                    );
                    self::fail('Unconfirmed persistence advanced the receipt: ' . $label);
                } catch (\RuntimeException $error) {
                    self::assertSame('VISITOR_ACCEPTANCE_TRACK_FAILED', $error->getMessage(), $label);
                }

                $receiptPath = BP . '/var/visitor/acceptance-fixtures/' . \hash('sha256', $requestKey) . '.json';
                $receipt = \json_decode((string)\file_get_contents($receiptPath), true);
                self::assertIsArray($receipt, $label);
                self::assertSame('failed', $receipt['status'] ?? null, $label);
                self::assertSame(0, $receipt['created_event_count'] ?? null, $label);
                self::assertSame([], $receipt['created_events'] ?? null, $label);
                $this->forgetFixtureReceipt($requestKey);
            }
        } finally {
            $this->setFixtureGates(false, false);
        }
    }

    public function testExecutesServerOnlyOptimizationSnapshotOperation(): void
    {
        $result = $this->provider()->execute('analyticsOptimizationSnapshot', [
            'website_id' => 0,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
        ]);

        self::assertSame('visitor.optimization_snapshot.v1', $result['contract']);
        self::assertSame('evidence_unavailable', $result['status']);
    }

    public function testDescriptorKeepsOptimizationSnapshotServerOnlyAndCanonical(): void
    {
        $operation = null;
        foreach ($this->provider()->getDescriptor()['operations'] as $candidate) {
            if (($candidate['name'] ?? '') === 'analyticsOptimizationSnapshot') {
                $operation = $candidate;
                break;
            }
        }

        self::assertIsArray($operation);
        self::assertFalse($operation['frontend']);
        self::assertSame('read', $operation['mode']);
        self::assertTrue($operation['params']['website_id']['required']);
        self::assertTrue($operation['params']['start_date']['required']);
        self::assertTrue($operation['params']['end_date']['required']);
        self::assertArrayNotHasKey('page_views', $operation['params']);
        self::assertSame('visitor.optimization_snapshot.v1', $operation['returns']['contract']);
    }
}
