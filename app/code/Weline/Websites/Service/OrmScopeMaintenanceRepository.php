<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\Database\Transaction\WriteIntentTransactionCoordinatorInterface;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\ScopeMaintenanceRepositoryInterface;
use Weline\Websites\Model\MaintenancePreviewToken;
use Weline\Websites\Model\ScopeMaintenanceAudit;
use Weline\Websites\Model\ScopeMaintenanceState;

final class OrmScopeMaintenanceRepository implements ScopeMaintenanceRepositoryInterface
{
    public function __construct(
        private readonly ScopeMaintenanceState $stateModel,
        private readonly MaintenancePreviewToken $tokenModel,
        private readonly ScopeMaintenanceAudit $auditModel,
        private readonly WriteIntentTransactionCoordinatorInterface $transactions,
    ) {
    }

    public function status(ScopeIdentity $scope): array
    {
        $row = $this->loadState($scope, false);
        if ($row === null) {
            return $this->defaultStatus($scope);
        }
        return $this->stateArray($row);
    }

    public function setMaintenance(
        ScopeIdentity $scope,
        bool $enabled,
        string $reason,
        int $now,
        string $actor = 'system',
    ): array {
        $this->assertNow($now);
        $reason = trim($reason);
        if (strlen($reason) > 500) {
            throw new \InvalidArgumentException('scope_maintenance_reason_too_long');
        }
        $actor = $this->normalizeActor($actor);
        $connection = $this->stateModel->getConnection();

        return $this->transactions->runWrite(
            $connection,
            function () use ($scope, $enabled, $reason, $now, $actor): array {
                $existing = $this->loadState($scope, true);
                $generation = ($existing === null
                    ? 0
                    : (int)$existing->getData(ScopeMaintenanceState::schema_fields_GENERATION)) + 1;
                if ($generation < 1) {
                    throw new \RuntimeException('scope_maintenance_generation_overflow');
                }
                $row = $this->scopeColumns($scope) + [
                    ScopeMaintenanceState::schema_fields_ENABLED => $enabled ? 1 : 0,
                    ScopeMaintenanceState::schema_fields_REASON => $enabled ? $reason : '',
                    ScopeMaintenanceState::schema_fields_GENERATION => $generation,
                    ScopeMaintenanceState::schema_fields_SINCE => $enabled ? $now : 0,
                    ScopeMaintenanceState::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s', $now),
                ];
                if ($existing === null) {
                    try {
                        (clone $this->stateModel)->clear()->insert($row)->fetch();
                    } catch (\Throwable $throwable) {
                        $existing = $this->loadState($scope, true);
                        if ($existing === null) {
                            throw $throwable;
                        }
                        $generation = (int)$existing->getData(
                            ScopeMaintenanceState::schema_fields_GENERATION,
                        ) + 1;
                    }
                }
                if ($existing !== null) {
                    (clone $this->stateModel)->clear()
                        ->where(ScopeMaintenanceState::schema_fields_SCOPE_KEY, $scope->canonicalKey())
                        ->update([
                            ScopeMaintenanceState::schema_fields_ENABLED => $enabled ? 1 : 0,
                            ScopeMaintenanceState::schema_fields_REASON => $enabled ? $reason : '',
                            ScopeMaintenanceState::schema_fields_GENERATION => $generation,
                            ScopeMaintenanceState::schema_fields_SINCE => $enabled ? $now : 0,
                            ScopeMaintenanceState::schema_fields_UPDATED_AT => gmdate('Y-m-d H:i:s', $now),
                        ])
                        ->fetch();
                }
                if (!$enabled) {
                    $this->revokeScopeRows($scope->canonicalKey(), $now);
                }
                $this->appendAudit(
                    $scope->canonicalKey(),
                    $enabled ? ScopeMaintenanceAudit::ACTION_ENABLED : ScopeMaintenanceAudit::ACTION_DISABLED,
                    $generation,
                    null,
                    $actor,
                    $now,
                );

                return [
                    'scope_key' => $scope->canonicalKey(),
                    'enabled' => $enabled,
                    'reason' => $enabled ? $reason : '',
                    'generation' => $generation,
                    'since' => $enabled ? $now : 0,
                ];
            },
        );
    }

    public function registerToken(
        ScopeIdentity $scope,
        string $tokenHash,
        string $kid,
        int $generation,
        int $issuedAt,
        int $expiresAt,
        string $actor = 'system',
    ): void {
        $this->assertTokenHash($tokenHash);
        $this->assertNow($issuedAt);
        if ($expiresAt <= $issuedAt || $generation < 1) {
            throw new \InvalidArgumentException('maintenance_preview_token_metadata_invalid');
        }
        $actor = $this->normalizeActor($actor);
        $connection = $this->tokenModel->getConnection();
        $this->transactions->runWrite($connection, function () use (
            $scope,
            $tokenHash,
            $kid,
            $generation,
            $issuedAt,
            $expiresAt,
            $actor,
        ): void {
            $state = $this->loadState($scope, true);
            if ($state === null
                || (int)$state->getData(ScopeMaintenanceState::schema_fields_ENABLED) !== 1
                || (int)$state->getData(ScopeMaintenanceState::schema_fields_GENERATION) !== $generation) {
                throw new \RuntimeException('maintenance_preview_generation_conflict');
            }
            (clone $this->tokenModel)->clear()->setData([
                MaintenancePreviewToken::schema_fields_TOKEN_HASH => $tokenHash,
                MaintenancePreviewToken::schema_fields_SCOPE_KEY => $scope->canonicalKey(),
                MaintenancePreviewToken::schema_fields_KID => $kid,
                MaintenancePreviewToken::schema_fields_GENERATION => $generation,
                MaintenancePreviewToken::schema_fields_ISSUED_AT => $issuedAt,
                MaintenancePreviewToken::schema_fields_EXPIRES_AT => $expiresAt,
                MaintenancePreviewToken::schema_fields_REVOKED => 0,
                MaintenancePreviewToken::schema_fields_REVOKED_AT => null,
            ])->save(true);
            $this->appendAudit(
                $scope->canonicalKey(),
                ScopeMaintenanceAudit::ACTION_TOKEN_ISSUED,
                $generation,
                $tokenHash,
                $actor,
                $issuedAt,
            );
        });
    }

    public function tokenStatus(string $tokenHash): ?array
    {
        $this->assertTokenHash($tokenHash);
        $row = (clone $this->tokenModel)->clear()
            ->where(MaintenancePreviewToken::schema_fields_TOKEN_HASH, $tokenHash)
            ->find()
            ->fetch();
        if ((int)$row->getData(MaintenancePreviewToken::schema_fields_ID) <= 0) {
            return null;
        }
        return [
            'scope_key' => (string)$row->getData(MaintenancePreviewToken::schema_fields_SCOPE_KEY),
            'token_hash' => (string)$row->getData(MaintenancePreviewToken::schema_fields_TOKEN_HASH),
            'kid' => (string)$row->getData(MaintenancePreviewToken::schema_fields_KID),
            'generation' => (int)$row->getData(MaintenancePreviewToken::schema_fields_GENERATION),
            'issued_at' => (int)$row->getData(MaintenancePreviewToken::schema_fields_ISSUED_AT),
            'expires_at' => (int)$row->getData(MaintenancePreviewToken::schema_fields_EXPIRES_AT),
            'revoked' => (int)$row->getData(MaintenancePreviewToken::schema_fields_REVOKED) === 1,
        ];
    }

    public function revokeToken(string $tokenHash, int $now, string $actor = 'system'): bool
    {
        $this->assertTokenHash($tokenHash);
        $this->assertNow($now);
        $actor = $this->normalizeActor($actor);
        $connection = $this->tokenModel->getConnection();

        return $this->transactions->runWrite($connection, function () use (
            $tokenHash,
            $now,
            $actor,
        ): bool {
            $row = $this->loadToken($tokenHash, true);
            if ($row === null) {
                return false;
            }
            if ((int)$row->getData(MaintenancePreviewToken::schema_fields_REVOKED) !== 1) {
                (clone $this->tokenModel)->clear()
                    ->where(MaintenancePreviewToken::schema_fields_TOKEN_HASH, $tokenHash)
                    ->update([
                        MaintenancePreviewToken::schema_fields_REVOKED => 1,
                        MaintenancePreviewToken::schema_fields_REVOKED_AT => $now,
                    ])
                    ->fetch();
                $this->appendAudit(
                    (string)$row->getData(MaintenancePreviewToken::schema_fields_SCOPE_KEY),
                    ScopeMaintenanceAudit::ACTION_TOKEN_REVOKED,
                    (int)$row->getData(MaintenancePreviewToken::schema_fields_GENERATION),
                    $tokenHash,
                    $actor,
                    $now,
                );
            }
            return true;
        });
    }

    public function revokeAllForScope(
        ScopeIdentity $scope,
        int $now,
        string $actor = 'system',
    ): void {
        $this->assertNow($now);
        $actor = $this->normalizeActor($actor);
        $connection = $this->tokenModel->getConnection();
        $this->transactions->runWrite($connection, function () use ($scope, $now, $actor): void {
            $this->revokeScopeRows($scope->canonicalKey(), $now);
            $state = $this->status($scope);
            $this->appendAudit(
                $scope->canonicalKey(),
                ScopeMaintenanceAudit::ACTION_TOKENS_REVOKED,
                $state['generation'],
                null,
                $actor,
                $now,
            );
        });
    }

    public function auditForScope(ScopeIdentity $scope): array
    {
        return (clone $this->auditModel)->clear()
            ->where(ScopeMaintenanceAudit::schema_fields_SCOPE_KEY, $scope->canonicalKey())
            ->order(ScopeMaintenanceAudit::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
    }

    private function loadState(ScopeIdentity $scope, bool $lockingRead): ?ScopeMaintenanceState
    {
        $row = clone $this->stateModel;
        $row->clear()->where(ScopeMaintenanceState::schema_fields_SCOPE_KEY, $scope->canonicalKey());
        if ($lockingRead && $this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        return (int)$row->getData(ScopeMaintenanceState::schema_fields_ID) > 0 ? $row : null;
    }

    private function loadToken(string $tokenHash, bool $lockingRead): ?MaintenancePreviewToken
    {
        $row = clone $this->tokenModel;
        $row->clear()->where(MaintenancePreviewToken::schema_fields_TOKEN_HASH, $tokenHash);
        if ($lockingRead && $this->supportsForUpdate()) {
            $row->additional('FOR UPDATE');
        }
        $row->find()->fetch();
        return (int)$row->getData(MaintenancePreviewToken::schema_fields_ID) > 0 ? $row : null;
    }

    private function revokeScopeRows(string $scopeKey, int $now): void
    {
        (clone $this->tokenModel)->clear()
            ->where(MaintenancePreviewToken::schema_fields_SCOPE_KEY, $scopeKey)
            ->where(MaintenancePreviewToken::schema_fields_REVOKED, 0)
            ->update([
                MaintenancePreviewToken::schema_fields_REVOKED => 1,
                MaintenancePreviewToken::schema_fields_REVOKED_AT => $now,
            ])
            ->fetch();
    }

    private function appendAudit(
        string $scopeKey,
        string $action,
        int $generation,
        ?string $tokenHash,
        string $actor,
        int $now,
    ): void {
        (clone $this->auditModel)->clear()->setData([
            ScopeMaintenanceAudit::schema_fields_SCOPE_KEY => $scopeKey,
            ScopeMaintenanceAudit::schema_fields_ACTION => $action,
            ScopeMaintenanceAudit::schema_fields_GENERATION => $generation,
            ScopeMaintenanceAudit::schema_fields_TOKEN_HASH => $tokenHash,
            ScopeMaintenanceAudit::schema_fields_ACTOR => $actor,
            ScopeMaintenanceAudit::schema_fields_RECORDED_AT => gmdate('Y-m-d H:i:s', $now),
        ])->save(true);
    }

    /**
     * @return array<string,mixed>
     */
    private function scopeColumns(ScopeIdentity $scope): array
    {
        if ($scope->isGlobal() || $scope->websiteId === null || $scope->websiteCode === null) {
            throw new \InvalidArgumentException('scope_maintenance_global_not_supported');
        }
        return [
            ScopeMaintenanceState::schema_fields_SCOPE_KEY => $scope->canonicalKey(),
            ScopeMaintenanceState::schema_fields_SCOPE_KIND => $scope->scopeKind,
            ScopeMaintenanceState::schema_fields_WEBSITE_ID => $scope->websiteId,
            ScopeMaintenanceState::schema_fields_WEBSITE_CODE => $scope->websiteCode,
            ScopeMaintenanceState::schema_fields_STORE_CODE => $scope->storeCode,
            ScopeMaintenanceState::schema_fields_CHANNEL_CODE => $scope->channelCode,
            ScopeMaintenanceState::schema_fields_STORE_MODE => $scope->storeMode,
            ScopeMaintenanceState::schema_fields_CONTEXT_VERSION => $scope->contextVersion,
        ];
    }

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    private function defaultStatus(ScopeIdentity $scope): array
    {
        $this->scopeColumns($scope);
        return [
            'scope_key' => $scope->canonicalKey(),
            'enabled' => false,
            'reason' => '',
            'generation' => 0,
            'since' => 0,
        ];
    }

    /**
     * @return array{scope_key:string,enabled:bool,reason:string,generation:int,since:int}
     */
    private function stateArray(ScopeMaintenanceState $row): array
    {
        return [
            'scope_key' => (string)$row->getData(ScopeMaintenanceState::schema_fields_SCOPE_KEY),
            'enabled' => (int)$row->getData(ScopeMaintenanceState::schema_fields_ENABLED) === 1,
            'reason' => (string)$row->getData(ScopeMaintenanceState::schema_fields_REASON),
            'generation' => (int)$row->getData(ScopeMaintenanceState::schema_fields_GENERATION),
            'since' => (int)$row->getData(ScopeMaintenanceState::schema_fields_SINCE),
        ];
    }

    private function assertTokenHash(string $tokenHash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $tokenHash) !== 1) {
            throw new \InvalidArgumentException('maintenance_preview_token_hash_invalid');
        }
    }

    private function assertNow(int $now): void
    {
        if ($now < 1) {
            throw new \InvalidArgumentException('scope_maintenance_time_invalid');
        }
    }

    private function normalizeActor(string $actor): string
    {
        $actor = trim($actor);
        if ($actor === '' || strlen($actor) > 128) {
            throw new \InvalidArgumentException('scope_maintenance_actor_invalid');
        }
        return $actor;
    }

    private function supportsForUpdate(): bool
    {
        $type = strtolower((string)$this->stateModel->getConnection()
            ->getConnector()->getConfigProvider()->getDbType());
        return in_array($type, ['mysql', 'mariadb', 'pgsql', 'postgres', 'postgresql'], true);
    }
}
