<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\ThemeResourceGateway;

class ResolveThemeAssetUrl implements ObserverInterface
{
    public function __construct(
        private readonly ThemeResourceGateway $themeResourceGateway,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }

        $moduleName = trim((string)$data->getData('module_name'));
        $area = trim((string)$data->getData('area'));
        $relativePath = trim((string)$data->getData('relative_path'));
        if ($moduleName === '' || $relativePath === '') {
            return;
        }

        $url = $this->themeResourceGateway->buildThemeAssetUrl($moduleName, $area, $relativePath, null, false);
        if ($url !== '') {
            $data->setData('url', $url);
        }
    }
}

