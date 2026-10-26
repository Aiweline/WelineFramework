<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Api;

use Weline\Frontend\Controller\FrontendRestController;
use Weline\Frontend\Helper\FrontendScopeReloadTip;
use Weline\Frontend\Helper\StorefrontGenerationIdentity;
use Weline\Frontend\Service\ScopeHolePunchSecurityGate;
use Weline\Framework\App\State;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\FrontendWorkerScopeHolePunchService;

/**
 * Storefront Scope hole-punch: Host+path → stable HttpOnly Scope Cookie (no opaque id).
 *
 * POST-only. Hard gate: HTTPS, same-origin, path shape, Scope+IP rate limit.
 * JSON returns stamp metadata only; Token stays in Cookie.
 */
class ScopeBootstrap extends FrontendRestController
{
    public function __construct(
        private readonly FrontendWorkerScopeHolePunchService $holePunch,
        private readonly ScopeHolePunchSecurityGate $securityGate,
    ) {
    }

    public function postIndex(): Response
    {
        $response = new Response();
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');

        $transport = $this->securityGate->assertTransport($this->request);
        if (($transport['ok'] ?? false) !== true) {
            return $this->deny($response, $transport);
        }

        // Hole-punch URL is /api/... (no locale segment); page path is in the body.
        // Arm request locale so tips match the storefront page language.
        $this->armRequestLocaleFromPagePath((string)($transport['path'] ?? ''));

        $clientIp = (string)$transport['client_ip'];
        try {
            $result = $this->holePunch->punch(
                $response,
                (string)$transport['path'],
                function (ScopeIdentity $scope) use ($clientIp): ?array {
                    $rate = $this->securityGate->assertRateLimit($scope, $clientIp);
                    if (($rate['ok'] ?? false) !== true) {
                        return $rate;
                    }

                    return null;
                },
            );
        } catch (FrontendWorkerScopeException $e) {
            \w_log_warning(
                '[ScopeBootstrap] hole-punch controlled failure: {reason}',
                ['reason' => $e->reason],
                'worker_scope',
            );
            $response->setHttpResponseCode(503);
            $response->setBody(\json_encode([
                'ok' => false,
                'error' => 'unavailable',
            ], \JSON_UNESCAPED_UNICODE) ?: '{"ok":false}');

            return $response;
        } catch (\Throwable $e) {
            \w_log_warning(
                '[ScopeBootstrap] hole-punch unavailable: {type}',
                ['type' => $e::class],
                'worker_scope',
            );
            $response->setHttpResponseCode(503);
            $response->setBody(\json_encode([
                'ok' => false,
                'error' => 'unavailable',
            ], \JSON_UNESCAPED_UNICODE) ?: '{"ok":false}');

            return $response;
        }

        if ($result === null) {
            $response->setHttpResponseCode(204);
            $response->setBody('');

            return $response;
        }

        if (($result['ok'] ?? true) === false) {
            return $this->deny($response, $result);
        }

        $generation = StorefrontGenerationIdentity::current();
        $response->setHttpResponseCode(200);
        $response->setBody(\json_encode([
            'ok' => true,
            'expires_at' => $result['expires_at'],
            'mode' => $result['mode'],
            // Storefront generation authority = Scope-published theme version (not system deploy).
            'theme_published_version_id' => $generation['theme_published_version_id'],
            'theme_published_version' => $generation['theme_published_version'],
            // Warmup-transition tolerance: pages baked with any of these (recent sealed)
            // generations keep working during the post-publish warmup window — the client
            // must NOT auto-reload when the page stamp is in this list (max 3, current first).
            'acceptable_theme_published_version_ids' => $this->acceptableStorefrontGenerationIds(),
            'page_generation_token' => \Weline\Framework\Http\Fpc\FpcHtmlDiskStore::pageGenerationToken(),
            // Warm client locale tip cache (hole-punch may detect mismatch without QueryBin tip).
            'tips' => FrontendScopeReloadTip::catalogForCurrentLocale(),
            'tips_locale' => FrontendScopeReloadTip::currentLocale(),
        ], \JSON_UNESCAPED_UNICODE) ?: '{"ok":false}');

        return $response;
    }

    /**
     * Warmup-transition acceptable generation ids (current + recent sealed, max 3).
     * Empty when the Theme resolver is absent or fails — client then keeps the
     * strict current-only comparison (fail-closed).
     *
     * @return list<string>
     */
    private function acceptableStorefrontGenerationIds(): array
    {
        if (!\class_exists(\Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class)) {
            return [];
        }
        try {
            /** @var \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver $resolver */
            $resolver = \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Theme\Service\ThemePublishedVersionRuntimeResolver::class
            );
            $ids = $resolver->collectAcceptableStorefrontGenerationIds();

            return \array_values(\array_map('strval', \array_keys($ids)));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Prefer locale from JSON body, else first path segment of the page path.
     */
    private function armRequestLocaleFromPagePath(string $pagePath): void
    {
        $locale = '';
        try {
            $rawBody = (string)$this->request->getBody();
            if ($rawBody !== '') {
                $decoded = \json_decode($rawBody, true);
                if (\is_array($decoded)) {
                    $locale = \trim((string)($decoded['locale'] ?? $decoded['lang'] ?? ''));
                }
            }
        } catch (\Throwable) {
            $locale = '';
        }
        if ($locale === '') {
            $trim = \trim($pagePath, '/');
            $first = $trim === '' ? '' : \explode('/', $trim, 2)[0];
            $locale = \trim((string)$first);
        }
        if ($locale === '') {
            return;
        }
        try {
            State::setRequestLanguageOverride($locale);
        } catch (\Throwable) {
            // Fail-open: tips fall back to area default language.
        }
    }

    /**
     * @param array{ok?:bool,error?:string,status?:int,retry_after?:int} $deny
     */
    private function deny(Response $response, array $deny): Response
    {
        $status = (int)($deny['status'] ?? 403);
        if ($status < 400) {
            $status = 403;
        }
        $error = (string)($deny['error'] ?? 'forbidden');
        $public = match ($error) {
            ScopeHolePunchSecurityGate::ERROR_METHOD => 'method_not_allowed',
            ScopeHolePunchSecurityGate::ERROR_HTTPS => 'https_required',
            ScopeHolePunchSecurityGate::ERROR_ORIGIN => 'forbidden',
            ScopeHolePunchSecurityGate::ERROR_PATH => 'bad_request',
            ScopeHolePunchSecurityGate::ERROR_RATE => 'rate_limited',
            ScopeHolePunchSecurityGate::ERROR_RATE_UNAVAILABLE => 'unavailable',
            default => 'forbidden',
        };
        if (isset($deny['retry_after']) && (int)$deny['retry_after'] > 0) {
            $response->setHeader('Retry-After', (string)(int)$deny['retry_after']);
        }
        $response->setHttpResponseCode($status);
        $response->setBody(\json_encode([
            'ok' => false,
            'error' => $public,
        ], \JSON_UNESCAPED_UNICODE) ?: '{"ok":false}');

        return $response;
    }
}
