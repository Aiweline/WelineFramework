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
        // Picker UI only exists as Backend\\Manager::getIframe. Preferring a
        // storefront manager URL when the request area is not backend (e.g. nested
        // widget paramrender) made the modal iframe load the shop 404 page.
        // Relative getBackendUrlPath keeps the iframe on the parent origin
        // (avoids http/https cookie / postMessage mismatch).
        // Do not merge the parent workbench query (public_id, preview_page_type, …)
        // into the iframe connector — those params confuse MediaManager routing.
        $connector = $this->request->getUrlBuilder()->getBackendUrlPath(
            'media/backend/manager/iframe',
            $params,
            false
        );
        $bindUrl = $this->request->getUrlBuilder()->getBackendUrlPath(
            'weline_filemanager/backend/media-reference/bind',
            [],
            false
        );
        $this->assign('connector', $connector);
        $this->assign('bind_url', $bindUrl);
        return parent::render();
    }
}
