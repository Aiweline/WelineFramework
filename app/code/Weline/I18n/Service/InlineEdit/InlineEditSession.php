<?php

declare(strict_types=1);

namespace Weline\I18n\Service\InlineEdit;

use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Fpc\FpcPierceStampAdminService;

/**
 * Storefront inline dictionary-edit session (operator only).
 * Presence = cookie + pierce; never env translation.mode.
 */
final class InlineEditSession
{
    public const COOKIE_NAME = 'w_i18n_inline_edit';
    public const QUERY_FLAG = 'i18n_inline';
    public const COOKIE_TTL_SECONDS = 28800;

    public function isActive(?Request $request = null): bool
    {
        // Storefront-only: pierce cookies must not mark backend/admin phrases
        // (no shell JS there → raw [[W18N:…]] leaks into dictionary UI).
        if ($this->isBackendArea($request)) {
            return false;
        }
        if (!FpcPierceStamp::isValidCurrentRequest()) {
            return false;
        }
        $cookieHeader = '';
        try {
            $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        } catch (\Throwable) {
            $cookieHeader = '';
        }
        // CookieScope may wire as w_i18n_inline_edit_w0 (website suffix).
        if ($cookieHeader !== '' && \preg_match(
            '/(?:^|;\s*)' . \preg_quote(self::COOKIE_NAME, '/') . '(?:_w\d+)?=([^;]+)/',
            $cookieHeader,
            $m
        )) {
            $v = \rawurldecode((string)$m[1]);
            if ($v === '1' || \strtolower($v) === 'true') {
                return true;
            }
        }
        if ($request !== null) {
            $flag = \trim((string)$request->getGet(self::QUERY_FLAG, ''));
            if ($flag === '1' || \strtolower($flag) === 'true') {
                return true;
            }
        }
        $q = [];
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            \parse_str($qs, $q);
        }
        $flag = \trim((string)($q[self::QUERY_FLAG] ?? ''));

        return $flag === '1' || \strtolower($flag) === 'true';
    }

    /**
     * Backend / rest_backend never run the storefront inline shell
     * (no shell JS → raw [[W18N:…]] / books must never appear in admin UI).
     */
    private function isBackendArea(?Request $request): bool
    {
        try {
            $req = $request ?? ObjectManager::getInstance(Request::class);
            if ($req->isBackend() || $req->isApiBackend()) {
                return true;
            }
        } catch (\Throwable) {
            // Fall through to server area flags.
        }
        $area = '';
        try {
            $area = (string)($_SERVER['WELINE_AREA'] ?? '');
        } catch (\Throwable) {
            $area = '';
        }
        if ($area === 'backend' || $area === 'rest_backend') {
            return true;
        }
        // Path fail-closed: custom admin frontName /api backend prefixes.
        $uri = '';
        try {
            $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
        } catch (\Throwable) {
            $uri = '';
        }
        if ($uri === '') {
            return false;
        }
        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: $uri);
        // Typical admin / backend entry segments (legacy names).
        if (\preg_match('#/(admin|backend)(/|$)#i', $path) === 1) {
            return true;
        }
        // Project frontName is a hashed area_routes prefix (not "admin").
        try {
            $routes = \Weline\Framework\App\Env::getInstance()->getConfig('router.area_routes', []);
            if (!\is_array($routes)) {
                $routes = [];
            }
            foreach (['backend', 'rest_backend'] as $areaKey) {
                $prefix = \trim((string)($routes[$areaKey]['prefix'] ?? ''), '/');
                if ($prefix === '') {
                    continue;
                }
                if (\preg_match('#^/' . \preg_quote($prefix, '#') . '(/|$)#i', $path) === 1) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Fail open only on path when Env unavailable; area flags above still apply.
        }

        return false;
    }

    /**
     * Keep pierce + session cookies on every active storefront render so in-site
     * links without query still edge-bypass (admin jump may have only set cookies
     * on the backend API response, or the operator opened jump_url alone).
     */
    public function reinforceBrowserCookies(): void
    {
        if (!FpcPierceStamp::isValidCurrentRequest()) {
            return;
        }
        try {
            $secret = FpcPierceStamp::ensureSecret();
            /** @var Response $response */
            $response = ObjectManager::getInstance(Response::class);
            $expires = \time() + self::COOKIE_TTL_SECONDS;
            $secure = true;
            try {
                $https = (string)($_SERVER['HTTPS'] ?? '');
                $secure = $https !== '' && \strtolower($https) !== 'off';
            } catch (\Throwable) {
                $secure = true;
            }
            $response->setCookie(
                FpcPierceStamp::COOKIE_NAME,
                $secret,
                $expires,
                '/',
                '',
                $secure,
                true,
                'Lax',
            );
            $response->setCookie(
                self::COOKIE_NAME,
                '1',
                $expires,
                '/',
                '',
                $secure,
                true,
                'Lax',
            );
        } catch (\Throwable) {
            // Shell still works for this response via query; cookie reinforce is best-effort.
        }
    }

    /**
     * @return array{jump_url:string, pierce_query:string, cookie_name:string}
     */
    public function startAndBuildJumpUrl(string $storefrontTargetUrl): array
    {
        /** @var FpcPierceStampAdminService $pierceAdmin */
        $pierceAdmin = ObjectManager::getInstance(FpcPierceStampAdminService::class);
        $pierce = $pierceAdmin->issueBrowserCookie(self::COOKIE_TTL_SECONDS);
        $secret = FpcPierceStamp::ensureSecret();
        $secure = (bool)($pierce['options']['secure'] ?? true);
        $sameSite = (string)($pierce['options']['samesite'] ?? 'Lax');
        $path = (string)($pierce['options']['path'] ?? '/');
        $expires = (int)($pierce['options']['expires'] ?? \time() + self::COOKIE_TTL_SECONDS);

        /** @var Response $response */
        $response = ObjectManager::getInstance(Response::class);
        $response->setCookie(
            $pierce['name'],
            $pierce['value'],
            $expires,
            $path,
            '',
            $secure,
            true,
            $sameSite,
        );
        $response->setCookie(
            self::COOKIE_NAME,
            '1',
            \time() + self::COOKIE_TTL_SECONDS,
            '/',
            '',
            $secure,
            true,
            $sameSite,
        );

        $target = \trim($storefrontTargetUrl);
        if ($target === '') {
            $target = '/';
        }
        $join = \str_contains($target, '?') ? '&' : '?';
        $pierceQ = FpcPierceStamp::buildQuerySuffix($secret);
        $jump = $target . $join . self::QUERY_FLAG . '=1&' . $pierceQ;

        return [
            'jump_url' => $jump,
            'pierce_query' => $pierceQ,
            'cookie_name' => self::COOKIE_NAME,
        ];
    }

    public function stop(): void
    {
        /** @var Response $response */
        $response = ObjectManager::getInstance(Response::class);
        $past = \time() - 3600;
        $response->setCookie(self::COOKIE_NAME, '', $past, '/', '', false, true, 'Lax');
        $response->setCookie(FpcPierceStamp::COOKIE_NAME, '', $past, '/', '', false, true, 'Lax');
    }
}
