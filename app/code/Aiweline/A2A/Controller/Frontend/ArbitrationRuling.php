<?php

declare(strict_types=1);

namespace Aiweline\A2A\Controller\Frontend;

use Aiweline\A2A\Exception\TradeActorAuthorizationException;
use Aiweline\A2A\Service\ArbitrationRulingService;
use Aiweline\A2A\Service\TradeActorAuthorizationGuardService;
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
                [
                    'require_runtime_proof' => true,
                    'runtime_proof_action' => $this->runtimeProofAction($rulingType),
                    'runtime_proof_token' => (string)($this->request->getParam('a2a_runtime_proof') ?? ''),
                ]
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

        return $this->getTemplate()->fetch('Aiweline_A2A::templates/Frontend/ArbitrationRuling/index.phtml');
    }

    private function disableArbitrationRulingPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }

    private function runtimeProofAction(string $rulingType): string
    {
        return match (\strtolower(\trim($rulingType))) {
            'full_release' => 'issue_full_release',
            'refund' => 'issue_refund',
            'rework' => 'request_rework',
            default => 'issue_partial_release',
        };
    }
}
