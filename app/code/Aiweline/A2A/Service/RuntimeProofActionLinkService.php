<?php

declare(strict_types=1);

namespace Aiweline\A2A\Service;

class RuntimeProofActionLinkService
{
    private const PROOF_REQUIRED_ACTIONS = [
        'platform' => [
            'freeze_funds',
            'monitor_wallet',
        ],
        'arbitrator' => [
            'issue_full_release',
            'issue_partial_release',
            'issue_refund',
            'request_rework',
            'execute_wallet_dry_run',
        ],
    ];

    public function __construct(
        private readonly RuntimeProofTokenService $runtimeProofTokenService
    ) {
    }

    public function decorateActions(array $actions, array $actorAcl, string $orderPublicId): array
    {
        $currentAssignment = \is_array($actorAcl['current_assignment'] ?? null)
            ? $actorAcl['current_assignment']
            : [];
        $role = \strtolower(\trim((string)($currentAssignment['role_code'] ?? '')));
        $proofUrl = (string)($currentAssignment['runtime_acl_proof_url'] ?? '');
        if ($role === '' || $proofUrl === '' || empty(self::PROOF_REQUIRED_ACTIONS[$role])) {
            return $actions;
        }

        $proofRequiredActions = self::PROOF_REQUIRED_ACTIONS[$role];
        foreach ($actions as $index => $action) {
            if (!\is_array($action)) {
                continue;
            }
            $code = \strtolower(\trim((string)($action['code'] ?? '')));
            if (!\in_array($code, $proofRequiredActions, true)) {
                continue;
            }
            $actions[$index]['runtime_proof_required'] = true;
            $actions[$index]['runtime_proof_label'] = (string) __('需要运行时实证票据');
            $actions[$index]['runtime_proof_url'] = $this->runtimeProofTokenService->proofUrlWithContext(
                $proofUrl,
                $orderPublicId,
                $code
            );
        }

        return $actions;
    }
}
