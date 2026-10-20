<?php

declare(strict_types=1);

/**
 * `backend-url` 标签（B1 URL 家族第一刀，内联用量 1,067 次）。
 *
 * 严格按旧回调的两个分支复刻：
 * - 元素形态 `tag`：子内容按 `|` 切成 `路径|参数`，去掉引号与空格后生成
 *   `<?=$this->getBackendUrl('路径'[,参数])?>`；
 * - 内联形态（`@backend-url{...}` / `(...)`）：**不去引号**，只去空格，按 `|` 切分，
 *   原样作为表达式传入（`@backend-url{'a'|['k'=>1]}` → `getBackendUrl('a',['k'=>1])`）。
 *
 * 两处差异（去不去引号）是旧实现的真实行为，不能“统一一下”。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Tag\Builtin;

use Weline\Framework\View\Compiler\Ir\LowerContext;
use Weline\Framework\View\Compiler\Ir\RawPhpStmt;
use Weline\Framework\View\Compiler\Parser\InlineNode;
use Weline\Framework\View\Compiler\Parser\TagNode;
use Weline\Framework\View\Compiler\Tag\TagDescriptor;

final class BackendUrlTag
{
    /**
     * `admin-url` 在旧实现里与 `backend-url` **完全同构**（元素和内联都调用 getBackendUrl），
     * 只是标签名不同；这里用同一个工厂避免两份实现漂移。
     */
    public static function descriptor(string $name = 'backend-url'): TagDescriptor
    {
        return new TagDescriptor(
            name: $name,
            paired: true,
            selfClosing: true,
            stage: TagDescriptor::STAGE_COMPILE,
            elementForm: true,
            inlineForm: true,
            lower: static function (TagNode $node, mixed $context): array {
                $content = $context instanceof LowerContext ? $context->children($node) : '';
                $parts = \explode('|', $content, 2);
                $path = \str_replace(' ', '', \trim($parts[0] ?? '', "'\""));
                if ($path === '') {
                    return [new RawPhpStmt('', $node->span())];
                }
                $params = isset($parts[1]) && \trim($parts[1]) !== '' ? ',' . \trim($parts[1]) : '';

                return [new RawPhpStmt('<?=$this->getBackendUrl(\'' . $path . '\'' . $params . ')?>', $node->span())];
            },
            inlineLower: static function (InlineNode $node): array {
                $raw = \str_replace(' ', '', $node->bodyRaw());
                $parts = \explode('|', $raw, 2);
                $pathExpr = $parts[0] ?? '';
                if ($pathExpr === '') {
                    return [new RawPhpStmt('', $node->span())];
                }
                $paramsExpr = isset($parts[1]) ? \trim($parts[1]) : '';
                $argument = $paramsExpr !== '' ? $pathExpr . ',' . $paramsExpr : $pathExpr;

                return [new RawPhpStmt('<?=$this->getBackendUrl(' . $argument . ')?>', $node->span())];
            },
        );
    }
}
