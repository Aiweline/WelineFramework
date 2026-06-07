<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Service\DeliverySubmissionService;
use GuoLaiRen\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class DeliverySubmission extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly DeliverySubmissionService $deliverySubmissionService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableDeliverySubmissionPageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? '');

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                'provider',
                __('提交 Agent 交付证据')
            );
            $delivery = $this->deliverySubmissionService->submit($orderPublicId);
            $delivery = \array_merge($delivery, $guard);
            foreach ($delivery as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A Agent 交付证据提交'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A Agent 交付证据提交'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/DeliverySubmission/index.phtml');
    }

    private function disableDeliverySubmissionPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
