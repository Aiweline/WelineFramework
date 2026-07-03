<?php
declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Acl\Service\AclService;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class BackendLoginReturnUrlService
{
    private const SESSION_KEYS = ['backend_login_referer', 'referer'];
    private const UNSAFE_RETURN_ROUTE_SEGMENTS = [
        'batch',
        'delete',
        'download',
        'export',
        'import',
        'upload',
    ];

    public function __construct(
        private readonly AclService $aclService,
        private readonly MenuServiceInterface $menuService,
        private readonly Request $request,
        private readonly Url $url
    ) {
    }

    public function buildLoginUrlWithReturn(string $loginUrl, string $currentUrl, string $reason = ''): string
    {
        $params = [];
        if ($reason !== '') {
            $params['no_access_reason'] = $reason;
        }

        if ($this->shouldCaptureCurrentRequestReturnUrl($this->request, $currentUrl)) {
            $returnUrl = $this->normalizeCandidateUrl($currentUrl);
            if ($returnUrl !== null) {
                $params['return_url'] = $this->toRelativeReturnUrl($returnUrl);
            }
        }

        if ($params === []) {
            return $loginUrl;
        }

        return $loginUrl . (str_contains($loginUrl, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function shouldCaptureCurrentRequestReturnUrl(?Request $request = null, string $currentUrl = ''): bool
    {
        $request ??= $this->request;
        if (!$request->isDocumentNavigationRequest()) {
            return false;
        }

        $path = (string)(parse_url($currentUrl, PHP_URL_PATH) ?: '');
        if ($path === '') {
            $path = (string)(parse_url($request->getUrlBuilder()->getCurrentUrl(), PHP_URL_PATH) ?: '');
        }

        return !$this->isApiOrInterfacePath($path);
    }

    public function resolveForUser(BackendUser $user, string $explicitReturnUrl = ''): ?string
    {
        $candidate = $this->validateForUser($user, $explicitReturnUrl);
        if ($candidate !== null) {
            return $candidate;
        }

        $session = \Weline\Framework\Session\SessionFactory::backend()->getSession();
        foreach (self::SESSION_KEYS as $key) {
            $stored = (string)($session->get($key) ?? '');
            $session->delete($key);
            $candidate = $this->validateForUser($user, $stored);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    public function normalizeCandidateUrl(string $candidate): ?string
    {
        $candidate = Url::removeExtraDoubleSlashes(trim($candidate));
        if ($candidate === '' || str_contains($candidate, '\\') || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return null;
        }

        if ($this->url->isLink($candidate) && !Url::is_same_site($candidate)) {
            return null;
        }

        $routePath = $this->extractRoutePath($candidate);
        if (!$this->isCandidateRouteAllowed($routePath)) {
            return null;
        }

        return $this->ensureSameOrigin($candidate);
    }

    public function validateForUser(BackendUser $user, string $candidate): ?string
    {
        $normalized = $this->normalizeCandidateUrl($candidate);
        if ($normalized === null) {
            return null;
        }

        $routePath = $this->extractRoutePath($normalized);
        if ($routePath === '') {
            return null;
        }
        $roleId = $this->resolveRoleId($user);
        if ($roleId <= 0) {
            return null;
        }

        if (!$this->aclService->isRouteProtected($routePath)) {
            return $normalized;
        }

        return $this->aclService->isRouteAllowed($roleId, $routePath, 'GET') ? $normalized : null;
    }

    public function resolveDefaultRedirectTarget(BackendUser $user): string
    {
        $roleId = $this->resolveRoleId($user);
        if ($roleId > 0) {
            $defaultRoute = $this->menuService->getDefaultEntryRoute($roleId);
            if ($defaultRoute !== null && $defaultRoute !== '') {
                return trim($defaultRoute, '/');
            }
        }

        return 'admin';
    }

    private function isCandidateRouteAllowed(string $routePath): bool
    {
        if ($routePath === '') {
            return false;
        }

        $normalized = strtolower(trim($routePath, '/'));
        if ($normalized === 'admin/login'
            || $normalized === 'admin/login/post'
            || $normalized === 'admin/login/logout'
            || str_ends_with($normalized, '/admin/login')
            || str_ends_with($normalized, '/admin/login/post')
            || str_ends_with($normalized, '/admin/login/logout')
        ) {
            return false;
        }

        if ($this->hasUnsafeReturnRouteSegment($normalized)) {
            return false;
        }

        if ($this->looksLikeBackendControllerRoute($normalized)) {
            return true;
        }

        return $this->isKnownBackendReturnRoute($normalized);
    }

    private function hasUnsafeReturnRouteSegment(string $routePath): bool
    {
        $segments = array_values(array_filter(
            explode('/', trim($routePath, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach ($segments as $segment) {
            $segment = strtolower($segment);
            foreach (self::UNSAFE_RETURN_ROUTE_SEGMENTS as $unsafeSegment) {
                if (str_contains($segment, $unsafeSegment)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isKnownBackendReturnRoute(string $routePath): bool
    {
        if ($routePath === 'admin') {
            return true;
        }

        if ($this->aclService->isRouteProtected($routePath)) {
            return true;
        }

        try {
            return MenuUrlValidator::isMenuUrl($routePath);
        } catch (\Throwable) {
            return false;
        }
    }

    private function looksLikeBackendControllerRoute(string $routePath): bool
    {
        $segments = array_values(array_filter(
            explode('/', trim($routePath, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        return isset($segments[0], $segments[1], $segments[2])
            && strtolower($segments[1]) === 'backend';
    }

    private function extractRoutePath(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
        $segments = $path === ''
            ? []
            : array_values(array_filter(explode('/', trim($path, '/')), static fn(string $segment): bool => $segment !== ''));

        $backendPrefix = trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        if ($backendPrefix !== '' && isset($segments[0]) && strcasecmp($segments[0], $backendPrefix) === 0) {
            array_shift($segments);
        }

        for ($i = 0; $i < 2 && $segments !== []; $i++) {
            $segment = (string)$segments[0];
            if (!$this->isCurrencySegment($segment) && !$this->isLocaleSegment($segment)) {
                break;
            }
            array_shift($segments);
        }
        if ($backendPrefix !== '' && isset($segments[0]) && strcasecmp((string)$segments[0], $backendPrefix) === 0) {
            array_shift($segments);
        }

        return trim(implode('/', $segments), '/');
    }

    private function isApiOrInterfacePath(string $path): bool
    {
        $routePath = strtolower($this->extractRoutePath($path));
        if ($routePath === '') {
            return false;
        }

        $segments = array_values(array_filter(
            explode('/', trim($routePath, '/')),
            static fn(string $segment): bool => $segment !== ''
        ));

        foreach ($segments as $segment) {
            if (in_array($segment, ['api', 'rest', 'graphql'], true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRoleId(BackendUser $user): int
    {
        $role = $user->getRoleModel();
        if ($role && $role->getId()) {
            return (int)$role->getId();
        }

        return (int)$user->getId() === 1 ? 1 : 0;
    }

    private function toRelativeReturnUrl(string $returnUrl): string
    {
        $parsed = parse_url($returnUrl);
        if (!is_array($parsed) || (!isset($parsed['scheme']) && !isset($parsed['host']))) {
            return $returnUrl;
        }

        $path = (string)($parsed['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? '#' . $parsed['fragment'] : '';

        return $path . $query . $fragment;
    }

    private function ensureSameOrigin(string $candidate): string
    {
        $parsed = parse_url($candidate);
        $path = (string)($parsed['path'] ?? '/');
        $path = $this->normalizeBackendPathForSameOrigin($path);
        $backendPrefix = $this->resolveCurrentBackendPrefix();
        if ($backendPrefix !== '') {
            $prefixPath = '/' . trim($backendPrefix, '/');
            if ($path !== $prefixPath && !str_starts_with($path, $prefixPath . '/')) {
                $routePath = $this->extractRoutePath($path);
                if ($routePath !== '') {
                    $path = $prefixPath . '/' . ltrim($routePath, '/');
                }
            }
        }
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) && $parsed['fragment'] !== '' ? '#' . $parsed['fragment'] : '';
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost';
        return $scheme . '://' . $host . $path . $query . $fragment;
    }

    private function resolveCurrentBackendPrefix(): string
    {
        $currentPath = (string)(parse_url($this->request->getUrlBuilder()->getCurrentUrl(), PHP_URL_PATH) ?: '');
        $adminPos = stripos($currentPath, '/admin/');
        if ($adminPos === false) {
            $adminPos = stripos($currentPath, '/admin');
        }
        if ($adminPos === false) {
            return trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
        }

        $prefix = trim(substr($currentPath, 0, $adminPos), '/');
        return $prefix !== '' ? $prefix : trim((string)(Env::getAreaRoutePrefix('backend') ?? ''), '/');
    }

    private function normalizeBackendPathForSameOrigin(string $path): string
    {
        $path = '/' . trim($path, '/');
        $segments = explode('/', trim($path, '/'));
        $firstSegment = (string)($segments[0] ?? '');

        if (isset($segments[1], $segments[2], $segments[3])
            && $firstSegment !== ''
            && $this->isCurrencySegment($segments[1])
            && $this->isLocaleSegment($segments[2])
            && $segments[3] === $firstSegment
        ) {
            array_splice($segments, 3, 1);
            return '/' . implode('/', $segments);
        }

        return $path;
    }

    private function isCurrencySegment(string $segment): bool
    {
        return State::isAllowedCurrencyCode($segment)
            || (bool)preg_match('/^[A-Z]{3}$/', $segment);
    }

    private function isLocaleSegment(string $segment): bool
    {
        return (bool)preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
    }
}
