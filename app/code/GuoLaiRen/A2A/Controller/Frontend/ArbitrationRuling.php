<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Service\ArbitrationRulingService;
use GuoLaiRen\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class ArbitrationRuling extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly ArbitrationRulingService $arbitrationRulingService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableArbitrationRulingPageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? 'A2A-ORDER-Q-48E8DB');
        $rulingType = (string)($this->request->getParam('ruling') ?? 'partial_release');

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                'arbitrator',
                __('签发仲裁裁决与钱包指令'),
                ['require_runtime_proof' => true]
            );
            $ruling = $this->arbitrationRulingService->issue($orderPublicId, $rulingType);
            $ruling = \array_merge($ruling, $guard);
            foreach ($ruling as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A 仲裁裁决与钱包指令'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 仲裁裁决与钱包指令'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/ArbitrationRuling/index.phtml');
    }

    private function disableArbitrationRulingPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
