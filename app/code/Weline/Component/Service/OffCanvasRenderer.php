<?php

declare(strict_types=1);

namespace Weline\Component\Service;

use Weline\Component\Api\OffCanvasRendererInterface;
use Weline\Component\Block\OffCanvas;
use Weline\Framework\Manager\ObjectManager;

final class OffCanvasRenderer implements OffCanvasRendererInterface
{
    public function render(array $data): string
    {
        /** @var OffCanvas $block */
        $block = ObjectManager::getInstance(OffCanvas::class, ['data' => $data]);
        $block->__init();
        return $block->render();
    }
}
