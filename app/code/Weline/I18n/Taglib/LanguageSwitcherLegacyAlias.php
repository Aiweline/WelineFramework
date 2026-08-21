<?php

declare(strict_types=1);

namespace Weline\I18n\Taglib;

use Weline\Framework\Taglib\TaglibInterface;

/**
 * Deprecated alias for &lt;w:i18n:language:switcher /&gt;.
 * Prefer &lt;w:i18n:switcher /&gt; (LanguageSwitcher).
 */
final class LanguageSwitcherLegacyAlias implements TaglibInterface
{
    public static function name(): string
    {
        return 'i18n:language:switcher';
    }

    public static function tag(): bool
    {
        return LanguageSwitcher::tag();
    }

    public static function tag_start(): bool
    {
        return LanguageSwitcher::tag_start();
    }

    public static function tag_end(): bool
    {
        return LanguageSwitcher::tag_end();
    }

    public static function attr(): array
    {
        return LanguageSwitcher::attr();
    }

    public static function callback(): callable
    {
        return LanguageSwitcher::callback();
    }

    public static function tag_self_close(): bool
    {
        return LanguageSwitcher::tag_self_close();
    }

    public static function tag_self_close_with_attrs(): bool
    {
        return LanguageSwitcher::tag_self_close_with_attrs();
    }

    public static function parent(): ?string
    {
        return LanguageSwitcher::parent();
    }

    public static function document(): string
    {
        return '<p><code>&lt;w:i18n:language:switcher /&gt;</code> 已弃用，请改用 <code>&lt;w:i18n:switcher /&gt;</code>。本别名映射到同一实现。</p>';
    }
}
