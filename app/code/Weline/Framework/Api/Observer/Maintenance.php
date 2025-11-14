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
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;

/**
 * 维护模式下 API 请求统一响应处理
 */
class Maintenance implements ObserverInterface
{
    private const DEFAULT_RETRY_AFTER = 60;

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        /** @var Request $request */
        $request = ObjectManager::getInstance(Request::class);

        // 仅处理 API 请求
        if (!$request->isApiFrontend() && !$request->isApiBackend()) {
            return;
        }

        /** @var DataObject|null $data */
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $whiteUrls = $data->getData('white_urls') ?? [];
        $uri = $request->getUri();

        foreach ($whiteUrls as $whiteUrl) {
            $whiteUrl = (string)$whiteUrl;
            if ($whiteUrl !== '' && str_contains($uri, $whiteUrl)) {
                // 已在白名单中，直接放行
                return;
            }
        }

        // 同步白名单数据，避免其他观察者覆盖
        $data->setData('white_urls', $whiteUrls);

        /** @var Response $response */
        $response = ObjectManager::getInstance(Response::class);

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
                'request_id' => $request->getId(),
            ],
        ];

        $response->setHttpResponseCode(503);
        $response->setHeader('Content-Type', 'application/json');
        $response->setHeader('Retry-After', (string)$retryAfter);
        $response->setBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $response->sendResponse();
    }
}



