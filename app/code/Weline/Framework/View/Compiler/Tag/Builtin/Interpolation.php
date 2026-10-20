<?php

declare(strict_types=1);

/**
 * 文本插值：`{{ expr }}` → `<?php echo ...; ?>`。
 *
 * 旧编译器在 Tokenizer/AstBuilder 阶段就把双花括号变量变成 PHP 占位节点
 * （`Token::Variable` → `buildVariableNode()`），所以它**发生在标签回调之前**：
 * 这也是为什么 `<lang>{{title}}</lang>` 的旧产物里，短语字符串本身含有 PHP 代码。
 *
 * 复刻要点（都有真实产物做基准，见 `InterpolationTest`）：
 * - `{{title}}`            → `<?php echo ($title ?? $this->getData('title')); ?>`
 * - `{{ group_set.name }}` → `<?php echo (($group_set ?? $this->getData('group_set') ?? [])['name'] ?? null); ?>`
 * - `{{$user.name}}`       → `<?php echo ($user ?? [])['name']; ?>`
 *
 * 扫描只发生在 `TextNode` 上：PHP 片段里的 `{{` 属于用户代码，不碰。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

final class Interpolation
{
    /**
     * 把一段文本切成「文本 / 插值」片段。
     *
     * @return list<array{type:string, raw:string}> type = text|php
     */
    public function split(string $text, ExpressionParser $parser = new ExpressionParser()): array
    {
        if (!\str_contains($text, '{{')) {
            return [['type' => 'text', 'raw' => $text]];
        }

        $parts = [];
        $offset = 0;
        while (\preg_match('/\{\{(.*?)\}\}/s', $text, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $matches[0][1];
            $raw = $matches[0][0];
            if ($start > $offset) {
                $parts[] = ['type' => 'text', 'raw' => \substr($text, $offset, $start - $offset)];
            }
            $inner = \trim($matches[1][0]);
            // 旧编译器对 `{{}}` 走 `parseVariablePath()`（路径 + `|` 默认值），
            // 不是条件表达式；两者在 `{{x||y}}`、`{{x|0}}` 上产物不同，不能混用。
            $expression = $parser->path($inner);
            $parts[] = ['type' => 'php', 'raw' => '<?php echo ' . $expression . '; ?>'];
            $offset = $start + \strlen($raw);
        }
        if ($offset < \strlen($text)) {
            $parts[] = ['type' => 'text', 'raw' => \substr($text, $offset)];
        }

        return $parts;
    }
}
