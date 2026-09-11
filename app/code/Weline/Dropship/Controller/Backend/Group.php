<?php

declare(strict_types=1);

namespace Weline\Dropship\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

/**
 * ACL parent node for 货源代发 menu group (no UI action).
 */
#[Acl('Weline_Dropship::commerce:dropship:group', '货源代发', 'package', '货源代发菜单组')]
class Group extends BackendController
{
    public function index(): string
    {
        return $this->redirect('*/backend/channel/index');
    }
}
