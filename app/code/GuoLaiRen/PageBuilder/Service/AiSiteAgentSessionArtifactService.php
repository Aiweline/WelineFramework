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
    private const STORAGE_INLINE = 'session_artifact_v1';
    private const STORAGE_EXTERNAL_FILE = 'session_artifact_file_v1';
    private const EXTERNAL_PAYLOAD_THRESHOLD_BYTES = 524288;

    private const ARTIFACT_FILE_BASE_DIR = 'data/pagebuilder/session-artifacts';

    // build 流程中同一会话的 plan_workbench / build_plan_v2 等 artifact 单文件可能达到 6-8MB JSON，
    // 同进程内 hydrateScope 会被反复触发（loadScopeForStage 调用 50+ 次）。每次都做 file_get_contents +
    // json_decode 会让 worker 在 512MB memory_limit 下 OOM。这里按 payload_hash 做 LRU 缓存，
    // 命中时直接复用已解码的 array（PHP COW 不会立即翻倍内存），未命中再走 disk + decode。
    // 上限 6 控制最坏情况下缓存常驻内存，超出按 FIFO 淘汰。
    private const PAYLOAD_VALUE_CACHE_LIMIT = 1;
    private const PAYLOAD_VALUE_CACHE_MAX_BYTES = 1048576;

    private bool $artifactTableEnsured = false;

    /** @var array<string, mixed> payload_hash => decoded payload */
    private array $payloadValueCache = [];

    /** @var array<string, bool> payload_hash => true，按 LRU 顺序维护 */
    private array $payloadValueCacheOrder = [];

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
        'plan_workbench' => [
            'stage' => AiSiteAgentSession::STAGE_PLAN,
            'path' => ['plan_workbench'],
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
        'theme_css' => [
            'stage' => AiSiteAgentSession::STAGE_VISUAL_EDIT,
            'path' => ['theme_css'],
            'empty' => '',
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
            'plan_workbench',
        ],
        AiSiteAgentSession::STAGE_VISUAL_EDIT => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'plan_workbench',
            'build_workbench',
            'build_contracts',
            'render_data_contract',
            'task_results',
            'qa_report',
            'repair_patch',
            'theme_css',
        ],
        AiSiteAgentSession::STAGE_PUBLISH => [
            'plan_json',
            'plan_structured',
            'plan_markdown',
            'build_plan_v2',
            'plan_projection',
            'content_manifest',
            'plan_workbench',
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
     * @return array{scope: array<string, mixed>, artifacts: list<array{stage_code:string, artifact_key:string, payload_json:string, hash:string, bytes:int, storage:string}>}
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
                if (
                    \in_array($artifactKey, ['plan_json', 'plan_structured'], true)
                    && \is_array($value)
                    && !isset($touchedMap[$artifactKey])
                    && !$this->hasCompleteStageOnePlanPayload($value)
                    && $existingRef !== []
                ) {
                    $refs[$stageCode][$artifactKey] = $existingRef;
                    $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                    continue;
                }
                if (
                    $artifactKey === 'build_plan_v2'
                    && \is_array($value)
                    && !$this->hasCompleteBuildPlanArtifactPayload($value)
                    && $existingRef !== []
                ) {
                    $refs[$stageCode][$artifactKey] = $existingRef;
                    $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                    continue;
                }
                $value = $this->compactArtifactPayloadForStorage($artifactKey, $value);
                $json = $this->encodeValueDocument($value);
                $hash = \sha1($json);
                $bytes = \strlen($json);
                $storage = $bytes > self::EXTERNAL_PAYLOAD_THRESHOLD_BYTES ? self::STORAGE_EXTERNAL_FILE : self::STORAGE_INLINE;
                $previousHash = \trim((string)($existingRef['hash'] ?? ''));
                $previousStorage = \trim((string)($existingRef['storage'] ?? ''));
                if ($previousHash !== '' && \hash_equals($previousHash, $hash) && $previousStorage === $storage) {
                    $refs[$stageCode][$artifactKey] = \array_replace($existingRef, [
                        'storage' => $storage,
                        'stage_code' => $stageCode,
                        'artifact_key' => $artifactKey,
                        'hash' => $hash,
                        'bytes' => $bytes,
                    ]);
                    $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                    unset($json, $value);
                    continue;
                }
                $payloadDocumentJson = $json;
                if ($storage === self::STORAGE_EXTERNAL_FILE && \defined('BP')) {
                    $relativePath = $this->writeExternalPayloadDocument($sessionId, $stageCode, $artifactKey, $hash, $json);
                    $payloadDocumentJson = $this->encodeValueDocument([
                        AiSiteAgentSessionArtifact::EXTERNAL_PAYLOAD_FILE_KEY => $relativePath,
                        'hash' => $hash,
                        'bytes' => $bytes,
                    ]);
                    unset($json);
                }
                $refs[$stageCode][$artifactKey] = [
                    'storage' => $storage,
                    'stage_code' => $stageCode,
                    'artifact_key' => $artifactKey,
                    'hash' => $hash,
                    'bytes' => $bytes,
                    'updated_at' => \date('Y-m-d H:i:s'),
                ];
                $artifacts[] = [
                    'stage_code' => $stageCode,
                    'artifact_key' => $artifactKey,
                    'payload_json' => $payloadDocumentJson,
                    'hash' => $hash,
                    'bytes' => $bytes,
                    'storage' => $storage,
                ];
                $scope = $this->setPathValue($scope, $definition['path'], $definition['empty']);
                unset($payloadDocumentJson, $value);
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

        return $scope;
    }

    private function compactArtifactPayloadForStorage(string $artifactKey, mixed $value): mixed
    {
        return $value;
    }

    /**
     * Manifest-only workspace loads can contain lightweight plan metadata such as
     * {"content_locale":"en_US"}. Those payloads must never replace a complete
     * plan artifact produced by stage one.
     *
     * @param array<string, mixed> $value
     */
    private function hasCompleteStageOnePlanPayload(array $value): bool
    {
        $pages = \is_array($value['pages'] ?? null) ? $value['pages'] : [];
        if ($pages !== []) {
            return true;
        }

        $pagePlans = \is_array($value['page_plans'] ?? null) ? $value['page_plans'] : [];
        return $pagePlans !== [];
    }

    /**
     * @param list<array{stage_code:string, artifact_key:string, payload_json?:string, payload?:mixed, hash?:string, bytes?:int, storage?:string}> $artifacts
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
            $payloadJson = (string)($artifactData['payload_json'] ?? '');
            $payloadHash = \trim((string)($artifactData['hash'] ?? ''));
            $payloadBytes = (int)($artifactData['bytes'] ?? 0);
            if ($payloadJson !== '') {
                if ($payloadBytes > self::EXTERNAL_PAYLOAD_THRESHOLD_BYTES && !$this->isExternalPayloadDocumentJson($payloadJson)) {
                    $relativePath = $this->writeExternalPayloadDocument($sessionId, $stageCode, $artifactKey, $payloadHash, $payloadJson);
                    $payloadJson = $this->encodeValueDocument([
                        AiSiteAgentSessionArtifact::EXTERNAL_PAYLOAD_FILE_KEY => $relativePath,
                        'hash' => $payloadHash,
                        'bytes' => $payloadBytes,
                    ]);
                }
                $artifact->setPayloadDocumentJson($payloadJson, $payloadHash, $payloadBytes > 0 ? $payloadBytes : null);
            } else {
                $artifact->setPayloadValue($artifactData['payload'] ?? []);
            }
            $artifact->save();
            // 写入完成的 payload 直接登记进进程内 cache，后续 hydrate 同 hash 时
            // 无需再 file_get_contents + json_decode 同一份 6MB+ 的大文件。
            if (
                $payloadHash !== ''
                && \array_key_exists('payload', $artifactData)
                && $this->hasArtifactPayload($artifactData['payload'])
                && $this->shouldCachePayloadValue($payloadBytes)
            ) {
                $this->payloadValueCache[$payloadHash] = $artifactData['payload'];
                unset($this->payloadValueCacheOrder[$payloadHash]);
                $this->payloadValueCacheOrder[$payloadHash] = true;
                while (\count($this->payloadValueCache) > self::PAYLOAD_VALUE_CACHE_LIMIT) {
                    $evict = \array_key_first($this->payloadValueCacheOrder);
                    if ($evict === null) {
                        break;
                    }
                    unset($this->payloadValueCache[$evict], $this->payloadValueCacheOrder[$evict]);
                }
            }
            $artifact->clearData()->clearQuery();
            unset($artifact, $payloadJson);
        }
    }

    private function isExternalPayloadDocumentJson(string $payloadJson): bool
    {
        if (!\str_contains($payloadJson, AiSiteAgentSessionArtifact::EXTERNAL_PAYLOAD_FILE_KEY)) {
            return false;
        }

        try {
            $decoded = \json_decode($payloadJson, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        $value = \is_array($decoded) ? ($decoded['value'] ?? null) : null;

        return \is_array($value)
            && \trim((string)($value[AiSiteAgentSessionArtifact::EXTERNAL_PAYLOAD_FILE_KEY] ?? '')) !== '';
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

        $explicitKeys = $artifactKeys !== [];
        $keys = $explicitKeys ? $artifactKeys : $this->resolveReferencedArtifactKeys($scope);
        \file_put_contents(
            BP . 'var/log/emergency_fallback.log',
            \date('Y-m-d H:i:s') . " [hydrateScope] sessionId={$sessionId} explicitKeys=" . ($explicitKeys ? 'true' : 'false') . ' keys=' . \implode(',', $keys) . "\n",
            \FILE_APPEND
        );
        foreach (\array_values(\array_unique($keys)) as $artifactKey) {
            $definition = self::ARTIFACT_DEFINITIONS[$artifactKey] ?? null;
            if (!\is_array($definition)) {
                \error_log("[hydrateScope] skip {$artifactKey}: no definition");
                continue;
            }

            $stageCode = (string)$definition['stage'];
            $inlineValue = $this->getPathValue($scope, $definition['path'], $definition['empty']);
            if (!$this->shouldHydrateArtifactFromStorage($artifactKey, $inlineValue)) {
                \error_log("[hydrateScope] skip {$artifactKey}: shouldHydrate=false (inlineValue type=" . \gettype($inlineValue) . ')');
                continue;
            }

            $artifact = $this->loadArtifactModel($sessionId, $stageCode, $artifactKey);
            \error_log("[hydrateScope] {$artifactKey}: artifactId=" . $artifact->getId());
            if ($artifact->getId() <= 0) {
                if (!$explicitKeys) {
                    continue;
                }
                // Fallback: load latest artifact from filesystem when DB metadata is missing.
                $payload = $this->loadLatestArtifactFromFilesystem($sessionId, $stageCode, $artifactKey);
                \error_log("[hydrateScope] {$artifactKey}: filesystem fallback payload=" . (\is_array($payload) ? 'array(' . \count($payload) . ')' : \gettype($payload)));
                if (!$this->hasArtifactPayload($payload)) {
                    continue;
                }
                $scope = $this->setPathValue($scope, $definition['path'], $payload);
                continue;
            }

            $ref = $this->getArtifactRef($scope, $stageCode, $artifactKey);
            if ($ref !== [] && !$explicitKeys) {
                $refHash = \trim((string)($ref['hash'] ?? ''));
                $payloadHash = \trim((string)$artifact->getPayloadHash());
                if ($refHash !== '' && $payloadHash !== '' && !\hash_equals($refHash, $payloadHash)) {
                    \error_log("[hydrateScope] skip {$artifactKey}: hash mismatch ref={$refHash} payload={$payloadHash}");
                    continue;
                }
            }

            $payload = $this->loadCachedOrFreshPayloadValue($artifact);
            if (!$this->hasArtifactPayload($payload)) {
                \error_log("[hydrateScope] skip {$artifactKey}: no payload");
                continue;
            }

            \error_log("[hydrateScope] {$artifactKey}: loaded OK, type=" . \gettype($payload));
            $scope = $this->setPathValue($scope, $definition['path'], $payload);
        }

        return $scope;
    }

    /**
     * 按 payload_hash 进行进程内 LRU 缓存：避免同一 worker 中反复 file_get_contents +
     * json_decode 同一份 6MB+ 大 artifact。命中时直接复用已解码 array，PHP COW 保障
     * 下游 setPathValue 入 scope 后不会立刻翻倍内存。
     */
    private function loadCachedOrFreshPayloadValue(AiSiteAgentSessionArtifact $artifact): mixed
    {
        $hash = \trim((string)$artifact->getPayloadHash());
        if ($hash !== '' && \array_key_exists($hash, $this->payloadValueCache)) {
            unset($this->payloadValueCacheOrder[$hash]);
            $this->payloadValueCacheOrder[$hash] = true;
            return $this->payloadValueCache[$hash];
        }

        $payload = $artifact->getPayloadValue();
        if ($hash !== '' && $this->hasArtifactPayload($payload) && $this->shouldCachePayloadValue($artifact->getPayloadBytes())) {
            $this->payloadValueCache[$hash] = $payload;
            unset($this->payloadValueCacheOrder[$hash]);
            $this->payloadValueCacheOrder[$hash] = true;
            while (\count($this->payloadValueCache) > self::PAYLOAD_VALUE_CACHE_LIMIT) {
                $evict = \array_key_first($this->payloadValueCacheOrder);
                if ($evict === null) {
                    break;
                }
                unset($this->payloadValueCache[$evict], $this->payloadValueCacheOrder[$evict]);
            }
        }

        return $payload;
    }

    private function shouldCachePayloadValue(int $payloadBytes): bool
    {
        return $payloadBytes > 0 && $payloadBytes <= self::PAYLOAD_VALUE_CACHE_MAX_BYTES;
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

    public function releasePayloadCache(): void
    {
        $this->payloadValueCache = [];
        $this->payloadValueCacheOrder = [];
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

    private function writeExternalPayloadDocument(
        int $sessionId,
        string $stageCode,
        string $artifactKey,
        string $payloadHash,
        string $payloadJson
    ): string {
        $hash = \preg_match('/^[a-f0-9]{40}$/i', $payloadHash) === 1 ? \strtolower($payloadHash) : \sha1($payloadJson);
        $stageCode = $this->sanitizeStorageSegment($stageCode);
        $artifactKey = $this->sanitizeStorageSegment($artifactKey);
        $directory = BP . self::ARTIFACT_FILE_BASE_DIR . \DIRECTORY_SEPARATOR . $sessionId . \DIRECTORY_SEPARATOR . $stageCode;
        if (!\is_dir($directory)) {
            \mkdir($directory, 0775, true);
        }

        $filename = $artifactKey . '-' . $hash . '.json';
        $path = $directory . \DIRECTORY_SEPARATOR . $filename;
        if (!\is_file($path)) {
            $temporaryPath = $path . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
            \file_put_contents($temporaryPath, $payloadJson, \LOCK_EX);
            \rename($temporaryPath, $path);
        }

        return self::ARTIFACT_FILE_BASE_DIR . '/' . $sessionId . '/' . $stageCode . '/' . $filename;
    }

    private function sanitizeStorageSegment(string $value): string
    {
        $segment = (string)\preg_replace('/[^a-zA-Z0-9_.-]+/', '_', \trim($value));

        return $segment !== '' ? $segment : 'artifact';
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

    /**
     * Load the latest artifact payload directly from filesystem when DB metadata is missing.
     *
     * @return array<string, mixed>|string|null
     */
    private function loadLatestArtifactFromFilesystem(int $sessionId, string $stageCode, string $artifactKey): mixed
    {
        $directory = BP . self::ARTIFACT_FILE_BASE_DIR . \DIRECTORY_SEPARATOR . $sessionId . \DIRECTORY_SEPARATOR . $stageCode;
        if (!\is_dir($directory)) {
            return null;
        }

        $prefix = $artifactKey . '-';
        $latestFile = null;
        $latestMtime = 0;

        $handle = \opendir($directory);
        if ($handle === false) {
            return null;
        }
        while (($entry = \readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..' || !\str_starts_with($entry, $prefix) || !\str_ends_with($entry, '.json')) {
                continue;
            }
            $filePath = $directory . \DIRECTORY_SEPARATOR . $entry;
            $mtime = \filemtime($filePath);
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
                $latestFile = $filePath;
            }
        }
        \closedir($handle);

        if ($latestFile === null) {
            return null;
        }

        $json = \file_get_contents($latestFile);
        if ($json === false || \trim($json) === '') {
            return null;
        }

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
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

    private function shouldHydrateArtifactFromStorage(string $artifactKey, mixed $inlineValue): bool
    {
        if (!$this->hasArtifactPayload($inlineValue)) {
            return true;
        }
        if ($artifactKey === 'build_plan_v2' && \is_array($inlineValue)) {
            return !$this->hasCompleteBuildPlanArtifactPayload($inlineValue);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function hasCompleteBuildPlanArtifactPayload(array $contract): bool
    {
        $blocks = \is_array($contract['blocks'] ?? null) ? $contract['blocks'] : [];
        $pages = \is_array($contract['pages'] ?? null) ? $contract['pages'] : [];

        return $blocks !== [] && $pages !== [];
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
