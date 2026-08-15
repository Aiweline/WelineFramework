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
            // Relative path keeps the picker iframe on the parent page origin.
            // Absolute getBackendUrl() can flip to https while the workbench stays on
            // http (or the reverse), which breaks session cookies / postMessage and
            // surfaces as a blank modal or login page inside the iframe.
            // Do not merge the parent workbench query (public_id, preview_page_type, …)
            // into the iframe connector — those params confuse MediaManager routing.
            $connector = $this->request->getUrlBuilder()->getBackendUrlPath(
                'media/backend/manager/iframe',
                $params,
                false
            );
        } else {
            $full = $this->request->getUrlBuilder()->getUrl('media/frontend/manager/iframe', $params, true);
            $pathPart = \parse_url($full, PHP_URL_PATH);
            $query = \parse_url($full, PHP_URL_QUERY);
            $connector = ($pathPart ?? '') . ($query !== null && $query !== '' ? '?' . $query : '');
        }
        $this->assign('connector', $connector);
        return parent::render();
    }
}
