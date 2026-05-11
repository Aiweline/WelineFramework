<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Service;

use GuoLaiRen\PageBuilder\Service\AiSiteBuildPlanTaskScheduler;
use PHPUnit\Framework\TestCase;

final class AiSiteBuildPlanTaskSchedulerTest extends TestCase
{
    public function testBuildConfirmationScopePatchConfirmsBuildPlanAndProjection(): void
    {
        $patch = (new AiSiteBuildPlanTaskScheduler())->buildConfirmationScopePatch([
            'page_types' => ['home_page'],
            'site_title' => 'Example Site',
            'brief_description' => 'Explain the service clearly.',
        ], [], 'virtual_theme');

        self::assertSame(1, $patch['build_plan_confirmed']);
        self::assertSame(1, $patch['has_build_plan_v2']);
        self::assertSame('virtual_theme', $patch['workspace_track']);
        self::assertSame('confirmed', $patch['build_plan_v2']['contract_meta']['status']);
        self::assertSame((string)$patch['build_plan_v2']['contract_meta']['id'], $patch['plan_projection']['source_contract_id']);
        self::assertSame(true, $patch['build_plan_v2_validation']['valid']);
        self::assertArrayHasKey('items', $patch['content_manifest']);
    }
}
