<?php

declare(strict_types=1);

namespace Weline\Feed\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendPageController;

/**
 * Opens the feed list shell. Creates, edits, and deletes go through feed_admin.
 */
#[Acl('Weline_Feed::catalog_list', '购物目录', 'list', '把本站商品生成为各平台要的商品文件')]
class Catalog extends BackendPageController
{
    public function index(): string
    {
        $this->assign('page_title', '购物目录');
        $this->assign('feed_query_provider', 'feed_admin');
        $options = \w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
        $this->assign(
            'websiteSelectOptionsJson',
            json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        );
        $this->layoutType = 'default.default';

        return (string)$this->fetch();
    }
}
