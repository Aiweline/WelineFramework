<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\SeoPlatformGroupCatalog;

final class SeoPlatformGroupCatalogTest extends TestCase
{
    public function testBuildGroupsKnownPlatformsAndLeavesUnknownInOther(): void
    {
        $catalog = new SeoPlatformGroupCatalog();
        $groups = $catalog->build([
            'google' => ['name' => 'Google'],
            'bing' => ['name' => 'Bing'],
            '360' => ['name' => '360'],
            'future_engine' => ['name' => 'Future'],
        ]);

        $byId = [];
        foreach ($groups as $group) {
            $byId[$group['id']] = $group;
        }

        self::assertArrayHasKey('recommended', $byId);
        self::assertSame(['google', 'bing'], array_column($byId['recommended']['platforms'], 'code'));
        self::assertArrayHasKey('china', $byId);
        self::assertSame(['360'], array_column($byId['china']['platforms'], 'code'));
        self::assertArrayHasKey('other', $byId);
        self::assertSame(['future_engine'], array_column($byId['other']['platforms'], 'code'));
        self::assertSame('recommended', $catalog->groupIdForPlatform('google', [
            'google' => ['name' => 'Google'],
            '360' => ['name' => '360'],
        ]));
    }

    public function testAccountFormUsesGroupedTabsNotFlatWall(): void
    {
        $root = dirname(__DIR__, 3);
        $template = (string)file_get_contents($root . '/view/templates/Backend/Account/form.phtml');
        $css = (string)file_get_contents($root . '/view/statics/css/seo-admin.css');

        self::assertStringContainsString('SeoPlatformGroupCatalog', $template);
        self::assertStringContainsString('data-seo-platform-tab', $template);
        self::assertStringContainsString('data-seo-platform-panel', $template);
        self::assertStringContainsString('w-tabs__list', $template);
        self::assertStringContainsString('每个 SEO 账户只绑定一个搜索平台', $template);
        self::assertStringContainsString('seo-platform-select-sr', $template);
        self::assertStringNotContainsString('border: 1px solid #d8dee8', $template);

        self::assertStringContainsString('.seo-platform-tabs', $css);
        self::assertStringContainsString('.seo-platform-grid', $css);
        self::assertStringContainsString('minmax(12rem,1fr)', $css);
        self::assertStringContainsString('repeat(auto-fit,minmax(12rem,1fr))', $css);
        self::assertStringContainsString('gap:var(--weline-space-4', $css);
        self::assertStringContainsString('padding:var(--weline-space-4', $css);
        self::assertStringNotContainsString('minmax(9.5rem,1fr)', $css);
        self::assertStringNotContainsString('repeat(auto-fill,minmax(12rem', $css);
        self::assertStringContainsString('var(--seo-border)', $css);

        $proto = $root . '/view/statics/prototype/seo-platform-tabs-ui.html';
        self::assertFileExists($proto);
        $protoSrc = (string)file_get_contents($proto);
        self::assertStringContainsString('?variant=', $protoSrc);
        self::assertStringContainsString('单选必填', $protoSrc);
    }
}
