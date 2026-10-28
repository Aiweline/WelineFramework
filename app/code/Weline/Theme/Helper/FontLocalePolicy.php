<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Env\WelineEnv;

/**
 * Decides which font families a rendered page is allowed to declare.
 *
 * Why this exists (theme-spec-gates S7): the storefront head used to declare every
 * CJK family unconditionally, so an English page still downloaded ~4 CJK subsets
 * (the biggest single waste on the playinginchina mobile audit). Font declaration is
 * now a function of the *rendered* language, decided in one place instead of
 * scattered `<w:if>` blocks across themes.
 */
final class FontLocalePolicy
{
    /** Language prefixes whose glyph coverage needs the CJK families. */
    private const CJK_PREFIXES = ['zh', 'ja', 'ko'];

    /** Normalize `zh_Hans_CN` / `zh-CN` / `en_US` to a two-letter prefix. */
    public static function primarySubtag(?string $lang = null): string
    {
        $lang = strtolower(trim((string)($lang ?? '')));
        if ($lang === '') {
            try {
                $lang = strtolower(trim((string)WelineEnv::getLangLocal()));
            } catch (\Throwable) {
                $lang = '';
            }
        }
        $lang = str_replace('-', '_', $lang);
        $prefix = explode('_', $lang)[0] ?? '';

        return $prefix;
    }

    /** True when the page paints CJK text and therefore needs the CJK font families. */
    public static function isCjk(?string $lang = null): bool
    {
        return in_array(self::primarySubtag($lang), self::CJK_PREFIXES, true);
    }
}
