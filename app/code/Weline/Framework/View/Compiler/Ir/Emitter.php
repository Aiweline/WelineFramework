<?php

declare(strict_types=1);

/**
 * Emitter：语句树 → PHP 源码。
 *
 * 唯一的序列化出口。它不做任何格式化/美化：`com_*.phtml` 的字节形态由
 * Lower 决定，Emitter 只负责把语句按顺序写出来。这样「渲染字节一致」
 * 才能与「产物规范化 diff 可控」同时成立。
 *
 * @author Aiweline
 */

namespace Weline\Framework\View\Compiler\Ir;

final class Emitter
{
    /**
     * @param list<Stmt> $statements
     */
    public function emit(array $statements): string
    {
        $output = '';
        foreach ($statements as $statement) {
            if ($statement instanceof TextStmt) {
                $output .= $statement->raw();
                continue;
            }
            if ($statement instanceof RawPhpStmt) {
                $output .= $statement->code();
                continue;
            }
            throw new \LogicException('未支持的语句类型：' . $statement::class);
        }

        return $output;
    }
}
