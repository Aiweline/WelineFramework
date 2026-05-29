<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder — AI 建站工作台调试预填内置默认值（未在 SystemConfig 中配置时使用）
 */

namespace GuoLaiRen\PageBuilder\Service;

final class AiSiteAgentWorkspaceDebugDefaults
{
    public const SITE_TITLE = '霓虹棋牌馆';

    public const BRIEF_DESCRIPTION = '打造一个霓虹棋牌风格的线上娱乐网站，面向喜欢棋牌游戏、赛事房间和快速上手体验的玩家。整体使用深色霓虹、赛博光效、牌桌质感、玩家信任证明、玩法亮点、活动福利、规则说明和客服支持，图片需要分别匹配首页主视觉、游戏特色、玩家证明、攻略内容和联系支持等区块。';

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
