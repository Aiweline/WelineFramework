<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ResultManager;

/**
 * 结果桥接重定向（Framework 层）
 *
 * 当控制器调用 resultSuccess/resultError/resultInfo/resultWarning 后 redirect，且请求为后台 iframe 时，
 * 通过事件 Weline_Framework_Manager::result_bridge_url 获取桥接页 URL，将重定向目标改为该页。
 * 桥接页具体地址由观察者通过事件返回（如 Component 的 Offcanvas getResult），Framework 不写死任何默认 URL。
 */
class ResultBridgeRedirect implements ObserverInterface
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function execute(Event &$event): void
    {
        if (!$this->request->isBackend() || !$this->request->isIframe()) {
            return;
        }

        $result = ResultManager::getAndClear();
        if ($result === null) {
            return;
        }

        $data = $event->getData('data');
        if ($data === null || !method_exists($data, 'getData')) {
            return;
        }
        $originalUrl = (string) ($data->getData('url') ?? '');
        if ($originalUrl === '') {
            return;
        }

        $urlBuilder = $this->request->getUrlBuilder();
        $targetUrl = str_starts_with($originalUrl, 'http') ? $originalUrl : $urlBuilder->getBackendUrl($originalUrl);

        $bridgeData = new DataObject([
            'type' => $result['type'],
            'message' => $result['message'],
            'target_url' => $targetUrl,
            'reload' => $result['reload'],
            'bridge_url' => '',
        ]);
        $eventData = new DataObject(['data' => $bridgeData]);
        ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class)
            ->dispatch(ResultManager::EVENT_RESULT_BRIDGE_URL, $eventData);

        $bridgeUrl = (string) $bridgeData->getData('bridge_url');
        if ($bridgeUrl !== '') {
            $data->setData('url', $bridgeUrl);
        }
    }
}
