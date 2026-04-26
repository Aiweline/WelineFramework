<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use ReflectionClass;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Controller\PcController;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Controller\Frontend\ThemePreview\Content;
use Weline\Theme\Helper\ThemeConfigHelper;
use Weline\Theme\Helper\ThemeData;

class ThemeTemplateTaglibTest extends TestCore
{
    public function testThemeConfigHelperBuildsSlashSeparatedTemplatePath(): void
    {
        ThemeData::setCurrentArea('frontend');

        $path = ThemeConfigHelper::getTemplatePath('partials.header', 'frontend');

        $this->assertStringContainsString('Weline_Theme::theme/frontend/partials/header/', $path);
        $this->assertStringNotContainsString('partials.header', $path);
        $this->assertStringEndsWith('.phtml', $path);
    }

    public function testThemeTemplateTagParsesConfiguredPartialPathWithoutDuplicatingThemePrefix(): void
    {
        ThemeData::setCurrentArea('frontend');

        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $content = <<<PHTML
<w:theme:template layout="partials.header">
    Weline_Theme::theme/frontend/partials/header/default.phtml
</w:theme:template>
PHTML;

        $result = $taglib->parse($template, 'theme-template-layout-test.phtml', $content);

        $this->assertStringNotContainsString('<w:theme:template', $result);
        $this->assertStringNotContainsString('theme/theme/frontend', $result);
        $this->assertStringNotContainsString('partials.header', $result);
        $this->assertStringContainsString('<header', $result);
    }

    public function testFrontendThemePreviewControllerUsesParentLayoutTypeProperty(): void
    {
        $reflection = new ReflectionClass(Content::class);
        $property = $reflection->getProperty('layoutType');

        $this->assertSame(PcController::class, $property->getDeclaringClass()->getName());
    }
}
