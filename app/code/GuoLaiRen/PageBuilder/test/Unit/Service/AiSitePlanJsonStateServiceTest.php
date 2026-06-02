<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSitePlanJsonStateService;
use PHPUnit\Framework\TestCase;

final class AiSitePlanJsonStateServiceTest extends TestCase
{
    public function testNormalizesAndSummarizesDesignPagesAndBlocks(): void
    {
        $service = new AiSitePlanJsonStateService();

        $plan = $service->normalizePlanJson([
            'design' => ['content' => 'Visual direction'],
            'pages' => [
                'home' => [
                    'blocks' => [
                        ['block_key' => 'hero', 'status' => 'generating'],
                        ['block_key' => 'proof', 'content' => 'Trust proof'],
                        ['block_key' => 'cta', 'error_message' => 'copy failed'],
                    ],
                ],
            ],
        ]);
        $summary = $service->buildStatusSummary($plan);

        self::assertSame('done', $plan['design']['status'] ?? null);
        self::assertSame('running', $plan['pages']['home']['status'] ?? null);
        self::assertSame('running', $plan['pages']['home']['blocks'][0]['status'] ?? null);
        self::assertSame('done', $plan['pages']['home']['blocks'][1]['status'] ?? null);
        self::assertSame('failed', $plan['pages']['home']['blocks'][2]['status'] ?? null);
        self::assertSame(1, $summary['design']['done']);
        self::assertSame(1, $summary['pages']['running']);
        self::assertSame(1, $summary['blocks']['running']);
        self::assertSame(1, $summary['blocks']['done']);
        self::assertSame(1, $summary['blocks']['failed']);
    }

    public function testFingerprintIsStableWhenNodesHaveNoUpdatedAt(): void
    {
        $service = new AiSitePlanJsonStateService();
        $plan = [
            'pages' => [
                'home' => [
                    'blocks' => [
                        ['block_key' => 'hero', 'status' => 'pending'],
                    ],
                ],
            ],
        ];

        self::assertSame($service->fingerprint($plan), $service->fingerprint($plan));
    }

    public function testNumericPagesAreKeyedByPageTypeForIncrementalMerges(): void
    {
        $service = new AiSitePlanJsonStateService();

        $plan = $service->normalizePlanJson([
            'pages' => [
                [
                    'page_type' => 'home_page',
                    'blocks' => [
                        ['block_key' => 'hero', 'content' => 'Home hero'],
                    ],
                ],
                [
                    'type' => 'about_page',
                    'blocks' => [
                        ['block_key' => 'intro', 'content' => 'About intro'],
                    ],
                ],
            ],
        ]);

        self::assertArrayHasKey('home_page', $plan['pages']);
        self::assertArrayHasKey('about_page', $plan['pages']);
        self::assertArrayNotHasKey(0, $plan['pages']);
        self::assertArrayNotHasKey(1, $plan['pages']);
        self::assertSame('home_page', $plan['pages']['home_page']['page_type']);
        self::assertSame('about_page', $plan['pages']['about_page']['page_type']);
    }

    public function testChangedPathsReportsPlanJsonDeltas(): void
    {
        $service = new AiSitePlanJsonStateService();

        $paths = $service->changedPaths(
            ['pages' => ['home' => ['status' => 'running']]],
            ['pages' => ['home' => ['status' => 'done']]]
        );

        self::assertContains('plan_json.pages.home.status', $paths);
    }
}
