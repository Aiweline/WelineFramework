<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;

/**
 * Transitional fiber-context bridge for WLS.
 *
 * It now keeps only the thin compatibility pieces still needed while the
 * framework moves toward Context as the real source of request state.
 */
class WlsFiberContext
{
    private mixed $sseConnection;
    private bool $sseEnabled;
    private bool $sseHeadersSent;
    private mixed $sseWriteCallback = null;
    private mixed $sseAliveCallback = null;

    private array $serverVars = [];
    private array $getVars = [];
    private array $postVars = [];
    private array $cookieVars = [];
    private array $requestVars = [];
    private array $filesVars = [];

    private ?object $request = null;
    private ?array $contextSnapshot = null;

    /** @var array{headers: array<string, string|array>, cookies: array<string, array<string, mixed>>, status_code: int, status_code_explicit: bool} */
    private array $headerCollectorState = [
        'headers' => [],
        'cookies' => [],
        'status_code' => 200,
        'status_code_explicit' => false,
    ];

    private function __construct()
    {
    }

    public static function capture(): self
    {
        $ctx = new self();

        $ctx->sseConnection = SseContext::getConnection();
        $ctx->sseEnabled = SseContext::isSseEnabled();
        $ctx->sseHeadersSent = SseContext::isHeadersSent();
        $ctx->sseWriteCallback = SseContext::getWriteCallback();
        $ctx->sseAliveCallback = SseContext::getAliveCallback();

        $ctx->serverVars = \is_array($_SERVER ?? null) ? $_SERVER : [];
        $ctx->getVars = \is_array($_GET ?? null) ? $_GET : [];
        $ctx->postVars = \is_array($_POST ?? null) ? $_POST : [];
        $ctx->cookieVars = \is_array($_COOKIE ?? null) ? $_COOKIE : [];
        $ctx->requestVars = \is_array($_REQUEST ?? null) ? $_REQUEST : [];
        $ctx->filesVars = \is_array($_FILES ?? null) ? $_FILES : [];

        try {
            $ctx->request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            $ctx->request = null;
        }

        try {
            $ctx->contextSnapshot = Context::getCurrent()?->toArray();
        } catch (\Throwable) {
            $ctx->contextSnapshot = null;
        }

        $ctx->headerCollectorState = HeaderCollector::getInstance()->captureState();

        return $ctx;
    }

    public function restore(bool $restoreResponseState = true): void
    {
        SseContext::reset();
        SseContext::setConnection($this->sseConnection);
        if (\is_callable($this->sseWriteCallback)) {
            SseContext::setWriteCallback($this->sseWriteCallback);
        } else {
            SseContext::clearWriteCallback();
        }
        if (\is_callable($this->sseAliveCallback)) {
            SseContext::setAliveCallback($this->sseAliveCallback);
        } else {
            SseContext::clearAliveCallback();
        }
        if ($this->sseEnabled) {
            SseContext::enableSse();
        }
        if ($this->sseHeadersSent) {
            SseContext::markHeadersSent();
        }

        $_SERVER = $this->serverVars;
        $_GET = $this->getVars;
        $_POST = $this->postVars;
        $_COOKIE = $this->cookieVars;
        $_REQUEST = $this->requestVars;
        $_FILES = $this->filesVars;

        Url::resetWlsFiberInterleavedParserScratch();

        if ($this->contextSnapshot !== null) {
            $context = new Context($this->contextSnapshot);
            $context->merge([
                'input' => [
                    'query' => $this->getVars,
                    'post' => $this->postVars,
                    'cookie' => $this->cookieVars,
                    'files' => $this->filesVars,
                    'server' => $this->serverVars,
                    'uri' => (string)($context->get('input.uri', $this->serverVars['REQUEST_URI'] ?? '/')),
                    'method' => (string)($context->get('input.method', $this->serverVars['REQUEST_METHOD'] ?? 'GET')),
                    'scheme' => (string)($context->get('input.scheme', $this->serverVars['REQUEST_SCHEME'] ?? 'http')),
                    'host' => (string)($context->get('input.host', $this->serverVars['HTTP_HOST'] ?? $this->serverVars['SERVER_NAME'] ?? '')),
                    'ip' => (string)($context->get('input.ip', $this->serverVars['REMOTE_ADDR'] ?? '')),
                ],
                'route' => [
                    'area' => (string)($context->get('route.area', $this->serverVars['WELINE_AREA'] ?? RequestContext::AREA_FRONTEND)),
                    'area_route' => (string)($context->get('route.area_route', $this->serverVars['WELINE_AREA_ROUTE'] ?? '')),
                    'website_id' => (int)($context->get('route.website_id', $this->serverVars['WELINE_WEBSITE_ID'] ?? 0)),
                    'website_code' => (string)($context->get('route.website_code', $this->serverVars['WELINE_WEBSITE_CODE'] ?? '')),
                    'website_url' => (string)($context->get('route.website_url', $this->serverVars['WELINE_WEBSITE_URL'] ?? '')),
                    'language' => (string)($context->get('route.language', $this->serverVars['WELINE_USER_LANG'] ?? 'zh_Hans_CN')),
                    'currency' => (string)($context->get('route.currency', $this->serverVars['WELINE_USER_CURRENCY'] ?? 'CNY')),
                    'is_backend' => (bool)($context->get(
                        'route.is_backend',
                        $this->serverVars['WELINE_IS_BACKEND']
                            ?? \in_array(($context->get('route.area', $this->serverVars['WELINE_AREA'] ?? '')), [RequestContext::AREA_BACKEND, RequestContext::AREA_REST_BACKEND], true)
                    )),
                    'is_static' => (bool)($context->get('route.is_static', $this->serverVars['WELINE_IS_STATIC_FILE'] ?? false)),
                    'url_parsed' => (bool)($context->get('route.url_parsed', $this->serverVars['WELINE_URL_PARSED'] ?? false)),
                ],
            ]);
            Context::enter($context);
            RequestContext::syncFromContext($context);
        } else {
            Context::leave();
            Context::enter(Context::fromGlobals());
            RequestContext::syncFromServer();
        }

        if ($this->request !== null) {
            ObjectManager::setInstance(Request::class, $this->request);
            try {
                $resolvedClass = ObjectManager::parserClass(Request::class);
                if ($resolvedClass !== Request::class) {
                    ObjectManager::setInstance($resolvedClass, $this->request);
                }
            } catch (\Throwable) {
            }
        } else {
            ObjectManager::removeInstance(Request::class);
            try {
                $resolvedClass = ObjectManager::parserClass(Request::class);
                if ($resolvedClass !== Request::class) {
                    ObjectManager::removeInstance($resolvedClass);
                }
            } catch (\Throwable) {
            }
        }

        if ($restoreResponseState) {
            HeaderCollector::getInstance()->restoreState($this->headerCollectorState);
        }
    }

    public function getSseConnection(): mixed
    {
        return $this->sseConnection;
    }
}
