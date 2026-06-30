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
use Weline\Payment\Service\PaymentScopeConfigService;
use Weline\Payment\Model\PaymentMethod;

#[Acl('Weline_Payment::payment_method', '支付方式管理', 'mdi-credit-card', '支付方式管理', 'Weline_Backend::payment_group')]
class Method extends BackendController
{
    private PaymentMethodManager $methodManager;
    private PaymentScopeConfigService $scopeConfigService;

    public function __construct(
        ObjectManager $objectManager
    ) {
        $this->methodManager = $objectManager->getInstance(PaymentMethodManager::class);
        $this->scopeConfigService = $objectManager->getInstance(PaymentScopeConfigService::class);
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
            'scope' => (string)$this->request->getParam('scope', ''),
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
        
        $metadata = [];
        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if ($provider) {
            $this->assign('configFields', $provider->getConfigSchema());
            $metadata = $this->methodManager->getProviderMetadata($paymentMethod, $provider);
        }

        $this->assign('method', $paymentMethod);
        $this->assign('scope', $scope);
        $this->assign('metadata', $metadata);
        $this->assign('runtimeConfig', $runtimeConfig);
        
        return $this->fetch();
    }

}

