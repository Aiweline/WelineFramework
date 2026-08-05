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
        if (isset(self::ROUTES[$path])) {
            $data->setData('path', self::ROUTES[$path]);
            $data->setData('rule', new DataObject());
            return;
        }

        if (preg_match('#^sitemaps/[A-Za-z0-9_-]+/[A-Za-z0-9_-]+/[A-Za-z0-9._-]+\.xml$#D', $path) === 1) {
            $data->setData('seo_sitemap_file', $path);
            $data->setData('path', 'seo/protocol/sitemapFile');
            $data->setData('rule', new DataObject());
        }
    }
}
