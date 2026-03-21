<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeAiPayloadValidator;
use Weline\Theme\Service\ThemeComponentRenderer;

class RenderVirtualComponentPreviewTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeAiPayloadValidator $payloadValidator,
        private readonly ThemeComponentRenderer $componentRenderer,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    public function getName(): string
    {
        return 'render_virtual_component_preview';
    }

    public function getDescription(): string
    {
        return 'Render an unsaved virtual theme component payload into preview HTML.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
                'payload' => ['description' => 'Candidate payload as object or JSON string'],
                'config' => ['description' => 'Optional preview config override object'],
            ],
            'required' => ['theme_id', 'payload'],
        ];
    }

    public function execute(array $args): mixed
    {
        $payload = $args['payload'] ?? [];
        if (is_string($payload)) {
            $payload = $this->payloadValidator->extractPayload($payload) ?? [];
        }
        if (!is_array($payload)) {
            return ['success' => false, 'message' => 'invalid payload'];
        }

        $validation = $this->payloadValidator->validatePayload($payload);
        if (!$validation['valid']) {
            return ['success' => false, 'validation' => $validation];
        }

        $payload = $validation['payload'];
        $area = strtolower((string)($args['area'] ?? 'frontend')) === 'backend' ? 'backend' : 'frontend';
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load((int)($args['theme_id'] ?? 0));

        $definition = new ThemeComponentDefinition(
            module: 'Weline_Theme',
            type: 'theme_component',
            code: (string)$payload['component_code'],
            name: (string)$payload['name'],
            description: (string)($payload['description'] ?? ''),
            area: $area,
            sourceType: 'virtual',
            category: (string)$payload['category'],
            renderMode: (string)($payload['render_mode'] ?? ThemeRenderable::MODE_TEMPLATE_CONTENT),
            configSchema: is_array($payload['config_schema_json'] ?? null) ? $payload['config_schema_json'] : [],
            defaultConfig: is_array($payload['default_config_json'] ?? null) ? $payload['default_config_json'] : [],
            meta: is_array($payload['meta_json'] ?? null) ? $payload['meta_json'] : [],
            params: $this->convertSchemaToParams(is_array($payload['config_schema_json'] ?? null) ? $payload['config_schema_json'] : []),
            position: is_array($payload['meta_json']['position'] ?? null) ? $payload['meta_json']['position'] : ['content'],
            pageLayouts: is_array($payload['meta_json']['page_layouts'] ?? null) ? $payload['meta_json']['page_layouts'] : ['*'],
            slots: is_array($payload['meta_json']['slots'] ?? null) ? $payload['meta_json']['slots'] : [],
            slot: isset($payload['meta_json']['slot']) ? (string)$payload['meta_json']['slot'] : null,
            exclusive: (bool)($payload['meta_json']['exclusive'] ?? false),
            compatible: !array_key_exists('compatible', $payload['meta_json'] ?? []) || (bool)$payload['meta_json']['compatible'],
            isContainer: (bool)($payload['meta_json']['is_container'] ?? false),
            isAiGenerated: true,
            icon: !empty($payload['icon']) ? (string)$payload['icon'] : null,
            templateContent: (string)$payload['template_content'],
            themeId: $theme->getId() ?: null,
            logicalKey: 'components/' . $payload['component_code'],
            layerKey: $theme->getId() ? 'theme:' . $theme->getId() : null,
        );

        try {
            $html = $this->componentRenderer->render(
                $definition,
                is_array($args['config'] ?? null) ? $args['config'] : [],
                $theme->getId() ? $theme : null,
                ['area' => $area, 'preview_mode' => true]
            );

            return [
                'success' => true,
                'html' => $html,
                'validation' => $validation,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'validation' => $validation,
            ];
        }
    }

    public function isEnabled(): bool
    {
        return true;
    }

    private function convertSchemaToParams(array $schema): array
    {
        $params = [];
        foreach ($schema as $key => $field) {
            if (!is_array($field)) {
                continue;
            }
            $params[$key] = [
                'name' => $field['label'] ?? $field['name'] ?? $key,
                'label' => $field['label'] ?? $field['name'] ?? $key,
                'description' => $field['description'] ?? '',
                'default' => $field['default'] ?? null,
                'type' => $field['type'] ?? 'text',
                'required' => (bool)($field['required'] ?? false),
                'options' => $field['options'] ?? [],
            ];
        }

        return $params;
    }
}
