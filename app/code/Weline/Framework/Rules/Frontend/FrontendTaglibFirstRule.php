<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;

/**
 * 报告级门禁， intentionally non-fatal until backlog cleared。
 *
 * 在 setup:upgrade 准备阶段仅打印手写领域控件报告，不阻断升级。
 */
final class FrontendTaglibFirstRule implements RuleInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly TaglibFirstScanner $scanner,
    ) {
    }

    public function getName(): string
    {
        return 'frontend-taglib-first';
    }

    public function getBrief(): string
    {
        return __('领域控件必须使用官方 Taglib（站点/商店/渠道/域名/语言/货币/范围/国家）');
    }

    public function getDescription(): string
    {
        return __(
            '手写站点/商店/渠道/域名/语言/货币/范围/国家控件必须逐步替换为官方 Taglib。'
            . '当前为报告级：只打印违规与建议标签，不阻断 setup:upgrade；存量清零后由后续波次切致命。'
            . '本地可先运行：php bin/w frontend:check-taglib-first'
        );
    }

    public function getPriority(): int
    {
        return 18;
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
            '【报告】手写领域控件未使用官方 Taglib：共 %{1} 处。',
            [count($violations)],
        ));
        foreach (\array_slice($violations, 0, 50) as $violation) {
            $this->printing->error($this->scanner->formatViolation($violation));
        }
        if (count($violations) > 50) {
            $this->printing->warning(__(
                '其余 %{1} 条已省略；请运行 php bin/w frontend:check-taglib-first --json 查看全部。',
                [count($violations) - 50],
            ));
        }
    }
}
