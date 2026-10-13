<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Context;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

/**
 * Scope hole-punch for anonymous FPC / generated/html pages.
 *
 * Resolves channel Scope from Host+path (same installer as DetectWebsite), issues
 * the Scope token + HttpOnly bootstrap cookie, and returns the opaque bootstrap_id
 * for SharedWorker / QueryBin. Never injects HTML meta stamps.
 */
final class FrontendWorkerScopeHolePunchService
{
    private const OPAQUE_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const MAX_PATH_BYTES = 2048;

    public function __construct(
        private readonly FrontendWorkerScopeProviderInterface $scopeProvider,
        private readonly FrontendWorkerSessionService $workerSessionService,
    ) {
    }

    /**
     * @param (callable(ScopeIdentity):(?array{ok?:false,error?:string,status?:int,retry_after?:int}))|null $beforeIssue
     *        Optional gate after Scope resolve / before token mint (e.g. IP rate limit).
     *        Return a deny array to abort without issuing; null to continue.
     * @return array{bootstrap_id:string,expires_at:int,mode:string}|array{ok:false,error:string,status:int,retry_after?:int}|null
     *         null when rollout off/shadow/token disabled
     */
    public function punch(Response $response, string $pagePath = '', ?callable $beforeIssue = null): ?array
    {
        $scheme = $this->requestScheme();
        $authorityHost = $this->authorityHost();
        if ($authorityHost === '') {
            throw new FrontendWorkerScopeException(
                'worker_scope_hole_punch_authority_invalid',
                400,
                \function_exists('__')
                    ? (string)\__('无效的请求主机。')
                    : 'Invalid request host.',
            );
        }

        $scope = RequestContext::scopeIdentity();
        if (!$scope instanceof ScopeIdentity || $scope->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            $scope = $this->resolveChannelScope($scheme, $authorityHost, $pagePath);
        }
        if (!$scope instanceof ScopeIdentity || $scope->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            if ($this->scopeProvider->requiresBinding($scheme !== '' ? $scheme : 'https')) {
                throw new FrontendWorkerScopeException(
                    'worker_scope_hole_punch_scope_missing',
                    503,
                    \function_exists('__')
                        ? (string)\__('系统正在升级维护，请稍后再试。')
                        : '系统正在升级维护，请稍后再试。',
                );
            }

            return null;
        }

        $decision = $this->scopeProvider->rollout($scope, $scheme !== '' ? $scheme : 'https');
        if ($decision->isOff() || $decision->isShadow() || !$decision->tokenEnabled) {
            return null;
        }

        if ($beforeIssue !== null) {
            $deny = $beforeIssue($scope);
            if (\is_array($deny) && ($deny['ok'] ?? true) === false) {
                return $deny;
            }
        }

        $token = $this->scopeProvider->issueToken($scope, $scheme, $authorityHost);
        if (!\is_string($token) || $token === '') {
            throw new FrontendWorkerScopeException(
                'worker_scope_hole_punch_token_unavailable',
                503,
                \function_exists('__')
                    ? (string)\__('系统正在升级维护，请稍后再试。')
                    : '系统正在升级维护，请稍后再试。',
            );
        }

        $binding = $this->scopeProvider->verifyToken($token, $scheme, $authorityHost);
        if ($binding === null) {
            throw new FrontendWorkerScopeException(
                'worker_scope_hole_punch_binding_unavailable',
                503,
                \function_exists('__')
                    ? (string)\__('系统正在升级维护，请稍后再试。')
                    : '系统正在升级维护，请稍后再试。',
            );
        }

        $bootstrap = $this->workerSessionService->createScopeBootstrap($binding);
        $bootstrapId = $bootstrap['bootstrap_id'] ?? null;
        $cookieName = $bootstrap['cookie_name'] ?? null;
        $expiresAt = $bootstrap['expires_at'] ?? null;
        if (!\is_string($bootstrapId)
            || \preg_match(self::OPAQUE_ID_PATTERN, $bootstrapId) !== 1
            || !\is_string($cookieName)
            || !\hash_equals(
                FrontendWorkerSessionService::SCOPE_BOOTSTRAP_COOKIE_PREFIX . $bootstrapId,
                $cookieName,
            )
            || !\is_int($expiresAt)
            || $expiresAt <= \time()
            || $expiresAt > $binding->tokenExpiresAt
        ) {
            throw new FrontendWorkerScopeException(
                'worker_scope_hole_punch_contract_invalid',
                503,
                \function_exists('__')
                    ? (string)\__('系统正在升级维护，请稍后再试。')
                    : '系统正在升级维护，请稍后再试。',
            );
        }

        $this->expireStaleScopeBootstrapCookies($response, $cookieName);
        $response->setCookie($cookieName, $token, $expiresAt, '/', '', true, true, 'Lax');
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');

        return [
            'bootstrap_id' => $bootstrapId,
            'expires_at' => $expiresAt,
            'mode' => (string)$decision->mode,
        ];
    }

    private function resolveChannelScope(string $scheme, string $host, string $pagePath): ?ScopeIdentity
    {
        $path = $this->normalizePagePath($pagePath);
        $fullUri = $scheme . '://' . $host . $path;
        try {
            /** @var RuntimeProviderResolver $resolver */
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(StorefrontScopeInstallerInterface::class);
            $installer = $resolution->provider;
            if (!$installer instanceof StorefrontScopeInstallerInterface) {
                return null;
            }
            $navigation = $installer->installNavigationScope($fullUri);
            $identity = $navigation->identity;
            if ($identity instanceof ScopeIdentity
                && $identity->scopeKind === ScopeIdentity::KIND_CHANNEL) {
                return $identity;
            }
        } catch (NoRouterException|ResponseTerminateException) {
            return null;
        } catch (\Weline\Framework\Runtime\RequestExitException $cancelled) {
            throw $cancelled;
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function normalizePagePath(string $raw): string
    {
        $raw = \trim($raw);
        if ($raw === '') {
            return '/';
        }
        if (\preg_match('#^https?://#i', $raw) === 1) {
            $parts = \parse_url($raw);
            $raw = \is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
        }
        if ($raw === '' || $raw[0] !== '/') {
            $raw = '/' . $raw;
        }
        if (\strlen($raw) > self::MAX_PATH_BYTES || \str_contains($raw, "\0")) {
            return '/';
        }
        $q = \strpos($raw, '?');
        if ($q !== false) {
            $raw = \substr($raw, 0, $q);
        }

        return $raw !== '' ? $raw : '/';
    }

    private function requestScheme(): string
    {
        $context = Context::getCurrent();
        if ($context instanceof Context) {
            $proto = \strtolower(\trim((string)$context->server('HTTP_X_FORWARDED_PROTO', '')));
            if (\str_contains($proto, ',')) {
                $proto = \trim(\explode(',', $proto, 2)[0]);
            }
            if (\in_array($proto, ['http', 'https'], true)) {
                return $proto;
            }
            $scheme = \strtolower(\trim((string)$context->server('REQUEST_SCHEME', '')));
            if (\in_array($scheme, ['http', 'https'], true)) {
                return $scheme;
            }
            $https = \strtolower(\trim((string)$context->server('HTTPS', '')));
            if ($https === 'on' || $https === '1') {
                return 'https';
            }
        }
        $scheme = \strtolower(\trim((string)($_SERVER['REQUEST_SCHEME'] ?? '')));

        return \in_array($scheme, ['http', 'https'], true) ? $scheme : 'https';
    }

    private function authorityHost(): string
    {
        $host = \strtolower(\trim(RequestAuthority::current()));
        if ($host !== '') {
            return $host;
        }

        return \strtolower(\trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    }

    private function expireStaleScopeBootstrapCookies(Response $response, string $keepCookieName): void
    {
        $cookieHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($cookieHeader === '') {
            return;
        }
        foreach (\explode(';', $cookieHeader) as $part) {
            $part = \trim($part);
            if ($part === '' || !\str_contains($part, '=')) {
                continue;
            }
            [$name] = \explode('=', $part, 2);
            $name = \trim($name);
            if ($name === ''
                || !\str_starts_with($name, FrontendWorkerSessionService::SCOPE_BOOTSTRAP_COOKIE_PREFIX)
                || \hash_equals($keepCookieName, $name)
            ) {
                continue;
            }
            $response->setCookie($name, '', 1, '/', '', true, true, 'Lax');
        }
    }
}
