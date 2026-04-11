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
                    'uri' => (string)($this->serverVars['REQUEST_URI'] ?? $context->get('input.uri', '/')),
                    'method' => (string)($this->serverVars['REQUEST_METHOD'] ?? $context->get('input.method', 'GET')),
                    'scheme' => (string)($this->serverVars['REQUEST_SCHEME'] ?? $context->get('input.scheme', 'http')),
                    'host' => (string)($this->serverVars['HTTP_HOST'] ?? $this->serverVars['SERVER_NAME'] ?? $context->get('input.host', '')),
                    'ip' => (string)($this->serverVars['REMOTE_ADDR'] ?? $context->get('input.ip', '')),
                ],
                'route' => [
                    'area' => (string)($this->serverVars['WELINE_AREA'] ?? $context->get('route.area', RequestContext::AREA_FRONTEND)),
                    'area_route' => (string)($this->serverVars['WELINE_AREA_ROUTE'] ?? $context->get('route.area_route', '')),
                    'website_id' => (int)($this->serverVars['WELINE_WEBSITE_ID'] ?? $context->get('route.website_id', 0)),
                    'website_code' => (string)($this->serverVars['WELINE_WEBSITE_CODE'] ?? $context->get('route.website_code', '')),
                    'website_url' => (string)($this->serverVars['WELINE_WEBSITE_URL'] ?? $context->get('route.website_url', '')),
                    'language' => (string)($this->serverVars['WELINE_USER_LANG'] ?? $context->get('route.language', 'zh_Hans_CN')),
                    'currency' => (string)($this->serverVars['WELINE_USER_CURRENCY'] ?? $context->get('route.currency', 'CNY')),
                    'is_backend' => (bool)($this->serverVars['WELINE_IS_BACKEND']
                        ?? $context->get('route.is_backend',
                            \in_array(($this->serverVars['WELINE_AREA'] ?? ''), [RequestContext::AREA_BACKEND, RequestContext::AREA_REST_BACKEND], true))),
                    'is_static' => (bool)($this->serverVars['WELINE_IS_STATIC_FILE'] ?? $context->get('route.is_static', false)),
                    'url_parsed' => (bool)($this->serverVars['WELINE_URL_PARSED'] ?? $context->get('route.url_parsed', false)),
                ],
            ]);
            Context::enter($context);
        } else {
            Context::leave();
            Context::enter(Context::fromGlobals());
        }

        RequestContext::syncFromServer();
        $currentContext = Context::getCurrent();
        RequestContext::area((string)($this->serverVars['WELINE_AREA'] ?? $currentContext?->get('route.area', RequestContext::AREA_FRONTEND)));
        RequestContext::setWelineAreaRoute((string)($this->serverVars['WELINE_AREA_ROUTE'] ?? $currentContext?->get('route.area_route', '')));
        RequestContext::setWelineWebsiteId((int)($this->serverVars['WELINE_WEBSITE_ID'] ?? $currentContext?->get('route.website_id', 0)));
        RequestContext::setWelineWebsiteCode((string)($this->serverVars['WELINE_WEBSITE_CODE'] ?? $currentContext?->get('route.website_code', '')));
        RequestContext::setWelineWebsiteUrl((string)($this->serverVars['WELINE_WEBSITE_URL'] ?? $currentContext?->get('route.website_url', '')));
        RequestContext::locale((string)($this->serverVars['WELINE_USER_LANG'] ?? $currentContext?->get('route.language', 'zh_Hans_CN')));
        RequestContext::currency((string)($this->serverVars['WELINE_USER_CURRENCY'] ?? $currentContext?->get('route.currency', 'CNY')));

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
