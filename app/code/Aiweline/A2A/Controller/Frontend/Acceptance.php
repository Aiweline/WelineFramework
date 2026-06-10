<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Frontend;

use Aiweline\A2A\Exception\TradeActorAuthorizationException;
use Aiweline\A2A\Service\DeliveryAcceptanceService;
use Aiweline\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Acceptance extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly DeliveryAcceptanceService $deliveryAcceptanceService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableAcceptancePageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? '');
        $decision = (string)($this->request->getParam('decision') ?? '');

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                'buyer',
                $this->formatAcceptanceOperationLabel($decision)
            );
            $acceptance = $this->deliveryAcceptanceService->accept($orderPublicId, $decision);
            $acceptance = \array_merge($acceptance, $guard);
            foreach ($acceptance as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A 交付验收与放款'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 交付验收与放款'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('Aiweline_A2A::templates/Frontend/Acceptance/index.phtml');
    }

    private function formatAcceptanceOperationLabel(string $decision): string
    {
        return match (\strtolower(\trim($decision))) {
            'accept' => (string) __('确认验收并释放托管'),
            'rework' => (string) __('要求 Agent 返工并保持托管冻结'),
            default => (string) __('审阅交付证据并选择验收决策'),
        };
    }

    private function disableAcceptancePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
