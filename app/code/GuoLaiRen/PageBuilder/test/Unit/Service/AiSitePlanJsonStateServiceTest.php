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
                'home_page' => [
                    'hero' => ['status' => 'generating'],
                    'proof' => ['content' => 'Trust proof'],
                    'cta' => ['error_message' => 'copy failed'],
                ],
            ],
        ]);
        $summary = $service->buildStatusSummary($plan);

        self::assertSame(1, $plan['design']['status'] ?? null);
        self::assertSame(2, $plan['pages']['home_page']['status'] ?? null);
        self::assertSame(2, $plan['pages']['home_page']['hero']['status'] ?? null);
        self::assertSame(1, $plan['pages']['home_page']['proof']['status'] ?? null);
        self::assertSame(-1, $plan['pages']['home_page']['cta']['status'] ?? null);
        self::assertSame(1, $summary['design']['done']);
        self::assertSame(1, $summary['pages']['running']);
        self::assertSame(1, $summary['block_nodes']['running']);
        self::assertSame(1, $summary['block_nodes']['done']);
        self::assertSame(1, $summary['block_nodes']['failed']);
    }

    public function testFingerprintIsStableWhenNodesHaveNoUpdatedAt(): void
    {
        $service = new AiSitePlanJsonStateService();
        $plan = [
            'pages' => [
                'home_page' => [
                    'hero' => ['status' => 0],
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
                    'hero' => ['content' => 'Home hero'],
                ],
                [
                    'type' => 'about_page',
                    'intro' => ['content' => 'About intro'],
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
            ['pages' => ['home_page' => ['hero' => ['status' => 2]]]],
            ['pages' => ['home_page' => ['hero' => ['status' => 1]]]]
        );

        self::assertContains('plan_json.pages.home_page.hero.status', $paths);
    }

    public function testNormalizeKeepsDynamicBlockNodes(): void
    {
        $service = new AiSitePlanJsonStateService(42);

        $plan = $service->normalizePlanJson([
            'confirmed' => 1,
            'pages' => [
                'home_page' => [
                    'page_type' => 'home_page',
                    'hero' => ['status' => 0],
                ],
            ],
        ]);

        self::assertSame(1, $plan['confirmed']);
        self::assertArrayHasKey('hero', $plan['pages']['home_page']);
    }

    public function testSessionEditorScopePatchPersistsOnlyPlanJsonState(): void
    {
        $service = new AiSitePlanJsonStateService(2317);

        $patch = $service->setConfirmedScopePatch([
            'confirmed' => 0,
            'pages' => [
                'home_page' => [
                    'hero' => ['status' => 0],
                ],
            ],
        ], true);

        self::assertSame(2317, $service->sessionId());
        self::assertSame(2317, $patch['plan_json_editor']['session_id']);
        self::assertSame(1, $patch['plan_json']['confirmed']);
        self::assertSame(0, $patch['plan_json']['pages']['home_page']['hero']['status']);
    }
}
