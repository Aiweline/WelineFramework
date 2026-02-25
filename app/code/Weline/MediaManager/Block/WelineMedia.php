<?php

declare(strict_types=1);

namespace Weline\MediaManager\Block;

use Weline\FileManager\Block\FileManager;
use Weline\FileManager\Helper\Image;

class WelineMedia extends FileManager
{
    protected string $_template = 'Weline_MediaManager::weline-media.phtml';
    public function render(): string
    {
        if ($this->request->isBackend()) {
            $connector = $this->request->getUrlBuilder()->getBackendUrl('media/backend/manager/iframe', $this->getParams(), true);
        } else {
            $connector = $this->request->getUrlBuilder()->getUrl('media/frontend/manager/iframe', $this->getParams(), true);
        }
        $this->assign('connector', $connector);
        return parent::render();
    }
}
