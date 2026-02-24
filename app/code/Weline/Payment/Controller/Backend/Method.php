<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\DiscountActionSupportService;
use Weline\Payment\Model\PaymentMethod;

#[Acl('Weline_Payment::payment_method', '支付方式管理', 'mdi-credit-card', '支付方式管理', 'Weline_Backend::system_service')]
class Method extends BackendController
{
    private PaymentMethodManager $methodManager;
    private DiscountActionSupportService $discountActionSupportService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
        $this->discountActionSupportService = $objectManager->getInstance(DiscountActionSupportService::class);
    }

    /**
     * 支付方式列表页
     */
    #[Acl('Weline_Payment::payment_method_index', '查看支付方式', 'mdi-format-list-bulleted', '查看支付方式列表')]
    public function index()
    {
        // 注册所有支付提供商
        $this->methodManager->registerAllProviders();
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $methods = $paymentMethod->select()->fetch();
        
        $this->assign('methods', $methods);
        
        return $this->fetch();
    }

    /**
     * 编辑支付方式
     */
    #[Acl('Weline_Payment::payment_method_edit', '编辑支付方式', 'mdi-pencil', '编辑支付方式配置')]
    public function edit()
    {
        $code = $this->request->getParam('code');
        
        if (!$code) {
            $this->getMessageManager()->addError(__('缺少支付方式代码'));
            return $this->redirect('*/backend/method/index');
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            $this->getMessageManager()->addError(__('支付方式不存在'));
            return $this->redirect('*/backend/method/index');
        }
        
        // 获取支付提供商实例
        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if ($provider) {
            $this->assign('configFields', $provider->getConfigFields());
        }
        
        // 获取所有可用的优惠方式
        $allDiscountActions = $this->discountActionSupportService->getAllDiscountActions();
        $supportedActions = $paymentMethod->getSupportedDiscountActions();
        
        $this->assign('method', $paymentMethod);
        $this->assign('allDiscountActions', $allDiscountActions);
        $this->assign('supportedActions', $supportedActions);
        
        return $this->fetch();
    }

    /**
     * 保存支付方式配置
     */
    #[Acl('Weline_Payment::payment_method_save', '保存支付方式', 'mdi-content-save', '保存支付方式配置')]
    public function save()
    {
        $code = $this->request->getParam('code');
        $isActive = (int)$this->request->getParam('is_active', 0);
        $sortOrder = (int)$this->request->getParam('sort_order', 0);
        $config = $this->request->getParam('config', []);
        $supportedDiscountActions = $this->request->getParam('supported_discount_actions', []);
        
        if (!$code) {
            return $this->error(__('缺少支付方式代码'));
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            return $this->error(__('支付方式不存在'));
        }
        
        // 获取支付提供商实例并设置配置
        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if ($provider) {
            $provider->setConfig($config);
        }
        
        // 保存支持的优惠方式
        if (is_array($supportedDiscountActions)) {
            $paymentMethod->setSupportedDiscountActions($supportedDiscountActions);
        }
        
        $paymentMethod->setData(PaymentMethod::fields_IS_ACTIVE, $isActive)
            ->setData(PaymentMethod::fields_SORT_ORDER, $sortOrder)
            ->setConfigData($config)
            ->save();
        
        return $this->success(__('支付方式配置保存成功'));
    }

    /**
     * 切换启用状态
     */
    #[Acl('Weline_Payment::payment_method_toggle', '切换支付方式状态', 'mdi-toggle-switch', '切换支付方式启用状态')]
    public function toggle()
    {
        $code = $this->request->getParam('code');
        
        if (!$code) {
            return $this->error(__('缺少支付方式代码'));
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            return $this->error(__('支付方式不存在'));
        }
        
        $isActive = $paymentMethod->isActive() ? 0 : 1;
        $paymentMethod->setData(PaymentMethod::fields_IS_ACTIVE, $isActive)
            ->save();
        
        return $this->success(__('支付方式状态已更新'));
    }
}

