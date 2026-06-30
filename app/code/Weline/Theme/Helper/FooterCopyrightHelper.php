<?php
declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * 页脚版权文案解析（支持主题 meta 与 i18n）
 */
class FooterCopyrightHelper
{
    private const RIGHTS_SUFFIX_ZH = '保留所有权利';

    public static function resolve(?string $raw): string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') {
            return __('© %{1} Weline Framework. %{2}', [date('Y'), __('保留所有权利')]);
        }

        $raw = str_replace('{year}', (string) date('Y'), $raw);
        $copyright = __($raw);

        if ($copyright === $raw && str_ends_with($raw, self::RIGHTS_SUFFIX_ZH)) {
            $prefix = mb_substr($raw, 0, mb_strlen($raw) - mb_strlen(self::RIGHTS_SUFFIX_ZH));
            $copyright = $prefix . __('保留所有权利');
        }

        return $copyright;
    }
}
