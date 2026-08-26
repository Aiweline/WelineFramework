<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Backend sidebar active-state contracts:
 * - sibling feature leaves (products ↔ offers) must not cross-match
 * - active leaf markup carries data-state=active
 * - active CSS is distinct from hover
 */
final class BackendMenuPathMatchContractTest extends TestCase
{
    public function testPathMatchRejectsSiblingFeatureLeavesAndKeepsActionAliases(): void
    {
        $js = $this->read('view/statics/assets/js/app.js');

        self::assertStringContainsString('禁止 products↔offers', $js);
        self::assertStringContainsString('actionAliases', $js);
        self::assertStringContainsString('actionAliases[menuAction] && actionAliases[currentAction]', $js);
        self::assertStringNotContainsString(
            '同级页面匹配（路径长度相同，前N-1段匹配，只有最后一段不同）',
            $js
        );
    }

    public function testActiveMenuMarkupIncludesDataState(): void
    {
        $php = $this->read('Service/MenuRenderService.php');
        self::assertStringContainsString(
            "aria-current=\"page\" data-state=\"active\"",
            $php
        );
        self::assertStringContainsString('stripLocalizationRouteSegments', $php);
    }

    public function testActiveCssIsDistinctFromHover(): void
    {
        foreach ([
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/weline-backend.css',
            dirname(__DIR__, 4) . '/Theme/view/ui/css/backend.css',
        ] as $cssPath) {
            self::assertFileExists($cssPath, $cssPath);
            $css = (string)file_get_contents($cssPath);
            self::assertStringContainsString('.w-backend-nav__item:hover {', $css);
            self::assertStringContainsString('.w-backend-nav__item[aria-current="page"]', $css);
            self::assertStringContainsString('var(--weline-theme-primary)', $css);
            self::assertStringNotContainsString(
                '.w-backend-nav__item:hover, .w-backend-nav__item[aria-current="page"], .w-backend-nav__item[data-state="active"]',
                $css
            );
        }
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        self::assertFileExists($path, $path);
        $content = file_get_contents($path);
        self::assertIsString($content);

        return $content;
    }
}
