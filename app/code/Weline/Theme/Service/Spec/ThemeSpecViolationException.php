<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Spec;

/**
 * 主题规范门禁违规（dev 编译期 / `theme:compile spec` 直接抛出）。
 *
 * 携带 rule / specFile / specLine / hint 四要素（不能复用 Exception::$file/$line），
 * 报错信息必须能让人一眼知道「哪条规则、改哪里」。
 */
final class ThemeSpecViolationException extends \RuntimeException
{
    public function __construct(
        public readonly string $rule,
        public readonly string $specFile,
        public readonly int $specLine,
        public readonly string $hint,
        string $message = ''
    ) {
        if ($message === '') {
            $message = sprintf(
                'Theme spec violation [%s] %s:%d — %s',
                $rule,
                $specFile,
                $specLine,
                $hint
            );
        }

        parent::__construct($message);
    }

    public function describe(): string
    {
        return sprintf(
            '%s %s:%d — %s',
            $this->rule,
            \defined('BP') ? str_replace(BP, '', $this->specFile) : $this->specFile,
            $this->specLine,
            $this->hint
        );
    }
}
