<?php

declare(strict_types=1);

namespace Weline\Framework\View\Helper;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\ResponseObservabilityPolicy;
use Weline\Framework\Runtime\MemDiag;
use Weline\Framework\Runtime\RequestContext;

/**
 * Observability probe: document &lt;title&gt; / H1 vs request locale cross-talk.
 *
 * Hot path stays cheap — callers must gate regex analysis behind shouldEmit().
 * Arm at request entry (see WlsRuntime) because emission may run after input reset.
 * Armed flag/URI live in RequestContext (Fiber-safe); static is UT-only fallback.
 */
final class TitleLocaleProbe
{
    public const QUERY_KEY = '__title_locale';

    private const HEADER_LOCALE = 'X-Weline-Title-Locale';
    private const HEADER_MISMATCH = 'X-Weline-Title-Locale-Mismatch';
    private const TITLE_FP_LEN = 40;
    private const CTX_ARMED = 'view.title_locale_probe.armed';
    private const CTX_URI = 'view.title_locale_probe.uri';

    /** UT / non-context fallback only — never rely on these under WLS Fiber. */
    private static ?bool $fallbackArmedEmit = null;
    private static string $fallbackArmedUri = '';

    /** @var array<string, string> locale-family prefix => canonical about H1 marker */
    private const ABOUT_H1_BY_FAMILY = [
        'zh' => '关于我们',
        'en' => 'About Us',
        'fr' => 'À propos de nous',
        'de' => 'Über uns',
        'es' => 'Sobre nosotros',
    ];

    /**
     * Capture emit gate + URI while request Context / $_SERVER are still live.
     */
    public static function armFromRequest(?string $requestUri = null): void
    {
        $uri = \trim((string)$requestUri);
        if ($uri === '') {
            try {
                $uri = (string)(WelineEnv::server('REQUEST_URI', '') ?? '');
                if ($uri === '') {
                    $uri = (string)(WelineEnv::server('WELINE_ORIGIN_REQUEST_URI', '') ?? '');
                }
                if ($uri === '') {
                    $uri = (string)(WelineEnv::server('WELINE_FULL_REQUEST_URI', '') ?? '');
                }
            } catch (\Throwable) {
                $uri = '';
            }
        }
        if ($uri === '') {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        }
        try {
            $qs = (string)(WelineEnv::server('QUERY_STRING', '') ?? '');
            if ($qs === '') {
                $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
            }
            if ($qs !== '' && $uri !== '' && !\str_contains($uri, '?')) {
                $uri .= '?' . $qs;
            } elseif ($uri === '' && $qs !== '') {
                $uri = '/?' . $qs;
            }
        } catch (\Throwable) {
        }
        $armed = self::detectEmitFlag($uri);
        try {
            RequestContext::set(self::CTX_URI, $uri);
            RequestContext::set(self::CTX_ARMED, $armed);
        } catch (\Throwable) {
            self::$fallbackArmedUri = $uri;
            self::$fallbackArmedEmit = $armed;
        }
    }

    public static function reset(): void
    {
        try {
            RequestContext::set(self::CTX_URI, '');
            RequestContext::set(self::CTX_ARMED, false);
        } catch (\Throwable) {
        }
        self::$fallbackArmedEmit = null;
        self::$fallbackArmedUri = '';
    }

    public static function armedRequestUri(): string
    {
        try {
            $uri = (string)RequestContext::get(self::CTX_URI, '');
            if ($uri !== '') {
                return $uri;
            }
        } catch (\Throwable) {
        }

        return self::$fallbackArmedUri;
    }

    /**
     * @return array{
     *     title: string,
     *     h1: string,
     *     request_locale: string,
     *     verdict: string,
     *     mismatch: bool,
     *     title_fp: string,
     *     path: string
     * }
     */
    public static function analyze(string $html, string $requestLocale, string $requestPath = ''): array
    {
        $title = self::extractTitle($html);
        $h1 = self::extractH1($html);
        $locale = \trim($requestLocale);
        $path = (string)$requestPath;
        $verdict = self::resolveVerdict($title, $h1, $locale, $path);
        $mismatch = $verdict !== 'ok';

        return [
            'title' => $title,
            'h1' => $h1,
            'request_locale' => $locale,
            'verdict' => $verdict,
            'mismatch' => $mismatch,
            'title_fp' => self::titleFingerprint($title),
            'path' => $path,
        ];
    }

    public static function shouldEmit(): bool
    {
        try {
            if (RequestContext::get(self::CTX_ARMED, false) === true) {
                return true;
            }
            $ctxUri = (string)RequestContext::get(self::CTX_URI, '');
            if ($ctxUri !== '' && self::detectEmitFlag($ctxUri)) {
                return true;
            }
        } catch (\Throwable) {
        }
        if (self::$fallbackArmedEmit === true) {
            return true;
        }
        $uri = self::$fallbackArmedUri;
        if ($uri === '') {
            try {
                $uri = (string)(WelineEnv::server('REQUEST_URI', '') ?? '');
            } catch (\Throwable) {
                $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
            }
        }
        if (self::detectEmitFlag($uri)) {
            return true;
        }
        try {
            if (MemDiag::isArmed()) {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            if (ResponseObservabilityPolicy::dynamicObservabilityEnabled()) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    public static function applyToResponse(object $response, array $analysis): void
    {
        if (!self::shouldEmit()) {
            return;
        }
        if (!\method_exists($response, 'setHeader')) {
            return;
        }

        $locale = (string)($analysis['request_locale'] ?? '');
        $verdict = (string)($analysis['verdict'] ?? 'ok');
        $titleFp = (string)($analysis['title_fp'] ?? self::titleFingerprint((string)($analysis['title'] ?? '')));
        $mismatch = !empty($analysis['mismatch']);

        $response->setHeader(
            self::HEADER_LOCALE,
            $locale . '|' . $verdict . '|' . $titleFp
        );
        $response->setHeader(self::HEADER_MISMATCH, $mismatch ? '1' : '0');

        if ($mismatch) {
            try {
                MemDiag::event('title_locale_mismatch', $analysis);
            } catch (\Throwable) {
            }
        }
    }

    public static function titleFingerprint(string $title): string
    {
        $flat = \preg_replace('/\s+/u', ' ', \trim($title)) ?? \trim($title);
        if (\function_exists('mb_substr')) {
            return (string)\mb_substr($flat, 0, self::TITLE_FP_LEN);
        }

        return \substr($flat, 0, self::TITLE_FP_LEN);
    }

    public static function localeFromRequestUri(string $uri): string
    {
        $path = (string)(\parse_url($uri, PHP_URL_PATH) ?: $uri);
        if (\preg_match(
            '#^/(zh_Hans_CN|zh_Hant_[A-Za-z]+|en_US|[a-z]{2}_[A-Z]{2})(?:/|$)#',
            $path,
            $m
        ) === 1) {
            return (string)$m[1];
        }

        return '';
    }

    private static function detectEmitFlag(string $uri): bool
    {
        try {
            $get = WelineEnv::getGet(self::QUERY_KEY, null);
            if ($get !== null && (string)$get !== '' && (string)$get !== '0') {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            $cookie = WelineEnv::getCookie(self::QUERY_KEY, null);
            if ($cookie !== null && (string)$cookie !== '' && (string)$cookie !== '0') {
                return true;
            }
        } catch (\Throwable) {
        }
        try {
            $qs = (string)(WelineEnv::server('QUERY_STRING', '') ?? '');
            if ($qs !== '' && \str_contains($qs, self::QUERY_KEY . '=')) {
                return !\str_contains($qs, self::QUERY_KEY . '=0');
            }
        } catch (\Throwable) {
        }
        if ($uri !== '' && \str_contains($uri, self::QUERY_KEY . '=')) {
            return !\str_contains($uri, self::QUERY_KEY . '=0');
        }
        if (isset($_GET[self::QUERY_KEY]) && (string)$_GET[self::QUERY_KEY] !== '0') {
            return true;
        }
        if (isset($_COOKIE[self::QUERY_KEY]) && (string)$_COOKIE[self::QUERY_KEY] !== '0') {
            return true;
        }
        $serverUri = (string)($_SERVER['REQUEST_URI'] ?? '');
        if ($serverUri !== '' && \str_contains($serverUri, self::QUERY_KEY . '=')) {
            return !\str_contains($serverUri, self::QUERY_KEY . '=0');
        }
        $serverQs = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($serverQs !== '' && \str_contains($serverQs, self::QUERY_KEY . '=')) {
            return !\str_contains($serverQs, self::QUERY_KEY . '=0');
        }

        return false;
    }

    private static function resolveVerdict(string $title, string $h1, string $locale, string $path): string
    {
        $titleCore = self::titleCore($title);
        $isZh = self::isZhLocale($locale);

        if (!$isZh && $titleCore !== '' && self::containsCjk($titleCore)) {
            return 'zh_title_on_non_zh';
        }

        if ($isZh && $titleCore !== ''
            && \preg_match('/^(Guide|Contact|Terms|Activity|Currency|Guidelines|Page|Seite)\b/iu', $titleCore) === 1
        ) {
            return 'latin_title_on_zh';
        }

        if (\str_contains(\strtolower($path), '/about') && $h1 !== '') {
            $matchedFamily = self::aboutH1MatchedFamily($h1);
            if ($matchedFamily !== null) {
                $requestFamily = self::localeFamily($locale);
                if ($requestFamily !== '' && $matchedFamily !== $requestFamily) {
                    return 'about_h1_cross_locale';
                }
            }
        }

        return 'ok';
    }

    private static function titleCore(string $title): string
    {
        $parts = \explode('|', $title, 2);

        return \trim($parts[0]);
    }

    private static function isZhLocale(string $locale): bool
    {
        return self::localeFamily($locale) === 'zh';
    }

    private static function localeFamily(string $locale): string
    {
        $locale = \trim($locale);
        if ($locale === '') {
            return '';
        }
        $norm = \str_replace('-', '_', $locale);
        $parts = \explode('_', $norm, 2);

        return \strtolower($parts[0] ?? '');
    }

    private static function containsCjk(string $text): bool
    {
        return \preg_match('/[\x{4E00}-\x{9FFF}]/u', $text) === 1;
    }

    private static function aboutH1MatchedFamily(string $h1): ?string
    {
        $flat = \preg_replace('/\s+/u', ' ', \trim($h1)) ?? \trim($h1);
        foreach (self::ABOUT_H1_BY_FAMILY as $family => $marker) {
            if ($flat === $marker) {
                return $family;
            }
        }

        return null;
    }

    private static function extractTitle(string $html): string
    {
        if (\preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) !== 1) {
            return '';
        }
        $raw = \html_entity_decode(\strip_tags($m[1]), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return \preg_replace('/\s+/u', ' ', \trim($raw)) ?? \trim($raw);
    }

    private static function extractH1(string $html): string
    {
        if (\preg_match('/id=["\']about-layout-title["\'][^>]*>(.*?)<\/h1>/is', $html, $m) === 1) {
            return self::plainText($m[1]);
        }
        if (\preg_match('/id=["\'][^"\']*layout-title[^"\']*["\'][^>]*>(.*?)<\/h1>/is', $html, $m) === 1) {
            return self::plainText($m[1]);
        }
        if (\preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m) === 1) {
            return self::plainText($m[1]);
        }

        return '';
    }

    private static function plainText(string $htmlFragment): string
    {
        $raw = \html_entity_decode(\strip_tags($htmlFragment), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return \preg_replace('/\s+/u', ' ', \trim($raw)) ?? \trim($raw);
    }
}
