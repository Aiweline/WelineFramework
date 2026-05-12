<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class ProtocolRouteRewrite implements ObserverInterface
{
    private const ROUTES = [
        'llms.txt' => 'geo/protocol/llms',
        'ai.txt' => 'geo/protocol/llms',
        'llms-full.txt' => 'geo/protocol/llmsfull',
        'geo-feed.json' => 'geo/protocol/feedjson',
        'geo-feed.xml' => 'geo/protocol/feedxml',
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
