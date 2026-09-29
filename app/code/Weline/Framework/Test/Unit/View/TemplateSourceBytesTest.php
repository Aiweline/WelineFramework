<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\App\State;
use Weline\Framework\View\Template;

final class TemplateSourceBytesTest extends TestCase
{
    public function testPinnedBytesUseNormalTagsAndKeepTemplateData(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'fetchSourceHtml'), 'Pinned sources need the ordinary Template compiler.');
        $template->setData('source_label', 'kept');
        $source = '<if condition="$this->getData(\'enabled\')"><b>{{source_label}}</b></if><?php echo $this->getData("suffix"); ?>';
        $html = $template->fetchSourceHtml('virtual-source.phtml', $source, __FILE__, 'revision-1', ['enabled' => true, 'suffix' => '!']);
        self::assertStringContainsString('<b>kept</b>!', $html);
        self::assertSame('kept', $template->getData('source_label'));
    }

    public function testOlderPinnedBytesCannotOverwriteNewerCompilation(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'getFetchFileFromSource'), 'Snapshot compilation must be addressable by source bytes.');
        $old = $template->getFetchFileFromSource('revision-source.phtml', '<b>old</b>', __FILE__, 'old-r');
        $new = $template->getFetchFileFromSource('revision-source.phtml', '<b>new</b>', __FILE__, 'new-r');
        $template->getFetchFileFromSource('revision-source.phtml', '<b>old</b>', __FILE__, 'old-r');
        self::assertNotSame($old, $new);
        self::assertStringContainsString('new', $template->ob_file($new));
        self::assertStringContainsString('old', $template->ob_file($old));
    }

    public function testOriginMagicConstantsRetainSourceLocation(): void
    {
        $template = Template::getInstance();
        self::assertTrue(method_exists($template, 'fetchSourceHtml'));
        $html = $template->fetchSourceHtml('magic.phtml', '<?php echo __DIR__ . "|" . __FILE__; ?>', __FILE__);
        self::assertSame(__DIR__ . '|' . __FILE__, trim($html));
    }
    public function testLanguageSeparatesSourceCompilationAndRuntimeMaterializerKeepsData(): void
    {
        $template = Template::getInstance();
        $template->setData('shared_value', 'survives');
        $runtime = new \Weline\Theme\Service\RuntimeTemplateMaterializer($template);
        State::setRequestLanguageOverride('en_US');
        try {
            $english = $runtime->materializeContent('<strong>{{shared_value}}</strong>', __FILE__);
            State::setRequestLanguageOverride('zh_Hans_CN');
            $chinese = $runtime->materializeContent('<strong>{{shared_value}}</strong>', __FILE__);
            self::assertNotSame($english, $chinese);
            self::assertStringContainsString('<strong>survives</strong>', $runtime->renderContent('<strong>{{shared_value}}</strong>', [], __FILE__));
            self::assertSame('survives', $template->getData('shared_value'));
        } finally {
            State::setRequestLanguageOverride('');
        }
    }
    public function testRealRequestUsesOriginModuleWithoutCloningRequest(): void
    {
        $template = Template::getInstance();
        $origin = BP . 'app/code/Weline/Theme/view/theme/frontend/layouts/homepage/default.phtml';
        $source = '<?php echo $this->fetchTagSource("statics", "origin-probe.css", false); ?>';
        $html = $template->fetchSourceHtml('origin-probe.phtml', $source, $origin);
        self::assertStringContainsString('/Weline/Theme/view/statics/origin-probe.css', $html);
    }
    public function testStrictTypesRemainsTheFirstPhpStatement(): void
    {
        $html = Template::getInstance()->fetchSourceHtml('strict-source.phtml', '<?php declare(strict_types=1); echo "strict-ok";', __FILE__);
        self::assertSame('strict-ok', $html);
    }
}
