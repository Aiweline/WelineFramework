<?php
declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Tool;

use Weline\Ai\Interface\ToolInterface;
use GuoLaiRen\PageBuilder\Service\AI\FrameworkBuilder;
use Weline\Framework\Manager\ObjectManager;

/**
 * 获取组件框架模板工具
 * 
 * 获取指定区域（header/footer/content）的框架模板结构和提示词指南
 */
class GetComponentFrameworkTool implements ToolInterface
{
    public function getName(): string
    {
        return 'get_component_framework';
    }

    public function getDescription(): string
    {
        return 'Get the framework template structure and prompt guide for a specific component category (header, footer, content). This provides the expected output format and structure constraints.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Component category: header, footer, or content',
                    'enum' => ['header', 'footer', 'content'],
                ],
            ],
            'required' => ['category'],
        ];
    }

    public function execute(array $args): mixed
    {
        $category = $args['category'] ?? 'content';

        /** @var FrameworkBuilder $frameworkBuilder */
        $frameworkBuilder = ObjectManager::getInstance(FrameworkBuilder::class);

        $result = [
            'category' => $category,
            'framework_exists' => $frameworkBuilder->frameworkExists($category),
        ];

        // 获取框架模板
        if ($result['framework_exists']) {
            $framework = $frameworkBuilder->loadFramework($category);
            $result['framework_template'] = mb_substr($framework, 0, 5000);
        }

        // 获取提示词指南
        $promptGuide = $frameworkBuilder->getFrameworkPromptGuide($category);
        if (!empty($promptGuide)) {
            $result['prompt_guide'] = mb_substr($promptGuide, 0, 3000);
        }

        // 框架已注入变量列表（与校验白名单、系统提示一致，禁止在 php_variables 中重复声明）
        $result['framework_variables'] = $frameworkBuilder->getFrameworkProvidedVariables($category);

        return $result;
    }

    public function isEnabled(): bool
    {
        return true;
    }
}
