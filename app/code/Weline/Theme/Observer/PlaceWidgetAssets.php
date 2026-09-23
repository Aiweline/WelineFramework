<?php
declare(strict_types=1);
namespace Weline\Theme\Observer;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\LayoutEntity\WidgetAssetHtmlPlacement;
final class PlaceWidgetAssets implements ObserverInterface
{
    public function __construct(private readonly WidgetAssetHtmlPlacement $placement) {}
    public function execute(Event &$event): void
    {
        $html = (string)$event->getData('content');
        if ($html !== '') {
            $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            $area = ($request->isBackend() || $request->isApiBackend()) ? 'backend' : 'frontend';
            $options = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Service\ThemeResourceConfig::class)->resolve(null, $area);
            $options['_area'] = $area;
            $event->setData('content', $this->placement->inject($html, '', $options));
        }
    }
}
