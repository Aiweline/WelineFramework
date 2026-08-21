<?php

declare(strict_types=1);

namespace Weline\Websites\Controller\Admin;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Product\Api\Data\CopyDraft;

/**
 * Store 商品复制向导入口。
 *
 * 页面只发布 UI 常量；所有浏览器业务动作通过 Product 自有
 * `product_copy` QueryProvider 进入 ProductCopyService。
 */
#[Acl('Weline_Websites::store_copy', 'Store 复制向导', 'copy', '从目录/他店复制经营投影', 'Weline_Websites::website_service')]
class StoreCopy extends BackendController
{
    #[Acl('Weline_Websites::store_copy_wizard', '打开复制向导', 'circle', 'Store 复制向导')]
    public function wizard()
    {
        $this->assign('entries', [
            CopyDraft::ENTRY_BLANK => (string)__('空白 Store'),
            CopyDraft::ENTRY_SITE_PULL => (string)__('站点目录拉取'),
            CopyDraft::ENTRY_STORE_INHERIT => (string)__('他店继承'),
        ]);
        $this->assign('packages', [
            CopyDraft::PKG_IDENTITY,
            CopyDraft::PKG_ATTRS,
            CopyDraft::PKG_PRICE,
            CopyDraft::PKG_MEDIA,
            CopyDraft::PKG_INVENTORY,
        ]);
        $this->assign('duplicate_policies', [
            CopyDraft::POLICY_SKIP => (string)__('跳过既有商品'),
            CopyDraft::POLICY_UPDATE => (string)__('仅更新所选字段包'),
        ]);
        $this->assign('copy_guide', 'Weline_Product::doc/copy-guide.md');
        return $this->fetch();
    }
}
