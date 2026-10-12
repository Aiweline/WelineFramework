<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Demo TTL 控制器必须钉死 777s 标记，便于线上推送抽检。
 */
final class DemoTtlAnnotationContractTest extends TestCase
{
    public function testApiAndPageControllersDeclareDemoTtl777(): void
    {
        $api = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Api/DemoTtl.php');
        $page = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Frontend/DemoTtl.php');

        self::assertStringContainsString('WELINE_DEMO_TTL_777_API', $api);
        self::assertStringContainsString('WELINE_DEMO_TTL_777_PAGE', $page);
        self::assertStringContainsString('/__weline_cdn_demo_ttl_api', $api);
        self::assertStringContainsString('/__weline_cdn_demo_ttl_page', $page);
        self::assertMatchesRegularExpression('/"ttl"\\s*:\\s*777/', $api);
        self::assertMatchesRegularExpression('/"ttl"\\s*:\\s*777/', $page);
        self::assertStringContainsString('max-age=777', $api);
        self::assertStringContainsString('max-age=777', $page);
    }
}
