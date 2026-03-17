<?php

declare(strict_types=1);

namespace Weline\MediaManager\Block;

use Weline\FileManager\Block\FileManager;

class WelineMedia extends FileManager
{
    protected string $_template = 'Weline_MediaManager::weline-media.phtml';
    
    public function render(): string
    {
        $params = $this->getParams();
        if ($this->request->isBackend()) {
            // 使用 media/backend/manager?iframe=1 避免 media/backend/manager/iframe 路由未注册导致 404
            $params['iframe'] = '1';
            $connector = $this->request->getUrlBuilder()->getBackendUrl('media/backend/manager', $params, true);
        } else {
            $connector = $this->request->getUrlBuilder()->getUrl('media/frontend/manager/iframe', $params, true);
        }
        $this->assign('connector', $connector);
        return parent::render();
    }
}
