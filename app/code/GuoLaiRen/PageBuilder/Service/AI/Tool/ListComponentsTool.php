<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Model\Component;
use Weline\Framework\Manager\ObjectManager;

/**
 * 列出组件工具
 * 
 * 列出指定风格/分类下的可用组件，供智能体了解已有组件
 */
class ListComponentsTool implements ToolInterface
{
    public function getName(): string
    {
        return 'list_components';
    }

    public function getDescription(): string
    {
        return 'List available components, optionally filtered by style code and/or category. Returns component code, name, description, and category.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'style_code' => [
                    'type' => 'string',
                    'description' => 'Filter by template style code (optional)',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Filter by category: header, footer, or content (optional)',
                    'enum' => ['header', 'footer', 'content'],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of components to return (default: 20)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $args): mixed
    {
        $styleCode = $args['style_code'] ?? '';
        $category = $args['category'] ?? '';
        $limit = (int)($args['limit'] ?? 20);
        $limit = min($limit, 50); // 安全上限

        /** @var Component $componentModel */
        $componentModel = ObjectManager::getInstance(Component::class);
        $query = $componentModel->reset()
            ->where(Component::fields_IS_ACTIVE, 1);

        if (!empty($styleCode)) {
            $query->where(Component::fields_STYLE_CODE, $styleCode);
        }
        if (!empty($category)) {
            $query->where(Component::fields_CATEGORY, $category);
        }

        $query->order(Component::fields_SORT_ORDER, 'ASC')
            ->limit($limit);

        $components = $query->select()->fetch();

        $result = [];
        if ($components && is_iterable($components)) {
            $items = is_object($components) && method_exists($components, 'getItems')
                ? $components->getItems()
                : $components;

            foreach ($items as $comp) {
                if (!is_object($comp)) {
                    continue;
                }
                $result[] = [
                    'code' => $comp->getData(Component::fields_CODE),
                    'name' => $comp->getData(Component::fields_NAME),
                    'description' => mb_substr($comp->getData(Component::fields_DESCRIPTION) ?: '', 0, 200),
                    'category' => $comp->getData(Component::fields_CATEGORY),
                    'style_code' => $comp->getData(Component::fields_STYLE_CODE),
                    'is_ai_generated' => (bool)$comp->getData(Component::fields_IS_AI_GENERATED),
                ];
            }
        }

        return [
            'total' => count($result),
            'components' => $result,
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
