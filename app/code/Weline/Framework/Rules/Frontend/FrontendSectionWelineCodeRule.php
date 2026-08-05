<?php

declare(strict_types=1);

namespace Weline\Framework\Rules\Frontend;

use Weline\Framework\App\Exception;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Rules\RuleInterface;

/**
 * 前台 section / w:slot[wrapper=section] 必须配置非空 weline-code。
 *
 * 在 setup:upgrade 准备阶段由 RulesManager 致命校验。
 */
final class FrontendSectionWelineCodeRule implements RuleInterface
{
    public function __construct(
        private readonly Printing $printing,
        private readonly SectionWelineCodeScanner $scanner,
    ) {
    }

    public function getName(): string
    {
        return 'frontend-section-weline-code';
    }

    public function getBrief(): string
    {
        return __('前台 section / wrapper=section 必须配置 weline-code');
    }

    public function getDescription(): string
    {
        return __(
            '前台纳入集模板中的字面 <section> 与 <w:slot wrapper="section"> 必须配置非空 weline-code（或含 PHP 插值）。'
            . '同文件内字面量 code 不得重复。后台/Admin/generated/view/tpl 不在本规则范围内。'
            . '本地可先运行：php bin/w frontend:check-section-code'
        );
    }

    public function getPriority(): int
    {
        return 15;
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
            '【致命错误】前台 section/slot-section 缺少 weline-code：共 %{1} 处。',
            [count($violations)],
        ));
        foreach (\array_slice($violations, 0, 50) as $violation) {
            $this->printing->error($this->scanner->formatViolation($violation));
        }
        if (count($violations) > 50) {
            $this->printing->warning(__(
                '其余 %{1} 条已省略；请运行 php bin/w frontend:check-section-code --json 查看全部。',
                [count($violations) - 50],
            ));
        }

        throw new Exception(__(
            '【致命错误】前台 section weline-code 约束违反（规则 frontend-section-weline-code），共 %{1} 处。请修复后重试 setup:upgrade。',
            [count($violations)],
        ));
    }
}
