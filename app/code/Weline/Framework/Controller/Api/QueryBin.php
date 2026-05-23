<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Binary\WelineBinaryCodec;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

class QueryBin extends FrontendController
{
    private const PROTOCOL = 'worker-query-bin-v1';
    private const WORKER_PROTOCOL = 'weline-worker-request-v1';
    private const SIGNED_PATH = '/api/framework/query-bin';
    private const TIMESTAMP_WINDOW = 120;

    public function __construct(
        private readonly WelineBinaryCodec $codec,
        private readonly FrontendQueryGateway $gateway,
        private readonly FrontendWorkerSessionService $sessionService
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

            $rawBody = $this->request->getParameterBag()->getRawBody();
            if ($rawBody === '') {
                throw new FrontendQueryException('protocol_error', 'Empty binary request body.', 400);
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
                $this->validateSignedRequest($headers, $rawBody);
                $markPhase('validate_signed_request');

                $result = $this->gateway->execute($payload, $headers['capability']);
                $markPhase('gateway_execute');
                $payload = [
                    'ok' => true,
                    'data' => $result,
                    'error' => null,
                    'request_id' => $requestId,
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
            $payload = [
                'ok' => false,
                'data' => null,
                'error' => [
                    'code' => 'business_error',
                    'message' => $throwable->getMessage(),
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

        return $this->binaryResponse(\is_array($payload) ? $payload : [
            'ok' => false,
            'data' => null,
            'error' => [
                'code' => 'protocol_error',
                'message' => 'Empty query-bin payload.',
            ],
            'request_id' => $requestId,
        ], $statusCode, $requestSummary, $elapsedMs);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleHandshake(array $payload, string $requestId): array
    {
        $deployVersion = (string)($payload['deploy_version'] ?? 'dev');
        $workerBuildId = (string)($payload['worker_build_id'] ?? 'dev');
        if ($deployVersion === '' || $workerBuildId === '') {
            throw new FrontendQueryException('protocol_error', 'Handshake is missing deploy version or worker build id.', 400);
        }

        return [
            'ok' => true,
            'data' => $this->sessionService->createSession($deployVersion, $workerBuildId),
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
    private function validateSignedRequest(array $headers, string $rawBody): void
    {
        $session = $this->sessionService->validateSessionAndConsumeNonce(
            $headers['session'],
            $headers['deploy_version'],
            $headers['worker_build_id'],
            $headers['nonce']
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
        $origin = (string)$this->request->getServer('HTTP_ORIGIN');
        if ($origin === '') {
            return;
        }

        if (\rtrim($origin, '/') !== $this->currentOrigin()) {
            throw new FrontendQueryException('auth_error', 'Worker request origin mismatch.', 401);
        }
    }

    private function currentOrigin(): string
    {
        $scheme = (string)$this->request->getServer('REQUEST_SCHEME');
        if ($scheme === '') {
            $https = (string)$this->request->getServer('HTTPS');
            $scheme = ($https !== '' && \strtolower($https) !== 'off') ? 'https' : 'http';
        }
        $host = (string)($this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost');

        return $scheme . '://' . $host;
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
            \w_log_warning('[QueryBin] Cleared pre-existing output buffer before binary response: ' . \mb_substr(\trim($preExisting), 0, 500));
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
            \w_log_warning('[QueryBin] Suppressed stray output during binary response: ' . \mb_substr(\trim($captured), 0, 500));
        }
    }
}
