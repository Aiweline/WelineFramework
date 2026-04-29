<?php

declare(strict_types=1);

/**
 * URL Guard 入口 Observer
 *
 * 监听 Weline_Framework_Router::before_start 事件，在路由真正解析前
 * 执行注册的 Guard 序列；越界请求直接抛 NoRouterException 拒绝并触发
 * Weline_Framework_Router::guard::overflow 事件，便于队列/CDN/告警模块订阅。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Router\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\GuardHeaders;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\UrlGuard\UrlGuardEvaluator;
use Weline\Framework\Router\UrlGuard\UrlGuardRegistry;

class UrlGuardObserver implements ObserverInterface
{
    private static ?UrlGuardRegistry $registryInstance = null;
    private static bool $registryConfigured = false;

    public function execute(Event &$event): void
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $uri = $this->resolveUri($request);
            if ($uri === '') {
                return;
            }

            $registry = $this->resolveRegistry();
            if ($registry->all() === []) {
                return;
            }

            $params = (array)$request->getParams();
            $headers = $this->resolveHeaders($request);

            $decision = (new UrlGuardEvaluator($registry))->evaluate($uri, $params, $headers);

            if (!$decision->isReject()) {
                return;
            }

            $this->dispatchOverflow($uri, $params, $decision->guardName, $decision->details);

            try {
                $response = $request->getResponse();
                if ($response !== null) {
                    GuardHeaders::writeUrlGuardDecision(
                        $response,
                        GuardHeaders::URL_GUARD_REJECTED,
                        $decision->guardName
                    );
                }
            } catch (\Throwable) {
                // 写响应头失败不应阻断 410 拒绝流程
            }

            throw new NoRouterException(
                $decision->rejectStatusCode,
                'URL out of bounds: ' . $decision->guardName
            );
        } catch (NoRouterException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 任何意外异常不应阻塞主流程，仅记录
            if (\function_exists('w_log_warning')) {
                w_log_warning('[UrlGuardObserver] unexpected error: ' . $e->getMessage());
            }
        }
    }

    /**
     * 测试 / 自定义场景下注入 Registry。
     */
    public static function setRegistry(?UrlGuardRegistry $registry): void
    {
        self::$registryInstance = $registry;
        self::$registryConfigured = $registry !== null;
    }

    private function resolveRegistry(): UrlGuardRegistry
    {
        if (self::$registryInstance !== null) {
            return self::$registryInstance;
        }

        $registry = new UrlGuardRegistry();

        $defaultsFile = (\defined('BP') ? BP : '')
            . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR
            . 'Weline' . DIRECTORY_SEPARATOR . 'Framework' . DIRECTORY_SEPARATOR
            . 'Router' . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'url_guards.php';

        if (\is_file($defaultsFile)) {
            $defaults = require $defaultsFile;
            if (\is_array($defaults)) {
                $registry->loadFromArray($defaults);
            }
        }

        try {
            $envItems = (array)(Env::getInstance()->getConfig('router.url_guards') ?? []);
            if ($envItems !== []) {
                $registry->loadFromArray($envItems);
            }
        } catch (\Throwable) {
            // env 不可用时静默
        }

        self::$registryInstance = $registry;
        self::$registryConfigured = true;
        return $registry;
    }

    private function resolveUri(Request $request): string
    {
        try {
            $uri = $request->getUri();
        } catch (\Throwable) {
            $uri = (string)\w_env('request.uri', '');
        }

        if (!\is_string($uri) || $uri === '') {
            return '';
        }

        $pos = \strpos($uri, '?');
        return $pos === false ? $uri : \substr($uri, 0, $pos);
    }

    /**
     * @return array<string, string|array<string, string>>
     */
    private function resolveHeaders(Request $request): array
    {
        try {
            $headers = $request->getHeader();
        } catch (\Throwable) {
            return [];
        }

        return \is_array($headers) ? $headers : [];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $details
     */
    private function dispatchOverflow(string $uri, array $params, string $guardName, array $details): void
    {
        try {
            ObjectManager::getInstance(EventsManager::class)->dispatch(
                'Weline_Framework_Router::guard::overflow',
                [
                    'uri' => $uri,
                    'guard_name' => $guardName,
                    'details' => $details,
                    'params_keys' => \array_keys($params),
                    'timestamp' => \time(),
                ]
            );
        } catch (\Throwable) {
            // 事件分发失败不影响 410 响应
        }
    }
}
