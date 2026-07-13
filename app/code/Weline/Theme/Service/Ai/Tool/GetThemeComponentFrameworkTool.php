<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Api\ToolInterface;
use Weline\Theme\Service\ThemeBuilderSchemaService;

class GetThemeComponentFrameworkTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeBuilderSchemaService $builderSchemaService,
    ) {
    }

    public function getName(): string
    {
        return 'get_theme_component_framework';
    }

    public function getDescription(): string
    {
        return 'Return the Weline Theme virtual component contract, available slots, variables, colors, and payload schema for AI generation.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
                'page_type' => ['type' => 'string', 'description' => 'Optional page type used for builder schema'],
            ],
            'required' => ['theme_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $schema = $this->builderSchemaService->getSchema(
            (int)($args['theme_id'] ?? 0),
            (string)($args['area'] ?? 'frontend'),
            isset($args['page_type']) ? (string)$args['page_type'] : null
        )->toArray();

        return [
            'payload_schema' => [
                'required' => ['name', 'description', 'category', 'component_code', 'template_content'],
                'fields' => [
                    'name' => 'Human readable component name',
                    'description' => 'Component description shown in builder palette',
                    'category' => 'Palette category, e.g. header/footer/content/banner/container/basic',
                    'component_code' => 'Unique logical key in form category/code',
                    'template_content' => 'PHTML template body, stored in DB and materialized to var/runtime at render time',
                    'config_schema_json' => 'Normalized form schema for builder configuration',
                    'default_config_json' => 'Component-level defaults',
                    'meta_json' => 'Slot, position, page_layouts, exclusive, icon, i18n label maps, etc.',
                ],
            ],
            'rules' => [
                'Do not write persistent repo theme files for virtual components.',
                'Prefer slot-aware markup using existing layout slot contracts.',
                'Return only JSON when asked for final output.',
                'Config labels/help can be locale maps like {"zh_Hans_CN":"标题","en_US":"Title"}.',
            ],
            'schema' => $schema,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
