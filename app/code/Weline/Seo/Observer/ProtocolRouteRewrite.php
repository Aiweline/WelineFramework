<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProtocolRouteRewrite implements ObserverInterface
{
    private const ROUTES = [
        'robots.txt' => 'seo/protocol/robots',
        'robots.xml' => 'seo/protocol/robots',
        'sitemap.xml' => 'seo/protocol/sitemap',
    ];

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $path = strtolower(trim((string)$data->getData('path'), '/'));
        if (!isset(self::ROUTES[$path])) {
            return;
        }

        $data->setData('path', self::ROUTES[$path]);
        $data->setData('rule', new DataObject());
    }
}
