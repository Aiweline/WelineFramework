<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Neutral sink for cookieless homepage Shared HIT receipts.
 *
 * FullPageCacheCoordinator must not hard-depend on the Server WlsRuntime class;
 * the persistent runtime registers a sink during bootstrap.
 */
final class HomepageCanonicalPublicationBroker
{
    /** @var (callable(array<string, mixed>): void)|null */
    private static $sink = null;

    /**
     * @param (callable(array<string, mixed>): void)|null $sink
     */
    public static function register(?callable $sink): void
    {
        self::$sink = $sink;
    }

    /**
     * @param array<string, mixed> $receipt
     */
    public static function note(array $receipt): void
    {
        if (self::$sink === null) {
            return;
        }
        (self::$sink)($receipt);
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$sink = null;
    }
}
