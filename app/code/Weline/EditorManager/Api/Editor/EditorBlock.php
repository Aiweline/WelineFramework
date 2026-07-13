<?php

declare(strict_types=1);

namespace Weline\EditorManager\Api\Editor;

use Weline\Framework\View\Block;

/** Stable block base for editor UI integrations. */
abstract class EditorBlock extends Block
{
    public function doc(): string
    {
        return \Weline\EditorManager\Taglib\EditorManager::document();
    }
}
