<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\InternalHomepagePrime;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendWorkerSessionService;

/**
 * Adds the one-time Worker Scope bootstrap to final storefront HTML responses.
 *
 * The page receives only the opaque bootstrap ID. The Scope Token remains in
 * a request-specific HttpOnly cookie and must never enter markup, logs, or the
 * request context.
 */
final class FrontendWorkerScopeBootstrapResponseService
{
    private const REQUEST_STATE_KEY = 'websites.frontend_worker_scope_bootstrap.v1';
    private const META_NAME = 'weline-worker-scope-bootstrap';
    private const OPAQUE_ID_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const MAX_WIRE_BODY_BYTES = 8 * 1024 * 1024;
    private const MAX_HTML_BODY_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private readonly FrontendWorkerScopeProviderInterface $scopeProvider,
        private readonly FrontendWorkerSessionService $workerSessionService,
    ) {
    }

    public function decorate(mixed $result): mixed
    {
        if (!$result instanceof Response && !\is_string($result)) {
            return $result;
        }
        if ($this->alreadyDecorated() || !$this->isEligibleRequest()) {
            return $result;
        }

        $response = $result instanceof Response ? $result : new Response();
        if (!$this->isEligibleResponse($response)) {
            return $result;
        }

        $scope = RequestContext::scopeIdentity();
        if (!$scope instanceof ScopeIdentity || $scope->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            return $result;
        }

        $scheme = $this->requestInput('scheme');
        $authorityHost = $this->requestInput('host');
        try {
            $decision = $this->scopeProvider->rollout($scope, $scheme);
        } catch (FrontendWorkerScopeException $exception) {
            try {
                if (!$this->scopeProvider->requiresBinding($scheme)) {
                    $this->logShadowSkip($exception->reason, 'shadow');
                    return $result;
                }
            } catch (\Throwable) {
                // The rollout source itself is unavailable, so its previous
                // authority cannot be inferred safely.
            }
            throw $exception;
        }
        if ($decision->isOff()) {
            // This return must precede token issuance, bootstrap-store access,
            // cookies, and cache-header mutation.
            RequestContext::set(self::REQUEST_STATE_KEY, ['status' => 'off']);
            return $result;
        }
        if ($decision->isShadow()) {
            $sampled = $this->isShadowSampled($decision->shadowSampleBasisPoints, $scope);
            RequestContext::set(self::REQUEST_STATE_KEY, [
                'status' => $sampled ? 'shadow_observed' : 'shadow_not_sampled',
            ]);
            if ($sampled) {
                \w_log_info(
                    '[FrontendWorkerScopeBootstrap] shadow Scope/catalog comparison matched',
                    [
                        'website_id' => $decision->websiteId,
                        'store_id' => $decision->storeId,
                        'channel_id' => $decision->channelId,
                    ],
                    'worker_scope',
                );
            }
            return $result;
        }
        if (!$decision->tokenEnabled) {
            return $result;
        }

        $bindingRequired = $this->scopeProvider->requiresBinding($scheme);
        if ($authorityHost === '') {
            return $this->skipOrFail(
                $result,
                $bindingRequired,
                $decision->mode,
                'worker_scope_bootstrap_authority_invalid',
            );
        }
        if ($bindingRequired) {
            $this->applyNoStore($response);
        }
        $wireBody = $result instanceof Response ? $response->getBody() : $result;
        $prepared = $this->prepareHtml($wireBody, $response, $bindingRequired, $decision->mode);
        if ($prepared === null) {
            return $result;
        }

        try {
            $token = $this->scopeProvider->issueToken($scope, $scheme, $authorityHost);
            if (!\is_string($token) || $token === '') {
                return $this->skipOrFail(
                    $result,
                    $bindingRequired,
                    $decision->mode,
                    'worker_scope_bootstrap_token_unavailable',
                );
            }

            $binding = $this->scopeProvider->verifyToken($token, $scheme, $authorityHost);
            if ($binding === null) {
                return $this->skipOrFail(
                    $result,
                    $bindingRequired,
                    $decision->mode,
                    'worker_scope_bootstrap_binding_unavailable',
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
                || $expiresAt > $binding->tokenExpiresAt) {
                return $this->skipOrFail(
                    $result,
                    $bindingRequired,
                    $decision->mode,
                    'worker_scope_bootstrap_contract_invalid',
                );
            }

            $decoratedBody = $this->injectBootstrapId($prepared['html'], $prepared['insertion_offset'], $bootstrapId);
            if ($prepared['encoding'] === 'gzip') {
                $encoded = \gzencode($decoratedBody, 6);
                if (!\is_string($encoded)) {
                    return $this->skipOrFail(
                        $result,
                        $bindingRequired,
                        $decision->mode,
                        'worker_scope_response_encoding_failed',
                    );
                }
                $decoratedBody = $encoded;
            }

            // Return the Response for both controller Response and legacy
            // string HTML paths. Returning only the decorated string would
            // let a later Response::normalize() replace the shared collector,
            // dropping this bootstrap's HttpOnly Cookie and no-store headers.
            $response->setBody($decoratedBody);
            $headers = $response->getHeaderCollectorInstance();
            foreach (['ETag', 'Content-MD5', 'Digest', 'Content-Digest', 'Last-Modified', 'Accept-Ranges'] as $header) {
                $headers->removeHeader($header);
            }
            $transferEncoding = \strtolower($this->headerValue($response, 'Transfer-Encoding'));
            if ($prepared['encoding'] === 'gzip' && $transferEncoding === '') {
                $response->setHeader('Content-Length', (string)\strlen($decoratedBody));
            } else {
                $headers->removeHeader('Content-Length');
            }
            if ($prepared['encoding'] === 'gzip') {
                $this->ensureVaryAcceptEncoding($response);
            }

            $this->applyNoStore($response);
            $response->setCookie($cookieName, $token, $expiresAt, '/', '', true, true, 'Lax');
            RequestContext::set(self::REQUEST_STATE_KEY, [
                'status' => 'decorated',
                'bootstrap_id' => $bootstrapId,
                'cookie_name' => $cookieName,
            ]);

            return $response;
        } catch (\Throwable $exception) {
            if ($bindingRequired) {
                if ($exception instanceof FrontendWorkerScopeException) {
                    throw $exception;
                }
                throw new FrontendWorkerScopeException(
                    'worker_scope_bootstrap_unavailable',
                    503,
                    (string)__('系统正在升级维护，请稍后再试。'),
                    $exception,
                );
            }

            $reason = $exception instanceof FrontendWorkerScopeException
                ? $exception->reason
                : 'worker_scope_bootstrap_unavailable';
            $this->logShadowSkip($reason, $decision->mode);
            return $result;
        }
    }

    private function alreadyDecorated(): bool
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY);
        return \is_array($state) && ($state['status'] ?? null) === 'decorated';
    }

    private function isEligibleRequest(): bool
    {
        if (InternalHomepagePrime::isCurrentRequest()) {
            return false;
        }

        if (\strtoupper($this->requestInput('method')) !== 'GET'
            || RequestContext::getWelineArea() !== RequestContext::AREA_FRONTEND) {
            return false;
        }

        $context = Context::getCurrent();
        if ($context === null
            || (bool)$context->get('route.is_static', false)
            || (bool)$context->get('route.is_media', false)) {
            return false;
        }

        return \strcasecmp((string)$context->server('HTTP_X_REQUESTED_WITH', ''), 'XMLHttpRequest') !== 0;
    }

    private function isEligibleResponse(Response $response): bool
    {
        if ($response->getStatusCode() !== 200
            || $this->headerValue($response, 'Content-Disposition') !== '') {
            return false;
        }

        $contentType = \strtolower($this->headerValue($response, 'Content-Type'));
        if ($contentType !== '' && \preg_match('~^text/html(?:\s*;|$)~D', $contentType) !== 1) {
            return false;
        }

        return !\str_contains($contentType, 'text/event-stream');
    }

    /**
     * @return array{html:string,encoding:string,insertion_offset:int}|null
     */
    private function prepareHtml(
        string $wireBody,
        Response $response,
        bool $bindingRequired,
        string $mode,
    ): ?array {
        if ($wireBody === '') {
            return $this->preparationFailure(
                $bindingRequired,
                $mode,
                'worker_scope_response_empty',
            );
        }
        if (\strlen($wireBody) > self::MAX_WIRE_BODY_BYTES) {
            return $this->preparationFailure(
                $bindingRequired,
                $mode,
                'worker_scope_response_too_large',
            );
        }

        $encoding = \strtolower(\trim($this->headerValue($response, 'Content-Encoding')));
        if ($encoding === '' || $encoding === 'identity') {
            $html = $wireBody;
            $encoding = 'identity';
        } elseif ($encoding === 'gzip') {
            if (!\function_exists('gzdecode') || !\function_exists('gzencode')) {
                return $this->preparationFailure(
                    $bindingRequired,
                    $mode,
                    'worker_scope_response_encoding_unsupported',
                );
            }
            $decoded = \gzdecode($wireBody, self::MAX_HTML_BODY_BYTES + 1);
            if (!\is_string($decoded) || \strlen($decoded) > self::MAX_HTML_BODY_BYTES) {
                return $this->preparationFailure(
                    $bindingRequired,
                    $mode,
                    'worker_scope_response_gzip_invalid',
                );
            }
            $html = $decoded;
        } else {
            return $this->preparationFailure(
                $bindingRequired,
                $mode,
                'worker_scope_response_encoding_unsupported',
            );
        }

        if (\stripos($html, self::META_NAME) !== false) {
            return $this->preparationFailure(
                $bindingRequired,
                $mode,
                'worker_scope_response_marker_conflict',
            );
        }

        $structure = $this->locateDocumentStructure($html);
        if ($structure === null) {
            return $this->preparationFailure(
                $bindingRequired,
                $mode,
                'worker_scope_response_incomplete_html',
            );
        }

        return [
            'html' => $html,
            'encoding' => $encoding,
            'insertion_offset' => $structure['head_content_offset'],
        ];
    }

    /** @return array{head_content_offset:int}|null */
    private function locateDocumentStructure(string $html): ?array
    {
        if (\strlen($html) > self::MAX_HTML_BODY_BYTES) {
            return null;
        }

        $length = \strlen($html);
        $offset = 0;
        $positions = [
            'html_open' => [],
            'head_open' => [],
            'head_close' => [],
            'html_close' => [],
        ];
        $headContentOffset = null;
        $rawTextNames = ['script', 'style', 'title', 'textarea'];

        while ($offset < $length) {
            $tagStart = \strpos($html, '<', $offset);
            if ($tagStart === false) {
                break;
            }
            if (\substr($html, $tagStart, 4) === '<!--') {
                $commentEnd = \strpos($html, '-->', $tagStart + 4);
                if ($commentEnd === false) {
                    return null;
                }
                $offset = $commentEnd + 3;
                continue;
            }

            $tagEnd = $this->findHtmlTagEnd($html, $tagStart + 1);
            if ($tagEnd === null) {
                return null;
            }
            $source = \ltrim(\substr($html, $tagStart + 1, $tagEnd - $tagStart - 1));
            if ($source === '' || \in_array($source[0], ['!', '?'], true)) {
                $offset = $tagEnd + 1;
                continue;
            }

            $closing = $source[0] === '/';
            if ($closing) {
                $source = \ltrim(\substr($source, 1));
            }
            if (\preg_match('/^([A-Za-z][A-Za-z0-9:-]*)/', $source, $match) !== 1) {
                $offset = $tagEnd + 1;
                continue;
            }
            $name = \strtolower($match[1]);
            $key = match ([$name, $closing]) {
                ['html', false] => 'html_open',
                ['head', false] => 'head_open',
                ['head', true] => 'head_close',
                ['html', true] => 'html_close',
                default => null,
            };
            if ($key !== null) {
                $positions[$key][] = $tagStart;
                if ($key === 'head_open') {
                    $headContentOffset = $tagEnd + 1;
                }
            }

            if (!$closing && \in_array($name, $rawTextNames, true)) {
                $rawClose = \stripos($html, '</' . $name, $tagEnd + 1);
                if ($rawClose === false) {
                    return null;
                }
                $offset = $rawClose;
                continue;
            }
            $offset = $tagEnd + 1;
        }

        foreach ($positions as $entries) {
            if (\count($entries) !== 1) {
                return null;
            }
        }
        if (!\is_int($headContentOffset)
            || $positions['html_open'][0] >= $positions['head_open'][0]
            || $positions['head_open'][0] >= $positions['head_close'][0]
            || $positions['head_close'][0] >= $positions['html_close'][0]) {
            return null;
        }

        return ['head_content_offset' => $headContentOffset];
    }

    private function findHtmlTagEnd(string $html, int $offset): ?int
    {
        $quote = null;
        $length = \strlen($html);
        for ($index = $offset; $index < $length; $index++) {
            $character = $html[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '>') {
                return $index;
            }
            if ($character === '<') {
                return null;
            }
        }
        return null;
    }

    private function injectBootstrapId(string $html, int $insertionOffset, string $bootstrapId): string
    {
        $meta = '<meta name="' . self::META_NAME . '" content="' . $bootstrapId . '">';
        $decorated = \substr_replace($html, "\n    {$meta}", $insertionOffset, 0);
        if (\substr_count($decorated, self::META_NAME) !== 1) {
            throw new FrontendWorkerScopeException(
                'worker_scope_response_marker_invalid',
                503,
                (string)__('系统正在升级维护，请稍后再试。'),
            );
        }
        return $decorated;
    }

    private function preparationFailure(
        bool $bindingRequired,
        string $mode,
        string $reason,
    ): ?array {
        if ($bindingRequired) {
            throw new FrontendWorkerScopeException(
                $reason,
                503,
                (string)__('系统正在升级维护，请稍后再试。'),
            );
        }

        $this->logShadowSkip($reason, $mode);
        return null;
    }

    private function skipOrFail(
        mixed $original,
        bool $bindingRequired,
        string $mode,
        string $reason,
    ): mixed {
        if ($bindingRequired) {
            throw new FrontendWorkerScopeException(
                $reason,
                503,
                (string)__('系统正在升级维护，请稍后再试。'),
            );
        }

        $this->logShadowSkip($reason, $mode);
        return $original;
    }

    private function logShadowSkip(string $reason, string $mode): void
    {
        \w_log_warning(
            '[FrontendWorkerScopeBootstrap] response decoration skipped: {reason} ({mode})',
            ['reason' => $reason, 'mode' => $mode],
            'worker_scope',
        );
    }

    private function isShadowSampled(int $basisPoints, ScopeIdentity $scope): bool
    {
        if ($basisPoints <= 0) {
            return false;
        }
        if ($basisPoints >= 10000) {
            return true;
        }

        $requestId = RequestContext::getRequestId() ?? '';
        $digest = \hash(
            'sha256',
            "weline-scope-shadow-sample\0" . $requestId . "\0" . $scope->toLegacyScopeString(),
            true,
        );
        $word = \unpack('Nvalue', \substr($digest, 0, 4));
        $bucket = (int)($word['value'] ?? 0) % 10000;
        return $bucket < $basisPoints;
    }

    private function applyNoStore(Response $response): void
    {
        $response->setHeader('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
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

    private function requestInput(string $name): string
    {
        $context = Context::getCurrent();
        if ($name === 'host') {
            return RequestAuthority::current();
        }

        $canonical = match ($name) {
            'method' => WelineEnv::getRequestMethod(),
            'scheme' => \strtolower(\trim(WelineEnv::getRequestScheme())),
            default => '',
        };
        if ($canonical !== '') {
            return $canonical;
        }

        if ($context === null) {
            return '';
        }

        $value = \trim((string)$context->get('input.' . $name, ''));
        if ($value !== '') {
            return $name === 'scheme' ? \strtolower($value) : $value;
        }

        return match ($name) {
            'method' => \trim((string)$context->server('REQUEST_METHOD', '')),
            'scheme' => $this->requestSchemeFromServer($context),
            'host' => \trim((string)$context->server(
                'HTTP_HOST',
                $context->server('SERVER_NAME', ''),
            )),
            default => '',
        };
    }

    private function requestSchemeFromServer(Context $context): string
    {
        $requestScheme = \strtolower(\trim((string)$context->server('REQUEST_SCHEME', '')));
        if ($requestScheme !== '') {
            return $requestScheme;
        }

        $https = \strtolower(\trim((string)$context->server('HTTPS', '')));
        return \in_array($https, ['1', 'https', 'on', 'true'], true) ? 'https' : 'http';
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
}
