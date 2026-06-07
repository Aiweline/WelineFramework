<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Service\ProviderScopeSubmissionService;
use GuoLaiRen\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class ProviderScope extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly ProviderScopeSubmissionService $providerScopeSubmissionService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableProviderScopePageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? '');

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                'provider',
                __('提交 Agent 执行范围')
            );
            $scope = $this->providerScopeSubmissionService->submit($orderPublicId);
            $scope = \array_merge($scope, $guard);
            foreach ($scope as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A Provider 执行范围'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A Provider 执行范围'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/ProviderScope/index.phtml');
    }

    private function disableProviderScopePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
