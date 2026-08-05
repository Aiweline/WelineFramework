<?php

declare(strict_types=1);

/**
 * TEST-P1D-05: production ORM persistence, Website isolation, audit, and recording-off withdrawal.
 *
 * stdin JSON: {"action":"cleanup"|"grant"|"read"|"recording_off"}
 * stdout JSON only.
 */

use Weline\Consent\Api\ConsentRecordingPolicyInterface;
use Weline\Consent\Api\ConsentRepositoryInterface;
use Weline\Consent\Model\ConsentAudit;
use Weline\Consent\Model\ConsentRecord;
use Weline\Consent\Service\ConsentService;
use Weline\Framework\Manager\ObjectManager;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

const P1D05_WEBSITE_A = 101;
const P1D05_WEBSITE_B = 102;
const P1D05_VISITOR = 'v1_P1D05AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const P1D05_BLOCKED_VISITOR = 'v1_P1D05BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBAA';

/**
 * @return array<string, mixed>
 */
function p1d05_input(): array
{
    $raw = file_get_contents('php://stdin');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        throw new \InvalidArgumentException('stdin must be a JSON object');
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function p1d05_output(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function p1d05_cleanup(): void
{
    $om = ObjectManager::getInstance();
    foreach ([P1D05_VISITOR, P1D05_BLOCKED_VISITOR] as $visitorKey) {
        /** @var ConsentAudit $audit */
        $audit = clone $om->get(ConsentAudit::class);
        $audit->clear()
            ->where(ConsentAudit::schema_fields_VISITOR_KEY, $visitorKey)
            ->delete()
            ->fetch();

        /** @var ConsentRecord $record */
        $record = clone $om->get(ConsentRecord::class);
        $record->clear()
            ->where(ConsentRecord::schema_fields_VISITOR_KEY, $visitorKey)
            ->delete()
            ->fetch();
    }
}

/**
 * @return array<string, mixed>
 */
function p1d05_grant(): array
{
    p1d05_cleanup();
    /** @var ConsentService $service */
    $service = ObjectManager::getInstance()->get(ConsentService::class);
    foreach (['necessary', 'analytics', 'marketing'] as $category) {
        $service->grant(P1D05_WEBSITE_A, P1D05_VISITOR, $category);
    }

    return [
        'ok' => true,
        'website_a' => P1D05_WEBSITE_A,
        'website_b' => P1D05_WEBSITE_B,
        'records_a' => count($service->listForWebsite(P1D05_WEBSITE_A)),
        'audit_a' => count($service->auditForWebsite(P1D05_WEBSITE_A)),
    ];
}

/**
 * @return array<string, mixed>
 */
function p1d05_read(): array
{
    /** @var ConsentService $service */
    $service = ObjectManager::getInstance()->get(ConsentService::class);
    $grantedA = [];
    $grantedB = [];
    foreach (['necessary', 'analytics', 'marketing'] as $category) {
        $grantedA[$category] = $service->isGranted(P1D05_WEBSITE_A, P1D05_VISITOR, $category);
        $grantedB[$category] = $service->isGranted(P1D05_WEBSITE_B, P1D05_VISITOR, $category);
    }
    if (in_array(false, $grantedA, true) || in_array(true, $grantedB, true)) {
        throw new \RuntimeException('consent Website isolation or cross-process persistence failed');
    }
    if ($service->shouldShowBanner(P1D05_WEBSITE_A, P1D05_VISITOR)) {
        throw new \RuntimeException('completed Website A consent must hide banner');
    }
    if (!$service->shouldShowBanner(P1D05_WEBSITE_B, P1D05_VISITOR)) {
        throw new \RuntimeException('isolated Website B must show banner');
    }

    return [
        'ok' => true,
        'granted_a' => $grantedA,
        'granted_b' => $grantedB,
        'banner_a' => false,
        'banner_b' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function p1d05_recording_off(): array
{
    $repository = ObjectManager::getInstance()->get(ConsentRepositoryInterface::class);
    $disabledPolicy = new class implements ConsentRecordingPolicyInterface {
        public function isRecordingEnabled(): bool
        {
            return false;
        }
    };
    $service = new ConsentService($repository, $disabledPolicy);

    $rejected = false;
    try {
        $service->grant(P1D05_WEBSITE_A, P1D05_BLOCKED_VISITOR, 'analytics');
    } catch (\RuntimeException $exception) {
        $rejected = $exception->getMessage() === 'consent_recording_disabled';
    }
    if (!$rejected) {
        throw new \RuntimeException('recording-off must reject new grants');
    }
    if ($service->isGranted(P1D05_WEBSITE_A, P1D05_BLOCKED_VISITOR, 'analytics')) {
        throw new \RuntimeException('rejected grant must not persist');
    }
    $service->withdraw(P1D05_WEBSITE_A, P1D05_VISITOR, 'analytics');
    if ($service->isGranted(P1D05_WEBSITE_A, P1D05_VISITOR, 'analytics')) {
        throw new \RuntimeException('withdrawal must update current state');
    }
    $audit = $service->auditForWebsite(P1D05_WEBSITE_A);
    if (count($audit) !== 4) {
        throw new \RuntimeException('append-only audit count mismatch');
    }

    return [
        'ok' => true,
        'new_grant_rejected' => true,
        'withdrawal_allowed' => true,
        'audit_count' => count($audit),
        'last_action' => (string)($audit[array_key_last($audit)]['action'] ?? ''),
    ];
}

try {
    $action = (string)(p1d05_input()['action'] ?? '');
    $result = match ($action) {
        'cleanup' => (function (): array {
            p1d05_cleanup();
            return ['ok' => true, 'cleaned' => true];
        })(),
        'grant' => p1d05_grant(),
        'read' => p1d05_read(),
        'recording_off' => p1d05_recording_off(),
        default => throw new \InvalidArgumentException('unknown action: ' . $action),
    };
    p1d05_output($result);
} catch (\Throwable $throwable) {
    p1d05_output(['ok' => false, 'error' => $throwable->getMessage()]);
    exit(1);
}
