<?php

declare(strict_types=1);

namespace Weline\Component\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ResultManager;

/**
 * 为结果桥接页提供默认 URL（Component Offcanvas getResult）。
 * 监听 Weline_Framework_Manager::result_bridge_url，设置 data['bridge_url']。
 */
class ResultBridgeUrlProvider implements ObserverInterface
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if ($data === null || !method_exists($data, 'getData')) {
            return;
        }
        if ((string) $data->getData('bridge_url') !== '') {
            return;
        }
        $urlBuilder = $this->request->getUrlBuilder();
        $bridgeUrl = $urlBuilder->getBackendUrl('component/backend/offcanvas/getResult', [
            'type' => $data->getData('type'),
            'msg' => $data->getData('message'),
            'url' => $data->getData('target_url'),
            'reload' => $data->getData('reload') ? '1' : '0',
        ]);
        $data->setData('bridge_url', $bridgeUrl);
    }
}
