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
    private array $registeredStaticState = [];

    private ?object $request = null;
    private ?array $contextSnapshot = null;
    private ?\WeakReference $targetFiber = null;

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
        $ctx = self::captureProcessProjection();

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

    /**
     * Capture the process-level compatibility projection for a suspended
     * request Fiber. The target Fiber already owns its Context,
     * HeaderCollector and ObjectManager request scope in WeakMaps, so those
     * stores must not be copied through the event-loop main context.
     */
    public static function captureForFiber(\Fiber $fiber): self
    {
        if (!$fiber->isSuspended()) {
            throw new \LogicException('WLS Fiber context can only be captured for a suspended Fiber.');
        }
        if (Context::getForFiber($fiber) === null) {
            throw new \LogicException('Suspended WLS request Fiber has no owned Context.');
        }

        $ctx = self::captureProcessProjection();
        $ctx->targetFiber = \WeakReference::create($fiber);

        try {
            $ctx->contextSnapshot = Context::getForFiber($fiber)?->toArray();
        } catch (\Throwable) {
            $ctx->contextSnapshot = null;
        }

        return $ctx;
    }

    public function restore(bool $restoreResponseState = true): void
    {
        $restoredServer = $this->restoreProcessProjection();

        if ($this->contextSnapshot !== null) {
            $context = new Context($this->contextSnapshot);
            $context->merge([
                'input' => [
                    'query' => $this->getVars,
                    'post' => $this->postVars,
                    'cookie' => $this->cookieVars,
                    'files' => $this->filesVars,
                    'server' => $restoredServer,
                    'uri' => (string)($context->get('input.uri', $restoredServer['REQUEST_URI'] ?? '/')),
                    'method' => (string)($context->get('input.method', $restoredServer['REQUEST_METHOD'] ?? 'GET')),
                    'scheme' => (string)($context->get('input.scheme', $restoredServer['REQUEST_SCHEME'] ?? 'http')),
                    'host' => (string)($context->get('input.host', $restoredServer['HTTP_HOST'] ?? $restoredServer['SERVER_NAME'] ?? '')),
                    'ip' => (string)($context->get('input.ip', $restoredServer['REMOTE_ADDR'] ?? '')),
                ],
                'route' => [
                    'area' => (string)($context->get('route.area', $restoredServer['WELINE_AREA'] ?? RequestContext::AREA_FRONTEND)),
                    'area_route' => (string)($context->get('route.area_route', $restoredServer['WELINE_AREA_ROUTE'] ?? '')),
                    'website_id' => (int)($context->get('route.website_id', $restoredServer['WELINE_WEBSITE_ID'] ?? 0)),
                    'website_code' => (string)($context->get('route.website_code', $restoredServer['WELINE_WEBSITE_CODE'] ?? '')),
                    'website_url' => (string)($context->get('route.website_url', $restoredServer['WELINE_WEBSITE_URL'] ?? '')),
                    'store_id' => (int)($context->get('route.store_id', $restoredServer['WELINE_STORE_ID'] ?? 0)),
                    'store_code' => (string)($context->get('route.store_code', $restoredServer['WELINE_STORE_CODE'] ?? '')),
                    'store_mode' => (string)($context->get('route.store_mode', $restoredServer['WELINE_STORE_MODE'] ?? '')),
                    'channel_id' => (int)($context->get('route.channel_id', $restoredServer['WELINE_CHANNEL_ID'] ?? 0)),
                    'channel_code' => (string)($context->get('route.channel_code', $restoredServer['WELINE_CHANNEL_CODE'] ?? '')),
                    'language' => (string)($context->get('route.language', $restoredServer['WELINE_USER_LANG'] ?? 'zh_Hans_CN')),
                    'currency' => (string)($context->get('route.currency', $restoredServer['WELINE_USER_CURRENCY'] ?? 'CNY')),
                    'is_backend' => (bool)($context->get(
                        'route.is_backend',
                        $restoredServer['WELINE_IS_BACKEND']
                            ?? \in_array(($context->get('route.area', $restoredServer['WELINE_AREA'] ?? '')), [RequestContext::AREA_BACKEND, RequestContext::AREA_REST_BACKEND], true)
                    )),
                    'is_static' => (bool)($context->get('route.is_static', $restoredServer['WELINE_IS_STATIC_FILE'] ?? false)),
                    'url_parsed' => (bool)($context->get('route.url_parsed', $restoredServer['WELINE_URL_PARSED'] ?? false)),
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

    /**
     * Restore only the process-level projection before resuming a target
     * Fiber. Its Fiber-local Context, HeaderCollector and ObjectManager scope
     * remain authoritative and are deliberately untouched here.
     */
    public function restoreForFiber(\Fiber $fiber): void
    {
        if ($this->targetFiber !== null && $this->targetFiber->get() !== $fiber) {
            throw new \LogicException('WLS Fiber context does not belong to the supplied Fiber.');
        }
        if (Context::getForFiber($fiber) === null) {
            throw new \LogicException('WLS request Fiber has no owned Context before resume.');
        }

        $this->restoreProcessProjection();
    }

    private static function captureProcessProjection(): self
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
        $ctx->registeredStaticState = StateManager::captureRegisteredStaticState();

        return $ctx;
    }

    /** @return array<string, mixed> */
    private function restoreProcessProjection(): array
    {
        StateManager::restoreRegisteredStaticState($this->registeredStaticState);
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

        $restoredServer = $this->serverVars;
        if ($this->contextSnapshot !== null) {
            $capturedInput = $this->contextSnapshot['input'] ?? null;
            $capturedContextServer = \is_array($capturedInput)
                && \is_array($capturedInput['server'] ?? null)
                ? $capturedInput['server']
                : [];
            $restoredServer = \array_replace($restoredServer, $capturedContextServer);
            if (!\array_key_exists('REQUEST_URI', $capturedContextServer)
                && \is_array($capturedInput)
                && \array_key_exists('uri', $capturedInput)) {
                $restoredServer['REQUEST_URI'] = (string)$capturedInput['uri'];
            }
        }

        $_SERVER = $restoredServer;
        $_GET = $this->getVars;
        $_POST = $this->postVars;
        $_COOKIE = $this->cookieVars;
        $_REQUEST = $this->requestVars;
        $_FILES = $this->filesVars;

        Url::resetWlsFiberInterleavedParserScratch();

        return $restoredServer;
    }

    public function getSseConnection(): mixed
    {
        return $this->sseConnection;
    }
}
