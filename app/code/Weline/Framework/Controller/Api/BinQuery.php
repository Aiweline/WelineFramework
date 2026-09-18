<?php
declare(strict_types=1);

namespace Weline\Framework\Controller\Api;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Framework\Binary\EmergencyPacket;
use Weline\Framework\Binary\Limits;
use Weline\Framework\Binary\WelineBinaryCodec;
use Weline\Framework\Http\BinaryOutputGuard;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerBackendAuthorizationException;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Query\BinQueryGateway;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\QueryUnexpectedFailurePayload;

#[Acl(
    source_id: 'Weline_Framework::binquery',
    source_name: 'BinQuery Gateway',
    icon: 'circle',
    document: 'External BinQuery binary gateway',
    parent_source: 'Weline_Framework::system_service_group',
    accessMode: 'edit',
    scopeGroup: 'framework',
    apiExposable: true
)]
class BinQuery extends FrontendRestController
{
    public function __construct(
        private readonly WelineBinaryCodec $codec,
        private readonly BinQueryGateway $gateway
    ) {
    }

    #[Acl(
        source_id: 'Weline_Framework::binquery::post',
        source_name: 'BinQuery Connect Query Call Graph',
        icon: 'circle',
        document: 'Connect, inspect, call and graph through /bin/query',
        parent_source: 'Weline_Framework::binquery',
        accessMode: 'edit',
        scopeGroup: 'framework',
        apiExposable: true
    )]
    public function postIndex(): Response
    {
        $requestId = \bin2hex(\random_bytes(8));
        $guard = $this->beginBinaryOutputGuard();
        $statusCode = 200;
        $readingProtocolInput = true;
        $responseHeaders = ['Cache-Control' => 'no-store'];
        $summary = [
            'type' => '',
            'provider' => '',
            'operation' => '',
        ];

        try {
            $this->assertProtocol();
            $this->assertContentType();
            $contentLength = (int)$this->request->getServer('CONTENT_LENGTH');
            if ($contentLength > Limits::PACKET_BYTES) {
                throw new \InvalidArgumentException(Limits::PACKET_ERROR);
            }
            $rawBody = $this->request->getParameterBag()->getRawBody();
            if ($rawBody === '') {
                throw new FrontendQueryException('protocol_error', 'Empty BinQuery request body.', 400);
            }
            if (\strlen($rawBody) > Limits::PACKET_BYTES) {
                throw new \InvalidArgumentException(Limits::PACKET_ERROR);
            }

            $payload = $this->codec->decodePacket($rawBody);
            if (!\is_array($payload)) {
                throw new FrontendQueryException('protocol_error', 'BinQuery payload must be a map.', 400);
            }
            $readingProtocolInput = false;

            $summary = $this->summarize($payload);
            $result = $this->gateway->execute(
                $payload,
                $this->readApiKey(),
                $this->readCacheMarker()
            );
            $statusCode = (int)($result['status'] ?? 200);
            $responseHeaders = \is_array($result['headers'] ?? null) ? $result['headers'] : $responseHeaders;
            $responsePayload = [
                'ok' => true,
                'data' => $result['data'] ?? null,
                'error' => null,
                'request_id' => $requestId,
            ];
        } catch (FrontendQueryException $exception) {
            $statusCode = $exception->getHttpStatus();
            $responsePayload = $this->errorPayload($exception->getErrorCode(), $exception->getMessage(), $requestId);
            $responseHeaders = ['Cache-Control' => 'no-store'];
        } catch (\InvalidArgumentException $exception) {
            if ($readingProtocolInput) {
                $statusCode = 400;
                $responsePayload = $this->errorPayload('protocol_error', $exception->getMessage(), $requestId);
            } else {
                $statusCode = 500;
                $this->logUnexpectedFailure($exception, $requestId, $summary);
                $responsePayload = $this->unexpectedFailurePayload($exception, $requestId);
            }
            $responseHeaders = ['Cache-Control' => 'no-store'];
        } catch (FrontendWorkerBackendAuthorizationException $exception) {
            $statusCode = $exception->httpStatus > 0 ? $exception->httpStatus : 403;
            $responsePayload = $this->errorPayload(
                $exception->reason !== '' ? $exception->reason : 'backend_acl_denied',
                $exception->getMessage(),
                $requestId
            );
            $responseHeaders = ['Cache-Control' => 'no-store'];
        } catch (\Throwable $throwable) {
            $statusCode = 500;
            $this->logUnexpectedFailure($throwable, $requestId, $summary);
            $responsePayload = $this->unexpectedFailurePayload($throwable, $requestId);
            $responseHeaders = ['Cache-Control' => 'no-store'];
        }

        $this->endBinaryOutputGuard($guard);

        try {
            return $this->binaryResponse($responsePayload, $statusCode, $responseHeaders, $summary);
        } catch (\Throwable $throwable) {
            $this->logUnexpectedFailure($throwable, $requestId, $summary, 'response_encode');
            $emptySummary = ['type' => '', 'provider' => '', 'operation' => ''];
            try {
                return $this->binaryResponse(
                    $this->errorPayload(
                        EmergencyPacket::ERROR_CODE,
                        EmergencyPacket::ERROR_MESSAGE,
                        $requestId
                    ),
                    500,
                    ['Cache-Control' => 'no-store'],
                    $emptySummary
                );
            } catch (\Throwable $fallbackThrowable) {
                $this->logUnexpectedFailure($fallbackThrowable, $requestId, $summary, 'fallback_encode');

                return $this->emergencyBinaryResponse($requestId);
            }
        }
    }

    private function assertProtocol(): void
    {
        $protocol = $this->serverHeader('X-Weline-BinQuery-Protocol');
        if ($protocol !== '' && $protocol !== BinQueryGateway::PROTOCOL) {
            throw new FrontendQueryException('protocol_error', 'Unsupported BinQuery protocol.', 400);
        }
    }

    private function assertContentType(): void
    {
        $contentType = \strtolower((string)$this->request->getServer('CONTENT_TYPE'));
        if (!\str_contains($contentType, WelineBinaryCodec::CONTENT_TYPE)) {
            throw new FrontendQueryException('protocol_error', 'Invalid BinQuery content type.', 400);
        }
    }

    private function readApiKey(): string
    {
        $bearer = (string)$this->request->getAuth('bearer');
        if ($bearer !== '') {
            return $bearer;
        }

        foreach (['X-Weline-BinQuery-Key', 'X-Weline-Api-Key', 'X-API-Token'] as $header) {
            $value = $this->serverHeader($header);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function readCacheMarker(): string
    {
        $value = $this->request->getParam(BinQueryCachePolicy::MARKER_PARAM, '');
        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{type:string,provider:string,operation:string}
     */
    private function summarize(array $payload): array
    {
        return [
            'type' => (string)($payload['type'] ?? ''),
            'provider' => (string)($payload['provider'] ?? ''),
            'operation' => (string)($payload['operation'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorPayload(string $code, string $message, string $requestId): array
    {
        return [
            'ok' => false,
            'data' => null,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $requestId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unexpectedFailurePayload(\Throwable $throwable, string $requestId): array
    {
        return [
            'ok' => false,
            'data' => null,
            'error' => QueryUnexpectedFailurePayload::build($throwable),
            'request_id' => $requestId,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @param array{type:string,provider:string,operation:string} $summary
     */
    private function binaryResponse(array $payload, int $statusCode, array $headers, array $summary): Response
    {
        $response = Response::fromContent(
            $this->codec->encodePacket($payload),
            $statusCode,
            WelineBinaryCodec::CONTENT_TYPE
        );
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Weline-BinQuery-Protocol', BinQueryGateway::PROTOCOL);
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }
        if ($summary['type'] !== '') {
            $response->setHeader('X-Weline-BinQuery-Type', $summary['type']);
        }
        if ($summary['provider'] !== '') {
            $response->setHeader('X-Weline-BinQuery-Provider', $summary['provider']);
        }
        if ($summary['operation'] !== '') {
            $response->setHeader('X-Weline-BinQuery-Operation', $summary['operation']);
        }

        return $response;
    }

    private function serverHeader(string $name): string
    {
        $key = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
        return \trim((string)$this->request->getServer($key));
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

        \w_log_error('[BinQuery] Unexpected request failure', $context, 'bin_query');
    }

    private function emergencyBinaryResponse(string $requestId): Response
    {
        $response = Response::fromContent(
            EmergencyPacket::internalServerError($requestId),
            500,
            WelineBinaryCodec::CONTENT_TYPE
        );
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Weline-BinQuery-Protocol', BinQueryGateway::PROTOCOL);

        return $response;
    }

    /**
     * @return array{mode: 'persistent'|'fpm', label: string, display_errors?: string|false, html_errors?: string|false}
     */
    private function beginBinaryOutputGuard(): array
    {
        return BinaryOutputGuard::begin('BinQuery');
    }

    /**
     * @param array{mode: 'persistent'|'fpm', label: string, display_errors?: string|false, html_errors?: string|false} $guard
     */
    private function endBinaryOutputGuard(array $guard): void
    {
        BinaryOutputGuard::end($guard);
    }
}
