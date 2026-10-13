<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Runtime\FrontendWorkerScopeProviderInterface;
use Weline\Framework\Runtime\InternalHomepagePrime;
use Weline\Framework\Runtime\RequestAuthority;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
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
        if ($this->alreadyDecorated()) {
            return $result;
        }
        // HTML Scope bootstrap stamp retired: anonymous FPC / generated/html must
        // stay stamp-free. Scope is issued via hole-punch API + SharedWorker.
        RequestContext::set(self::REQUEST_STATE_KEY, ['status' => 'hole_punch']);

        return $result;
    }

    private function alreadyDecorated(): bool
    {
        $state = RequestContext::get(self::REQUEST_STATE_KEY);
        if (!\is_array($state)) {
            return false;
        }
        $status = (string)($state['status'] ?? '');

        return $status === 'decorated' || $status === 'hole_punch';
    }

    private function isEligibleRequest(): bool
    {
        // bin/w finalize through response_ready under CLI SAPI with meta.type=system.
        // RequestContext defaults area to frontend, so without this guard binding-on
        // fail-closed rewrites every CLI Response body to the maintenance string.
        // PHPUnit / simulated HTTP keep meta.type=request under the same SAPI.
        if (Runtime::isCli()) {
            $cliContext = Context::getCurrent();
            $metaType = $cliContext !== null ? (string)$cliContext->get('meta.type', '') : '';
            if ($metaType === 'system' || $metaType === '') {
                return false;
            }
        }

        if (InternalHomepagePrime::hasServerOnlyMarker()) {
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

    /**
     * Recover channel ScopeIdentity for FPC/cold-disk responses that never ran
     * DetectWebsite. Owning installer stays Websites via Framework interface.
     */
    private function tryRecoverChannelScopeFromRequest(): ?ScopeIdentity
    {
        $fullUri = \trim($this->requestInput('full_request_uri'));
        if ($fullUri === '') {
            $host = \trim($this->requestInput('host'));
            if ($host === '') {
                return null;
            }
            $scheme = \trim($this->requestInput('scheme'));
            if ($scheme === '') {
                $scheme = 'https';
            }
            $uri = \trim($this->requestInput('uri'));
            if ($uri === '') {
                $uri = '/';
            }
            if ($uri[0] !== '/') {
                $uri = '/' . $uri;
            }
            $fullUri = $scheme . '://' . $host . $uri;
        }

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

    /**
     * When an early FPC/cold-disk hit cannot decorate (ineligible request Context)
     * but commerce scope binding is required, refuse the undecorated public HTML.
     */
    private function failClosedIfCachedHtmlNeedsBinding(mixed $result): void
    {
        if (!$result instanceof Response || !$this->isEligibleResponse($result)) {
            return;
        }
        if (!WelineEnv::get('response.from_cache', false)) {
            return;
        }

        $bindingRequired = false;
        try {
            $bindingRequired = $this->scopeProvider->requiresBinding($this->requestInput('scheme'));
        } catch (FrontendWorkerScopeException $exception) {
            throw $exception;
        } catch (\Weline\Framework\Runtime\RequestExitException $cancelled) {
            throw $cancelled;
        } catch (\Throwable) {
            $bindingRequired = false;
        }
        if (!$bindingRequired) {
            return;
        }

        throw new FrontendWorkerScopeException(
            'worker_scope_bootstrap_fpc_context_missing',
            503,
            \function_exists('__')
                ? (string)\__('系统正在升级维护，请稍后再试。')
                : '系统正在升级维护，请稍后再试。',
        );
    }

    private function isEligibleResponse(Response $response): bool
    {
        if (!\in_array($response->getStatusCode(), [200, 404], true)
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
        } elseif ($encoding === 'br') {
            if (!\function_exists('brotli_uncompress') || !\function_exists('brotli_compress')) {
                return $this->preparationFailure(
                    $bindingRequired,
                    $mode,
                    'worker_scope_response_encoding_unsupported',
                );
            }
            $decoded = \brotli_uncompress($wireBody);
            if (!\is_string($decoded) || \strlen($decoded) > self::MAX_HTML_BODY_BYTES) {
                return $this->preparationFailure(
                    $bindingRequired,
                    $mode,
                    'worker_scope_response_br_invalid',
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
                (string)\__('系统正在升级维护，请稍后再试。'),
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
                (string)\__('系统正在升级维护，请稍后再试。'),
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
                (string)\__('系统正在升级维护，请稍后再试。'),
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

    /**
     * Expire prior Host-scoped Scope bootstrap cookies so the browser jar only
     * retains the cookie that matches this response's meta bootstrap id.
     */
    private function expireStaleScopeBootstrapCookies(Response $response, string $keepCookieName): void
    {
        $prefix = FrontendWorkerSessionService::SCOPE_BOOTSTRAP_COOKIE_PREFIX;
        $rawHeader = (string)($_SERVER['HTTP_COOKIE'] ?? '');
        if ($rawHeader === '' || \strlen($rawHeader) > 65536) {
            return;
        }
        $seen = [];
        foreach (\explode(';', $rawHeader) as $part) {
            $pair = \explode('=', \trim($part), 2);
            if (\count($pair) < 1) {
                continue;
            }
            $name = \urldecode($pair[0]);
            if ($name === '' || isset($seen[$name]) || !\str_starts_with($name, $prefix)) {
                continue;
            }
            $seen[$name] = true;
            if ($name === $keepCookieName) {
                continue;
            }
            // __Host- cookies require Secure + Path=/ + empty Domain.
            $response->setCookie($name, '', 1, '/', '', true, true, 'Lax');
        }
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

        if ($name === 'scheme') {
            return $this->resolveRequestScheme($context);
        }

        $canonical = match ($name) {
            'method' => WelineEnv::getRequestMethod(),
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
            return $value;
        }

        return match ($name) {
            'method' => \trim((string)$context->server('REQUEST_METHOD', '')),
            'host' => \trim((string)$context->server(
                'HTTP_HOST',
                $context->server('SERVER_NAME', ''),
            )),
            default => '',
        };
    }

    /**
     * Worker speaks plain HTTP to nginx; public storefront is HTTPS via
     * X-Forwarded-Proto. Cold-disk/FPC hits must not treat the upstream hop as
     * the token scheme or Scope Kernel throws scope_token_https_required → 503.
     */
    private function resolveRequestScheme(?Context $context): string
    {
        $env = \strtolower(\trim(WelineEnv::getRequestScheme()));
        if ($env === 'https') {
            return 'https';
        }

        if ($context !== null) {
            $forwarded = \strtolower(\trim((string)$context->server('HTTP_X_FORWARDED_PROTO', '')));
            if ($forwarded !== '') {
                $forwarded = \trim(\explode(',', $forwarded, 2)[0]);
                if ($forwarded === 'https' || $forwarded === 'http') {
                    return $forwarded;
                }
            }
            $input = \strtolower(\trim((string)$context->get('input.scheme', '')));
            if ($input === 'https' || $input === 'http') {
                return $input;
            }
            $fromServer = $this->requestSchemeFromServer($context);
            if ($fromServer === 'https') {
                return 'https';
            }
        }

        return $env !== '' ? $env : 'http';
    }

    private function requestSchemeFromServer(Context $context): string
    {
        $forwarded = \strtolower(\trim((string)$context->server('HTTP_X_FORWARDED_PROTO', '')));
        if ($forwarded !== '') {
            $forwarded = \trim(\explode(',', $forwarded, 2)[0]);
            if ($forwarded === 'https' || $forwarded === 'http') {
                return $forwarded;
            }
        }

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
