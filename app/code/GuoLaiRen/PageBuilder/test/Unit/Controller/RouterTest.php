<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Controller;

use GuoLaiRen\PageBuilder\Controller\Router;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Env\WelineEnv;

/**
 * Router::process 行为回归：原 shouldRewriteRootPath 已内联进 process，改为直接测对外入口。
 */
class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        Router::clearCache();
        parent::tearDown();
    }

    public function testProcessReturnsEarlyWhenModuleAlreadyResolved(): void
    {
        $path = '/any';
        $rule = ['module' => 'Other_Module'];
        Router::process($path, $rule);
        self::assertSame('/any', $path);
        self::assertSame('Other_Module', $rule['module']);
    }

    public function testProcessSkipsSystemAdminPath(): void
    {
        $path = '/admin/dashboard';
        $rule = [];
        Router::process($path, $rule);
        self::assertSame('/admin/dashboard', $path);
        self::assertArrayNotHasKey('module', $rule);
    }

    public function testProcessRewritesEmptyPathWhenPreviewWithHandle(): void
    {
        $savedGet = $_GET;
        $env = WelineEnv::getInstance();
        $envSnapshot = $env->capture();
        try {
            $env->reset();
            $_GET = $savedGet;
            $_GET['preview'] = '1';
            $_GET['handle'] = 'preview-home';
            $_GET['website_id'] = '1';
            $path = '';
            $rule = [];
            Router::process($path, $rule);
            self::assertSame('/pagebuilder/frontend/page/view', $path);
            self::assertSame('GuoLaiRen_PageBuilder', $rule['module']);
            self::assertSame('preview-home', $rule['handle']);
        } finally {
            $env->restore($envSnapshot);
            $_GET = $savedGet;
        }
    }

    public function testNonRootPathResolvesWebsiteFromHostBeforeGlobalHandleFallback(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Router.php');
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            <<<'REGEX'
/\$websiteId = self::getCurrentWebsiteId\(\);\s*if \(\$websiteId <= 0\) \{\s*\$websiteId = self::resolveWebsiteIdByCurrentHost\(\);\s*\}\s*\$isPreview = \\w_env_get\('preview'\) == '1';/s
REGEX,
            $source
        );
    }
}
