<?php

declare(strict_types=1);

namespace Weline\Frontend\Helper;


use Weline\Framework\Env\WelineEnv;
use Weline\Framework\App\State;

/**
 * Localized toast tips for Scope / theme-generation expiry that force reload.
 *
 * Server builds tip with __() for the active request locale; client caches by
 * locale so hole-punch / offline presentation need not seed the page i18n bag.
 */
final class FrontendScopeReloadTip
{
    /** @var list<string> */
    public const TIP_CODES = [
        'scope_reload_required',
        'scope_binding_required',
        'scope_context_conflict',
        'request_scope_already_conflicts',
        'scope_token_expired',
        'scope_token_context_conflict',
        'deploy_reload_required',
        'scope_bootstrap_waiting',
    ];

    /**
     * Chinese sources for title + message (module CSV / Phrase bags).
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function catalogSources(): array
    {
        return [
            'scope_reload_required' => [
                '商城范围已变更',
                '当前页面商城范围已过期，正在重新签章以继续。',
            ],
            'scope_binding_required' => [
                '商城范围未就绪',
                '页面绑定信息缺失或已失效，正在重新签章以继续。',
            ],
            'scope_context_conflict' => [
                '商城范围已变更',
                '检测到渠道或店铺切换，正在刷新页面。',
            ],
            'request_scope_already_conflicts' => [
                '商城范围已变更',
                '当前请求与页面商城范围不一致，正在刷新页面。',
            ],
            'scope_token_expired' => [
                '商城范围已变更',
                '当前页面商城范围凭证已过期，正在重新获取。',
            ],
            'scope_token_context_conflict' => [
                '商城范围已变更',
                '当前页面商城范围与请求上下文不一致，正在重新获取。',
            ],
            'deploy_reload_required' => [
                '站点已更新',
                '主题已发布新版本。请重新加载页面以继续。',
            ],
            // Soft wait while first Scope stamp ensures — title empty-safe: same phrase.
            'scope_bootstrap_waiting' => [
                '请稍等片刻...',
                '请稍等片刻...',
            ],
        ];
    }

    public static function isTipCode(string $code): bool
    {
        return \in_array($code, self::TIP_CODES, true);
    }

    public static function currentLocale(): string
    {
        try {
            $locale = \trim((string)(WelineEnv::getLang() ?: ''));
        } catch (\Throwable) {
            $locale = '';
        }

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    /**
     * @return array{title:string,message:string,locale:string}|null
     */
    public static function build(string $code): ?array
    {
        $sources = self::catalogSources()[$code] ?? null;
        if ($sources === null) {
            return null;
        }

        return [
            'title' => self::translate($sources[0]),
            'message' => self::translate($sources[1]),
            'locale' => self::currentLocale(),
        ];
    }

    /**
     * Warm-cache catalog for the active locale (ScopeBootstrap success).
     *
     * @return array<string, array{title:string,message:string,locale:string}>
     */
    public static function catalogForCurrentLocale(): array
    {
        $out = [];
        foreach (self::TIP_CODES as $code) {
            $tip = self::build($code);
            if ($tip !== null) {
                $out[$code] = $tip;
            }
        }

        return $out;
    }

    private static function translate(string $source): string
    {
        $source = \trim($source);
        if ($source === '') {
            return '';
        }
        try {
            $translated = \trim((string)__($source));
        } catch (\Throwable) {
            $translated = '';
        }

        return $translated !== '' ? $translated : $source;
    }
}
