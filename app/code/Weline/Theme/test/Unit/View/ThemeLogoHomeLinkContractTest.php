<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Logo/首页链接必须走站内 URL 构造器。
 *
 * 原始 "/" 或 getUrl('') 会落到域名根 / 当前页面：子目录、多语言站点点击 Logo
 * 回不到本站首页（表现为原地刷新）。
 */
final class ThemeLogoHomeLinkContractTest extends TestCase
{
    private static function repoRoot(): string
    {
        return \defined('BP') ? \rtrim((string)\BP, '/\\') . '/' : \dirname(__DIR__, 7) . '/';
    }

    public function testHeaderLogoWidgetResolvesLinkThroughUrlBuilder(): void
    {
        $src = (string)\file_get_contents(
            self::repoRoot() . 'app/code/Weline/Theme/view/theme/frontend/widgets/header/logo/default.phtml'
        );

        self::assertStringContainsString('toDocumentRootRelative((string)$this->getUrl($logoLink))', $src);
        self::assertStringNotContainsString("\$this->getUrl('')", $src);
    }

    public function testHanfuHomeHelperResolvesRootPathInsteadOfCurrentPage(): void
    {
        $src = (string)\file_get_contents(self::repoRoot() . 'app/design/Weline/hanfu/changan/View.php');

        self::assertStringContainsString("? (string)\$tpl->getUrl('/') :", $src);
    }
}
