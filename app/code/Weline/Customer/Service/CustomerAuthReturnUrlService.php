<?php

declare(strict_types=1);

namespace Weline\Customer\Service;

use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;

/**
 * Keeps one safe storefront destination across the whole customer auth journey.
 */
final class CustomerAuthReturnUrlService
{
    private const SESSION_KEY = 'login_referer';

    private const AUTH_ROUTE_PREFIXES = [
        'customer/account/login',
        'customer/account/register',
        'customer/account/forgot-password',
        'customer/account/challenge',
        'customer/account/logout',
    ];

    public function __construct(private readonly Request $request) {
    }

    public function capture(
        AuthenticatedSessionInterface $session,
        string $explicitTarget = '',
        string $referer = ''
    ): string {
        $target = $this->normalizeTarget($explicitTarget);
        if ($target === '') {
            $target = $this->normalizeTarget($referer);
        }

        if ($target !== '') {
            $session->set(self::SESSION_KEY, $target);
            return $target;
        }

        return $this->normalizeTarget((string)($session->get(self::SESSION_KEY) ?? ''));
    }

    public function resolve(AuthenticatedSessionInterface $session, string $explicitTarget = ''): string
    {
        $target = $this->normalizeTarget($explicitTarget);
        if ($target !== '') {
            return $target;
        }

        return $this->normalizeTarget((string)($session->get(self::SESSION_KEY) ?? ''));
    }

    public function consume(AuthenticatedSessionInterface $session, string $explicitTarget = ''): string
    {
        $target = $this->resolve($session, $explicitTarget);
        $session->delete(self::SESSION_KEY);

        return $target;
    }

    public function normalizeTarget(string $candidate): string
    {
        return $this->normalizeCandidate($candidate, true);
    }

    public function formatInternalNavigation(string $candidate, string $fallback = '/customer/account'): string
    {
        $target = $this->normalizeCandidate($candidate, false);

        return $target !== '' ? ($target[0] === '/' ? $target : '/' . $target) : $fallback;
    }

    private function normalizeCandidate(string $candidate, bool $blockAuthRoutes): string
    {
        $candidate = $this->decodeTarget($candidate);
        if ($candidate === ''
            || str_starts_with($candidate, '//')
            || str_contains($candidate, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1
        ) {
            return '';
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return '';
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            if (!$this->isSameOriginHttpUrl($parts)) {
                return '';
            }
        }

        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            return '';
        }

        $path = (string)preg_replace('#/+#', '/', '/' . ltrim($path, '/'));
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
        if ($path === '/') {
            return '/' . $query . $fragment;
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($segments === [] || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            return '';
        }

        if (Env::isAreaRoutePathSegment((string)$segments[0])) {
            return '';
        }

        $routePath = $this->stripLocalizationPrefixForRouteCheck($segments);
        $routeFirstSegment = explode('/', $routePath, 2)[0] ?? '';
        if ($routePath === ''
            || Env::isAreaRoutePathSegment($routeFirstSegment)
            || ($blockAuthRoutes && $this->isAuthRoute($routePath))
        ) {
            return '';
        }

        return ltrim($path, '/') . $query . $fragment;
    }

    public function formatRedirect(string $candidate): string
    {
        $target = $this->normalizeTarget($candidate);
        if ($target === '') {
            return '/customer/account';
        }

        if ($target === 'customer/account/index') {
            return '/customer/account';
        }

        return $target[0] === '/' ? $target : '/' . $target;
    }

    /**
     * @param array<string, scalar> $params
     */
    public function buildAuthPageUrl(string $route, string $target = '', array $params = []): string
    {
        $route = '/' . trim($route, '/');
        $target = $this->normalizeTarget($target);
        if ($target !== '') {
            $params = ['redirect_url' => $target] + $params;
        }

        $params = array_filter(
            $params,
            static fn(mixed $value): bool => $value !== '' && $value !== null
        );
        if ($params === []) {
            return $route;
        }

        return $route . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function isSameOriginHttpUrl(array $target): bool
    {
        if (isset($target['user']) || isset($target['pass'])) {
            return false;
        }

        $targetScheme = strtolower((string)($target['scheme'] ?? ''));
        $targetHost = strtolower((string)($target['host'] ?? ''));
        if (!in_array($targetScheme, ['http', 'https'], true) || $targetHost === '') {
            return false;
        }

        $currentUrl = (string)$this->request->getUrlBuilder()->getCurrentUrl();
        $base = parse_url($currentUrl);
        if (!is_array($base) || empty($base['host'])) {
            $base = parse_url((string)(Env::getInstance()->getBaseUrl() ?? ''));
        }
        if (!is_array($base)) {
            return false;
        }

        $baseScheme = strtolower((string)($base['scheme'] ?? ''));
        $baseHost = strtolower((string)($base['host'] ?? ''));
        if ($targetScheme !== $baseScheme || $targetHost !== $baseHost) {
            return false;
        }

        $targetPort = (int)($target['port'] ?? $this->defaultPort($targetScheme));
        $basePort = (int)($base['port'] ?? $this->defaultPort($baseScheme));

        return $targetPort === $basePort;
    }

    /**
     * @param list<string> $segments
     */
    private function stripLocalizationPrefixForRouteCheck(array $segments): string
    {
        $localization = State::resolveLocalizationFromPathSegments(array_slice($segments, 0, 2));
        $stripCount = ($localization['currency'] !== '' ? 1 : 0)
            + ($localization['language'] !== '' ? 1 : 0);

        return strtolower(implode('/', array_slice($segments, $stripCount)));
    }

    private function isAuthRoute(string $routePath): bool
    {
        $routePath = strtolower(trim($routePath, '/'));
        foreach (self::AUTH_ROUTE_PREFIXES as $blocked) {
            if ($routePath === $blocked || str_starts_with($routePath, $blocked . '/')) {
                return true;
            }
        }

        return false;
    }

    private function decodeTarget(string $candidate): string
    {
        $candidate = trim($candidate);
        for ($i = 0; $i < 2 && $candidate !== ''; $i++) {
            $decoded = rawurldecode($candidate);
            if ($decoded === $candidate) {
                break;
            }
            $candidate = trim($decoded);
        }

        return $candidate;
    }

    private function defaultPort(string $scheme): int
    {
        return match (strtolower($scheme)) {
            'http' => 80,
            'https' => 443,
            default => 0,
        };
    }
}
