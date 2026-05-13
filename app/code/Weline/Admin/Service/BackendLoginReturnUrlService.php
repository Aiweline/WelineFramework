<?php
declare(strict_types=1);

namespace Weline\Admin\Service;

use Weline\Acl\Service\AclService;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class BackendLoginReturnUrlService
{
    private const SESSION_KEYS = ['backend_login_referer', 'referer'];

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

        $returnUrl = $this->normalizeCandidateUrl($currentUrl);
        if ($returnUrl !== null) {
            $params['return_url'] = $returnUrl;
        }

        if ($params === []) {
            return $loginUrl;
        }

        return $loginUrl . (str_contains($loginUrl, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
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
        if (str_contains(strtolower($routePath), 'pagebuilder/backend/ai-site-agent/workspace-preview')) {
            $query = (string)(parse_url($normalized, PHP_URL_QUERY) ?: '');
            parse_str($query, $params);
            if ((string)($params['public_id'] ?? '') === '') {
                return null;
            }
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

        if (str_contains($normalized, 'add')
            || str_contains($normalized, 'edit')
            || str_contains($normalized, 'download')
            || str_contains($normalized, 'upload')
            || str_contains($normalized, 'export')
            || str_contains($normalized, 'import')
            || str_contains($normalized, 'delete')
            || str_contains($normalized, 'batch')
        ) {
            return false;
        }

        return true;
    }

    private function extractRoutePath(string $url): string
    {
        $parsed = Url::parser($url);
        return trim((string)($parsed['uri'] ?? ''), '/');
    }

    private function resolveRoleId(BackendUser $user): int
    {
        $role = $user->getRoleModel();
        if ($role && $role->getId()) {
            return (int)$role->getId();
        }

        return (int)$user->getId() === 1 ? 1 : 0;
    }

    private function ensureSameOrigin(string $candidate): string
    {
        $parsed = parse_url($candidate);
        $path = (string)($parsed['path'] ?? '/');
        $backendPrefix = $this->resolveCurrentBackendPrefix();
        if ($backendPrefix !== '' && str_starts_with($path, '/pagebuilder/backend/')) {
            $path = '/' . $backendPrefix . $path;
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
}
