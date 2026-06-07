<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Model\SettlementCase as SettlementCaseModel;
use GuoLaiRen\A2A\Service\SettlementCaseService;
use GuoLaiRen\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class SettlementCase extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly SettlementCaseService $settlementCaseService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableSettlementCasePageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? '');
        $caseType = (string)($this->request->getParam('case') ?? '');
        $isSubmit = $this->isSubmitRequest((string)($this->request->getParam('submit') ?? ''));
        $applicationInput = [
            'reason' => (string)($this->request->getParam('reason') ?? ''),
            'desired_outcome' => (string)($this->request->getParam('desired_outcome') ?? ''),
            'evidence_note' => (string)($this->request->getParam('evidence_note') ?? ''),
        ];

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                $this->resolveAllowedRoles($caseType),
                $this->formatOperationLabel($caseType, $isSubmit),
                [
                    'require_runtime_proof_roles' => ['platform'],
                    'runtime_proof_action' => 'freeze_funds',
                    'runtime_proof_token' => (string)($this->request->getParam('a2a_runtime_proof') ?? ''),
                ]
            );
            $settlementCase = $this->settlementCaseService->open($orderPublicId, $caseType, $isSubmit, $applicationInput);
            $settlementCase = \array_merge($settlementCase, $guard);
            foreach ($settlementCase as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A 结算分支与仲裁'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 结算分支与仲裁'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/SettlementCase/index.phtml');
    }

    private function resolveAllowedRoles(string $caseType): array
    {
        return \strtolower(\trim($caseType)) === SettlementCaseModel::TYPE_DISPUTE
            ? ['buyer', 'platform']
            : ['buyer'];
    }

    private function formatOperationLabel(string $caseType, bool $isSubmit): string
    {
        $isDispute = \strtolower(\trim($caseType)) === SettlementCaseModel::TYPE_DISPUTE;
        if ($isSubmit) {
            return $isDispute
                ? (string) __('提交争议仲裁申请')
                : (string) __('提交退款复核申请');
        }

        return $isDispute
            ? (string) __('预览争议仲裁申请')
            : (string) __('预览退款复核申请');
    }

    private function isSubmitRequest(string $submit): bool
    {
        return \in_array(\strtolower(\trim($submit)), ['1', 'true', 'submit', 'confirm'], true);
    }

    private function disableSettlementCasePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
