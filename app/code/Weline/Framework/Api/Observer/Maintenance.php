<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Api\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 维护模式下 API 请求统一响应处理
 */
class Maintenance implements ObserverInterface
{
    private const DEFAULT_RETRY_AFTER = 60;
    private const OPTIONAL_MAINTENANCE_URL_PARSER = 'Weline\\Maintenance\\Helper\\UrlParser';

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 在 run_before 阶段，使用轻量级 URL 解析器判断是否是 API 请求（不触发事件，不查询数据库）
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $isApiRequest = $this->isApiRequest($uri);
        
        // 如果 area 不是 API，再检查 Accept 头（兼容某些特殊情况）
        if (!$isApiRequest) {
            $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
            $isApiRequest = str_contains($acceptHeader, 'application/json');
        }
        
        // 开发环境下：通过查询参数 ?api=1 可以测试 API 维护模式响应
        if (!$isApiRequest && defined('DEV') && DEV) {
            $queryString = $_SERVER['QUERY_STRING'] ?? '';
            parse_str($queryString, $queryParams);
            if (isset($queryParams['api']) && ($queryParams['api'] === '1' || $queryParams['api'] === 'true')) {
                $isApiRequest = true;
            }
        }
        
        // 仅处理 API 请求
        if ($isApiRequest) {
             /** @var DataObject|null $data */
            $data = $event->getData('data');
            if (!$data instanceof DataObject) {
                return;
            }

            $whiteUrls = $data->getData('white_urls') ?? [];
            // 使用 $_SERVER 获取 URI，避免 Request 对象未初始化的问题
            $uri = $_SERVER['REQUEST_URI'] ?? '';

            foreach ($whiteUrls as $whiteUrl) {
                $whiteUrl = (string)$whiteUrl;
                if ($whiteUrl !== '' && str_contains($uri, $whiteUrl)) {
                    // 已在白名单中，直接放行
                    return;
                }
            }

            // 同步白名单数据，避免其他观察者覆盖
            $data->setData('white_urls', $whiteUrls);

            // 获取语言（从事件数据中读取，如果事件数据中有的话）
            $lang = $data->getData('language') ?? $_SERVER['WELINE_USER_LANG'] ?? $_COOKIE['WELINE_USER_LANG'] ?? 'zh_Hans_CN';
            // 设置语言到 $_SERVER，以便翻译函数能够使用正确的语言
            $_SERVER['WELINE_USER_LANG'] = $lang;

            $retryAfter = (int)(Env::get('maintenance.retry_after', self::DEFAULT_RETRY_AFTER));
            if ($retryAfter <= 0) {
                $retryAfter = self::DEFAULT_RETRY_AFTER;
            }

            $payload = [
                'success' => false,
                'code' => 'maintenance',
                'message' => __('系统正在升级，请稍后再试。'),
                'data' => [
                    'retry_after' => $retryAfter,
                    'request_id' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
                ],
            ];

            // 标记为已处理，阻止 MaintenanceInterceptor 继续执行
            $data->setData('handled', true);

            // 直接输出 JSON，避免使用 Response 对象（可能未初始化）
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $retryAfter);
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    private function isApiRequest(string $uri): bool
    {
        $parserClass = self::OPTIONAL_MAINTENANCE_URL_PARSER;
        if (class_exists($parserClass) && method_exists($parserClass, 'isApiRequest')) {
            return (bool)$parserClass::isApiRequest($uri);
        }

        $normalizedUri = trim((string)$uri, '/');
        if ($normalizedUri === '') {
            return false;
        }

        return str_contains($normalizedUri, '/api/')
            || str_starts_with($normalizedUri, 'api/')
            || str_contains($normalizedUri, '/rest/');
    }
}



