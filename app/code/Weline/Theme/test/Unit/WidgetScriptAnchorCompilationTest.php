<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;

final class WidgetScriptAnchorCompilationTest extends TestCase
{
    public function testEveryMigratedInitializerAnchorSurvivesFrameworkTagCompilation(): void
    {
        $root = dirname(__DIR__, 6);
        $inventory = json_decode((string)file_get_contents(
            dirname(__DIR__, 2) . '/doc/开发/team/widget-assets-position-migration/widget-inventory.json'
        ), true, 512, JSON_THROW_ON_ERROR);
        $taglib = ObjectManager::getInstance(Taglib::class);
        $template = Template::getInstance();
        $count = 0;
        foreach ($inventory['templates'] as $entry) {
            $source = (string)file_get_contents($root . '/' . $entry['template']);
            preg_match_all('~<(template|span)\b[^<]*?data-widget-script="([^"]+)".*?</\1>~s', $source, $anchors, PREG_SET_ORDER);
            foreach ($anchors as $anchor) {
                ++$count;
                $compiled = $taglib->tagReplace($template, $anchor[0]);
                self::assertStringContainsString('data-widget-script="' . $anchor[2] . '"', $compiled, $entry['template']);
                self::assertStringContainsString('hidden', $compiled, $entry['template']);
            }
        }
        self::assertGreaterThan(20, $count, 'The migration inventory must exercise actual widget anchors.');
    }
}
