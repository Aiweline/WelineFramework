<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Ai\Adapter;

/**
 * Theme 编辑器虚拟部件 AI 场景（theme_component_generation）。
 */
class ThemeComponentGenerationAdapter extends ThemeAdapter
{
    public function getCode(): string
    {
        return 'theme_component_generation';
    }

    public function getName(): string
    {
        return (string)__('Theme 虚拟部件生成');
    }

    public function getDescription(): string
    {
        return (string)__(
            '用于主题编辑器 AI 生成/精修虚拟部件（theme_component*），与 theme_component_builder 智能体配套。',
        );
    }
}
