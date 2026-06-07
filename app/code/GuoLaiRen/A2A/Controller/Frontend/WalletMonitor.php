<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Exception\TradeActorAuthorizationException;
use GuoLaiRen\A2A\Service\WalletInstructionAdapterService;
use GuoLaiRen\A2A\Service\TradeActorAuthorizationGuardService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class WalletMonitor extends FrontendController
{
    private readonly TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService;

    public function __construct(
        private readonly WalletInstructionAdapterService $walletInstructionAdapterService,
        ?TradeActorAuthorizationGuardService $tradeActorAuthorizationGuardService = null
    ) {
        $this->tradeActorAuthorizationGuardService = $tradeActorAuthorizationGuardService
            ?? ObjectManager::getInstance(TradeActorAuthorizationGuardService::class);
    }

    public function index(): string
    {
        $this->disableWalletMonitorPageCache();

        $orderPublicId = (string)($this->request->getParam('order') ?? 'A2A-ORDER-Q-48E8DB');
        $mode = (string)($this->request->getParam('mode') ?? 'dry_run_execute');

        try {
            $guard = $this->tradeActorAuthorizationGuardService->assertBoundActor(
                $this->session,
                $orderPublicId,
                $this->resolveAllowedRoles($mode),
                $this->formatOperationLabel($mode),
                [
                    'require_runtime_proof' => true,
                    'runtime_proof_action' => $this->runtimeProofAction($mode),
                    'runtime_proof_token' => (string)($this->request->getParam('a2a_runtime_proof') ?? ''),
                ]
            );
            $walletMonitor = $this->walletInstructionAdapterService->inspect($orderPublicId, $mode);
            $walletMonitor = \array_merge($walletMonitor, $guard);
            foreach ($walletMonitor as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
        } catch (TradeActorAuthorizationException $exception) {
            $this->request->getResponse()->setHttpResponseCode(403);
            $this->assign('page_title', __('A2A 钱包适配器执行监控'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 钱包适配器执行监控'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/WalletMonitor/index.phtml');
    }

    private function resolveAllowedRoles(string $mode): array
    {
        return match (\strtolower(\trim($mode))) {
            'dry_run_execute' => ['arbitrator'],
            'simulate_failure' => ['platform'],
            'retry_failed' => ['platform', 'arbitrator'],
            default => ['platform', 'arbitrator'],
        };
    }

    private function formatOperationLabel(string $mode): string
    {
        return match (\strtolower(\trim($mode))) {
            'dry_run_execute' => (string) __('执行钱包 dry-run'),
            'simulate_failure' => (string) __('模拟钱包失败'),
            'retry_failed' => (string) __('重试失败钱包指令'),
            default => (string) __('查看钱包监控'),
        };
    }

    private function runtimeProofAction(string $mode): string
    {
        return match (\strtolower(\trim($mode))) {
            'dry_run_execute' => 'execute_wallet_dry_run',
            default => 'monitor_wallet',
        };
    }

    private function disableWalletMonitorPageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }
}
