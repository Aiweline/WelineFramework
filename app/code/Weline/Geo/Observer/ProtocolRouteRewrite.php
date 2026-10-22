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
        'llms-full.txt' => 'geo/protocol/llmsfull',
        // These documents share the already-registered llms controller, which
        // switches on the public path. New controller routes would stay 404
        // until a router collect, and must not be required for discovery files.
        'ai.txt' => 'geo/protocol/llms',
        'license.xml' => 'geo/protocol/llms',
        'trust.txt' => 'geo/protocol/llms',
        '.well-known/tdmrep.json' => 'geo/protocol/llms',
        '.well-known/trust.txt' => 'geo/protocol/llms',
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
