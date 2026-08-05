<?php

declare(strict_types=1);

use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchRolloutGate;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Model\SystemConfig;

require dirname(__DIR__, 7) . '/app/bootstrap.php';

/** @return array<string,mixed> */
function r43_search_input(): array
{
    $decoded = json_decode((string)file_get_contents('php://stdin'), true);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new InvalidArgumentException('stdin_must_be_json_object');
    }
    return $decoded;
}

/** @param array<string,mixed> $payload */
function r43_search_output(array $payload, int $code = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    exit($code);
}

/** @return array{connector:string,database:string} */
function r43_search_assert_isolated_pgsql(): array
{
    if (getenv('WELINE_E2E_ISOLATED_DB') !== '1') {
        throw new RuntimeException('r43_search_requires_isolated_database_opt_in');
    }
    /** @var SystemConfig $model */
    $model = ObjectManager::getInstance(SystemConfig::class);
    $connector = get_class($model->getConnection()->getConnector());
    if (!str_contains(strtolower($connector), 'pgsql') && !str_contains(strtolower($connector), 'postgres')) {
        throw new RuntimeException('r43_search_requires_postgresql:' . $connector);
    }
    $database = (string)$model->getConnection()->getConnector()->getConfigProvider()->getDatabase();
    if (!str_starts_with($database, 'mig_clone_')) {
        throw new RuntimeException('r43_search_requires_migration_clone:' . $database);
    }
    return ['connector' => $connector, 'database' => $database];
}

/** @return list<string> */
function r43_search_subjects(array $configuration): array
{
    $subjects = array_map(
        static fn(array $row): string => SearchRolloutGate::tupleKey(
            (int)$row['website_id'],
            (int)$row['store_id'],
            (int)$row['channel_id'],
        ),
        (array)($configuration['allowlist_rows'] ?? []),
    );
    sort($subjects, SORT_STRING);

    return $subjects;
}

/** @return array{requested:bool,instance:string,exit_code:?int} */
function r43_search_reload_dedicated_wls(): array
{
    $configured = getenv('WELINE_E2E_WLS_INSTANCE');
    if ($configured === false || $configured === '') {
        return ['requested' => false, 'instance' => '', 'exit_code' => null];
    }
    if (trim($configured) !== $configured
        || preg_match('/^ai-test-commerce-r43-[A-Za-z0-9][A-Za-z0-9_-]{0,80}$/D', $configured) !== 1
    ) {
        throw new RuntimeException('r43_search_refuses_non_dedicated_wls_instance:' . $configured);
    }

    $root = dirname(__DIR__, 7);
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $root . '/bin/w', 'server:reload', $configured],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('r43_search_dedicated_wls_reload_start_failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('r43_search_dedicated_wls_reload_failed:' . trim((string)$stderr . "\n" . (string)$stdout));
    }

    return ['requested' => true, 'instance' => $configured, 'exit_code' => $exitCode];
}

try {
    $input = r43_search_input();
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $isolation = r43_search_assert_isolated_pgsql();
    $connector = $isolation['connector'];
    $database = $isolation['database'];
    /** @var SearchRolloutGate $gate */
    $gate = ObjectManager::getInstance(SearchRolloutGate::class);

    if ($action === 'prepare') {
        $configuration = $gate->configuration();
        if (!empty($configuration['env_locked'])) {
            throw new RuntimeException('search_rollout_env_locked');
        }
        $target = ($configuration['mode'] ?? '') === CommerceRolloutGateInterface::MODE_SHADOW
            ? CommerceRolloutGateInterface::MODE_OFF
            : CommerceRolloutGateInterface::MODE_SHADOW;
        r43_search_output([
            'ok' => true,
            'connector' => $connector,
            'database' => $database,
            'original_mode' => (string)$configuration['mode'],
            'original_subjects' => r43_search_subjects($configuration),
            'target_mode' => $target,
        ]);
    }

    if ($action === 'inspect') {
        $expected = (string)($input['expected_mode'] ?? '');
        $configuration = $gate->configuration();
        $actual = (string)($configuration['mode'] ?? '');
        $ok = $actual === $expected && ($configuration['allowlist'] ?? []) === [];
        r43_search_output([
            'ok' => $ok,
            'connector' => $connector,
            'database' => $database,
            'actual_mode' => $actual,
            'allowlist_count' => count((array)($configuration['allowlist'] ?? [])),
        ], $ok ? 0 : 1);
    }

    if ($action === 'cleanup') {
        $originalMode = trim((string)($input['original_mode'] ?? ''));
        $originalSubjects = array_values(array_map('strval', (array)($input['original_subjects'] ?? [])));
        sort($originalSubjects, SORT_STRING);
        $gate->setMode(
            SearchRolloutGate::CAPABILITY,
            $originalMode,
            $originalSubjects,
            $originalMode === CommerceRolloutGateInterface::MODE_ON ? 'r43-e2e-clone-restore' : '',
        );
        $restored = $gate->configuration();
        $restoredSubjects = r43_search_subjects($restored);
        if ((string)($restored['mode'] ?? '') !== $originalMode || $restoredSubjects !== $originalSubjects) {
            throw new RuntimeException('search_rollout_cleanup_business_state_mismatch');
        }
        $wlsReload = r43_search_reload_dedicated_wls();
        r43_search_output([
            'ok' => true,
            'connector' => $connector,
            'database' => $database,
            'restored_mode' => $originalMode,
            'restored_subjects' => $restoredSubjects,
            'formal_api_restore' => true,
            'wls_reload' => $wlsReload,
        ]);
    }

    throw new InvalidArgumentException('unknown_action:' . $action);
} catch (Throwable $throwable) {
    r43_search_output(['ok' => false, 'error' => $throwable->getMessage()], 1);
}
