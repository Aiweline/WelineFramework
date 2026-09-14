<?php

declare(strict_types=1);

namespace Weline\Faq\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Faq::config', 'FAQ 配置', 'mdi-cog', '商品详情 FAQ 范围配置（SystemConfig）', 'Weline_Backend::cms_group')]
final class Config extends BackendController
{
    #[Acl('Weline_Faq::config_view', '查看 FAQ 配置', 'mdi-cog', '打开系统配置中的商品 FAQ')]
    public function getIndex(): string
    {
        return $this->redirect($this->getUrl('weline_systemconfig/backend/config', [
            'module' => 'Weline_Faq',
        ]));
    }
}
