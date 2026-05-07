<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/12/20
 * 描述：产品布局管理控制器
 */

namespace WeShop\Product\Controller\Backend\Product;

use WeShop\Product\Model\Product;
use WeShop\Product\Model\ProductLayout;
use WeShop\Product\Model\ProductLayoutSchedule;
use WeShop\Product\Service\ProductLayoutService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

#[Acl('WeShop_Product::product_layout', 'Product layout actions', 'mdi mdi-view-dashboard-edit-outline', 'Manage product layouts', 'WeShop_Product::product')]
class Layout extends BackendController
{
    private ProductLayoutService $layoutService;

    public function __construct(
        Request $request,
        ProductLayoutService $layoutService
    ) {
        parent::__construct($request);
        $this->layoutService = $layoutService;
    }

    /**
     * 产品布局配置页面
     */
    #[Acl('WeShop_Product::product_layout_index', 'View product layouts', 'mdi mdi-view-dashboard-outline', 'View product layout page')]
    public function index()
    {
        $productId = (int)$this->request->getParam('product_id');
        
        if (!$productId) {
            $this->getMessageManager()->addErrorMessage(__('请选择产品'));
            $this->redirect('*/product/index');
            return;
        }

        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            $this->getMessageManager()->addErrorMessage(__('产品不存在'));
            $this->redirect('*/product/index');
            return;
        }

        // 获取产品的所有布局配置
        /** @var ProductLayout $layoutModel */
        $layoutModel = ObjectManager::getInstance(ProductLayout::class);
        $productLayouts = $layoutModel->getByProduct($productId);

        // 获取产品的所有布局计划
        $schedules = $this->layoutService->getProductSchedules($productId);

        $this->assign('product', $product);
        $this->assign('productLayouts', $productLayouts);
        $this->assign('schedules', $schedules);
        $this->assign('layoutTypes', [
            'product_detail' => __('产品详情'),
            'product_list' => __('产品列表')
        ]);

        return $this->fetch();
    }

    /**
     * 保存产品布局配置
     */
    #[Acl('WeShop_Product::product_layout_save', 'Save product layout', 'mdi mdi-content-save', 'Save product layout data')]
    public function save()
    {
        $productId = (int)$this->request->getParam('product_id');
        $layoutType = $this->request->getParam('layout_type');
        $layoutCode = $this->request->getParam('layout_code');

        if (!$productId || !$layoutType || !$layoutCode) {
            $this->getMessageManager()->addErrorMessage(__('参数不完整'));
            $this->redirect('*/product/layout/index', ['product_id' => $productId]);
            return;
        }

        $result = $this->layoutService->applyProductLayout($productId, $layoutType, $layoutCode);

        if ($result) {
            $this->getMessageManager()->addSuccessMessage(__('布局配置保存成功'));
        } else {
            $this->getMessageManager()->addErrorMessage(__('布局配置保存失败'));
        }

        $this->redirect('*/product/layout/index', ['product_id' => $productId]);
    }

    /**
     * 删除产品布局配置
     */
    #[Acl('WeShop_Product::product_layout_delete', 'Delete product layout', 'mdi mdi-delete-outline', 'Delete product layout data')]
    public function delete()
    {
        $layoutId = (int)$this->request->getParam('layout_id');
        $productId = (int)$this->request->getParam('product_id');

        if (!$layoutId) {
            $this->getMessageManager()->addErrorMessage(__('请选择要删除的布局'));
            $this->redirect('*/product/layout/index', ['product_id' => $productId]);
            return;
        }

        /** @var ProductLayout $layoutModel */
        $layoutModel = ObjectManager::getInstance(ProductLayout::class);
        $layoutModel->load($layoutId);

        if ($layoutModel->getId()) {
            $layoutModel->setIsActive(false)->save();
            $this->getMessageManager()->addSuccessMessage(__('布局配置已删除'));
        } else {
            $this->getMessageManager()->addErrorMessage(__('布局配置不存在'));
        }

        $this->redirect('*/product/layout/index', ['product_id' => $productId]);
    }

    /**
     * 创建布局计划
     */
    #[Acl('WeShop_Product::product_layout_schedule_create', 'Create layout schedule', 'mdi mdi-calendar-plus', 'Create product layout schedule')]
    public function createSchedule()
    {
        $productId = (int)$this->request->getParam('product_id');
        
        if (!$productId) {
            $this->getMessageManager()->addErrorMessage(__('请选择产品'));
            $this->redirect('*/product/index');
            return;
        }

        /** @var Product $product */
        $product = ObjectManager::getInstance(Product::class);
        $product->load($productId);

        if (!$product->getId()) {
            $this->getMessageManager()->addErrorMessage(__('产品不存在'));
            $this->redirect('*/product/index');
            return;
        }

        if ($this->request->isPost()) {
            $layoutType = $this->request->getParam('layout_type');
            $layoutCode = $this->request->getParam('layout_code');
            $startTime = $this->request->getParam('start_time');
            $endTime = $this->request->getParam('end_time') ?: null;
            $isRecurring = (bool)$this->request->getParam('is_recurring');
            $cronExpression = $this->request->getParam('cron_expression') ?: '';
            $description = $this->request->getParam('description') ?: '';

            if (!$layoutType || !$layoutCode || !$startTime) {
                $this->getMessageManager()->addErrorMessage(__('请填写完整信息'));
            } else {
                $schedule = $this->layoutService->createProductLayoutSchedule(
                    $productId,
                    $layoutType,
                    $layoutCode,
                    $startTime,
                    $endTime,
                    $isRecurring,
                    $cronExpression,
                    $description
                );

                if ($schedule) {
                    $this->getMessageManager()->addSuccessMessage(__('布局计划创建成功'));
                    $this->redirect('*/product/layout/index', ['product_id' => $productId]);
                    return;
                } else {
                    $this->getMessageManager()->addErrorMessage(__('布局计划创建失败'));
                }
            }
        }

        $this->assign('product', $product);
        $this->assign('layoutTypes', [
            'product_detail' => __('产品详情'),
            'product_list' => __('产品列表')
        ]);

        return $this->fetch();
    }

    /**
     * 编辑布局计划
     */
    #[Acl('WeShop_Product::product_layout_schedule_edit', 'Edit layout schedule', 'mdi mdi-calendar-edit', 'Edit product layout schedule')]
    public function editSchedule()
    {
        $scheduleId = (int)$this->request->getParam('schedule_id');
        $productId = (int)$this->request->getParam('product_id');

        if (!$scheduleId) {
            $this->getMessageManager()->addErrorMessage(__('请选择要编辑的计划'));
            $this->redirect('*/product/layout/index', ['product_id' => $productId]);
            return;
        }

        /** @var ProductLayoutSchedule $schedule */
        $schedule = ObjectManager::getInstance(ProductLayoutSchedule::class);
        $schedule->load($scheduleId);

        if (!$schedule->getId()) {
            $this->getMessageManager()->addErrorMessage(__('计划不存在'));
            $this->redirect('*/product/layout/index', ['product_id' => $productId]);
            return;
        }

        if ($this->request->isPost()) {
            $data = [
                'layout_code' => $this->request->getParam('layout_code'),
                'start_time' => $this->request->getParam('start_time'),
                'end_time' => $this->request->getParam('end_time') ?: null,
                'is_recurring' => (bool)$this->request->getParam('is_recurring'),
                'cron_expression' => $this->request->getParam('cron_expression') ?: '',
                'status' => $this->request->getParam('status'),
                'description' => $this->request->getParam('description') ?: ''
            ];

            $result = $this->layoutService->updateProductLayoutSchedule($scheduleId, $data);

            if ($result) {
                $this->getMessageManager()->addSuccessMessage(__('布局计划更新成功'));
                $this->redirect('*/product/layout/index', ['product_id' => $productId]);
                return;
            } else {
                $this->getMessageManager()->addErrorMessage(__('布局计划更新失败'));
            }
        }

        $this->assign('schedule', $schedule);
        $this->assign('product', $schedule->getProduct());
        $this->assign('layoutTypes', [
            'product_detail' => __('产品详情'),
            'product_list' => __('产品列表')
        ]);

        return $this->fetch();
    }

    /**
     * 删除布局计划
     */
    #[Acl('WeShop_Product::product_layout_schedule_delete', 'Delete layout schedule', 'mdi mdi-calendar-remove', 'Delete product layout schedule')]
    public function deleteSchedule()
    {
        $scheduleId = (int)$this->request->getParam('schedule_id');
        $productId = (int)$this->request->getParam('product_id');

        if (!$scheduleId) {
            $this->getMessageManager()->addErrorMessage(__('请选择要删除的计划'));
            $this->redirect('*/product/layout/index', ['product_id' => $productId]);
            return;
        }

        $result = $this->layoutService->deleteProductLayoutSchedule($scheduleId);

        if ($result) {
            $this->getMessageManager()->addSuccessMessage(__('布局计划已删除'));
        } else {
            $this->getMessageManager()->addErrorMessage(__('布局计划删除失败'));
        }

        $this->redirect('*/product/layout/index', ['product_id' => $productId]);
    }
}

