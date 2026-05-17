<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Exception;

/**
 * AI 站点组件强契约异常。
 *
 * 用途：
 *   `AiSitePageComponentGenerationService::ensureAiPayloadValid` 与门禁链路在
 *   发现 AI 输出不符合契约时不再只抛 `\RuntimeException(...message...)`，而是
 *   抛出本异常并带上结构化 findings。每条 finding 描述：
 *     - rule：违反的契约 ID（如 visual_depth.gradient / required_image.slot_missing）
 *     - field：相关 JSON 字段（如 css_extra / html_content / palette）
 *     - found：AI 实际产出值的摘要
 *     - expected：契约期望
 *     - hint：给 AI 的自然语言修复提示（中文，强契约自检语气）
 *
 *   `buildStrictComponentRecoveryPrompt` 会读取 findings，并把它们「逐条」编码
 *   到下一轮 prompt，让 AI 定点修复，而不是凭一句模糊的英文 message 重新猜。
 */
final class AiSiteComponentContractException extends \RuntimeException
{
    /**
     * @param list<array{rule:string,field?:string,found?:string,expected?:string,hint?:string}> $findings
     */
    public function __construct(
        string $message,
        private readonly array $findings = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<array{rule:string,field?:string,found?:string,expected?:string,hint?:string}>
     */
    public function getFindings(): array
    {
        return $this->findings;
    }

    /**
     * 渲染成可直接拼到 prompt 里的中文清单。每条一行，前缀「-」。
     */
    public function renderFindingsForPrompt(int $maxLines = 12): string
    {
        if ($this->findings === []) {
            return '';
        }

        $lines = [];
        foreach ($this->findings as $finding) {
            if (!\is_array($finding)) {
                continue;
            }
            $rule = (string)($finding['rule'] ?? '');
            if ($rule === '') {
                continue;
            }
            $field = (string)($finding['field'] ?? '');
            $found = (string)($finding['found'] ?? '');
            $expected = (string)($finding['expected'] ?? '');
            $hint = (string)($finding['hint'] ?? '');

            $segments = ['- [' . $rule . ']'];
            if ($field !== '') {
                $segments[] = '字段=' . $field;
            }
            if ($expected !== '') {
                $segments[] = '期望=' . $expected;
            }
            if ($found !== '') {
                $segments[] = '实际=' . $found;
            }
            if ($hint !== '') {
                $segments[] = '修复=' . $hint;
            }
            $lines[] = \implode(' | ', $segments);
            if (\count($lines) >= $maxLines) {
                break;
            }
        }

        return \implode("\n", $lines);
    }
}
