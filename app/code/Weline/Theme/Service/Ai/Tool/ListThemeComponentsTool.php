<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\ThemeComponentCatalog;

class ListThemeComponentsTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeComponentCatalog $componentCatalog,
        private readonly WelineTheme $welineTheme,
    ) {
    }

    public function getName(): string
    {
        return 'list_theme_components';
    }

    public function getDescription(): string
    {
        return 'List effective theme components visible to the current theme inheritance chain.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme_id' => ['type' => 'integer', 'description' => 'Theme ID'],
                'area' => ['type' => 'string', 'description' => 'frontend or backend'],
                'category' => ['type' => 'string', 'description' => 'Optional category filter'],
                'limit' => ['type' => 'integer', 'description' => 'Maximum rows to return, default 20'],
            ],
            'required' => ['theme_id'],
        ];
    }

    public function execute(array $args): mixed
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load((int)($args['theme_id'] ?? 0));
        $area = strtolower((string)($args['area'] ?? 'frontend')) === 'backend' ? 'backend' : 'frontend';
        $category = trim((string)($args['category'] ?? ''));
        $limit = min(max((int)($args['limit'] ?? 20), 1), 50);

        $rows = [];
        foreach ($this->componentCatalog->getDefinitions($area, $theme) as $definition) {
            if ($category !== '' && $definition->category !== $category) {
                continue;
            }

            $rows[] = [
                'module' => $definition->module,
                'type' => $definition->type,
                'code' => $definition->code,
                'name' => $definition->name,
                'description' => $definition->description,
                'category' => $definition->category,
                'source_type' => $definition->sourceType,
                'logical_key' => $definition->logicalKey,
                'slot' => $definition->slot,
                'position' => $definition->position,
                'page_layouts' => $definition->pageLayouts,
                'version_id' => $definition->versionId,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return [
            'total' => count($rows),
            'components' => $rows,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
