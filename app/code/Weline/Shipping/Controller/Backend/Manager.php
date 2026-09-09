<?php
declare(strict_types=1);

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

/**
 * 配送系统管理入口
 *
 * 真链跳转到分区页（地区 / 承运商 / … / 系统禁运 / 物流），禁止嵌套框架页。
 */
#[Acl('Weline_Shipping::shipping_system', '配送系统', 'truck', '配送系统管理聚合页', 'Weline_Backend::shipping_group')]
class Manager extends BackendController
{
    /** @var array<string, string> */
    private const TAB_PATHS = [
        'region' => 'shipping/backend/region',
        'carrier' => 'shipping/backend/carrier',
        'ratetemplate' => 'shipping/backend/ratetemplate',
        'freeshippingrule' => 'shipping/backend/freeshippingrule',
        'shippingservice' => 'shipping/backend/shippingservice',
        'systemembargo' => 'shipping/backend/systemembargo',
        'tracking' => 'shipping/backend/tracking',
    ];

    #[Acl('Weline_Shipping::shipping_system_index', '查看配送系统', 'grid', '查看配送系统聚合页')]
    public function index()
    {
        $tab = (string)$this->request->getGet('tab', 'region');
        if (!isset(self::TAB_PATHS[$tab])) {
            $tab = 'region';
        }

        return $this->redirect(self::TAB_PATHS[$tab]);
    }
}
