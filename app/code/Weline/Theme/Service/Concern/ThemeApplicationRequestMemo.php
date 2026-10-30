<?php
declare(strict_types=1);

namespace Weline\Theme\Service\Concern;

use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Theme\ThemeApplicationInterface;
use Weline\Websites\Api\Theme\ThemeApplicationRepositoryInterface;

/**
 * Request-scoped memo for `websites_theme_application` reads made by Theme.
 *
 * The Website repository memoises per request, but Theme resolves the applied
 * theme, the scope binding and the published version through several service
 * instances per page render; each of them may miss that memo (request id
 * unavailable, Context copy inside a request fork, or a different repository
 * instance), which used to produce ~10 `WHERE store_mode=? AND area=?` reads per
 * cold storefront render.
 *
 * Scope: {@see RequestContext::shared()} — one entry per logical request, dropped
 * by `RequestContext::cleanup()`; never survives a request, so a theme-binding
 * change can never be served from a stale worker memo.
 * Invalidation: every write path calls {@see bump()} (the memo key embeds the
 * generation), so a bind/switch inside the same request invalidates memos
 * captured before it.
 *
 * NOTE: the authoritative repository lives in Weline_Websites (outside this
 * change's scope). This is the consumer-side dedupe; the durable fix belongs in
 * `OrmThemeApplicationRepository` so every module benefits.
 */
final class ThemeApplicationRequestMemo
{
    private const GENERATION_KEY = 'theme.application.read.generation';
    private const LIST_PREFIX = 'theme.application.list.v1';
    private const OWN_PREFIX = 'theme.application.own.v1';

    private static function generation(): string
    {
        return (string)RequestContext::sharedGeneration(self::GENERATION_KEY);
    }

    /** Invalidate every memo captured before the current application write. */
    public static function bump(): void
    {
        RequestContext::sharedBumpGeneration(self::GENERATION_KEY);
    }

    /** @return list<array<string, mixed>> */
    public static function listForArea(
        ThemeApplicationRepositoryInterface $applications,
        string $storeMode,
        string $area,
    ): array {
        $key = self::LIST_PREFIX . '.' . $storeMode . '.' . $area . '.g' . self::generation();

        return (array)RequestContext::shared(
            $key,
            static fn(): array => $applications->listForArea($storeMode, $area),
        );
    }

    /** @return array<string, mixed> */
    public static function own(
        ThemeApplicationInterface $applications,
        string $scopeKey,
        string $storeMode,
        string $area,
    ): array {
        $key = self::OWN_PREFIX . '.' . \md5($scopeKey) . '.' . $storeMode . '.' . $area . '.g' . self::generation();

        return (array)RequestContext::shared(
            $key,
            static fn(): array => $applications->getOwn($scopeKey, $storeMode, $area),
        );
    }
}
