<?php
declare(strict_types=1);
namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Service\LayoutEntity\LayoutRelationCompiler;
use Weline\Widget\Service\WidgetData;

require_once __DIR__ . '/fixtures/ResolvedPhtmlFixture.php';

final class NativeInlineRequiredChildrenExecutionTest extends TestCase
{
    use ResolvedPhtmlFixture;

    public function testModulelessNativeDispatchAndBranchLocalChildrenExecuteOnce(): void
    {
        $previous = ObjectManager::getInstance(WidgetData::class);
        $widgetData = $this->createStub(WidgetData::class);
        $widgetData->method('getWidget')->willReturn(['module' => 'Fixture']);
        ObjectManager::setInstance(WidgetData::class, $widgetData);
        try {
            $this->registry->definitions['native-box'] = $this->definition('native-box', '<h1><?= $this->getData("title") ?></h1><div data-wslot="inside" data-wslot-exclusive="true">DEFAULT</div>', ['inside']);
            $child = $this->node('c', 'inside', 'CHILD', 0);
            $child['source'] = 'default_injection';
            $source = '<w:slot id="main"><?php if ($left): ?><w:widget type="content" name="native-box" params=\'{"title":"LEFT"}\' /><?php else: ?><w:widget type="content" name="native-box" params=\'{"title":"RIGHT"}\' /><?php endif; ?></w:slot>';
            $compiled = (new LayoutRelationCompiler($this->registry))->compile($source, [$child]);
            foreach ([true => 'LEFT', false => 'RIGHT'] as $branch => $title) {
                $html = $this->execute($compiled, ['left' => (bool)$branch]);
                self::assertSame(1, substr_count($html, '<b>CHILD</b>'));
                self::assertStringContainsString('<h1>' . $title . '</h1>', $html);
                self::assertStringNotContainsString('DEFAULT', $html);
            }
        } finally {
            ObjectManager::setInstance(WidgetData::class, $previous);
        }
    }

    public function testTwoNativeInstancesExecuteFrozenLocaleConfigDespiteChangedDefaults(): void
    {
        // 本用例验证英文覆盖，显式设置请求语言，避免依赖 CLI 的站点默认语言。
        \Weline\Framework\App\State::setRequestLanguageOverride('en_US');
        self::assertSame('en_US', \Weline\Theme\Helper\WidgetI18n::storefrontLocale());
        $template = '<h1><?= $this->getData("title") ?></h1><small><?= $this->getData("theme_value") ?></small><div data-wslot="inside" data-wslot-exclusive="true">DEFAULT</div>';
        $this->registry->definitions['native-box'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'native-box', name: 'native-box',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, defaultConfig: ['theme_value' => 'ORIGINAL DEFAULT'], templateContent: $template, slots: ['inside']);
        $child = $this->node('c', 'inside', 'CHILD', 0); $child['source'] = 'default_injection';
        $source = '<w:slot id="main"><w:widget module="Fixture" type="content" name="native-box" ref="left" params=\'{"title":"LEFT"}\' /><w:widget module="Fixture" type="content" name="native-box" ref="right" params=\'{"title":"RIGHT"}\' /></w:slot>';
        $compiler = new LayoutRelationCompiler($this->registry);
        $nodes = $compiler->discoverNativeOwners($source, [$child]);
        $locales = [];
        foreach ($nodes as $uid => &$node) {
            if ($node['widget_code'] !== 'native-box') { continue; }
            $node['config']['theme_value'] = 'FROZEN THEME';
            $node['_explicit_config'] = $node['config'];
            $locales['en_US'][$uid] = array_replace($node['config'], ['title' => $node['config']['title'] . ' LOCALE', 'theme_value' => 'FROZEN LOCALE']);
        }
        unset($node);
        $compiled = $compiler->compile($source, $nodes, [], $locales);
        $this->registry->definitions['native-box'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'native-box', name: 'native-box',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, defaultConfig: ['title' => 'CURRENT', 'theme_value' => 'CURRENT DEFAULT'], templateContent: $template, slots: ['inside']);
        $html = $this->execute($compiled);
        self::assertStringContainsString('<h1>LEFT LOCALE</h1>', $html);
        self::assertStringContainsString('<h1>RIGHT LOCALE</h1>', $html);
        self::assertSame(2, substr_count($html, '<small>FROZEN LOCALE</small>'));
        self::assertSame(2, substr_count($html, '<b>CHILD</b>'));
        self::assertStringNotContainsString('CURRENT', $html);
        preg_match_all('/data-node-uid="([^"]+)"/', $html, $uids);
        self::assertCount(4, array_unique($uids[1]));
    }

    public function testDynamicParamsExecuteInOriginalScopeWithoutReadingCurrentDefaults(): void
    {
        $template = '<h1><?= $this->getData("title") ?></h1><small><?= $this->getData("theme_value") ?></small><i><?= $this->getData("nullable") === null ? "NULL KEPT" : "NULL LOST" ?></i><div data-wslot="inside" data-wslot-exclusive="true"></div>';
        $this->registry->definitions['native-box'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'native-box', name: 'native-box',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, defaultConfig: ['theme_value' => 'FROZEN'], templateContent: $template, slots: ['inside']);
        $child = $this->node('c', 'inside', 'CHILD', 0); $child['source'] = 'default_injection';
        $source = '<w:slot id="main"><w:widget module="Fixture" type="content" name="native-box" params=\'{"title":"<?= $title ?>","nullable":<?= json_encode($nullable) ?>}\' /></w:slot>';
        $compiler = new LayoutRelationCompiler($this->registry);
        $nodes = $compiler->discoverNativeOwners($source, [$child]);
        $locales = [];
        foreach ($nodes as $uid => &$node) {
            if ($node['widget_code'] !== 'native-box') { continue; }
            $node['config']['theme_value'] = 'FROZEN';
            $node['_explicit_config'] = $node['config'];
            $locales['en_US'][$uid] = $node['config'];
        }
        unset($node);
        $compiled = $compiler->compile($source, $nodes, [], $locales);
        $this->registry->definitions['native-box'] = new ThemeComponentDefinition(module: 'Fixture', type: 'content', code: 'native-box', name: 'native-box',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT, defaultConfig: ['theme_value' => 'CURRENT DEFAULT'], templateContent: $template, slots: ['inside']);
        $html = $this->execute($compiled, ['title' => 'RUNTIME VALUE', 'nullable' => null]);
        self::assertStringContainsString('<h1>RUNTIME VALUE</h1>', $html);
        self::assertStringContainsString('<small>FROZEN</small>', $html);
        self::assertSame(1, substr_count($html, '<b>CHILD</b>'));
        self::assertStringNotContainsString('CURRENT DEFAULT', $html);
        self::assertStringContainsString('<i>NULL KEPT</i>', $html);
    }
}
