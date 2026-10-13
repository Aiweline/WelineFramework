<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Api;

use Weline\Frontend\Controller\FrontendRestController;
use Weline\Frontend\Service\ScopeHolePunchSecurityGate;
use Weline\Framework\App\Env;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\FrontendWorkerScopeHolePunchService;

/**
 * Storefront Scope hole-punch: Host+path → HttpOnly bootstrap cookie + opaque id.
 *
 * POST-only. Hard gate: HTTPS, same-origin, path shape, Scope+IP rate limit.
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

        $response->setHttpResponseCode(200);
        $response->setBody(\json_encode([
            'ok' => true,
            'bootstrap_id' => $result['bootstrap_id'],
            'expires_at' => $result['expires_at'],
            'mode' => $result['mode'],
            'deploy_version' => $this->currentDeployVersion(),
        ], \JSON_UNESCAPED_UNICODE) ?: '{"ok":false}');

        return $response;
    }

    /**
     * Current storefront deploy generation for client stale-page detection.
     */
    private function currentDeployVersion(): string
    {
        try {
            $fromEnv = \trim((string)Env::get('deploy_version', ''));
            if ($fromEnv !== '') {
                return $fromEnv;
            }
        } catch (\Throwable) {
            // fall through
        }
        try {
            /** @var DeployFpcInvalidation $invalidation */
            $invalidation = ObjectManager::getInstance(DeployFpcInvalidation::class);

            return \trim($invalidation->readDeployStamp());
        } catch (\Throwable) {
            return '';
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
