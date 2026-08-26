<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;

/**
 * Theme layouts/partials 仅允许内嵌 Weline_Theme 部件。
 *
 * 在 setup:upgrade 准备阶段由 RulesManager 致命校验。
 */
final class FrontendThemeLayoutWidgetOwnerRule implements RuleInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly ThemeLayoutWidgetOwnerScanner $scanner,
    ) {
    }

    public function getName(): string
    {
        return 'frontend-theme-layout-widget-owner';
    }

    public function getBrief(): string
    {
        return __('Theme 布局/partial 仅允许内嵌 Weline_Theme 部件');
    }

    public function getDescription(): string
    {
        return __(
            'Theme view/theme/**/layouts|partials 中禁止内嵌非 Weline_Theme 注册的 <w:widget> 或 fetch(.../widgets/...)。'
            . '其他模块须使用空 slot + default_injections。'
            . '本地可先运行：php bin/w frontend:check-theme-layout-widgets'
        );
    }

    public function getPriority(): int
    {
        return 16;
    }

    public function getCategory(): string
    {
        return 'frontend';
    }

    public function validate(): void
    {
        $root = \defined('BP') ? BP . '/app/code' : (\dirname(__DIR__, 5) . '/app/code');
        $violations = $this->scanner->scanProject($root);
        if ($violations === []) {
            return;
        }

        $this->printing->error(__(
            '【致命错误】Theme 布局内嵌非 Theme 部件：共 %{1} 处。',
            [count($violations)],
        ));
        foreach (\array_slice($violations, 0, 50) as $violation) {
            $this->printing->error($this->scanner->formatViolation($violation));
        }
        if (count($violations) > 50) {
            $this->printing->warning(__(
                '其余 %{1} 条已省略；请运行 php bin/w frontend:check-theme-layout-widgets --json 查看全部。',
                [count($violations) - 50],
            ));
        }

        throw new Exception(__(
            '【致命错误】Theme 布局部件归属约束违反（规则 frontend-theme-layout-widget-owner），共 %{1} 处。请修复后重试 setup:upgrade。',
            [count($violations)],
        ));
    }
}
