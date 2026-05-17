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
        'build_plan_v2' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['build_plan_v2'],
            'empty' => [],
        ],
        'plan_projection' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['plan_projection'],
            'empty' => [],
        ],
        'content_manifest' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['content_manifest'],
            'empty' => [],
        ],
        'build_blueprint' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['build_blueprint'],
            'empty' => [],
        ],
        'build_workbench' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['build_workbench'],
            'empty' => [],
        ],
        'build_contracts' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['build_contracts'],
            'empty' => [],
        ],
        'render_data_contract' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['render_data_contract'],
            'empty' => [],
        ],
        'task_results' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['task_results'],
            'empty' => [],
        ],
        'qa_report' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['qa_report_v2'],
            'empty' => [],
        ],
        'repair_patch' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['repair_patch'],
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
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
        ],
        AiSiteAgentSession::STAGE_VISUAL_EDIT => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'build_blueprint',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'task_results',
            'qa_report',
            'repair_patch',
        ],
        AiSiteAgentSession::STAGE_PUBLISH => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'build_blueprint',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'task_results',
            'qa_report',
            'repair_patch',
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
        $explicitArtifactRefReset = \array_key_exists(self::REF_KEY, $scope)
            && \is_array($scope[self::REF_KEY])
            && $scope[self::REF_KEY] === [];
        $refs = \is_array($scope[self::REF_KEY] ?? null) ? $scope[self::REF_KEY] : [];
        $touchedMap = \array_fill_keys($touchedArtifactKeys, true);
        $artifacts = [];

        foreach (self::ARTIFACT_DEFINITIONS as $artifactKey => $definition) {
            $stageCode = (string)$definition['stage'];
            $value = $this->getPathValue($scope, $definition['path'], $definition['empty']);
            $hasValue = $this->hasArtifactPayload($value);
            $existingRef = \is_array($refs[$stageCode][$artifactKey] ?? null) ? $refs[$stageCode][$artifactKey] : [];

            if ($explicitArtifactRefReset && !isset($touchedMap[$artifactKey])) {
                $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                unset($refs[$stageCode][$artifactKey]);
                continue;
            }

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
            $scope['theme_context_snapshot'],
            $scope['shared_prompt_context']
        );

        foreach (['build_blueprint'] as $key) {
            if (\is_array($scope[$key] ?? null)) {
                $scope[$key] = $this->stripSnapshotBackupsFromTree($scope[$key]);
            }
        }

        return $scope;
    }

    private function compactArtifactPayloadForStorage(string $artifactKey, mixed $value): mixed
    {
        return $value;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function stripSnapshotBackupsFromTree(array $tree): array
    {
        unset(
            $tree['confirmed_stage1_plan_book'],
            $tree['theme_context_snapshot'],
            $tree['shared_prompt_context']
        );

        if (\is_array($tree['runtime_context'] ?? null)) {
            unset(
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

            $artifact = $this->loadArtifactModel($sessionId, $stageCode, $artifactKey);
            if ($artifact->getId() <= 0) {
                continue;
            }

            $ref = $this->getArtifactRef($scope, $stageCode, $artifactKey);
            if ($ref !== []) {
                $refHash = \trim((string)($ref['hash'] ?? ''));
                $payloadHash = \trim((string)$artifact->getPayloadHash());
                if ($refHash !== '' && $payloadHash !== '' && !\hash_equals($refHash, $payloadHash)) {
                    continue;
                }
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
        foreach ([
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'build_blueprint',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'task_results',
            'qa_report_v2',
            'repair_patch',
        ] as $topLevelKey) {
            if (\array_key_exists($topLevelKey, $patch)) {
                $keys[] = $topLevelKey === 'qa_report_v2' ? 'qa_report' : $topLevelKey;
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
            if (\in_array($artifactKey, ['build_blueprint'], true)) {
                $expanded[] = 'build_blueprint';
            }
            if (\in_array($artifactKey, ['build_plan_v2', 'plan_projection', 'content_manifest'], true)) {
                $expanded[] = 'build_plan_v2';
                $expanded[] = 'plan_projection';
                $expanded[] = 'content_manifest';
            }
            if (\in_array($artifactKey, ['task_results', 'qa_report', 'repair_patch'], true)) {
                $expanded[] = 'task_results';
                $expanded[] = 'qa_report';
                $expanded[] = 'repair_patch';
            }
        }

        return \array_values(\array_unique($expanded));
    }

    public function deleteArtifactsForSession(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }
        $this->ensureArtifactTableExists();
        $pdo = $this->getPgsqlPdo();
        if ($pdo === null) {
            return;
        }

        $table = $this->artifactModel->getTable();
        $sessionIdField = AiSiteAgentSessionArtifact::schema_fields_AGENT_SESSION_ID;
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE \"{$sessionIdField}\" = :session_id");
        if ($stmt) {
            $stmt->execute(['session_id' => $sessionId]);
        }
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
