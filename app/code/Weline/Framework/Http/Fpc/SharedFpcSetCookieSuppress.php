<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Runtime\InternalHomepagePrime;
use Weline\Framework\Runtime\RequestContext;

/**
 * Shared anonymous FPC shell must not emit Set-Cookie.
 *
 * Covers homepage prime, process/Shared HIT/STALE serve, and anonymous SSR that
 * is eligible to build/publish a shareable shell. Session preference jars belong
 * to hole-punch / QueryBin / non-shareable SSR only. Login does not opt out.
 */
final class SharedFpcSetCookieSuppress
{
    private const REQUEST_KEY = 'wls.fpc.suppress_set_cookie';

    public static function arm(string $reason): void
    {
        $prev = RequestContext::get(self::REQUEST_KEY);
        $reasons = \is_array($prev) && isset($prev['reasons']) && \is_array($prev['reasons'])
            ? $prev['reasons']
            : [];
        $reason = \trim($reason);
        if ($reason !== '') {
            $reasons[] = $reason;
        }
        RequestContext::set(self::REQUEST_KEY, [
            'armed' => true,
            'reasons' => \array_values(\array_unique(\array_map(
                static fn (mixed $item): string => \trim((string)$item),
                $reasons,
            ))),
        ]);
        try {
            HeaderCollector::getInstance()->clearCookies();
        } catch (\Throwable) {
            // Fail-open: later setCookie no-ops still protect the outbound response.
        }
    }

    public static function isArmed(): bool
    {
        if (InternalHomepagePrime::isCurrentRequest()) {
            return true;
        }
        if ((bool)WelineEnv::get('response.from_cache', false)) {
            return true;
        }
        $state = RequestContext::get(self::REQUEST_KEY);

        return \is_array($state) && !empty($state['armed']);
    }

    /** @return list<string> */
    public static function reasons(): array
    {
        $state = RequestContext::get(self::REQUEST_KEY);
        if (!\is_array($state) || !isset($state['reasons']) || !\is_array($state['reasons'])) {
            return InternalHomepagePrime::isCurrentRequest() ? ['internal_homepage_prime'] : [];
        }

        return \array_values(\array_filter(\array_map('strval', $state['reasons']), static fn (string $r): bool => $r !== ''));
    }
}
