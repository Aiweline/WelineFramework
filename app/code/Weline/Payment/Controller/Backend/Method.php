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
use Weline\Payment\Service\PaymentConfigValidationService;
use Weline\Payment\Service\PaymentScopeConfigService;
use Weline\Payment\Model\PaymentMethodConfig;
use Weline\Payment\Model\PaymentMethod;

#[Acl('Weline_Payment::payment_method', '支付方式管理', 'mdi-credit-card', '支付方式管理', 'Weline_Backend::payment_group')]
class Method extends BackendController
{
    private PaymentMethodManager $methodManager;
    private DiscountActionSupportService $discountActionSupportService;
    private PaymentScopeConfigService $scopeConfigService;
    private PaymentConfigValidationService $configValidationService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
        $this->discountActionSupportService = $objectManager->getInstance(DiscountActionSupportService::class);
        $this->scopeConfigService = $objectManager->getInstance(PaymentScopeConfigService::class);
        $this->configValidationService = $objectManager->getInstance(PaymentConfigValidationService::class);
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
        $scope = $this->scopeConfigService->resolveScope([
            'scope_type' => (string)$this->request->getParam('scope_type', 'global'),
            'scope_code' => (string)$this->request->getParam('scope_code', 'default'),
            'environment' => (string)$this->request->getParam('environment', 'sandbox'),
        ]);
        
        if (!$code) {
            $this->getMessageManager()->addError(__('缺少支付方式代码'));
            return $this->redirect('*/backend/method/index');
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
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
        $this->assign('scope', $scope);
        $this->assign('scopeProfile', $this->methodManager->getScopeProfile($paymentMethod, $scope));
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
        $scope = $this->scopeConfigService->resolveScope([
            'scope_type' => (string)$this->request->getParam('scope_type', 'global'),
            'scope_code' => (string)$this->request->getParam('scope_code', 'default'),
            'environment' => (string)$this->request->getParam('environment', 'sandbox'),
        ]);
        $runTest = (int)$this->request->getParam('test_config', 0) === 1;
        
        if (!$code) {
            return $this->error(__('缺少支付方式代码'));
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            return $this->error(__('支付方式不存在'));
        }
        
        // 获取支付提供商实例并设置配置
        $provider = $this->methodManager->getProviderInstance($paymentMethod, $scope);
        if ($provider) {
            $provider->setConfig($config);
        }

        $profile = $this->methodManager->getScopeProfile($paymentMethod, $scope);
        $testStatus = $profile ? (string)$profile->getData(PaymentMethodConfig::schema_fields_TEST_STATUS) : PaymentMethodConfig::TEST_STATUS_UNTESTED;
        $testMessage = $profile ? (string)$profile->getData(PaymentMethodConfig::schema_fields_TEST_MESSAGE) : '';
        $testedAt = $profile ? (string)$profile->getData(PaymentMethodConfig::schema_fields_TESTED_AT) : '';

        if ($runTest) {
            $testResult = $this->configValidationService->validateMethod([
                'code' => $code,
                'config_fields' => $provider ? $provider->getConfigFields() : [],
                'has_documentation' => true,
                'flow' => 'redirect',
            ], is_array($config) ? $config : [], $provider, $scope);
            $testStatus = (string)$testResult['status'];
            $testMessage = (string)$testResult['message'];
            $testedAt = (string)$testResult['tested_at'];
            if (!$testResult['success']) {
                $isActive = 0;
            }
        }

        if ($isActive === 1 && $testStatus !== PaymentMethodConfig::TEST_STATUS_PASSED) {
            return $this->error(__('Payment method must pass configuration testing before it can be enabled.'));
        }
        
        // 保存支持的优惠方式
        if (is_array($supportedDiscountActions)) {
            $paymentMethod->setSupportedDiscountActions($supportedDiscountActions);
        }
        
        $this->scopeConfigService->saveProfile((string)$code, $scope['scope_type'], $scope['scope_code'], $scope['environment'], [
            'enabled' => $isActive === 1,
            'is_default' => false,
            'sort_order' => $sortOrder,
            'config' => is_array($config) ? $config : [],
            'test_status' => $testStatus,
            'test_message' => $testMessage,
            'tested_at' => $testedAt,
        ]);

        $paymentMethod->setData(PaymentMethod::schema_fields_IS_ACTIVE, $isActive)
            ->setData(PaymentMethod::schema_fields_SORT_ORDER, $sortOrder)
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
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            return $this->error(__('支付方式不存在'));
        }
        
        $scope = $this->scopeConfigService->resolveScope([
            'scope_type' => (string)$this->request->getParam('scope_type', 'global'),
            'scope_code' => (string)$this->request->getParam('scope_code', 'default'),
            'environment' => (string)$this->request->getParam('environment', 'sandbox'),
        ]);
        $isActive = $paymentMethod->isActive() ? 0 : 1;
        if ($isActive === 1 && !$this->methodManager->isMethodActiveForScope($paymentMethod, $scope)) {
            return $this->error(__('Payment method must pass configuration testing for this scope before it can be enabled.'));
        }
        $paymentMethod->setData(PaymentMethod::schema_fields_IS_ACTIVE, $isActive)
            ->save();
        
        return $this->success(__('支付方式状态已更新'));
    }
}

