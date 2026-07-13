<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentCatalog;

class PreviewReferenceThemeComponentTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeComponentCatalog $componentCatalog,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    public function getName(): string
    {
        return 'preview_reference_component';
    }

    public function getDescription(): string
    {
        return 'Return a reference component definition and template snippet for reuse or style alignment.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
                'component_code' => ['type' => 'string', 'description' => 'Component logical code, e.g. banner/hero'],
            ],
            'required' => ['theme_id', 'component_code'],
        ];
    }

    public function execute(array $args): mixed
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load((int)($args['theme_id'] ?? 0));
        $area = strtolower((string)($args['area'] ?? 'frontend')) === 'backend' ? 'backend' : 'frontend';
        $code = (string)($args['component_code'] ?? '');
        $definition = $this->componentCatalog->find('Weline_Theme', 'theme_component', $code, $area, $theme);

        if (!$definition) {
            foreach ($this->componentCatalog->getDefinitions($area, $theme) as $candidate) {
                if ($candidate->code === $code) {
                    $definition = $candidate;
                    break;
                }
            }
        }

        if (!$definition) {
            return ['found' => false, 'message' => 'component not found'];
        }

        $templatePreview = '';
        if ($definition->templateContent) {
            $templatePreview = mb_substr($definition->templateContent, 0, 4000);
        } elseif ($definition->templatePath && is_file($definition->templatePath)) {
            $templatePreview = mb_substr((string)file_get_contents($definition->templatePath), 0, 4000);
        }

        return [
            'found' => true,
            'component' => $definition->toArray(),
            'template_preview' => $templatePreview,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
