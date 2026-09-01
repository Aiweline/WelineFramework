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

#[Acl('Weline_Payment::payment_method', '支付方式管理', 'edit', '支付方式管理', 'Weline_Backend::payment_group')]
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
    #[Acl('Weline_Payment::payment_method_index', '查看支付方式', 'list', '查看支付方式列表')]
    public function index()
    {
        $missingScopeRedirect = $this->redirectUnlessUsableTargetScope('*/backend/method');
        if ($missingScopeRedirect !== null) {
            return $missingScopeRedirect;
        }
        try {
            [$target, $grantVersion] = $this->authorizeTarget(ObjectAction::LIST);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        $storageScope = $this->publicTargetScope($target);
        $listContext = [
            'scope' => $storageScope === 'global' ? 'default.default.default' : $storageScope,
            'environment' => 'sandbox',
        ];
        $methods = $this->methodManager->listMethodsForAdmin($listContext);

        $this->assign('methods', $methods);
        $this->assign('page_title', (string)__('支付方式'));
        $this->assign('target_scope', $storageScope);
        $updateGrant = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class)
            ->check(ObjectAction::UPDATE, $target);
        $this->assign('can_register_providers', $updateGrant->allowed && $updateGrant->matchedGrantVersion > 0);
        $this->assign('can_reorder_methods', $updateGrant->allowed && $updateGrant->matchedGrantVersion > 0);
        $this->assign('expected_grant_version', $updateGrant->matchedGrantVersion);
        $this->assign('list_scope_context', $listContext);

        return $this->fetch();
    }

    /**
     * 编辑支付方式
     */
    #[Acl('Weline_Payment::payment_method_edit', '编辑支付方式', 'edit', '编辑支付方式配置')]
    public function edit()
    {
        $code = $this->request->getParam('code');
        $missingScopeRedirect = $this->redirectUnlessUsableTargetScope('*/backend/method/edit');
        if ($missingScopeRedirect !== null) {
            return $missingScopeRedirect;
        }
        try {
            [$target, $grantVersion] = $this->authorizeTarget(ObjectAction::VIEW);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        $storageScope = $this->publicTargetScope($target);
        $scope = $this->scopeConfigService->resolveScope([
            'scope' => $storageScope === 'global' ? 'default.default.default' : $storageScope,
            'environment' => (string)$this->request->getParam('environment', 'sandbox'),
        ]);
        
        if (!$code) {
            $this->getMessageManager()->addError(__('缺少支付方式代码'));
            return $this->redirect('*/backend/method/index', [
                'target_scope' => $storageScope,
            ]);
        }
        
        /** @var PaymentMethod $paymentMethod */
        $paymentMethod = ObjectManager::getInstance(PaymentMethod::class);
        $paymentMethod->load(PaymentMethod::schema_fields_CODE, $code);
        
        if (!$paymentMethod->getId()) {
            $this->getMessageManager()->addError(__('支付方式不存在'));
            return $this->redirect('*/backend/method/index', [
                'target_scope' => $storageScope,
            ]);
        }
        
        $metadata = [];
        $runtimeConfig = $this->methodManager->getRuntimeConfig($paymentMethod, $scope);

        $provider = $this->methodManager->getProviderInstance($paymentMethod);
        if ($provider) {
            $this->assign('configFields', $provider->getConfigSchema());
            $metadata = $this->methodManager->getProviderMetadata($paymentMethod, $provider);
        }

        $this->assign('method', $paymentMethod);
        $this->assign('page_title', (string)__('编辑支付方式'));
        $this->assign('scope', $scope);
        $this->assign('target_scope', $storageScope);
        $this->assign('expected_grant_version', $grantVersion);
        $this->assign('metadata', $metadata);
        $this->assign('runtimeConfig', $runtimeConfig);
        
        return $this->fetch();
    }

    /**
     * 裸链缺少或携带非法 Scope（如截断的 default.）时补齐默认站，
     * 避免被误报为「操作授权条件不满足」。
     */
    private function redirectUnlessUsableTargetScope(string $path): ?string
    {
        $raw = \trim((string)$this->request->getParam(
            'target_scope',
            $this->request->getParam('scope', ''),
        ));
        if ($this->isUsablePaymentTargetScope($raw)) {
            return null;
        }

        $params = ['target_scope' => 'default.default.default'];
        foreach (['code', 'environment'] as $key) {
            $value = \trim((string)$this->request->getParam($key, ''));
            if ($value !== '') {
                $params[$key] = $value;
            }
        }

        return $this->redirect($path, $params);
    }

    private function isUsablePaymentTargetScope(string $raw): bool
    {
        $raw = \strtolower(\trim($raw));
        if ($raw === 'global') {
            return true;
        }

        return $raw !== '' && \preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/D', $raw) === 1;
    }

    private function publicTargetScope(\Weline\Framework\Runtime\ScopeIdentity $target): string
    {
        if ($target->isGlobal()) {
            return 'global';
        }
        $legacy = \trim($target->toLegacyScopeString());

        return $legacy !== '' ? $legacy : 'default.default.default';
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
