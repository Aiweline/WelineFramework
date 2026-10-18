<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;

/**
 * Unified storefront-generation Cookie + Vary on framework responses.
 *
 * Cookie name is readable (not HttpOnly) so storefront JS can compare it to the
 * page bake stamp. Shared anonymous FPC still clears Set-Cookie via
 * {@see Fpc\SharedFpcSetCookieSuppress} — this helper only *attempts* setCookie.
 */
final class StorefrontGenerationOutbound
{
    public const COOKIE_NAME = 'weline_storefront_gen';

    /** ~1 year; publish overwrites with a fresh Set-Cookie. */
    private const COOKIE_TTL_SECONDS = 31536000;

    public static function applyToResponse(Response $response): void
    {
        try {
            if ((bool)WelineEnv::get('is_static_file', false)) {
                return;
            }

            if (self::isBackendArea()) {
                return;
            }

            // Shareable FPC HIT/STALE (and armed suppress) must never mint identity Cookie.
            // Defense for Worker emission after StateManager::reset cleared RequestContext.
            if (self::forbidsIdentityCookie($response)) {
                return;
            }

            $id = self::resolveVersionId();
            if ($id === '') {
                return;
            }

            // Readable for weline.js; Secure forced by HeaderCollector on HTTPS.
            $response->setCookie(
                self::COOKIE_NAME,
                $id,
                \time() + self::COOKIE_TTL_SECONDS,
                '/',
                '',
                false,
                false,
                'Lax'
            );
            self::ensureVaryCookie($response);
        } catch (\Throwable) {
            // Outbound decoration must never block emission.
        }
    }

    /**
     * True when Set-Cookie (incl. storefront generation) must not leave the process.
     */
    public static function forbidsIdentityCookie(Response $response): bool
    {
        if (\class_exists(Fpc\SharedFpcSetCookieSuppress::class)
            && Fpc\SharedFpcSetCookieSuppress::isArmed()
        ) {
            return true;
        }
        if ((bool)WelineEnv::get('response.from_cache', false)) {
            return true;
        }

        foreach (['X-Weline-FPC', 'X-Weline-Fpc'] as $name) {
            $raw = $response->getHeader($name);
            $value = \is_array($raw) ? \implode(',', \array_map('strval', $raw)) : (string)($raw ?? '');
            if ($value !== '' && \preg_match('/\b(HIT|STALE)\b/i', $value) === 1) {
                return true;
            }
        }
        $cacheStatus = $response->getHeader('X-Weline-Cache-Status');
        $cacheStatusValue = \is_array($cacheStatus)
            ? \implode(',', \array_map('strval', $cacheStatus))
            : (string)($cacheStatus ?? '');
        if ($cacheStatusValue !== '' && \preg_match('/\bhit\b/i', $cacheStatusValue) === 1) {
            return true;
        }

        return false;
    }

    public static function resolveVersionId(): string
    {
        try {
            if (!\class_exists(\Weline\Frontend\Helper\StorefrontGenerationIdentity::class)) {
                return '';
            }

            return \trim((string)\Weline\Frontend\Helper\StorefrontGenerationIdentity::currentVersionId());
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isBackendArea(): bool
    {
        try {
            $request = ObjectManager::getInstance(Request::class);
            if ($request instanceof Request) {
                return $request->isBackend() || $request->isApiBackend();
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return false;
    }

    private static function ensureVaryCookie(Response $response): void
    {
        $vary = $response->getHeader('Vary');
        $varyValue = \is_array($vary) ? \implode(', ', \array_map('strval', $vary)) : (string)($vary ?? '');
        if ($varyValue === '') {
            $response->setHeader('Vary', 'Cookie');
            return;
        }

        foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
            if (\strcasecmp($part, 'Cookie') === 0) {
                return;
            }
        }

        $response->setHeader('Vary', $varyValue . ', Cookie');
    }
}
