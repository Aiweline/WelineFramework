<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Payment\Service\PaymentMethodManager;
use Weline\Payment\Service\PaymentScopeConfigService;
use Weline\Payment\Model\PaymentMethod;
use Weline\Payment\Service\PaymentObjectScopeService;

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
        try {
            [$target, $grantVersion] = $this->authorizeTarget(ObjectAction::LIST);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(403);

            return (string)__('操作授权条件不满足');
        }
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->select()->fetch();
        $methods = $paymentMethod->getItems();
        
        $this->assign('methods', $methods);
        $this->assign('target_scope', $target->isGlobal() ? 'global' : $target->toLegacyScopeString());
        $updateGrant = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class)
            ->check(ObjectAction::UPDATE, $target);
        $this->assign('can_register_providers', $updateGrant->allowed && $updateGrant->matchedGrantVersion > 0);
        $this->assign('expected_grant_version', $updateGrant->matchedGrantVersion);
        
        return $this->fetch();
    }

    /**
     * 编辑支付方式
     */
    #[Acl('Weline_Payment::payment_method_edit', '编辑支付方式', 'mdi-pencil', '编辑支付方式配置')]
    public function edit()
    {
        $code = $this->request->getParam('code');
        try {
            [$target, $grantVersion] = $this->authorizeTarget(ObjectAction::VIEW);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(403);

            return (string)__('操作授权条件不满足');
        }
        $storageScope = $target->isGlobal() ? 'global' : $target->toLegacyScopeString();
        $scope = $this->scopeConfigService->resolveScope([
            'scope' => $storageScope,
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
        $this->assign('target_scope', $storageScope);
        $this->assign('expected_grant_version', $grantVersion);
        $this->assign('metadata', $metadata);
        $this->assign('runtimeConfig', $runtimeConfig);
        
        return $this->fetch();
    }

    /**
     * @return array{0:\Weline\Framework\Runtime\ScopeIdentity,1:int}
     */
    private function authorizeTarget(string $action): array
    {
        $target = ObjectManager::getInstance(PaymentObjectScopeService::class)->fromExplicitTarget([
            'target_scope' => (string)$this->request->getParam(
                'target_scope',
                $this->request->getParam('scope', ''),
            ),
        ]);
        $result = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class)
            ->requireForQuery($action, $target);

        return [$target, $result->matchedGrantVersion];
    }
}
