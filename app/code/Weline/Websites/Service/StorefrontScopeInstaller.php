<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\StorefrontNavigationScope;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Websites\Service\Exception\ScopeResolutionException;

/**
 * Installs the complete Storefront Scope after Website URL detection.
 */
final class StorefrontScopeInstaller implements StorefrontScopeInstallerInterface
{
    public function __construct(
        private readonly ScopeResolver $scopeResolver,
    ) {
    }

    public function installNavigationScope(string $fullUri): StorefrontNavigationScope
    {
        $params = [];
        $query = (string)(\parse_url($fullUri, \PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            $parsed = [];
            \parse_str($query, $parsed);
            foreach ([ScopeResolver::PARAM_STORE, ScopeResolver::PARAM_CHANNEL] as $parameter) {
                if (\array_key_exists($parameter, $parsed)) {
                    $params[$parameter] = $parsed[$parameter];
                }
            }
        }
        $routePath = (string)(\parse_url($fullUri, \PHP_URL_PATH) ?: '/');

        try {
            return $this->scopeResolver->resolve(
                RequestContext::getWelineWebsiteId(),
                RequestContext::getWelineWebsiteCode(),
                $fullUri,
                $params,
                $routePath,
            );
        } catch (ScopeResolutionException $exception) {
            \w_log_error('Scope 三段解析拒绝请求', [
                'reason' => $exception->reason,
                'http_status' => $exception->httpStatus,
            ], 'websites');
            ObjectManager::getInstance(Response::class)
                ->noRouter($exception->httpStatus, $exception->getMessage());
        }
    }
}
