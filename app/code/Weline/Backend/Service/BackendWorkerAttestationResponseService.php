<?php

declare(strict_types=1);

namespace Weline\Backend\Service;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationException;
use Weline\Framework\Runtime\FrontendWorkerBackendAttestationProviderInterface;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

/**
 * Replaces one trusted backend-head slot with an opaque, one-time Worker
 * bootstrap ID. The proof remains exclusively in a host-only HttpOnly cookie.
 */
final class BackendWorkerAttestationResponseService
{
    private const REQUEST_STATE_KEY = 'backend.frontend_worker_attestation.v1';
    private const SLOT = '<meta name="weline-worker-backend-bootstrap-slot" content="">';
    private const META_NAME = 'weline-worker-backend-bootstrap';
    private const OPAQUE_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const MAX_WIRE_BODY_BYTES = 8 * 1024 * 1024;
    private const MAX_HTML_BODY_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly FrontendWorkerBackendAttestationProviderInterface $provider,
        private readonly FrontendWorkerSessionService $workerSessionService,
    ) {
    }

    public function decorate(mixed $result): mixed
    {
        if ((!$result instanceof Response && !\is_string($result))
            || $this->alreadyProcessed()
            || !$this->isEligibleRequest()) {
            return $result;
        }

        $response = $result instanceof Response ? $result : new Response();
        if (!$this->isEligibleResponse($response)) {
            return $result;
        }

        $body = $result instanceof Response ? $response->getBody() : $result;

        // Browser fetch()/XHR and iframe loads must never mint a backend page
        // proof. Production requires positive user-activated top-level Fetch
        // Metadata; only local DEV harnesses may omit the headers entirely.
        if (!$this->isTopLevelDocumentNavigation()) {
            RequestContext::set(self::REQUEST_STATE_KEY, ['status' => 'non_navigation']);
            return $result;
        }

        $scheme = \strtolower(\trim(WelineEnv::getRequestScheme()));
        $host = RequestAuthority::current();
        if ($host === '') {
            throw $this->failure('backend_attestation_authority_invalid', 503);
        }
        $secure = $scheme === 'https';
        if (!$secure && !(\defined('DEV') && DEV)) {
            throw $this->failure('backend_attestation_https_required', 503);
        }

        try {
            $binding = $this->provider->issueBinding($host);
            if ($binding === null) {
                // Login and other anonymous backend pages retain only the inert
                // slot; they cannot establish a backend-authoritative Worker.
                RequestContext::set(self::REQUEST_STATE_KEY, ['status' => 'anonymous']);
                return $result;
            }
            if ($body === '') {
                throw $this->failure('backend_attestation_response_empty', 503);
            }
            if (\strlen($body) > self::MAX_WIRE_BODY_BYTES) {
                throw $this->failure('backend_attestation_response_too_large', 503);
            }

            $prepared = $this->prepareHtml($body, $response);
            $html = $prepared['html'];

            if (\substr_count($html, self::SLOT) !== 1
                || \stripos($html, 'name="' . self::META_NAME . '"') !== false) {
                throw $this->failure('backend_attestation_response_slot_invalid', 503);
            }

            $bootstrap = $this->workerSessionService->createBackendBootstrap($binding, $secure);
            $bootstrapId = $bootstrap['bootstrap_id'] ?? null;
            $cookieName = $bootstrap['cookie_name'] ?? null;
            $cookieValue = $bootstrap['cookie_value'] ?? null;
            $expiresAt = $bootstrap['expires_at'] ?? null;
            if (!\is_string($bootstrapId)
                || \preg_match(self::OPAQUE_ID_PATTERN, $bootstrapId) !== 1
                || !\is_string($cookieName)
                || !\hash_equals(
                    FrontendWorkerSessionService::backendBootstrapCookieName($bootstrapId, $secure),
                    $cookieName,
                )
                || !\is_string($cookieValue)
                || \preg_match(self::OPAQUE_ID_PATTERN, $cookieValue) !== 1
                || !\is_int($expiresAt)
                || $expiresAt <= \time()
                || $expiresAt > $binding->expiresAt) {
                throw $this->failure('backend_attestation_bootstrap_contract_invalid', 503);
            }

            $meta = '<meta name="' . self::META_NAME . '" content="' . $bootstrapId . '">';
            $decoratedHtml = \str_replace(self::SLOT, $meta, $html, $replacements);
            if ($replacements !== 1) {
                throw $this->failure('backend_attestation_response_slot_invalid', 503);
            }
            $decoratedBody = $decoratedHtml;
            if ($prepared['encoding'] === 'gzip') {
                $encoded = \gzencode($decoratedHtml, 6);
                if (!\is_string($encoded)) {
                    throw $this->failure('backend_attestation_response_encoding_failed', 503);
                }
                $decoratedBody = $encoded;
            }

            $response->setBody($decoratedBody);
            $headers = $response->getHeaderCollectorInstance();
            foreach (['ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Last-Modified', 'Accept-Ranges'] as $header) {
                $headers->removeHeader($header);
            }
            if ($this->headerValue($response, 'Transfer-Encoding') === '') {
                $response->setHeader('Content-Length', (string)\strlen($decoratedBody));
            } else {
                $headers->removeHeader('Content-Length');
            }
            if ($prepared['encoding'] === 'gzip') {
                $this->ensureVaryAcceptEncoding($response);
            }
            $this->applyNoStore($response);
            $response->setCookie($cookieName, $cookieValue, $expiresAt, '/', '', $secure, true, 'Strict');
            RequestContext::set(self::REQUEST_STATE_KEY, [
                'status' => 'decorated',
                'bootstrap_id' => $bootstrapId,
                'cookie_name' => $cookieName,
            ]);

            return $response;
        } catch (FrontendWorkerBackendAttestationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw $this->failure('backend_attestation_unavailable', 503, $exception);
        }
    }

    private function alreadyProcessed(): bool
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY);
        return \is_array($state) && isset($state['status']);
    }

    private function isEligibleRequest(): bool
    {
        if (\strtoupper(WelineEnv::getRequestMethod()) !== 'GET'
            || RequestContext::getWelineArea() !== RequestContext::AREA_BACKEND) {
            return false;
        }
        $context = Context::getCurrent();
        return $context !== null
            && !(bool)$context->get('route.is_static', false)
            && !(bool)$context->get('route.is_media', false);
    }

    private function isEligibleResponse(Response $response): bool
    {
        if ($response->getStatusCode() !== 200
            || $this->headerValue($response, 'Content-Disposition') !== '') {
            return false;
        }
        $contentType = \strtolower($this->headerValue($response, 'Content-Type'));
        return ($contentType === '' || \preg_match('~^text/html(?:\s*;|$)~D', $contentType) === 1)
            && !\str_contains($contentType, 'text/event-stream');
    }

    private function isTopLevelDocumentNavigation(): bool
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return false;
        }
        $mode = \strtolower(\trim((string)$context->server('HTTP_SEC_FETCH_MODE', '')));
        $dest = \strtolower(\trim((string)$context->server('HTTP_SEC_FETCH_DEST', '')));
        $user = \trim((string)$context->server('HTTP_SEC_FETCH_USER', ''));
        if ($mode === '' && $dest === '' && $user === '') {
            // Local DEV HTTP probes and legacy Browser harnesses may not supply
            // Fetch Metadata. Production never mints a backend proof without a
            // positively identified user-activated top-level navigation.
            return \defined('DEV') && DEV;
        }
        return $mode === 'navigate' && $dest === 'document' && $user === '?1';
    }

    private function applyNoStore(Response $response): void
    {
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
    }

    /** @return array{html:string,encoding:string} */
    private function prepareHtml(string $wireBody, Response $response): array
    {
        $encoding = \strtolower(\trim($this->headerValue($response, 'Content-Encoding')));
        if ($encoding === '' || $encoding === 'identity') {
            if (\strlen($wireBody) > self::MAX_HTML_BODY_BYTES) {
                throw $this->failure('backend_attestation_response_too_large', 503);
            }
            return ['html' => $wireBody, 'encoding' => 'identity'];
        }
        if ($encoding !== 'gzip' || !\function_exists('gzdecode') || !\function_exists('gzencode')) {
            throw $this->failure('backend_attestation_response_encoding_unsupported', 503);
        }
        $decoded = \gzdecode($wireBody, self::MAX_HTML_BODY_BYTES + 1);
        if (!\is_string($decoded) || \strlen($decoded) > self::MAX_HTML_BODY_BYTES) {
            throw $this->failure('backend_attestation_response_gzip_invalid', 503);
        }
        return ['html' => $decoded, 'encoding' => 'gzip'];
    }

    private function ensureVaryAcceptEncoding(Response $response): void
    {
        $rawVary = $response->getHeader('Vary');
        $vary = \is_array($rawVary)
            ? \implode(', ', \array_map('strval', $rawVary))
            : \trim((string)($rawVary ?? ''));
        if ($vary === '') {
            $response->setHeader('Vary', 'Accept-Encoding');
            return;
        }
        foreach (\array_map('trim', \explode(',', $vary)) as $part) {
            if (\strcasecmp($part, 'Accept-Encoding') === 0) {
                return;
            }
        }
        $response->setHeader('Vary', $vary . ', Accept-Encoding');
    }

    private function headerValue(Response $response, string $name): string
    {
        $value = $response->getHeader($name);
        if (\is_array($value)) {
            if (\count($value) !== 1) {
                return '__multiple__';
            }
            $value = $value[0] ?? '';
        }
        return \is_scalar($value) ? \trim((string)$value) : '';
    }

    private function failure(
        string $reason,
        int $status,
        ?\Throwable $previous = null,
    ): FrontendWorkerBackendAttestationException {
        return new FrontendWorkerBackendAttestationException(
            $reason,
            $status,
            (string)__('后台安全凭证暂不可用，请稍后刷新页面。'),
            $previous,
        );
    }
}
