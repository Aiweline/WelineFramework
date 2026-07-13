<?php

declare(strict_types=1);

namespace Weline\MediaManager\Block;

use Weline\FileManager\Api\Block\FileManager;

class WelineMedia extends FileManager
{
    protected string $_template = 'Weline_MediaManager::weline-media.phtml';
    
    public function render(): string
    {
        $params = $this->getParams();
        if ($this->request->isBackend()) {
            $connector = $this->request->getUrlBuilder()->getBackendUrl('media/backend/manager/iframe', $params, true);
        } else {
            $connector = $this->request->getUrlBuilder()->getUrl('media/frontend/manager/iframe', $params, true);
        }
        $this->assign('connector', $connector);
        return parent::render();
    }
}
