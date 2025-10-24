<?php

namespace Weline\EditorManager\Block;

use Weline\Framework\View\Block;

class EditorManager extends Block
{
    public function doc(): string
    {
        return \Weline\EditorManager\Taglib\EditorManager::document();
    }
}
