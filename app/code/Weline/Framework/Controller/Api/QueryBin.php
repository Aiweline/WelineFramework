<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\App\State;
use Weline\Framework\Binary\EmergencyPacket;
use Weline\Framework\Binary\Limits;
use Weline\Framework\Binary\WelineBinaryCodec;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;
use Weline\Framework\Service\Query\Value\FrontendWorkerBackendBinding;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

class QueryBin extends FrontendRestController
{
    private const PROTOCOL = 'worker-query-bin-v1';
    private const WORKER_PROTOCOL = 'weline-worker-request-v1';
    private const SIGNED_PATH = '/api/framework/query-bin';
    private const TIMESTAMP_WINDOW = 120;
    private const RUNTIME_IDENTIFIER_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:+-]{0,127}$/D';

    public function __construct(
        private readonly WelineBinaryCodec $codec,
        private readonly FrontendQueryGateway $gateway,
        private readonly FrontendWorkerSessionService $sessionService,
        private readonly ?RuntimeProviderResolver $runtimeProviderResolver = null,
    ) {
    }

    public function postIndex(): Response
    {
        $requestId = \bin2hex(\random_bytes(8));
        $guard = $this->beginBinaryOutputGuard();
        $payload = null;
        $requestSummary = [
            'type' => '',
            'provider' => '',
            'operation' => '',
        ];
        $statusCode = 200;
        $startedAt = \microtime(true);
        $phaseProfile = [];
        $phaseLast = $startedAt;
        $markPhase = static function (string $name, array $meta = []) use (&$phaseProfile, &$phaseLast): void {
            $now = \microtime(true);
            $step = [
                'name' => $name,
                'duration_ms' => \round(($now - $phaseLast) * 1000, 2),
            ];
            if ($meta !== []) {
                $step['meta'] = $meta;
            }
            $phaseProfile[] = $step;
            $phaseLast = $now;
        };

        try {
            $this->assertProtocolHeaders();
            $markPhase('assert_protocol_headers');
            $this->assertSameOrigin();
            $markPhase('assert_same_origin');
            $this->assertContentType();
            $markPhase('assert_content_type');

            $contentLength = (int)$this->request->getServer('CONTENT_LENGTH');
            if ($contentLength > Limits::PACKET_BYTES) {
                throw new \InvalidArgumentException(Limits::PACKET_ERROR);
            }
            $rawBody = $this->request->getParameterBag()->getRawBody();
            if ($rawBody === '') {
                throw new FrontendQueryException('protocol_error', 'Empty binary request body.', 400);
            }
            if (\strlen($rawBody) > Limits::PACKET_BYTES) {
                throw new \InvalidArgumentException(Limits::PACKET_ERROR);
            }
            $markPhase('read_raw_body', ['bytes' => \strlen($rawBody)]);

            $payload = $this->codec->decodePacket($rawBody);
            if (!\is_array($payload)) {
                throw new FrontendQueryException('protocol_error', 'Worker request payload must be a map.', 400);
            }
            $markPhase('decode_packet');
            $requestSummary = $this->summarizeRequestPayload($payload);
            RequestContext::set('query_bin.summary', $requestSummary);
            $markPhase('summarize_payload', $requestSummary);

            if (($payload['type'] ?? '') === 'handshake') {
                $payload = $this->handleHandshake($payload, $requestId);
                $markPhase('handle_handshake');
                $statusCode = 200;
            } else {
                $headers = $this->readSignedHeaders();
                $markPhase('read_signed_headers');
                $committedSession = $this->validateSignedRequest($headers, $rawBody);
                $markPhase('validate_signed_request');
                $this->applyLocalizationContext($payload);
                $markPhase('apply_localization_context');

                $executionContext = $this->executionContextFromSession(
                    $committedSession,
                    $headers['session'],
                );
                $result = $this->gateway->execute(
                    $payload,
                    $headers['capability'],
                    $executionContext->scopeBinding,
                    $executionContext,
                );
                $markPhase('gateway_execute');
                $payload = [
                    'ok' => true,
                    'data' => $result,
                    'error' => null,
                    'request_id' => $requestId,
                    'scope_meta' => RequestContext::scopeMetadata(),
                ];
                $markPhase('build_success_payload');
                $statusCode = 200;
            }
        } catch (FrontendQueryException $exception) {
            $markPhase('frontend_query_exception', [
                'code' => $exception->getErrorCode(),
                'status' => $exception->getHttpStatus(),
            ]);
            $payload = [
                'ok' => false,
                'data' => null,
                'error' => [
                    'code' => $exception->getErrorCode(),
                    'message' => $exception->getMessage(),
                ],
                'request_id' => $requestId,
            ];
            $statusCode = $exception->getHttpStatus();
        } catch (\InvalidArgumentException $exception) {
            $markPhase('invalid_argument_exception');
            $payload = [
                'ok' => false,
                'data' => null,
                'error' => [
                    'code' => 'protocol_error',
                    'message' => $exception->getMessage(),
                ],
                'request_id' => $requestId,
            ];
            $statusCode = 400;
        } catch (\Throwable $throwable) {
            $markPhase('throwable_exception', [
                'class' => \get_class($throwable),
            ]);
            $this->logUnexpectedFailure($throwable, $requestId, $requestSummary);
            $payload = [
                'ok' => false,
                'data' => null,
                'error' => [
                    'code' => EmergencyPacket::ERROR_CODE,
                    'message' => EmergencyPacket::ERROR_MESSAGE,
                ],
                'request_id' => $requestId,
            ];
            $statusCode = 500;
        }

        $this->endBinaryOutputGuard($guard);
        $markPhase('end_output_guard');

        $elapsedMs = \round((\microtime(true) - $startedAt) * 1000, 2);
        $gatewayProfile = RequestContext::get('query_bin.gateway_profile');
        $serviceProfile = RequestContext::get('query_bin.service_profile');
        $providerProfile = RequestContext::get('query_bin.provider_profile');
        RequestContext::set('query_bin.timing', $requestSummary + [
            'request_id' => $requestId,
            'status' => $statusCode,
            'duration_ms' => $elapsedMs,
            'phases' => $phaseProfile,
            'gateway_profile' => \is_array($gatewayProfile) ? $gatewayProfile : [],
            'service_profile' => \is_array($serviceProfile) ? $serviceProfile : [],
            'provider_profile' => \is_array($providerProfile) ? $providerProfile : [],
        ]);
        $this->logSlowQueryBin($requestId, $requestSummary, $statusCode, $elapsedMs);

        $responsePayload = \is_array($payload) ? $payload : [
            'ok' => false,
            'data' => null,
            'error' => [
                'code' => 'protocol_error',
                'message' => 'Empty query-bin payload.',
            ],
            'request_id' => $requestId,
        ];

        try {
            return $this->binaryResponse($responsePayload, $statusCode, $requestSummary, $elapsedMs);
        } catch (\Throwable $throwable) {
            $this->logUnexpectedFailure($throwable, $requestId, $requestSummary, 'response_encode');
            $emptySummary = ['type' => '', 'provider' => '', 'operation' => ''];
            try {
                return $this->binaryResponse([
                    'ok' => false,
                    'data' => null,
                    'error' => [
                        'code' => EmergencyPacket::ERROR_CODE,
                        'message' => EmergencyPacket::ERROR_MESSAGE,
                    ],
                    'request_id' => $requestId,
                ], 500, $emptySummary, $elapsedMs);
            } catch (\Throwable $fallbackThrowable) {
                $this->logUnexpectedFailure($fallbackThrowable, $requestId, $requestSummary, 'fallback_encode');

                return $this->emergencyBinaryResponse($requestId, $elapsedMs);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleHandshake(array $payload, string $requestId): array
    {
        $deployVersion = $payload['deploy_version'] ?? 'dev';
        $workerBuildId = $payload['worker_build_id'] ?? 'dev';
        if (!\is_string($deployVersion)
            || !\is_string($workerBuildId)
            || \preg_match(self::RUNTIME_IDENTIFIER_PATTERN, $deployVersion) !== 1
            || \preg_match(self::RUNTIME_IDENTIFIER_PATTERN, $workerBuildId) !== 1) {
            throw new FrontendQueryException('protocol_error', 'Handshake is missing deploy version or worker build id.', 400);
        }

        $scopeBootstrapId = $payload['scope_bootstrap_id'] ?? '';
        if (!\is_string($scopeBootstrapId)) {
            throw new FrontendQueryException(
                'scope_bootstrap_invalid',
                'Worker Scope bootstrap ID must be a string.',
                400,
            );
        }
        $scopeBootstrapId = \trim($scopeBootstrapId);
        $backendBootstrapId = $payload['backend_bootstrap_id'] ?? '';
        if (!\is_string($backendBootstrapId)) {
            throw new FrontendQueryException(
                'backend_attestation_invalid',
                'Worker backend bootstrap ID must be a string.',
                400,
            );
        }
        $backendBootstrapId = \trim($backendBootstrapId);
        if ($scopeBootstrapId !== '' && $backendBootstrapId !== '') {
            throw new FrontendQueryException(
                'protocol_error',
                'Worker handshake cannot combine storefront and backend bootstraps.',
                400,
            );
        }

        if ($scopeBootstrapId === '' && $backendBootstrapId === '') {
            $provider = $this->resolveScopeProvider(false);
            if ($provider instanceof FrontendWorkerScopeProviderInterface) {
                try {
                    $requiresBinding = $provider->requiresBinding($this->requestScheme());
                } catch (FrontendWorkerScopeException $exception) {
                    throw $this->scopeFailure($exception);
                } catch (\Throwable $exception) {
                    throw new FrontendQueryException(
                        'scope_service_unavailable',
                        'Worker Scope binding policy is unavailable.',
                        503,
                        $exception,
                    );
                }
                if ($requiresBinding) {
                    throw new FrontendQueryException(
                        'scope_binding_required',
                        'Worker Scope bootstrap is required for this request.',
                        401,
                    );
                }
            }
            $session = $this->sessionService->createSession($deployVersion, $workerBuildId);
        } elseif ($scopeBootstrapId !== '') {
            $cookieName = FrontendWorkerSessionService::scopeBootstrapCookieName($scopeBootstrapId);
            $scopeToken = $this->readSingleCookie(
                $cookieName,
                'scope_bootstrap_invalid',
                'Worker Scope bootstrap Cookie',
            );
            $provider = $this->resolveScopeProvider(true);
            if (!$provider instanceof FrontendWorkerScopeProviderInterface) {
                throw new FrontendQueryException(
                    'scope_service_unavailable',
                    'Worker Scope provider is unavailable.',
                    503,
                );
            }

            try {
                $binding = $provider->verifyToken(
                    $scopeToken,
                    $this->requestScheme(),
                    $this->authorityHost(),
                );
            } catch (FrontendWorkerScopeException $exception) {
                throw $this->scopeFailure($exception);
            } catch (\Throwable $exception) {
                throw new FrontendQueryException(
                    'scope_service_unavailable',
                    'Worker Scope verification is unavailable.',
                    503,
                    $exception,
                );
            }
            if ($binding === null) {
                throw new FrontendQueryException(
                    'scope_bootstrap_invalid',
                    'Worker Scope bootstrap is not active for this request.',
                    401,
                );
            }

            try {
                $session = $this->sessionService->createSessionFromScopeBootstrap(
                    $deployVersion,
                    $workerBuildId,
                    $scopeBootstrapId,
                    $binding->tokenFingerprint,
                    $binding->digest(),
                );
            } catch (FrontendQueryException $exception) {
                if ($exception->getHttpStatus() >= 500) {
                    throw $exception;
                }
                throw new FrontendQueryException(
                    'scope_bootstrap_invalid',
                    'Worker Scope bootstrap is invalid, expired, or already consumed.',
                    401,
                    $exception,
                );
            }
            RequestContext::set('query_bin.bootstrap_cookie_clear', [
                'name' => $cookieName,
                'secure' => true,
                'same_site' => 'Lax',
            ]);
        } else {
            $secureCookie = $this->requestScheme() === 'https';
            if (!$secureCookie && !(\defined('DEV') && DEV)) {
                throw new FrontendQueryException(
                    'backend_attestation_https_required',
                    'Backend Worker attestation requires HTTPS.',
                    503,
                );
            }
            $cookieName = FrontendWorkerSessionService::backendBootstrapCookieName(
                $backendBootstrapId,
                $secureCookie,
            );
            $cookieProof = $this->readSingleCookie(
                $cookieName,
                'backend_attestation_invalid',
                'Worker backend bootstrap Cookie',
            );
            try {
                $binding = $this->sessionService->peekBackendBootstrap(
                    $backendBootstrapId,
                    $cookieProof,
                    $secureCookie,
                );
                $provider = $this->resolveBackendAttestationProvider();
                $restored = $provider->restoreBinding($binding, $this->authorityHost());
                $session = $this->sessionService->createSessionFromBackendBootstrap(
                    $deployVersion,
                    $workerBuildId,
                    $backendBootstrapId,
                    $cookieProof,
                    $secureCookie,
                    $restored->digest(),
                );
            } catch (FrontendWorkerBackendAttestationException $exception) {
                throw $this->backendAttestationFailure($exception);
            } catch (FrontendQueryException $exception) {
                if ($exception->getHttpStatus() >= 500) {
                    throw $exception;
                }
                throw new FrontendQueryException(
                    'backend_attestation_invalid',
                    'Worker backend bootstrap is invalid, expired, or already consumed.',
                    401,
                    $exception,
                );
            } catch (\Throwable $exception) {
                throw new FrontendQueryException(
                    'backend_attestation_unavailable',
                    'Worker backend attestation is unavailable.',
                    503,
                    $exception,
                );
            }
            RequestContext::set('query_bin.bootstrap_cookie_clear', [
                'name' => $cookieName,
                'secure' => $secureCookie,
                'same_site' => 'Strict',
            ]);
        }

        return [
            'ok' => true,
            'data' => $session,
            'error' => null,
            'request_id' => $requestId,
        ];
    }

    /**
     * @return array{session:string, capability:string, nonce:string, timestamp:string, body_hash:string, signature:string, deploy_version:string, worker_build_id:string}
     */
    private function readSignedHeaders(): array
    {
        $headers = [
            'session' => $this->serverHeader('X-Weline-Worker-Session'),
            'capability' => $this->serverHeader('X-Weline-Worker-Capability'),
            'nonce' => $this->serverHeader('X-Weline-Worker-Nonce'),
            'timestamp' => $this->serverHeader('X-Weline-Worker-Timestamp'),
            'body_hash' => $this->serverHeader('X-Weline-Worker-Body-Hash'),
            'signature' => $this->serverHeader('X-Weline-Worker-Signature'),
            'deploy_version' => $this->serverHeader('X-Weline-Deploy-Version') ?: 'dev',
            'worker_build_id' => $this->serverHeader('X-Weline-Worker-Build-Id') ?: 'dev',
        ];

        foreach (['session', 'capability', 'nonce', 'timestamp', 'body_hash', 'signature'] as $key) {
            if ($headers[$key] === '') {
                throw new FrontendQueryException('auth_error', 'Missing signed worker header: ' . $key, 401);
            }
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function validateSignedRequest(array $headers, string $rawBody): array
    {
        // Never consume a nonce before the body and HMAC are authenticated.
        // Otherwise an attacker can burn a victim's nonce with a forged
        // signature and turn replay protection into a denial-of-service tool.
        $session = $this->sessionService->validateSession(
            $headers['session'],
            $headers['deploy_version'],
            $headers['worker_build_id'],
        );

        $timestamp = (int)$headers['timestamp'];
        if ($timestamp <= 0 || \abs(\time() - $timestamp) > self::TIMESTAMP_WINDOW) {
            throw new FrontendQueryException('auth_error', 'Worker timestamp is outside allowed window.', 401);
        }

        $bodyHash = \hash('sha256', $rawBody);
        if (!\hash_equals($bodyHash, $headers['body_hash'])) {
            throw new FrontendQueryException('auth_error', 'Worker body hash mismatch.', 401);
        }

        $signatureBase = \implode("\n", [
            'POST',
            self::SIGNED_PATH,
            $headers['deploy_version'],
            $headers['worker_build_id'],
            $headers['capability'],
            $headers['nonce'],
            $headers['timestamp'],
            $headers['body_hash'],
        ]);
        $expected = \hash_hmac('sha256', $signatureBase, (string)$session['secret']);
        if (!\hash_equals($expected, $headers['signature'])) {
            throw new FrontendQueryException('auth_error', 'Worker signature mismatch.', 401);
        }

        // Revalidate the session and consume the nonce under one store lock.
        // Concurrent replays can pass the read-only HMAC phase, but only one
        // request can commit the nonce.
        $committedSession = $this->sessionService->validateSessionAndConsumeNonce(
            $headers['session'],
            $headers['deploy_version'],
            $headers['worker_build_id'],
            $headers['nonce'],
        );
        if (!\hash_equals((string)$session['secret'], (string)$committedSession['secret'])) {
            throw new FrontendQueryException('auth_error', 'Worker session changed during validation.', 401);
        }

        return $committedSession;
    }

    /**
     * Convert committed store data into a strict server-only authority context.
     * Existing sessions that predate area persistence are deliberately treated
     * as frontend; they can never be upgraded by request payload fields.
     *
     * @param array<string, mixed> $session
     */
    private function executionContextFromSession(
        array $session,
        ?string $sessionToken = null,
    ): FrontendWorkerExecutionContext
    {
        $area = $session['attested_area'] ?? FrontendWorkerExecutionContext::AREA_FRONTEND;
        $scopeBinding = $session['scope_binding'] ?? null;
        if ($scopeBinding !== null && !$scopeBinding instanceof FrontendWorkerScopeBinding) {
            throw new FrontendQueryException('auth_error', 'Worker session Scope binding is invalid.', 401);
        }
        $backendBinding = $session['backend_binding'] ?? null;
        if ($backendBinding !== null && !$backendBinding instanceof FrontendWorkerBackendBinding) {
            throw new FrontendQueryException('auth_error', 'Worker session backend binding is invalid.', 401);
        }

        if ($area === FrontendWorkerExecutionContext::AREA_FRONTEND) {
            if ($backendBinding !== null) {
                throw new FrontendQueryException('auth_error', 'Frontend Worker session contains backend authority.', 401);
            }
            return FrontendWorkerExecutionContext::frontend($scopeBinding);
        }
        if ($area !== FrontendWorkerExecutionContext::AREA_BACKEND
            || !$backendBinding instanceof FrontendWorkerBackendBinding
            || $scopeBinding !== null) {
            throw new FrontendQueryException('auth_error', 'Worker session authority is invalid.', 401);
        }

        try {
            $restored = $this->resolveBackendAttestationProvider()->restoreBinding(
                $backendBinding,
                $this->authorityHost(),
            );
        } catch (FrontendWorkerBackendAttestationException $exception) {
            throw $this->backendAttestationFailure($exception);
        } catch (FrontendQueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'backend_attestation_unavailable',
                'Worker backend attestation is unavailable.',
                503,
                $exception,
            );
        }

        if ($sessionToken !== null
            && $sessionToken !== ''
            && !\hash_equals($restored->digest(), $backendBinding->digest())) {
            $this->sessionService->slideBackendSession($sessionToken, $restored);
        }

        RequestContext::set('frontend_worker.backend_attestation.' . $restored->digest(), true);

        return FrontendWorkerExecutionContext::backend($restored);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLocalizationContext(array $payload): void
    {
        $context = $payload['context'] ?? null;
        if (!\is_array($context)) {
            return;
        }

        $language = $this->normalizeWorkerLanguage(
            $context['locale'] ?? $context['lang'] ?? $context['language'] ?? ''
        );
        if ($language !== '') {
            RequestContext::setWelineUserLang($language);
        }

        $currency = $this->normalizeWorkerCurrency($context['currency'] ?? '');
        if ($currency !== '') {
            RequestContext::setWelineUserCurrency($currency);
        }
    }

    private function normalizeWorkerLanguage(mixed $value): string
    {
        $language = \trim((string)$value);
        return \preg_match('/^[a-z]{2}_[A-Za-z]{2,8}(?:_[A-Z]{2})?$/', $language) === 1 ? $language : '';
    }

    private function normalizeWorkerCurrency(mixed $value): string
    {
        $currency = \strtoupper(\trim((string)$value));
        return State::isAllowedCurrencyCode($currency) ? $currency : '';
    }

    private function assertProtocolHeaders(): void
    {
        if ($this->serverHeader('X-Weline-Protocol') !== self::PROTOCOL) {
            throw new FrontendQueryException('protocol_error', 'Missing Weline worker binary protocol header.', 400);
        }
        if ($this->serverHeader('X-Weline-Worker-Protocol') !== self::WORKER_PROTOCOL) {
            throw new FrontendQueryException('protocol_error', 'Missing Weline worker request protocol header.', 400);
        }
    }

    private function assertContentType(): void
    {
        $contentType = \strtolower((string)$this->request->getServer('CONTENT_TYPE'));
        if (!\str_contains($contentType, WelineBinaryCodec::CONTENT_TYPE)) {
            throw new FrontendQueryException('protocol_error', 'Invalid query-bin content type.', 400);
        }
    }

    private function assertSameOrigin(): void
    {
        $currentOrigin = $this->currentOrigin();
        $origin = (string)$this->request->getServer('HTTP_ORIGIN');
        if ($origin === '') {
            return;
        }

        if (\rtrim($origin, '/') !== $currentOrigin) {
            throw new FrontendQueryException('auth_error', 'Worker request origin mismatch.', 401);
        }
    }

    private function currentOrigin(): string
    {
        return $this->requestScheme() . '://' . $this->authorityHost();
    }

    private function requestScheme(): string
    {
        $scheme = \strtolower(\trim(WelineEnv::getRequestScheme()));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new FrontendQueryException('protocol_error', 'Request scheme is invalid.', 400);
        }
        return $scheme;
    }

    private function authorityHost(): string
    {
        $host = RequestAuthority::current();
        if ($host === '') {
            throw new FrontendQueryException('protocol_error', 'Request authority is invalid.', 400);
        }
        return $host;
    }

    private function readSingleCookie(
        string $expectedName,
        string $errorCode,
        string $label,
    ): string
    {
        $rawHeader = (string)$this->request->getServer('HTTP_COOKIE');
        if ($rawHeader === '' || \strlen($rawHeader) > 65536) {
            throw new FrontendQueryException(
                $errorCode,
                $label . ' is missing or invalid.',
                401,
            );
        }

        $matches = [];
        foreach (\explode(';', $rawHeader) as $part) {
            $pair = \explode('=', \trim($part), 2);
            if (\count($pair) !== 2 || \urldecode($pair[0]) !== $expectedName) {
                continue;
            }
            $matches[] = \urldecode($pair[1]);
        }
        if (\count($matches) !== 1
            || $matches[0] === ''
            || \strlen($matches[0]) > 8192
            || \preg_match('/[\x00-\x1F\x7F]/', $matches[0]) === 1) {
            throw new FrontendQueryException(
                $errorCode,
                $label . ' is missing, duplicated, or invalid.',
                401,
            );
        }

        return $matches[0];
    }

    private function resolveScopeProvider(bool $required): ?FrontendWorkerScopeProviderInterface
    {
        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(FrontendWorkerScopeProviderInterface::class);
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'scope_service_unavailable',
                'Worker Scope provider registry is unavailable.',
                503,
                $exception,
            );
        }

        if ($resolution->isAvailable()
            && $resolution->provider instanceof FrontendWorkerScopeProviderInterface) {
            return $resolution->provider;
        }
        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED && !$required) {
            return null;
        }

        throw new FrontendQueryException(
            'scope_service_unavailable',
            'Worker Scope provider is configured but unavailable.',
            503,
        );
    }

    private function resolveBackendAttestationProvider(): FrontendWorkerBackendAttestationProviderInterface
    {
        try {
            $resolver = $this->runtimeProviderResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(
                FrontendWorkerBackendAttestationProviderInterface::class,
            );
        } catch (\Throwable $exception) {
            throw new FrontendQueryException(
                'backend_attestation_unavailable',
                'Worker backend attestation provider registry is unavailable.',
                503,
                $exception,
            );
        }

        if ($resolution->isAvailable()
            && $resolution->provider instanceof FrontendWorkerBackendAttestationProviderInterface) {
            return $resolution->provider;
        }

        throw new FrontendQueryException(
            'backend_attestation_unavailable',
            'Worker backend attestation provider is unavailable.',
            503,
        );
    }

    private function scopeFailure(FrontendWorkerScopeException $exception): FrontendQueryException
    {
        return new FrontendQueryException(
            $exception->reason,
            $exception->getMessage(),
            $exception->httpStatus,
            $exception,
        );
    }

    private function backendAttestationFailure(
        FrontendWorkerBackendAttestationException $exception,
    ): FrontendQueryException {
        return new FrontendQueryException(
            $exception->reason,
            $exception->getMessage(),
            $exception->httpStatus,
            $exception,
        );
    }

    private function serverHeader(string $name): string
    {
        $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
        return \trim((string)$this->request->getServer($key));
    }

    /**
     * @param array<string, mixed> $payload
     */
    /**
     * @param array<string, mixed> $payload
     * @return array{type:string,provider:string,operation:string}
     */
    private function summarizeRequestPayload(array $payload): array
    {
        $type = (string)($payload['type'] ?? '');
        $provider = '';
        $operation = '';
        if ($type === 'call') {
            $provider = (string)($payload['provider'] ?? '');
            $operation = (string)($payload['operation'] ?? '');
        } elseif ($type === 'stream-ticket') {
            $channel = (string)($payload['channel'] ?? '');
            if (\str_contains($channel, '.')) {
                [$provider, $operation] = \explode('.', $channel, 2);
            }
        }

        return [
            'type' => $type,
            'provider' => $provider,
            'operation' => $operation,
        ];
    }

    /**
     * @param array{type:string,provider:string,operation:string} $summary
     */
    private function logSlowQueryBin(string $requestId, array $summary, int $statusCode, float $elapsedMs): void
    {
        if ($elapsedMs < 100.0 || !\function_exists('w_log_warning')) {
            return;
        }

        \w_log_warning('[QueryBinTiming] slow request', [
            'request_id' => $requestId,
            'type' => $summary['type'],
            'provider' => $summary['provider'],
            'operation' => $summary['operation'],
            'status' => $statusCode,
            'duration_ms' => $elapsedMs,
        ], 'query_bin');
    }

    /**
     * @param array{type:string,provider:string,operation:string} $summary
     */
    private function logUnexpectedFailure(
        \Throwable $throwable,
        string $requestId,
        array $summary,
        string $stage = 'request'
    ): void
    {
        if (!\function_exists('w_log_error')) {
            return;
        }

        $context = \function_exists('w_log_exception_build_context')
            ? \w_log_exception_build_context($throwable)
            : [
                'exception_class' => $throwable::class,
                'exception_message' => $throwable->getMessage(),
                'exception_file' => $throwable->getFile(),
                'exception_line' => $throwable->getLine(),
                '_exception_trace' => $throwable->getTraceAsString(),
            ];
        $context['request_id'] = $requestId;
        $context['type'] = $summary['type'];
        $context['provider'] = $summary['provider'];
        $context['operation'] = $summary['operation'];
        $context['stage'] = $stage;

        \w_log_error('[QueryBin] Unexpected request failure', $context, 'query_bin');
    }

    private function emergencyBinaryResponse(string $requestId, float $elapsedMs): Response
    {
        $response = Response::fromContent(
            EmergencyPacket::internalServerError($requestId),
            500,
            WelineBinaryCodec::CONTENT_TYPE
        );
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Weline-Query-Bin-Time', (string)$elapsedMs);

        return $this->expireConsumedBootstrapCookie($response);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{type:string,provider:string,operation:string} $summary
     */
    private function binaryResponse(array $payload, int $statusCode, array $summary, float $elapsedMs): Response
    {
        $encodeStart = \microtime(true);
        $encodedPayload = $this->codec->encodePacket($payload);
        $encodeMs = \round((\microtime(true) - $encodeStart) * 1000, 2);
        $timing = RequestContext::get('query_bin.timing');
        if (\is_array($timing)) {
            $timing['response_encode_ms'] = $encodeMs;
            $timing['response_bytes'] = \strlen($encodedPayload);
            RequestContext::set('query_bin.timing', $timing);
        }

        $response = Response::fromContent(
            $encodedPayload,
            $statusCode,
            WelineBinaryCodec::CONTENT_TYPE
        );
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Weline-Query-Bin-Time', (string)$elapsedMs);
        if ($summary['type'] !== '') {
            $response->setHeader('X-Weline-Query-Bin-Type', $summary['type']);
        }
        if ($summary['provider'] !== '') {
            $response->setHeader('X-Weline-Query-Bin-Provider', $summary['provider']);
        }
        if ($summary['operation'] !== '') {
            $response->setHeader('X-Weline-Query-Bin-Operation', $summary['operation']);
        }

        return $this->expireConsumedBootstrapCookie($response);
    }

    private function expireConsumedBootstrapCookie(Response $response): Response
    {
        $cookie = RequestContext::get('query_bin.bootstrap_cookie_clear');
        if (!\is_array($cookie)
            || \array_keys($cookie) !== ['name', 'secure', 'same_site']
            || !\is_string($cookie['name'])
            || !\is_bool($cookie['secure'])
            || !\is_string($cookie['same_site'])
            || !\in_array($cookie['same_site'], ['Lax', 'Strict'], true)
            || \preg_match(
                '/^(?:__Host-Weline-Worker-(?:Scope|Backend)-Bootstrap-|Weline-Worker-Backend-Bootstrap-)[A-Za-z0-9_-]{43}$/D',
                $cookie['name'],
            ) !== 1) {
            return $response;
        }

        $response->setCookie(
            $cookie['name'],
            '',
            \time() - 3600,
            '/',
            '',
            $cookie['secure'],
            true,
            $cookie['same_site'],
        );
        return $response;
    }

    /**
     * @return array{display_errors:string|false,html_errors:string|false}
     */
    private function beginBinaryOutputGuard(): array
    {
        $preExisting = '';
        while (\ob_get_level() > 0) {
            $chunk = \ob_get_clean();
            if (\is_string($chunk) && $chunk !== '') {
                $preExisting = $chunk . $preExisting;
            }
        }

        if ($preExisting !== '' && \function_exists('w_log_warning')) {
            \w_log_warning('[QueryBin] Cleared pre-existing output buffer before binary response.', [
                'bytes' => \strlen($preExisting),
                'sha256' => \hash('sha256', $preExisting),
            ], 'query_bin');
        }

        $guard = [
            'display_errors' => \ini_get('display_errors'),
            'html_errors' => \ini_get('html_errors'),
        ];

        @\ini_set('display_errors', '0');
        @\ini_set('html_errors', '0');
        \ob_start();

        return $guard;
    }

    /**
     * @param array{display_errors:string|false,html_errors:string|false} $guard
     */
    private function endBinaryOutputGuard(array $guard): void
    {
        $captured = '';
        while (\ob_get_level() > 0) {
            $chunk = \ob_get_clean();
            if (\is_string($chunk) && $chunk !== '') {
                $captured = $chunk . $captured;
            }
        }

        if (\is_string($guard['display_errors']) || $guard['display_errors'] === false) {
            @\ini_set('display_errors', $guard['display_errors'] === false ? '0' : (string)$guard['display_errors']);
        }
        if (\is_string($guard['html_errors']) || $guard['html_errors'] === false) {
            @\ini_set('html_errors', $guard['html_errors'] === false ? '0' : (string)$guard['html_errors']);
        }

        if ($captured !== '' && \function_exists('w_log_warning')) {
            \w_log_warning('[QueryBin] Suppressed stray output during binary response.', [
                'bytes' => \strlen($captured),
                'sha256' => \hash('sha256', $captured),
            ], 'query_bin');
        }
    }
}
