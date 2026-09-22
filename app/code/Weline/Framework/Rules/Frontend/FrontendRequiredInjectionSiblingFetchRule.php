<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;

/**
 * setup:upgrade 致命门禁：required injection 目标槽旁/槽内禁止再 fetch 同部件。
 */
final class FrontendRequiredInjectionSiblingFetchRule implements RuleInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly RequiredInjectionSiblingFetchScanner $scanner,
    ) {
    }

    public function getName(): string
    {
        return 'frontend-required-injection-sibling-fetch';
    }

    public function getBrief(): string
    {
        return __('禁止 required injection 槽旁再 fetch 同部件');
    }

    public function getDescription(): string
    {
        return __(
            '同身份 XOR：布局/宿主内嵌 与 required default_injections 禁止并存。'
            . '布局已有则清空注入并标 placement=layout；否则只留空槽 + 注入。'
            . '本地：php bin/w frontend:check-required-injection-sibling-fetch'
        );
    }

    public function getPriority(): int
    {
        return 17;
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
            '【致命错误】required injection 槽旁叠渲双路径：共 %{1} 处。',
            [count($violations)],
        ));
        foreach (\array_slice($violations, 0, 50) as $violation) {
            $this->printing->error($this->scanner->formatViolation($violation));
        }
        if (count($violations) > 50) {
            $this->printing->warning(__(
                '其余 %{1} 条已省略；请运行 php bin/w frontend:check-required-injection-sibling-fetch --json 查看全部。',
                [count($violations) - 50],
            ));
        }

        throw new Exception(__(
            '【致命错误】required injection 槽旁叠渲约束违反（规则 frontend-required-injection-sibling-fetch），共 %{1} 处。'
            . '请改为空槽 + default_injections 后重试 setup:upgrade。',
            [count($violations)],
        ));
    }
}
