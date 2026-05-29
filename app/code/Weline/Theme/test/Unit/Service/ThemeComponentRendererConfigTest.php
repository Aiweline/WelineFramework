<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Service\ThemeComponentRenderer;

final class ThemeComponentRendererConfigTest extends TestCore
{
    public function testThemeComponentInstanceConfigIsAvailableAsTemplateMetaData(): void
    {
        /** @var ThemeComponentRenderer $renderer */
        $renderer = ObjectManager::getInstance(ThemeComponentRenderer::class);

        $definition = new ThemeComponentDefinition(
            module: 'Weline_Theme',
            type: 'theme_component',
            code: 'basic/probe',
            name: 'Probe',
            renderMode: ThemeRenderable::MODE_TEMPLATE_CONTENT,
            defaultConfig: [
                'text' => 'Default Text',
                'type' => 'secondary',
            ],
            params: [
                'text' => ['default' => 'Default Text'],
                'type' => ['default' => 'secondary'],
            ],
            templateContent: <<<'PHTML'
<?php
$metaParams = ['text' => 'Default Text', 'type' => 'secondary'];
foreach ($metaParams as $key => $value) {
    if ($this->getData("meta.{$key}") === null) {
        $this->setData("meta.{$key}", $value);
    }
}
echo '<button class="' . htmlspecialchars((string)$this->getData('meta.type')) . '">' . htmlspecialchars((string)$this->getData('meta.text')) . '</button>';
PHTML
        );

        $html = $renderer->render($definition, [
            'text' => 'Runtime Text',
            'type' => 'primary',
        ], null, [
            'area' => 'frontend',
            'preview_mode' => true,
        ]);

        self::assertStringContainsString('Runtime Text', $html);
        self::assertStringContainsString('class="primary"', $html);
        self::assertStringNotContainsString('Default Text', $html);
    }
}
