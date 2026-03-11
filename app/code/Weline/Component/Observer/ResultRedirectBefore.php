<?php

declare(strict_types=1);

namespace Weline\Component\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ResultManager;

/**
 * 当控制器调用 success/error/info 后 redirect，且请求来自 iframe 时，
 * 将重定向目标改为结果桥接页，由桥接页通过 BackendToast 显示通知。
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
        $bridgeUrl = $urlBuilder->getBackendUrl('component/backend/offcanvas/getResult', [
            'type' => $result['type'],
            'msg' => $result['message'],
            'url' => $targetUrl,
            'reload' => $result['reload'] ? '1' : '0',
        ]);

        $data->setData('url', $bridgeUrl);
    }
}
