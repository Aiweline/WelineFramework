<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder — AI 建站工作台调试预填内置默认值（未在 SystemConfig 中配置时使用）
 */

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAgentWorkspaceDebugDefaults
{
    public const SITE_TITLE = 'Teenipiya websiteProfile';

    public const BRIEF_DESCRIPTION = '我想做一个印度市场的棋牌网站，推广棋牌apk下载的seo网站';

    /** 会话未带主语言时，与后台「AI 建站工作台 · 调试预填」中的默认主语言一致，内置为简体中文 */
    public const DEFAULT_LOCALE = 'zh_Hans_CN';

    /**
     * @param non-empty-list<string> $allowed
     */
    public static function normalizeDefaultLocale(?string $value, array $allowed = ['zh_Hans_CN', 'en_US', 'zh_Hant_TW', 'ja_JP', 'ko_KR']): string
    {
        $t = $value === null ? '' : \trim($value);
        if ($t === '' || !\in_array($t, $allowed, true)) {
            return self::DEFAULT_LOCALE;
        }

        return $t;
    }
}
