<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Http\Sse\SseContext;
use Weline\Framework\Manager\ObjectManager;

/**
 * WLS Fiber 请求级上下文
 *
 * 每个 Fiber（请求）suspend 前调用 capture() 保存当前请求状态快照，
 * resume 前调用 restore() 恢复该 Fiber 的独立上下文，防止多 Fiber 并发时状态互相覆盖。
 *
 * 审计提示：未纳入快照的进程级状态仍可能串请求，须注册 StateManager::registerResetCallback
 * / registerStaticReset，或改为 RequestContext::set（Fiber 下走 WeakMap）。
 * 典型风险：仅用 static 存请求缓存、Session 引用缓存、HeaderCollector 以外的自定义单例。
 * Url 解析可变静态见 {@see Url::resetWlsFiberInterleavedParserScratch()}。
 */
class WlsFiberContext
{
    /** @var resource|null SSE 连接资源 */
    private mixed $sseConnection;
    private bool $sseEnabled;
    private bool $sseHeadersSent;
    private mixed $sseWriteCallback = null;

    private array $serverVars;
    private array $getVars;
    private array $postVars;
    private array $cookieVars;
    private array $requestVars;
    /** @var array<mixed> */
    private array $filesVars;

    /** Request 对象引用（WlsRequest 或 Request），使用引用以兼容 ObjectManager::setInstance */
    private ?object $request = null;

    /** RequestContext 存储快照 */
    private ?string $requestId;
    /** @var array{headers: array<string, string|array>, cookies: array<string, array<string, mixed>>, status_code: int, status_code_explicit: bool} */
    private array $headerCollectorState = [
        'headers' => [],
        'cookies' => [],
        'status_code' => 200,
        'status_code_explicit' => false,
    ];

    private function __construct() {}

    /**
     * 从当前全局环境快照：suspend 前调用
     */
    public static function capture(): self
    {
        $ctx = new self();

        $ctx->sseConnection = SseContext::getConnection();
        $ctx->sseEnabled = SseContext::isSseEnabled();
        $ctx->sseHeadersSent = SseContext::isHeadersSent();
        $ctx->sseWriteCallback = SseContext::getWriteCallback();

        $ctx->serverVars = $_SERVER;
        $ctx->getVars = $_GET;
        $ctx->postVars = $_POST;
        $ctx->cookieVars = $_COOKIE;
        $ctx->requestVars = $_REQUEST;
        $ctx->filesVars = $_FILES;

        try {
            $ctx->request = ObjectManager::getInstance(Request::class);
        } catch (\Throwable) {
            $ctx->request = null;
        }

        $ctx->requestId = RequestContext::getId();
        $ctx->headerCollectorState = HeaderCollector::getInstance()->captureState();

        return $ctx;
    }

    /**
     * 将此快照恢复到全局环境：resume 前调用
     */
    public function restore(bool $restoreResponseState = true): void
    {
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

        RequestContext::syncFromServer();

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

        if ($this->requestId !== null) {
            RequestContext::setId($this->requestId);
        }
        if ($restoreResponseState) {
            HeaderCollector::getInstance()->restoreState($this->headerCollectorState);
        }
    }

    /**
     * 获取 SSE 连接（用于 Worker 检查连接状态等场景）
     */
    public function getSseConnection(): mixed
    {
        return $this->sseConnection;
    }
}
