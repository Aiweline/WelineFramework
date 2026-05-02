<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use GuoLaiRen\PageBuilder\Model\AiSiteAgentSession;
use GuoLaiRen\PageBuilder\Model\AiSiteAgentSessionArtifact;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AiSiteAgentSessionArtifactService
{
    private const REF_KEY = '_artifact_refs';
    private bool $artifactTableEnsured = false;

    /**
     * @var array<string, array{stage:string, path:list<string>, empty:mixed}>
     */
    private const ARTIFACT_DEFINITIONS = [
        'plan_json' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['plan_json'],
            'empty' => [],
        ],
        'plan_structured' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['plan_structured'],
            'empty' => [],
        ],
        'plan_markdown' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['plan_markdown'],
            'empty' => '',
        ],
        'task_plan_structured' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['task_plan_structured'],
            'empty' => [],
        ],
        'task_plan_markdown' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['task_plan_markdown'],
            'empty' => '',
        ],
        'task_plan_draft' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['virtual_theme_plan', 'draft'],
            'empty' => [],
        ],
        'task_plan_draft_markdown' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['virtual_theme_plan', 'draft_markdown'],
            'empty' => '',
        ],
        'task_plan_confirmed' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['virtual_theme_plan', 'confirmed'],
            'empty' => [],
        ],
        'task_plan_confirmed_markdown' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['virtual_theme_plan', 'confirmed_markdown'],
            'empty' => '',
        ],
        'build_blueprint' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['build_blueprint'],
            'empty' => [],
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ARTIFACT_KEYS_BY_STAGE = [
        AiSiteAgentSession::STAGE_PLAN => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
        ],
        AiSiteAgentSession::STAGE_VISUAL_EDIT => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'task_plan_structured',
            'task_plan_markdown',
            'task_plan_draft',
            'task_plan_draft_markdown',
            'task_plan_confirmed',
            'task_plan_confirmed_markdown',
            'build_blueprint',
        ],
        AiSiteAgentSession::STAGE_PUBLISH => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'task_plan_structured',
            'task_plan_markdown',
            'task_plan_draft',
            'task_plan_draft_markdown',
            'task_plan_confirmed',
            'task_plan_confirmed_markdown',
            'build_blueprint',
        ],
    ];

    public function __construct(
        private readonly AiSiteAgentSessionArtifact $artifactModel
    ) {
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $touchedArtifactKeys
     * @return array{scope: array<string, mixed>, artifacts: list<array{stage_code:string, artifact_key:string, payload:mixed, hash:string, bytes:int}>}
     */
    public function prepareScopeForStorage(int $sessionId, array $scope, array $touchedArtifactKeys = []): array
    {
        if ($sessionId <= 0) {
            return ['scope' => $scope, 'artifacts' => []];
        }

        $scope = $this->removeSnapshotBackupsFromScope($scope);
        $scope = $this->compactStageTwoArtifactsForStorage($scope);
        $refs = \is_array($scope[self::REF_KEY] ?? null) ? $scope[self::REF_KEY] : [];
        $touchedMap = \array_fill_keys($touchedArtifactKeys, true);
        $artifacts = [];

        foreach (self::ARTIFACT_DEFINITIONS as $artifactKey => $definition) {
            $stageCode = (string)$definition['stage'];
            $value = $this->getPathValue($scope, $definition['path'], $definition['empty']);
            $hasValue = $this->hasArtifactPayload($value);
            $existingRef = \is_array($refs[$stageCode][$artifactKey] ?? null) ? $refs[$stageCode][$artifactKey] : [];

            if ($hasValue) {
                $value = $this->compactArtifactPayloadForStorage($artifactKey, $value);
                $json = $this->encodeValueDocument($value);
                $hash = \sha1($json);
                $bytes = \strlen($json);
                $previousHash = \trim((string)($existingRef['hash'] ?? ''));
                if ($previousHash !== '' && \hash_equals($previousHash, $hash)) {
                    $refs[$stageCode][$artifactKey] = \array_replace($existingRef, [
                        'storage' => 'session_artifact_v1',
                        'stage_code' => $stageCode,
                        'artifact_key' => $artifactKey,
                        'hash' => $hash,
                        'bytes' => $bytes,
                    ]);
                    $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                    unset($json, $value);
                    continue;
                }
                $refs[$stageCode][$artifactKey] = [
                    'storage' => 'session_artifact_v1',
                    'stage_code' => $stageCode,
                    'artifact_key' => $artifactKey,
                    'hash' => $hash,
                    'bytes' => $bytes,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ];
                $artifacts[] = [
                    'stage_code' => $stageCode,
                    'artifact_key' => $artifactKey,
                    'payload' => $value,
                    'hash' => $hash,
                    'bytes' => $bytes,
                ];
                $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                unset($json, $value);
                continue;
            }

            if ($existingRef !== [] && !isset($touchedMap[$artifactKey])) {
                $refs[$stageCode][$artifactKey] = $existingRef;
                $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                continue;
            }

            if (isset($refs[$stageCode][$artifactKey])) {
                unset($refs[$stageCode][$artifactKey]);
            }
        }

        $refs = $this->pruneEmptyRefs($refs);
        if ($refs === []) {
            unset($scope[self::REF_KEY]);
        } else {
            $scope[self::REF_KEY] = $refs;
        }

        return ['scope' => $scope, 'artifacts' => $artifacts];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function removeSnapshotBackupsFromScope(array $scope): array
    {
        unset(
            $scope['confirmed_stage1_plan_book'],
            $scope['stage2_context_snapshot'],
            $scope['theme_context_snapshot'],
            $scope['shared_prompt_context']
        );

        foreach (['build_blueprint', 'task_plan_structured'] as $key) {
            if (\is_array($scope[$key] ?? null)) {
                $scope[$key] = $this->stripSnapshotBackupsFromTree($scope[$key]);
            }
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        foreach (['draft', 'confirmed'] as $key) {
            if (\is_array($virtualThemePlan[$key] ?? null)) {
                $virtualThemePlan[$key] = $this->stripSnapshotBackupsFromTree($virtualThemePlan[$key]);
            }
        }
        if ($virtualThemePlan !== []) {
            $scope['virtual_theme_plan'] = $virtualThemePlan;
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function compactStageTwoArtifactsForStorage(array $scope): array
    {
        if (\is_array($scope['task_plan_structured'] ?? null)) {
            $scope['task_plan_structured'] = $this->compactStageTwoTaskPlanPayloadForStorage(
                $scope['task_plan_structured']
            );
        }

        $virtualThemePlan = \is_array($scope['virtual_theme_plan'] ?? null) ? $scope['virtual_theme_plan'] : [];
        foreach (['draft', 'confirmed'] as $key) {
            if (\is_array($virtualThemePlan[$key] ?? null)) {
                $virtualThemePlan[$key] = $this->compactStageTwoTaskPlanPayloadForStorage($virtualThemePlan[$key]);
            }
        }

        $draft = \is_array($virtualThemePlan['draft'] ?? null) ? $virtualThemePlan['draft'] : [];
        $confirmed = \is_array($virtualThemePlan['confirmed'] ?? null) ? $virtualThemePlan['confirmed'] : [];
        $structured = \is_array($scope['task_plan_structured'] ?? null) ? $scope['task_plan_structured'] : [];

        if ($draft !== [] && $structured !== [] && $this->stageTwoTaskPlanPayloadsEquivalent($structured, $draft)) {
            $scope['task_plan_structured'] = [];
            $structured = [];
        }

        if ((int)($scope['task_plan_confirmed'] ?? 0) === 1 && $confirmed !== []) {
            if ($structured !== [] && $this->stageTwoTaskPlanPayloadsEquivalent($structured, $confirmed)) {
                $scope['task_plan_structured'] = [];
            }
            if ($draft !== [] && $this->stageTwoTaskPlanPayloadsEquivalent($draft, $confirmed)) {
                $virtualThemePlan['draft'] = [];
            }
        }

        if ($virtualThemePlan !== []) {
            $scope['virtual_theme_plan'] = $virtualThemePlan;
        }

        return $scope;
    }

    private function compactArtifactPayloadForStorage(string $artifactKey, mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (\in_array($artifactKey, ['task_plan_structured', 'task_plan_draft', 'task_plan_confirmed'], true)) {
            return $this->compactStageTwoTaskPlanPayloadForStorage($value);
        }

        return $value;
    }

    /**
     * Stage-2 snapshots carry the same contract set in three places:
     * task_plan_workbench.contracts, task_plan_workbench.confirmed.contracts,
     * and stage2_contracts. Keep stage2_contracts as the canonical contract
     * payload and store lightweight refs in the workbench mirrors.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function compactStageTwoTaskPlanPayloadForStorage(array $snapshot): array
    {
        $snapshot = $this->stripSnapshotBackupsFromTree($snapshot);
        $contracts = \is_array($snapshot['stage2_contracts'] ?? null) ? $snapshot['stage2_contracts'] : [];
        if ($contracts === []) {
            return $snapshot;
        }

        $contractRefs = $this->buildContractRefs($contracts);
        $workbench = \is_array($snapshot['task_plan_workbench'] ?? null) ? $snapshot['task_plan_workbench'] : [];
        if ($workbench === []) {
            return $snapshot;
        }

        if (\is_array($workbench['contracts'] ?? null)) {
            unset($workbench['contracts']);
            $workbench['contract_refs'] = $contractRefs;
        }

        if (\is_array($workbench['confirmed'] ?? null)) {
            if (\is_array($workbench['confirmed']['contracts'] ?? null)) {
                unset($workbench['confirmed']['contracts']);
                $workbench['confirmed']['contract_refs'] = $contractRefs;
            }
            if ($workbench['confirmed'] === []) {
                unset($workbench['confirmed']);
            }
        }

        $snapshot['task_plan_workbench'] = $workbench;

        return $snapshot;
    }

    /**
     * @param array<int|string, mixed> $contracts
     * @return array<string, array{id:string,type:string,version:string,status:string,payload_hash:string}>
     */
    private function buildContractRefs(array $contracts): array
    {
        $refs = [];
        foreach ($contracts as $key => $contract) {
            if (!\is_array($contract) || $contract === []) {
                continue;
            }
            $meta = \is_array($contract['contract_meta'] ?? null) ? $contract['contract_meta'] : [];
            $type = \trim((string)($meta['type'] ?? $contract['type'] ?? (\is_string($key) ? $key : '')));
            if ($type === '') {
                continue;
            }
            $payload = \is_array($contract['payload'] ?? null) ? $contract['payload'] : [];
            $id = \trim((string)($meta['id'] ?? $meta['contract_id'] ?? $contract['id'] ?? $contract['contract_id'] ?? ''));
            if ($id === '') {
                $id = 'contract_' . \substr(\sha1((string)\json_encode($contract, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)), 0, 16);
            }
            $refs[$type] = [
                'id' => $id,
                'type' => $type,
                'version' => \trim((string)($meta['version'] ?? $contract['version'] ?? 'v1')),
                'status' => \trim((string)($meta['status'] ?? $contract['status'] ?? '')),
                'payload_hash' => \sha1((string)\json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)),
            ];
        }

        return $refs;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function stageTwoTaskPlanPayloadsEquivalent(array $left, array $right): bool
    {
        $leftCore = $this->stageTwoTaskPlanEquivalenceCore($left);
        $rightCore = $this->stageTwoTaskPlanEquivalenceCore($right);

        return $leftCore !== [] && $leftCore == $rightCore;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function stageTwoTaskPlanEquivalenceCore(array $payload): array
    {
        $core = [];
        foreach ([
            'plan_signature',
            'content_locale',
            'plan_locale',
            'shared_tasks',
            'page_tasks',
            'task_tree',
            'execution_blueprint',
            'execution_order',
            'block_task_schema',
            'stage2_contracts',
        ] as $key) {
            if (\array_key_exists($key, $payload)) {
                $core[$key] = $payload[$key];
            }
        }

        return $core;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function stripSnapshotBackupsFromTree(array $tree): array
    {
        unset(
            $tree['confirmed_stage1_plan_book'],
            $tree['stage2_context_snapshot'],
            $tree['theme_context_snapshot'],
            $tree['shared_prompt_context']
        );

        if (\is_array($tree['runtime_context'] ?? null)) {
            unset(
                $tree['runtime_context']['stage2_context_snapshot'],
                $tree['runtime_context']['theme_context_snapshot'],
                $tree['runtime_context']['shared_prompt_context']
            );
            if ($tree['runtime_context'] === []) {
                unset($tree['runtime_context']);
            }
        }

        foreach (['tasks', 'shared_tasks'] as $listKey) {
            if (!\is_array($tree[$listKey] ?? null)) {
                continue;
            }
            foreach ($tree[$listKey] as $idx => $item) {
                if (\is_array($item)) {
                    $tree[$listKey][$idx] = $this->stripSnapshotBackupsFromTree($item);
                }
            }
        }

        foreach (['page_tasks', 'pages'] as $mapKey) {
            if (!\is_array($tree[$mapKey] ?? null)) {
                continue;
            }
            foreach ($tree[$mapKey] as $key => $value) {
                if (\is_array($value)) {
                    $tree[$mapKey][$key] = $this->stripSnapshotBackupsFromTree($value);
                }
            }
        }

        if (\is_array($tree['execution_blueprint']['tasks'] ?? null)) {
            foreach ($tree['execution_blueprint']['tasks'] as $idx => $task) {
                if (\is_array($task)) {
                    $tree['execution_blueprint']['tasks'][$idx] = $this->stripSnapshotBackupsFromTree($task);
                }
            }
        }

        if (\is_array($tree['execution_blueprint']['task_groups'] ?? null)) {
            $tree['execution_blueprint']['task_groups'] = $this->stripSnapshotBackupsFromTree($tree['execution_blueprint']['task_groups']);
        }

        return $tree;
    }

    /**
     * @param list<array{stage_code:string, artifact_key:string, payload:mixed}> $artifacts
     */
    public function persistArtifacts(int $sessionId, array $artifacts): void
    {
        if ($sessionId <= 0 || $artifacts === []) {
            return;
        }
        $this->ensureArtifactTableExists();

        foreach ($artifacts as $artifactData) {
            $stageCode = \trim((string)($artifactData['stage_code'] ?? ''));
            $artifactKey = \trim((string)($artifactData['artifact_key'] ?? ''));
            if ($stageCode === '' || $artifactKey === '') {
                continue;
            }

            $artifact = $this->loadArtifactModel($sessionId, $stageCode, $artifactKey);
            $now = \date('Y-m-d H:i:s');
            if ($artifact->getId() <= 0) {
                $artifact->setData(AiSiteAgentSessionArtifact::schema_fields_AGENT_SESSION_ID, $sessionId);
                $artifact->setData(AiSiteAgentSessionArtifact::schema_fields_STAGE_CODE, $stageCode);
                $artifact->setData(AiSiteAgentSessionArtifact::schema_fields_ARTIFACT_KEY, $artifactKey);
                $artifact->setData(AiSiteAgentSessionArtifact::schema_fields_CREATE_TIME, $now);
            }
            $artifact->setData(AiSiteAgentSessionArtifact::schema_fields_UPDATE_TIME, $now);
            $artifact->setPayloadValue($artifactData['payload'] ?? []);
            $artifact->save();
            $artifact->clearData()->clearQuery();
            unset($artifact);
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $artifactKeys Empty means all referenced artifacts.
     * @return array<string, mixed>
     */
    public function hydrateScope(int $sessionId, array $scope, array $artifactKeys = []): array
    {
        if ($sessionId <= 0) {
            return $scope;
        }

        $keys = $artifactKeys === [] ? $this->resolveReferencedArtifactKeys($scope) : $artifactKeys;
        foreach (\array_values(\array_unique($keys)) as $artifactKey) {
            $definition = self::ARTIFACT_DEFINITIONS[$artifactKey] ?? null;
            if (!\is_array($definition)) {
                continue;
            }

            $stageCode = (string)$definition['stage'];
            if ($this->hasArtifactPayload($this->getPathValue($scope, $definition['path'], $definition['empty']))) {
                continue;
            }

            $ref = $this->getArtifactRef($scope, $stageCode, $artifactKey);
            if ($ref === []) {
                continue;
            }

            $artifact = $this->loadArtifactModel($sessionId, $stageCode, $artifactKey);
            if ($artifact->getId() <= 0) {
                continue;
            }

            $payload = $artifact->getPayloadValue();
            if (!$this->hasArtifactPayload($payload)) {
                continue;
            }

            $scope = $this->setPathValue($scope, $definition['path'], $payload);
        }

        return $scope;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function hydrateScopeForStage(int $sessionId, array $scope, string $stageCode): array
    {
        $stageCode = \trim($stageCode);
        $keys = self::ARTIFACT_KEYS_BY_STAGE[$stageCode] ?? [];
        if ($keys === []) {
            return $scope;
        }

        return $this->hydrateScope($sessionId, $scope, $keys);
    }

    /**
     * @return list<string>
     */
    public function artifactKeysForStage(string $stageCode): array
    {
        return self::ARTIFACT_KEYS_BY_STAGE[\trim($stageCode)] ?? [];
    }

    /**
     * @return list<string>
     */
    public function artifactPath(string $artifactKey): array
    {
        $definition = self::ARTIFACT_DEFINITIONS[$artifactKey] ?? null;

        return \is_array($definition) && \is_array($definition['path'] ?? null) ? $definition['path'] : [];
    }

    public function artifactStage(string $artifactKey): string
    {
        $definition = self::ARTIFACT_DEFINITIONS[$artifactKey] ?? null;

        return \is_array($definition) ? (string)($definition['stage'] ?? '') : '';
    }

    public function payloadHasContent(mixed $value): bool
    {
        return $this->hasArtifactPayload($value);
    }

    /**
     * @param array<string, mixed> $patch
     * @return list<string>
     */
    public function resolveTouchedArtifactKeysFromPatch(array $patch): array
    {
        $keys = [];
        foreach (['plan_json', 'plan_structured', 'task_plan_structured', 'task_plan_markdown', 'build_blueprint'] as $topLevelKey) {
            if (\array_key_exists($topLevelKey, $patch)) {
                $keys[] = $topLevelKey;
            }
        }

        $virtualThemePlan = \is_array($patch['virtual_theme_plan'] ?? null) ? $patch['virtual_theme_plan'] : [];
        $nestedMap = [
            'draft' => 'task_plan_draft',
            'draft_markdown' => 'task_plan_draft_markdown',
            'confirmed' => 'task_plan_confirmed',
            'confirmed_markdown' => 'task_plan_confirmed_markdown',
        ];
        foreach ($nestedMap as $nestedKey => $artifactKey) {
            if (\array_key_exists($nestedKey, $virtualThemePlan)) {
                $keys[] = $artifactKey;
            }
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * @param list<string> $artifactKeys
     * @return list<string>
     */
    public function expandArtifactKeysForMerge(array $artifactKeys): array
    {
        $expanded = $artifactKeys;
        foreach ($artifactKeys as $artifactKey) {
            if (\in_array($artifactKey, ['task_plan_draft', 'task_plan_draft_markdown', 'task_plan_confirmed', 'task_plan_confirmed_markdown'], true)) {
                $expanded[] = 'task_plan_draft';
                $expanded[] = 'task_plan_draft_markdown';
                $expanded[] = 'task_plan_confirmed';
                $expanded[] = 'task_plan_confirmed_markdown';
            }
            if (\in_array($artifactKey, ['task_plan_structured', 'task_plan_markdown', 'build_blueprint'], true)) {
                $expanded[] = 'task_plan_structured';
                $expanded[] = 'task_plan_markdown';
                $expanded[] = 'build_blueprint';
            }
        }

        return \array_values(\array_unique($expanded));
    }

    private function loadArtifactModel(int $sessionId, string $stageCode, string $artifactKey): AiSiteAgentSessionArtifact
    {
        $this->ensureArtifactTableExists();
        $artifact = clone $this->artifactModel;
        $artifact->clearData()->clearQuery()
            ->where(AiSiteAgentSessionArtifact::schema_fields_AGENT_SESSION_ID, $sessionId)
            ->where(AiSiteAgentSessionArtifact::schema_fields_STAGE_CODE, $stageCode)
            ->where(AiSiteAgentSessionArtifact::schema_fields_ARTIFACT_KEY, $artifactKey)
            ->find()
            ->fetch();

        return $artifact;
    }

    private function ensureArtifactTableExists(): void
    {
        if ($this->artifactTableEnsured) {
            return;
        }
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            $this->artifactTableEnsured = true;
            return;
        }

        $table = $this->artifactModel->getTable();
        $id = AiSiteAgentSessionArtifact::schema_fields_ID;
        $sessionId = AiSiteAgentSessionArtifact::schema_fields_AGENT_SESSION_ID;
        $stageCode = AiSiteAgentSessionArtifact::schema_fields_STAGE_CODE;
        $artifactKey = AiSiteAgentSessionArtifact::schema_fields_ARTIFACT_KEY;
        $payloadJson = AiSiteAgentSessionArtifact::schema_fields_PAYLOAD_JSON;
        $payloadHash = AiSiteAgentSessionArtifact::schema_fields_PAYLOAD_HASH;
        $payloadBytes = AiSiteAgentSessionArtifact::schema_fields_PAYLOAD_BYTES;
        $createTime = AiSiteAgentSessionArtifact::schema_fields_CREATE_TIME;
        $updateTime = AiSiteAgentSessionArtifact::schema_fields_UPDATE_TIME;

        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    "{$id}" SERIAL PRIMARY KEY,
    "{$sessionId}" INTEGER NOT NULL,
    "{$stageCode}" VARCHAR(64) NOT NULL DEFAULT '',
    "{$artifactKey}" VARCHAR(96) NOT NULL DEFAULT '',
    "{$payloadJson}" TEXT NULL,
    "{$payloadHash}" VARCHAR(64) NOT NULL DEFAULT '',
    "{$payloadBytes}" INTEGER NOT NULL DEFAULT 0,
    "{$createTime}" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "{$updateTime}" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
        $pdo->exec(<<<SQL
CREATE UNIQUE INDEX IF NOT EXISTS idx_pb_ai_site_artifact_session_stage_key
ON {$table} ("{$sessionId}", "{$stageCode}", "{$artifactKey}")
SQL);
        $pdo->exec(<<<SQL
CREATE INDEX IF NOT EXISTS idx_pb_ai_site_artifact_session_stage
ON {$table} ("{$sessionId}", "{$stageCode}")
SQL);
        $this->syncArtifactPrimaryKeySequence($pdo, $table, $id);

        $this->artifactTableEnsured = true;
    }

    private function syncArtifactPrimaryKeySequence(\PDO $pdo, string $table, string $idField): void
    {
        try {
            $sequenceNameStmt = $pdo->query(
                "SELECT pg_get_serial_sequence('{$table}', '{$idField}') AS seq_name"
            );
            if (!$sequenceNameStmt instanceof \PDOStatement) {
                return;
            }
            $sequenceName = (string)$sequenceNameStmt->fetchColumn();
            if (\trim($sequenceName) === '') {
                return;
            }

            // Keep sequence in sync with existing rows to avoid duplicate PK on insert.
            $pdo->exec(
                "SELECT setval('{$sequenceName}', COALESCE((SELECT MAX(\"{$idField}\") FROM {$table}), 0), true)"
            );
        } catch (\Throwable) {
            // Ignore sequence sync failures and keep runtime path non-fatal.
        }
    }

    private function getPgsqlPdo(): ?\PDO
    {
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connector instanceof PgsqlConnector || !\method_exists($connector, 'getWrappedConnection')) {
            return null;
        }
        $pdo = $connector->getWrappedConnection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return null;
        }

        return $pdo;
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function resolveReferencedArtifactKeys(array $scope): array
    {
        $refs = \is_array($scope[self::REF_KEY] ?? null) ? $scope[self::REF_KEY] : [];
        $keys = [];
        foreach ($refs as $stageRefs) {
            if (!\is_array($stageRefs)) {
                continue;
            }
            foreach (\array_keys($stageRefs) as $artifactKey) {
                if (\is_string($artifactKey) && isset(self::ARTIFACT_DEFINITIONS[$artifactKey])) {
                    $keys[] = $artifactKey;
                }
            }
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    private function getArtifactRef(array $scope, string $stageCode, string $artifactKey): array
    {
        $refs = \is_array($scope[self::REF_KEY] ?? null) ? $scope[self::REF_KEY] : [];
        $ref = $refs[$stageCode][$artifactKey] ?? [];

        return \is_array($ref) ? $ref : [];
    }

    private function hasArtifactPayload(mixed $value): bool
    {
        if (\is_array($value)) {
            return $value !== [];
        }
        if (\is_string($value)) {
            return \trim($value) !== '';
        }

        return $value !== null && $value !== false;
    }

    private function encodeValueDocument(mixed $value): string
    {
        try {
            return (string)\json_encode(
                ['value' => $value],
                \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            return '{"value":[]}';
        }
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $path
     */
    private function getPathValue(array $scope, array $path, mixed $default = null): mixed
    {
        $cursor = $scope;
        foreach ($path as $part) {
            if (!\is_array($cursor) || !\array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * @param array<string, mixed> $scope
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function setPathValue(array $scope, array $path, mixed $value): array
    {
        if ($path === []) {
            return $scope;
        }

        $cursor =& $scope;
        foreach ($path as $index => $part) {
            if ($index === \count($path) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!\is_array($cursor[$part] ?? null)) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
        unset($cursor);

        return $scope;
    }

    /**
     * @param array<string, mixed> $refs
     * @return array<string, mixed>
     */
    private function pruneEmptyRefs(array $refs): array
    {
        foreach ($refs as $stageCode => $stageRefs) {
            if (!\is_array($stageRefs) || $stageRefs === []) {
                unset($refs[$stageCode]);
            }
        }

        return $refs;
    }
}
