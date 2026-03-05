<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Model\Component;
use Weline\Framework\Manager\ObjectManager;

/**
 * 预览参考组件工具
 * 
 * 获取指定组件的完整代码，供智能体参考生成类似组件
 */
class PreviewReferenceTool implements ToolInterface
{
    public function getName(): string
    {
        return 'preview_reference_component';
    }

    public function getDescription(): string
    {
        return 'Get the complete code (HTML, CSS, JS, PHP variables) of an existing component by its code identifier. Use this to reference existing component styles and patterns.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'component_code' => [
                    'type' => 'string',
                    'description' => 'The unique code identifier of the component to preview',
                ],
            ],
            'required' => ['component_code'],
        ];
    }

    public function execute(array $args): mixed
    {
        $componentCode = $args['component_code'] ?? '';
        if (empty($componentCode)) {
            return ['error' => 'component_code is required'];
        }

        /** @var Component $componentModel */
        $componentModel = ObjectManager::getInstance(Component::class);
        $component = $componentModel->reset()
            ->where(Component::schema_fields_CODE, $componentCode)
            ->find()
            ->fetch();

        if (!$component || !$component->getId()) {
            return ['error' => __('组件不存在：%{1}', [$componentCode])];
        }

        $templateContent = $component->getData(Component::schema_fields_TEMPLATE_CONTENT);
        $decoded = null;
        if (!empty($templateContent)) {
            $decoded = json_decode($templateContent, true);
        }

        // 如果 template_content 中有结构化数据，使用它
        if (is_array($decoded)) {
            return [
                'code' => $componentCode,
                'name' => $component->getData(Component::schema_fields_NAME),
                'category' => $component->getData(Component::schema_fields_CATEGORY),
                'html_content' => $decoded['html_content'] ?? '',
                'css_content' => $decoded['css_content'] ?? '',
                'css_responsive' => $decoded['css_responsive'] ?? '',
                'js_content' => $decoded['js_content'] ?? '',
                'php_variables' => $decoded['php_variables'] ?? '',
                'extra_fields' => $decoded['extra_fields'] ?? '',
            ];
        }

        // 回退：读取组件文件
        $path = $component->getData(Component::schema_fields_PATH);
        if (!empty($path)) {
            $fullPath = BP . DIRECTORY_SEPARATOR . $path;
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                return [
                    'code' => $componentCode,
                    'name' => $component->getData(Component::schema_fields_NAME),
                    'category' => $component->getData(Component::schema_fields_CATEGORY),
                    'file_content' => mb_substr($content, 0, 10000), // 限制大小
                ];
            }
        }

        return [
            'code' => $componentCode,
            'name' => $component->getData(Component::schema_fields_NAME),
            'category' => $component->getData(Component::schema_fields_CATEGORY),
            'note' => 'No template content or file found',
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
