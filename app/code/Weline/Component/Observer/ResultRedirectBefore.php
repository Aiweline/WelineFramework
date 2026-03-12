<?php

declare(strict_types=1);

namespace Weline\Component\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ResultManager;

/**
 * 当控制器调用 success/error/info/warning 后 redirect，且请求来自 iframe 时，
 * 通过事件获取结果桥接页 URL，将重定向目标改为该页，由桥接页通过 BackendToast 显示通知。
 */
class ResultRedirectBefore implements ObserverInterface
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
        if ($bridgeUrl === '') {
            $bridgeUrl = $urlBuilder->getBackendUrl('component/backend/offcanvas/getResult', [
                'type' => $result['type'],
                'msg' => $result['message'],
                'url' => $targetUrl,
                'reload' => $result['reload'] ? '1' : '0',
            ]);
        }

        $data->setData('url', $bridgeUrl);
    }
}
