<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Shipping\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Shipping\Model\Carrier as CarrierModel;

#[Acl('Weline_Shipping::carrier', '快递公司管理', 'mdi-truck', '快递公司管理', 'Weline_Backend::business_module')]
class Carrier extends BackendController
{
    private CarrierModel $carrier;

    public function __construct(ObjectManager $objectManager)
    {
        $this->carrier = $objectManager->getInstance(CarrierModel::class);
    }

    /**
     * 快递公司列表页
     */
    #[Acl('Weline_Shipping::carrier_index', '查看快递公司', 'mdi-format-list-bulleted', '查看快递公司列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        $isActive = $this->request->getParam('is_active');
        
        $query = $this->carrier->reset();
        
        // 搜索功能：按代码或名称搜索
        if ($keyword) {
            $query->where(CarrierModel::fields_CARRIER_CODE, 'like', "%{$keyword}%")
                  ->orWhere(CarrierModel::fields_CARRIER_NAME, 'like', "%{$keyword}%");
        }
        
        // 状态筛选
        if ($isActive !== null && $isActive !== '') {
            $query->where(CarrierModel::fields_IS_ACTIVE, (int)$isActive);
        }
        
        // 获取总数
        $total = $query->count();
        $totalPages = (int)ceil($total / $limit);
        
        // 分页查询
        $offset = ($page - 1) * $limit;
        $carriers = $query->order(CarrierModel::fields_SORT_ORDER, 'ASC')
            ->order(CarrierModel::fields_CARRIER_NAME, 'ASC')
            ->limit($limit, $offset)
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('carriers', $carriers);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('keyword', $keyword);
        $this->assign('is_active', $isActive);

        return $this->fetch();
    }

    /**
     * 编辑/新增快递公司
     */
    #[Acl('Weline_Shipping::carrier_edit', '编辑快递公司', 'mdi-pencil', '编辑快递公司')]
    public function edit()
    {
        $id = $this->request->getParam('id');
        $carrier = null;
        
        if ($id) {
            $carrier = $this->carrier->reset()->load($id);
            if (!$carrier->getId()) {
                $this->getMessageManager()->addError(__('快递公司不存在'));
                return $this->redirect('shipping/backend/carrier');
            }
        }

        $this->assign('carrier', $carrier);
        return $this->fetch();
    }

    /**
     * 保存快递公司
     */
    #[Acl('Weline_Shipping::carrier_save', '保存快递公司', 'mdi-content-save', '保存快递公司')]
    public function save()
    {
        try {
            $id = $this->request->getParam('id');
            $carrierCode = trim($this->request->getParam('carrier_code', ''));
            $carrierName = trim($this->request->getParam('carrier_name', ''));
            $carrierType = $this->request->getParam('carrier_type', CarrierModel::TYPE_MANUAL);
            $trackingUrlTemplate = trim($this->request->getParam('tracking_url_template', ''));
            $trackingApiEndpoint = trim($this->request->getParam('tracking_api_endpoint', ''));
            $trackingApiMethod = $this->request->getParam('tracking_api_method', 'GET');
            $trackingSupportStatus = $this->request->getParam('tracking_support_status', CarrierModel::TRACKING_SUPPORTED);
            $isActive = (int)$this->request->getParam('is_active', 1);
            $sortOrder = (int)$this->request->getParam('sort_order', 0);
            $apiConfig = $this->request->getParam('api_config', '');

            // 验证必填字段
            if (empty($carrierCode)) {
                throw new \RuntimeException(__('快递公司代码不能为空'));
            }
            if (empty($carrierName)) {
                throw new \RuntimeException(__('快递公司名称不能为空'));
            }
            if (empty($trackingUrlTemplate)) {
                throw new \RuntimeException(__('物流跟踪URL模板为必填项，所有快递公司必须支持追踪功能'));
            }

            $carrier = $this->carrier->reset();
            
            if ($id) {
                // 编辑模式
                $carrier->load($id);
                if (!$carrier->getId()) {
                    throw new \RuntimeException(__('快递公司不存在'));
                }
            } else {
                // 新增模式：检查代码是否已存在
                $existing = $this->carrier->reset()
                    ->load(CarrierModel::fields_CARRIER_CODE, $carrierCode);
                if ($existing->getId()) {
                    throw new \RuntimeException(__('快递公司代码已存在'));
                }
            }

            // 设置数据
            $carrier->setData(CarrierModel::fields_CARRIER_CODE, $carrierCode);
            $carrier->setData(CarrierModel::fields_CARRIER_NAME, $carrierName);
            $carrier->setData(CarrierModel::fields_CARRIER_TYPE, $carrierType);
            $carrier->setData(CarrierModel::fields_TRACKING_URL_TEMPLATE, $trackingUrlTemplate);
            $carrier->setData(CarrierModel::fields_TRACKING_API_ENDPOINT, $trackingApiEndpoint ?: null);
            $carrier->setData(CarrierModel::fields_TRACKING_API_METHOD, $trackingApiMethod);
            $carrier->setData(CarrierModel::fields_TRACKING_SUPPORT_STATUS, $trackingSupportStatus);
            $carrier->setData(CarrierModel::fields_IS_ACTIVE, $isActive);
            $carrier->setData(CarrierModel::fields_SORT_ORDER, $sortOrder);
            
            // API配置
            if ($apiConfig) {
                $configArray = json_decode($apiConfig, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $carrier->setApiConfig($configArray);
                } else {
                    throw new \RuntimeException(__('API配置JSON格式错误'));
                }
            }

            $carrier->save();

            $this->getMessageManager()->addSuccess($id ? __('更新成功') : __('创建成功'));
            return $this->redirect('shipping/backend/carrier');

        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('shipping/backend/carrier/edit' . ($id ? '?id=' . $id : ''));
        }
    }

    /**
     * 删除快递公司
     */
    #[Acl('Weline_Shipping::carrier_delete', '删除快递公司', 'mdi-delete', '删除快递公司')]
    public function delete()
    {
        try {
            $id = $this->request->getParam('id');
            if (!$id) {
                throw new \RuntimeException(__('参数错误'));
            }

            $carrier = $this->carrier->reset()->load($id);
            if (!$carrier->getId()) {
                throw new \RuntimeException(__('快递公司不存在'));
            }

            $carrier->delete();
            $this->getMessageManager()->addSuccess(__('删除成功'));

        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/carrier');
    }

    /**
     * 切换启用/禁用状态
     */
    #[Acl('Weline_Shipping::carrier_toggle', '切换快递公司状态', 'mdi-toggle-switch', '切换快递公司启用/禁用状态')]
    public function toggle()
    {
        try {
            $id = $this->request->getParam('id');
            if (!$id) {
                throw new \RuntimeException(__('参数错误'));
            }

            $carrier = $this->carrier->reset()->load($id);
            if (!$carrier->getId()) {
                throw new \RuntimeException(__('快递公司不存在'));
            }

            $currentStatus = $carrier->getData(CarrierModel::fields_IS_ACTIVE);
            $newStatus = $currentStatus ? 0 : 1;
            $carrier->setData(CarrierModel::fields_IS_ACTIVE, $newStatus);
            $carrier->save();

            $this->getMessageManager()->addSuccess($newStatus ? __('启用成功') : __('禁用成功'));

        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('shipping/backend/carrier');
    }
}


