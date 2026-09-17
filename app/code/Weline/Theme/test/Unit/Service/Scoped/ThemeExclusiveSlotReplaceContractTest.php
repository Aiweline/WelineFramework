<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Exclusive homepage-hero replace must clear every node in the slot, not only
 * same widget_code — otherwise ad-banner + hero-slider stack and the default
 * banner appears unable to hang onto the homepage.
 */
final class ThemeExclusiveSlotReplaceContractTest extends TestCase
{
    public function testExclusiveAddClearsEntireSlotNotOnlySameWidgetCode(): void
    {
        $source = $this->read('app/code/Weline/Theme/Service/Scoped/ThemeScopedLayoutWriteService.php');

        self::assertStringContainsString(
            'Exclusive replace owns the whole slot: clear every node in the slot',
            $source,
        );
        self::assertStringContainsString(
            '$this->removeNodesInSlot($state, $area, $slotId, null)',
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            '/if \(\$exclusive && !\$hasTemplateRef\) \{[^}]*removeNodesInSlot\(\$state, \$area, \$slotId, \(string\)\(\$data\[\'widget_code\'\]/s',
            $source,
        );
        self::assertStringContainsString('?string $widgetCode = null', $source);
        self::assertStringContainsString('$filterByCode = $widgetCode !== null && $widgetCode !== \'\'', $source);
    }

    public function testEditorKnowsHomepageHeroIsExclusive(): void
    {
        $legacy = $this->read('app/code/Weline/Theme/view/statics/js/theme-editor.js');
        $bundle = $this->read('app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js');

        foreach ([$legacy, $bundle] as $js) {
            self::assertStringContainsString("'homepage-hero'", $js);
            self::assertStringContainsString("'homepage-promo'", $js);
            self::assertStringContainsString('nested [data-wslot] shells', $js);
        }
    }

    private function read(string $relative): string
    {
        $path = BP . $relative;
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
