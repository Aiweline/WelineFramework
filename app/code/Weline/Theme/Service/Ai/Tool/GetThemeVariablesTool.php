<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Theme\Service\ThemeBuilderSchemaService;

class GetThemeVariablesTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeBuilderSchemaService $builderSchemaService,
    ) {
    }

    public function getName(): string
    {
        return 'get_theme_variables';
    }

    public function getDescription(): string
    {
        return 'Return effective theme variables, colors, and selected defaults for the target theme and area.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
            ],
            'required' => ['theme_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $schema = $this->builderSchemaService->getSchema(
            (int)($args['theme_id'] ?? 0),
            (string)($args['area'] ?? 'frontend')
        )->toArray();

        return [
            'variables' => $schema['variables'] ?? [],
            'colors' => $schema['colors'] ?? [],
            'defaults' => $schema['meta']['defaults'] ?? [],
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
