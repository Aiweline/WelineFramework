<?php

declare(strict_types=1);

/**
 * TEST-P1D-06: production ORM restart, Scope A/B, revoke and generation.
 *
 * stdin JSON:
 * {"action":"cleanup"|"enable_issue"|"read"|"revoke"|"generation","token":"..."}
 */

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Model\MaintenancePreviewToken;
use Weline\Websites\Model\ScopeMaintenanceAudit;
use Weline\Websites\Model\ScopeMaintenanceState;
use Weline\Websites\Service\MaintenancePreviewTokenService;
use Weline\Websites\Service\ScopeMaintenanceGate;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

function p1d06_scope_a(): ScopeIdentity
{
    return ScopeIdentity::store(0, 'default', 'p1d06_a', ScopeIdentity::MODE_TEST);
}

function p1d06_scope_b(): ScopeIdentity
{
    return ScopeIdentity::store(0, 'default', 'p1d06_b', ScopeIdentity::MODE_TEST);
}

function p1d06_scope_live(): ScopeIdentity
{
    return ScopeIdentity::channel(
        0,
        'default',
        'default',
        'default',
        ScopeIdentity::MODE_NORMAL,
    );
}

function p1d06_scope_live_legacy(): ScopeIdentity
{
    return ScopeIdentity::channel(
        0,
        'default',
        'main',
        'web',
        ScopeIdentity::MODE_NORMAL,
    );
}

/** @return array<string,mixed> */
function p1d06_input(): array
{
    $raw = file_get_contents('php://stdin');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }
    return $data;
}

/** @param array<string,mixed> $payload */
function p1d06_output(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p1d06_cleanup(): void
{
    $om = ObjectManager::getInstance();
    foreach ([
        p1d06_scope_a()->canonicalKey(),
        p1d06_scope_b()->canonicalKey(),
        p1d06_scope_live()->canonicalKey(),
        p1d06_scope_live_legacy()->canonicalKey(),
    ] as $scopeKey) {
        /** @var ScopeMaintenanceAudit $audit */
        $audit = clone $om->get(ScopeMaintenanceAudit::class);
        $audit->clear()
            ->where(ScopeMaintenanceAudit::schema_fields_SCOPE_KEY, $scopeKey)
            ->delete()
            ->fetch();
        /** @var MaintenancePreviewToken $tokens */
        $tokens = clone $om->get(MaintenancePreviewToken::class);
        $tokens->clear()
            ->where(MaintenancePreviewToken::schema_fields_SCOPE_KEY, $scopeKey)
            ->delete()
            ->fetch();
        /** @var ScopeMaintenanceState $state */
        $state = clone $om->get(ScopeMaintenanceState::class);
        $state->clear()
            ->where(ScopeMaintenanceState::schema_fields_SCOPE_KEY, $scopeKey)
            ->delete()
            ->fetch();
    }
}

/** @return array<string,mixed> */
function p1d06_live_enable_issue(): array
{
    $now = time();
    $om = ObjectManager::getInstance();
    /** @var ScopeMaintenanceGate $gate */
    $gate = $om->get(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = $om->get(MaintenancePreviewTokenService::class);
    $state = $gate->enable(p1d06_scope_live(), 'p1d06-live', $now, 'fixture');
    return [
        'ok' => true,
        'token' => $tokens->issue(p1d06_scope_live(), 300, $now, 'fixture'),
        'generation' => $state['generation'],
    ];
}

/** @return array<string,mixed> */
function p1d06_live_reenable_issue(): array
{
    $now = time();
    $om = ObjectManager::getInstance();
    /** @var ScopeMaintenanceGate $gate */
    $gate = $om->get(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = $om->get(MaintenancePreviewTokenService::class);
    $gate->disable(p1d06_scope_live(), $now, 'fixture');
    $state = $gate->enable(p1d06_scope_live(), 'p1d06-live-reenabled', $now + 1, 'fixture');
    return [
        'ok' => true,
        'token' => $tokens->issue(p1d06_scope_live(), 300, $now + 1, 'fixture'),
        'generation' => $state['generation'],
    ];
}

/** @return array<string,mixed> */
function p1d06_live_disable(): array
{
    /** @var ScopeMaintenanceGate $gate */
    $gate = ObjectManager::getInstance()->get(ScopeMaintenanceGate::class);
    $state = $gate->disable(p1d06_scope_live(), time(), 'fixture');
    return ['ok' => true, 'generation' => $state['generation'], 'enabled' => $state['enabled']];
}

/** @return array<string,mixed> */
function p1d06_enable_issue(): array
{
    p1d06_cleanup();
    $now = time();
    $om = ObjectManager::getInstance();
    /** @var ScopeMaintenanceGate $gate */
    $gate = $om->get(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = $om->get(MaintenancePreviewTokenService::class);
    $state = $gate->enable(p1d06_scope_a(), 'p1d06', $now, 'fixture');
    $token = $tokens->issue(p1d06_scope_a(), 300, $now, 'fixture');
    return [
        'ok' => true,
        'token' => $token,
        'generation' => $state['generation'],
        'a_enabled' => $gate->isMaintenance(p1d06_scope_a()),
        'b_enabled' => $gate->isMaintenance(p1d06_scope_b()),
    ];
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function p1d06_read(array $input): array
{
    $token = (string)($input['token'] ?? '');
    $om = ObjectManager::getInstance();
    /** @var ScopeMaintenanceGate $gate */
    $gate = $om->get(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = $om->get(MaintenancePreviewTokenService::class);
    $validA = $tokens->verify($token, p1d06_scope_a());
    $validB = $tokens->verify($token, p1d06_scope_b());
    if (!$gate->isMaintenance(p1d06_scope_a())
        || $gate->isMaintenance(p1d06_scope_b())
        || !$validA
        || $validB) {
        throw new \RuntimeException('maintenance restart or Scope isolation failed');
    }
    return [
        'ok' => true,
        'a_enabled' => true,
        'b_enabled' => false,
        'valid_a' => $validA,
        'valid_b' => $validB,
    ];
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function p1d06_revoke(array $input): array
{
    $token = (string)($input['token'] ?? '');
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = ObjectManager::getInstance()->get(MaintenancePreviewTokenService::class);
    if (!$tokens->revoke($token, time(), 'fixture')) {
        throw new \RuntimeException('maintenance token revoke failed');
    }
    return [
        'ok' => true,
        'revoked' => true,
        'valid_after_revoke' => $tokens->verify($token, p1d06_scope_a()),
    ];
}

/** @return array<string,mixed> */
function p1d06_generation(): array
{
    $now = time();
    $om = ObjectManager::getInstance();
    /** @var ScopeMaintenanceGate $gate */
    $gate = $om->get(ScopeMaintenanceGate::class);
    /** @var MaintenancePreviewTokenService $tokens */
    $tokens = $om->get(MaintenancePreviewTokenService::class);
    $old = $tokens->issue(p1d06_scope_a(), 300, $now, 'fixture');
    $disabled = $gate->disable(p1d06_scope_a(), $now + 1, 'fixture');
    $enabled = $gate->enable(p1d06_scope_a(), 'p1d06-reenabled', $now + 2, 'fixture');
    $new = $tokens->issue(p1d06_scope_a(), 300, $now + 2, 'fixture');
    $oldValid = $tokens->verify($old, p1d06_scope_a(), $now + 3);
    $newValid = $tokens->verify($new, p1d06_scope_a(), $now + 3);
    $audit = $tokens->auditForScope(p1d06_scope_a());
    if ($oldValid || !$newValid || $enabled['generation'] !== $disabled['generation'] + 1) {
        throw new \RuntimeException('maintenance generation invalidation failed');
    }
    return [
        'ok' => true,
        'old_valid' => $oldValid,
        'new_valid' => $newValid,
        'disabled_generation' => $disabled['generation'],
        'enabled_generation' => $enabled['generation'],
        'audit_actions' => array_column($audit, ScopeMaintenanceAudit::schema_fields_ACTION),
    ];
}

try {
    $input = p1d06_input();
    $result = match ((string)($input['action'] ?? '')) {
        'cleanup' => (function (): array {
            p1d06_cleanup();
            return ['ok' => true, 'cleaned' => true];
        })(),
        'enable_issue' => p1d06_enable_issue(),
        'read' => p1d06_read($input),
        'revoke' => p1d06_revoke($input),
        'generation' => p1d06_generation(),
        'live_enable_issue' => p1d06_live_enable_issue(),
        'live_reenable_issue' => p1d06_live_reenable_issue(),
        'live_disable' => p1d06_live_disable(),
        default => throw new \InvalidArgumentException('unknown action'),
    };
    p1d06_output($result);
} catch (\Throwable $throwable) {
    p1d06_output(['ok' => false, 'error' => $throwable->getMessage()]);
    exit(1);
}
