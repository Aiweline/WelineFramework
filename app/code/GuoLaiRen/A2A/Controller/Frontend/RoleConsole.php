<?php

declare(strict_types=1);

namespace GuoLaiRen\A2A\Controller\Frontend;

use GuoLaiRen\A2A\Service\RoleActionPolicyService;
use GuoLaiRen\A2A\Service\RoleSessionService;
use GuoLaiRen\A2A\Service\RuntimeProofActionLinkService;
use GuoLaiRen\A2A\Service\TradeActorAssignmentService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class RoleConsole extends FrontendController
{
    private readonly RoleActionPolicyService $roleActionPolicyService;
    private readonly RoleSessionService $roleSessionService;
    private readonly TradeActorAssignmentService $tradeActorAssignmentService;
    private ?RuntimeProofActionLinkService $runtimeProofActionLinkService = null;

    public function __construct(
        RoleActionPolicyService $roleActionPolicyService,
        ?RoleSessionService $roleSessionService = null,
        ?TradeActorAssignmentService $tradeActorAssignmentService = null
    ) {
        $this->roleActionPolicyService = $roleActionPolicyService;
        $this->roleSessionService = $roleSessionService ?? new RoleSessionService();
        $this->tradeActorAssignmentService = $tradeActorAssignmentService
            ?? ObjectManager::getInstance(TradeActorAssignmentService::class);
    }

    public function index(): string
    {
        $this->disableRoleConsolePageCache();

        $requestedRole = (string)($this->request->getParam('role') ?? '');
        $switchRequested = (string)($this->request->getParam('switch_role') ?? '') === '1';
        $bindActorRequested = (string)($this->request->getParam('bind_actor') ?? '') === '1';
        $orderPublicId = (string)($this->request->getParam('order') ?? 'A2A-ORDER-Q-48E8DB');
        $action = (string)($this->request->getParam('action') ?? '');

        try {
            $actor = $this->roleSessionService->resolveActor($this->session, $requestedRole, $switchRequested);
            $console = $this->roleActionPolicyService->inspect($orderPublicId, (string)$actor['role'], $action);
            $console['actor_acl'] = $bindActorRequested
                ? $this->tradeActorAssignmentService->bindCurrentActor($orderPublicId, $actor, 'role_console_claim')
                : $this->tradeActorAssignmentService->inspect($orderPublicId, $actor);
            $console['actions'] = $this->runtimeProofActionLinkService()->decorateActions(
                \is_array($console['actions'] ?? null) ? $console['actions'] : [],
                $console['actor_acl'],
                $orderPublicId
            );
            $console['actor'] = $actor;
            foreach ($console as $key => $value) {
                $this->assign($key, $value);
            }
            $this->assign('has_error', false);
            if (($console['is_forbidden'] ?? false) === true) {
                $this->request->getResponse()->setHttpResponseCode(403);
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setHttpResponseCode(404);
            $this->assign('page_title', __('A2A 角色权限控制台'));
            $this->assign('has_error', true);
            $this->assign('error_message', $exception->getMessage());
        }

        return $this->getTemplate()->fetch('GuoLaiRen_A2A::templates/Frontend/RoleConsole/index.phtml');
    }

    private function disableRoleConsolePageCache(): void
    {
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setHeader('X-Accel-Expires', '0');
    }

    private function runtimeProofActionLinkService(): RuntimeProofActionLinkService
    {
        $this->runtimeProofActionLinkService ??= ObjectManager::getInstance(RuntimeProofActionLinkService::class);

        return $this->runtimeProofActionLinkService;
    }
}
