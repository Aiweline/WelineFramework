<?php
declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * 配送系统管理聚合页
 *
 * 地区管理 | 快递公司 | 费用模板 | 免邮规则 | 配送服务 | 物流跟踪
 * Tab 聚合，URL 持久化 ?tab=region|carrier|...
 *
 * @package Weline_Shipping
 */
#[Acl('Weline_Shipping::shipping_system', '配送系统', 'truck', '配送系统管理聚合页', 'Weline_Backend::shipping_group')]
class Manager extends BackendController
{
    /**
     * 聚合页：6 个 Tab（已移除遗留配送区域 Zone）
     */
    #[Acl('Weline_Shipping::shipping_system_index', '查看配送系统', 'grid', '查看配送系统聚合页')]
    public function index(): string
    {
        $tab = (string) $this->request->getGet('tab', 'region');
        $allowedTabs = [
            'region',
            'carrier',
            'ratetemplate',
            'freeshippingrule',
            'shippingservice',
            'tracking',
        ];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'region';
        }
        $this->assign('activeTab', $tab);
        return $this->fetch();
    }
}
