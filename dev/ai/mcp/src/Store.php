<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Store
{
    public const SCHEMA_VERSION = 1;

    private PDO $db;
    private string $path;

    public function __construct(private readonly Config $config)
    {
        $dataDir = $config->dataDir();
        if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Unable to create data directory: ' . $dataDir);
        }
        chmod($dataDir, 0700);
        $this->path = $dataDir . '/learning.db';
        $this->db = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        foreach ([
            'PRAGMA foreign_keys = ON',
            'PRAGMA journal_mode = WAL',
            'PRAGMA synchronous = NORMAL',
            'PRAGMA busy_timeout = 5000',
        ] as $statement) {
            $this->db->exec($statement);
        }
        $this->migrate();
        if (is_file($this->path)) {
            chmod($this->path, 0600);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function close(): void
    {
        unset($this->db);
    }

    public function schemaVersion(): int
    {
        return (int) ($this->db->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() ?: 0);
    }

    /** @param array<string, mixed> $project */
    public function upsertProject(array $project): void
    {
        $now = self::now();
        $this->execute(
            'INSERT INTO projects(id, name, root_fingerprint, remote_fingerprint, default_branch, config_json, created_at, updated_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                name = excluded.name,
                root_fingerprint = excluded.root_fingerprint,
                remote_fingerprint = excluded.remote_fingerprint,
                default_branch = COALESCE(NULLIF(excluded.default_branch, \'\'), projects.default_branch),
                config_json = excluded.config_json,
                updated_at = excluded.updated_at',
            [
                self::required($project, 'id'),
                self::required($project, 'name'),
                self::required($project, 'root_fingerprint'),
                self::nullable($project['remote_fingerprint'] ?? ''),
                self::nullable($project['default_branch'] ?? ''),
                Json::encode($project['config'] ?? []),
                (string) ($project['created_at'] ?? $now),
                $now,
            ],
        );
    }

    /** @param array<string, mixed> $session */
    public function upsertSession(array $session): void
    {
        $now = self::now();
        $this->execute(
            'INSERT INTO sessions(id, project_id, agent, cwd, branch, worktree, base_commit, head_commit,
                dirty_at_start, dirty_at_end, status, outcome, consent_json, started_at, last_activity_at, closed_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                project_id = excluded.project_id,
                agent = excluded.agent,
                cwd = excluded.cwd,
                branch = excluded.branch,
                worktree = excluded.worktree,
                head_commit = COALESCE(NULLIF(excluded.head_commit, \'\'), sessions.head_commit),
                dirty_at_end = CASE WHEN excluded.status = \'closed\' THEN excluded.dirty_at_end ELSE sessions.dirty_at_end END,
                status = excluded.status,
                outcome = COALESCE(NULLIF(excluded.outcome, \'\'), sessions.outcome),
                consent_json = excluded.consent_json,
                last_activity_at = excluded.last_activity_at,
                closed_at = COALESCE(excluded.closed_at, sessions.closed_at)',
            [
                self::required($session, 'id'),
                self::required($session, 'project_id'),
                (string) ($session['agent'] ?? 'codex'),
                self::required($session, 'cwd'),
                self::nullable($session['branch'] ?? ''),
                self::nullable($session['worktree'] ?? ''),
                self::nullable($session['base_commit'] ?? ''),
                self::nullable($session['head_commit'] ?? ''),
                !empty($session['dirty_at_start']) ? 1 : 0,
                !empty($session['dirty_at_end']) ? 1 : 0,
                (string) ($session['status'] ?? 'active'),
                self::nullable($session['outcome'] ?? ''),
                Json::encode($session['consent'] ?? ['allow_learning' => true, 'allow_cross_project' => false]),
                (string) ($session['started_at'] ?? $now),
                (string) ($session['last_activity_at'] ?? $now),
                self::nullable($session['closed_at'] ?? ''),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function getSession(string $id): array
    {
        $row = $this->one(
            'SELECT id, project_id, agent, cwd, COALESCE(branch, \'\') AS branch,
                COALESCE(worktree, \'\') AS worktree, COALESCE(base_commit, \'\') AS base_commit,
                COALESCE(head_commit, \'\') AS head_commit, dirty_at_start, dirty_at_end,
                status, COALESCE(outcome, \'\') AS outcome, consent_json, started_at,
                last_activity_at, closed_at FROM sessions WHERE id = ?',
            [$id],
        );
        if ($row === null) {
            throw new ToolException('NOT_FOUND', 'Session not found', false, ['session_id' => $id]);
        }
        $row['dirty_at_start'] = (bool) $row['dirty_at_start'];
        $row['dirty_at_end'] = (bool) $row['dirty_at_end'];
        $row['consent'] = Json::decode((string) $row['consent_json'], []);
        unset($row['consent_json']);

        return $row;
    }

    /** @param array<string, mixed> $event
     *  @return array{id:string,inserted:bool}
     */
    public function insertEvent(array $event): array
    {
        foreach (['event_id', 'project_id', 'session_id', 'type', 'content_hash', 'dedup_key'] as $required) {
            self::required($event, $required);
        }
        $trust = is_array($event['trust'] ?? null) ? $event['trust'] : [];
        $score = (float) ($trust['score'] ?? 0.0);
        if ($score < 0 || $score > 1) {
            throw new RuntimeException('trust.score must be between 0 and 1');
        }
        $statement = $this->execute(
            'INSERT INTO events(id, schema_version, project_id, session_id, turn_id, episode_id, event_type,
                source, role, content_redacted, content_hash, dedup_key, raw_ref, trust_class, trust_score,
                context_json, metadata_json, observed_at, ingested_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(dedup_key) DO NOTHING',
            [
                $event['event_id'],
                (string) ($event['schema_version'] ?? 'event.v1'),
                $event['project_id'],
                $event['session_id'],
                self::nullable($event['turn_id'] ?? ''),
                self::nullable($event['episode_id'] ?? ''),
                $event['type'],
                (string) ($event['source'] ?? 'codex_hook'),
                self::nullable($event['role'] ?? ''),
                (string) ($event['content_redacted'] ?? ''),
                $event['content_hash'],
                $event['dedup_key'],
                self::nullable($event['raw_ref'] ?? ''),
                (string) ($trust['class'] ?? 'unclassified'),
                $score,
                Json::encode($event['context'] ?? []),
                Json::encode($event['metadata'] ?? []),
                (string) ($event['observed_at'] ?? self::now()),
                self::now(),
            ],
        );
        if ($statement->rowCount() === 0) {
            $id = (string) $this->scalar('SELECT id FROM events WHERE dedup_key = ?', [$event['dedup_key']]);
            return ['id' => $id, 'inserted' => false];
        }
        $this->execute('UPDATE sessions SET last_activity_at = ? WHERE id = ?', [(string) $event['observed_at'], $event['session_id']]);

        return ['id' => (string) $event['event_id'], 'inserted' => true];
    }

    public function closeSession(string $sessionId, string $outcome, string $closedAt): void
    {
        $statement = $this->execute(
            'UPDATE sessions SET status = \'closed\', outcome = ?, closed_at = ?, last_activity_at = ? WHERE id = ?',
            [self::nullable($outcome), $closedAt, $closedAt, $sessionId],
        );
        if ($statement->rowCount() !== 1) {
            throw new ToolException('NOT_FOUND', 'Session not found', false, ['session_id' => $sessionId]);
        }
    }

    /** @return list<array<string, mixed>> */
    public function listEvents(string $sessionId): array
    {
        $rows = $this->all(
            'SELECT id, schema_version, project_id, session_id, COALESCE(turn_id, \'\') AS turn_id,
                COALESCE(episode_id, \'\') AS episode_id, event_type, source, COALESCE(role, \'\') AS role,
                COALESCE(content_redacted, \'\') AS content_redacted, content_hash, dedup_key,
                COALESCE(raw_ref, \'\') AS raw_ref, trust_class, trust_score, context_json,
                metadata_json, observed_at, ingested_at
             FROM events WHERE session_id = ? ORDER BY observed_at, ingested_at, id',
            [$sessionId],
        );
        foreach ($rows as &$row) {
            $row = [
                'event_id' => $row['id'],
                'schema_version' => $row['schema_version'],
                'project_id' => $row['project_id'],
                'session_id' => $row['session_id'],
                'turn_id' => $row['turn_id'],
                'episode_id' => $row['episode_id'],
                'type' => $row['event_type'],
                'source' => $row['source'],
                'role' => $row['role'],
                'content_redacted' => $row['content_redacted'],
                'content_hash' => $row['content_hash'],
                'dedup_key' => $row['dedup_key'],
                'raw_ref' => $row['raw_ref'],
                'trust' => ['class' => $row['trust_class'], 'score' => (float) $row['trust_score']],
                'context' => Json::decode((string) $row['context_json'], []),
                'metadata' => Json::decode((string) $row['metadata_json'], []),
                'observed_at' => $row['observed_at'],
                'ingested_at' => $row['ingested_at'],
            ];
        }

        return $rows;
    }

    /** @return array{id:string,created:bool} */
    public function enqueueAnalysisForSession(string $sessionId, string $projectId): array
    {
        $session = $this->getSession($sessionId);
        if ($session['project_id'] !== $projectId) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Session belongs to a different project');
        }
        $last = $this->one(
            'SELECT COUNT(*) AS event_count,
                COALESCE((SELECT id FROM events WHERE session_id = ? ORDER BY observed_at DESC, ingested_at DESC, id DESC LIMIT 1), \'\') AS last_id
             FROM events WHERE session_id = ?',
            [$sessionId, $sessionId],
        ) ?? ['event_count' => 0, 'last_id' => ''];
        $checkpoint = substr(hash('sha256', $last['event_count'] . "\n" . $last['last_id']), 0, 20);

        return $this->enqueueJob([
            'job_type' => 'analyze_session',
            'project_id' => $projectId,
            'session_id' => $sessionId,
            'idempotency_key' => 'analyze_session:' . $sessionId . ':php-v1:' . $checkpoint,
            'payload' => ['analyzer_version' => 'php-v1', 'checkpoint' => $checkpoint],
        ]);
    }

    /** @param array<string, mixed> $job
     *  @return array{id:string,created:bool}
     */
    public function enqueueJob(array $job): array
    {
        $type = self::required($job, 'job_type');
        $key = self::required($job, 'idempotency_key');
        $projectId = trim((string) ($job['project_id'] ?? ''));
        $sessionId = trim((string) ($job['session_id'] ?? ''));
        if ($sessionId !== '') {
            if ($projectId === '') {
                throw new RuntimeException('project_id is required when session_id is set');
            }
            $session = $this->getSession($sessionId);
            if ($session['project_id'] !== $projectId) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Session belongs to a different project');
            }
        }
        $id = (string) ($job['job_id'] ?? Ids::make('job'));
        $now = self::now();
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $statement = $this->execute(
            'INSERT INTO analysis_jobs(id, job_type, project_id, session_id, idempotency_key, status, attempt,
                available_at, leased_until, payload_json, error_json, created_at, updated_at)
             VALUES(?, ?, ?, ?, ?, \'pending\', 0, ?, NULL, ?, NULL, ?, ?)
             ON CONFLICT(idempotency_key) DO NOTHING',
            [
                $id,
                $type,
                self::nullable($projectId),
                self::nullable($sessionId),
                $key,
                (string) ($job['available_at'] ?? $now),
                Json::encode($payload),
                $now,
                $now,
            ],
        );
        if ($statement->rowCount() === 1) {
            return ['id' => $id, 'created' => true];
        }
        $existing = $this->one(
            'SELECT id, job_type, COALESCE(project_id, \'\') AS project_id,
                COALESCE(session_id, \'\') AS session_id, payload_json
             FROM analysis_jobs WHERE idempotency_key = ?',
            [$key],
        );
        if ($existing === null) {
            throw new RuntimeException('Unable to resolve duplicate job');
        }
        if ($existing['job_type'] !== $type || $existing['project_id'] !== $projectId
            || $existing['session_id'] !== $sessionId
            || Json::canonical(Json::decode((string) $existing['payload_json'], [])) !== Json::canonical($payload)) {
            throw new ToolException('IDEMPOTENCY_CONFLICT', 'Job idempotency key was reused with different content', false, ['idempotency_key' => $key]);
        }

        return ['id' => (string) $existing['id'], 'created' => false];
    }

    /** @return array<string, mixed>|null */
    public function claimJob(int $leaseSeconds, int $maxAttempts): ?array
    {
        $now = self::now();
        $leasedUntil = self::timeAfter($leaseSeconds);
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $job = $this->one(
                'SELECT id, job_type, COALESCE(project_id, \'\') AS project_id,
                    COALESCE(session_id, \'\') AS session_id, idempotency_key, status, attempt,
                    available_at, leased_until, payload_json, COALESCE(error_json, \'{}\') AS error_json,
                    created_at, updated_at
                 FROM analysis_jobs
                 WHERE attempt < ? AND available_at <= ?
                    AND (status = \'pending\' OR (status = \'running\' AND leased_until < ?))
                 ORDER BY available_at, created_at LIMIT 1',
                [$maxAttempts, $now, $now],
            );
            if ($job === null) {
                $this->db->exec('COMMIT');
                return null;
            }
            $updated = $this->execute(
                'UPDATE analysis_jobs SET status = \'running\', attempt = attempt + 1,
                    leased_until = ?, updated_at = ?
                 WHERE id = ? AND (status = \'pending\' OR leased_until < ?)',
                [$leasedUntil, $now, $job['id'], $now],
            )->rowCount();
            if ($updated !== 1) {
                $this->db->exec('ROLLBACK');
                return null;
            }
            $this->db->exec('COMMIT');
            $job['status'] = 'running';
            $job['attempt'] = (int) $job['attempt'] + 1;
            $job['leased_until'] = $leasedUntil;
            $job['payload'] = Json::decode((string) $job['payload_json'], []);
            $job['error'] = Json::decode((string) $job['error_json'], []);
            unset($job['payload_json'], $job['error_json']);

            return $job;
        } catch (Throwable $exception) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $details */
    public function completeJob(string $id, array $details): void
    {
        $statement = $this->execute(
            'UPDATE analysis_jobs SET status = \'completed\', leased_until = NULL,
                error_json = NULL, updated_at = ? WHERE id = ? AND status = \'running\'',
            [self::now(), $id],
        );
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Running job not found: ' . $id);
        }
        $this->writeAudit('learningd', 'complete_job', 'job', $id, $details);
    }

    /** @param array<string, mixed> $job */
    public function failJob(array $job, Throwable $cause, bool $retryable, int $maxAttempts): void
    {
        $attempt = (int) ($job['attempt'] ?? 1);
        $status = 'dead_letter';
        $availableAt = self::now();
        if ($retryable && $attempt < $maxAttempts) {
            $status = 'pending';
            $availableAt = self::timeAfter(2 ** min($attempt, 6));
        }
        $this->execute(
            'UPDATE analysis_jobs SET status = ?, available_at = ?, leased_until = NULL,
                error_json = ?, updated_at = ? WHERE id = ?',
            [
                $status,
                $availableAt,
                Json::encode(['message' => $cause->getMessage(), 'retryable' => $retryable, 'attempt' => $attempt]),
                self::now(),
                $job['id'],
            ],
        );
    }

    /** @return list<string> */
    public function enqueueIdleSessions(int $idleSeconds, int $limit = 50): array
    {
        $cutoff = self::timeAfter(-$idleSeconds);
        $rows = $this->all(
            'SELECT id, project_id FROM sessions
             WHERE status = \'active\' AND last_activity_at <= ?
             ORDER BY last_activity_at LIMIT ?',
            [$cutoff, max(1, min(500, $limit))],
        );
        $ids = [];
        foreach ($rows as $row) {
            $result = $this->enqueueAnalysisForSession((string) $row['id'], (string) $row['project_id']);
            if ($result['created']) {
                $ids[] = $result['id'];
            }
        }

        return $ids;
    }

    /** @return list<string> */
    public function enqueueLearningSkillSyncs(int $limit = 20): array
    {
        if ($this->config->get('knowledge.learning_skills.enabled', false) !== true) {
            return [];
        }
        $rows = $this->all(
            'SELECT e.id, e.project_id, e.version, e.status, e.confidence, e.updated_at,
                    s.id AS session_id, s.cwd, s.worktree
               FROM experiences e
               JOIN experience_sources source ON source.experience_id = e.id
               JOIN sessions s ON s.id = source.session_id
              ORDER BY e.project_id, e.id, s.id
              LIMIT 10000',
        );
        $projects = [];
        foreach ($rows as $row) {
            $projectId = (string) $row['project_id'];
            if (!isset($projects[$projectId])) {
                $projects[$projectId] = ['experiences' => [], 'repositories' => []];
            }
            $projects[$projectId]['experiences'][(string) $row['id']] = [
                'id' => (string) $row['id'],
                'version' => (int) $row['version'],
                'status' => (string) $row['status'],
                'confidence' => round((float) $row['confidence'], 3),
                'updated_at' => (string) $row['updated_at'],
            ];
            $repository = trim((string) ($row['worktree'] ?: $row['cwd']));
            if ($repository !== '') {
                $projects[$projectId]['repositories'][$repository] = true;
            }
        }

        $ids = [];
        $processed = 0;
        foreach ($projects as $projectId => $state) {
            if ($processed >= max(1, min(100, $limit))) {
                break;
            }
            $repository = '';
            foreach (array_keys($state['repositories']) as $candidate) {
                $resolved = realpath($candidate);
                if (!is_string($resolved) || !is_dir($resolved)) {
                    continue;
                }
                try {
                    $project = ProjectResolver::resolve($resolved);
                } catch (Throwable) {
                    continue;
                }
                if (($project['project']['id'] ?? '') === $projectId) {
                    $repository = $resolved;
                    break;
                }
            }
            if ($repository === '') {
                continue;
            }
            $experiences = array_values($state['experiences']);
            usort($experiences, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
            $snapshotDigest = Ids::hash(Json::canonical([
                'generator_version' => LearningSkillService::GENERATOR_VERSION,
                'project_id' => $projectId,
                'policy' => [
                    'minimum_confidence' => (float) $this->config->get('knowledge.learning_skills.minimum_confidence', 0.9),
                    'max_experiences' => (int) $this->config->get('knowledge.learning_skills.max_experiences', 100),
                    'max_skills' => (int) $this->config->get('knowledge.learning_skills.max_skills', 12),
                ],
                'experiences' => $experiences,
                'projection_fingerprint' => LearningSkillService::projectionFingerprint($repository, $this->config),
            ]));
            $job = $this->enqueueJob([
                'job_type' => 'sync_learning_skills',
                'project_id' => $projectId,
                'idempotency_key' => 'sync_learning_skills:' . substr(hash('sha256', $projectId . "\n" . $snapshotDigest), 0, 40),
                'payload' => [
                    'repository' => $repository,
                    'snapshot_digest' => $snapshotDigest,
                    'generator_version' => LearningSkillService::GENERATOR_VERSION,
                ],
            ]);
            if ($job['created']) {
                $ids[] = $job['id'];
            }
            ++$processed;
        }

        return $ids;
    }

    /** @param array<string, mixed> $evidence
     *  @return array{id:string,created:bool}
     */
    public function putEvidence(array $evidence): array
    {
        foreach (['project_id', 'evidence_type', 'claim', 'polarity'] as $field) {
            self::required($evidence, $field);
        }
        [$claim] = Redactor::string((string) $evidence['claim']);
        [$locator] = Redactor::value(is_array($evidence['locator'] ?? null) ? $evidence['locator'] : []);
        $id = trim((string) ($evidence['evidence_id'] ?? ''));
        if ($id === '') {
            $id = Ids::deterministic('ev', implode("\n", [
                $evidence['project_id'],
                $evidence['session_id'] ?? '',
                $evidence['evidence_type'],
                $evidence['source_event_id'] ?? '',
                $claim,
            ]));
        }
        $strength = (float) ($evidence['strength'] ?? 0.5);
        if ($strength < 0 || $strength > 1) {
            throw new RuntimeException('Evidence strength must be between 0 and 1');
        }
        $statement = $this->execute(
            'INSERT INTO evidence(id, project_id, session_id, evidence_type, source_event_id, artifact_id,
                claim, polarity, strength, locator_json, verified, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(id) DO NOTHING',
            [
                $id,
                $evidence['project_id'],
                self::nullable($evidence['session_id'] ?? ''),
                $evidence['evidence_type'],
                self::nullable($evidence['source_event_id'] ?? ''),
                self::nullable($evidence['artifact_id'] ?? ''),
                $claim,
                $evidence['polarity'],
                $strength,
                Json::encode($locator),
                !empty($evidence['verified']) ? 1 : 0,
                (string) ($evidence['created_at'] ?? self::now()),
            ],
        );
        if ($statement->rowCount() === 0) {
            $existing = $this->evidence([$id]);
            if ($existing === [] || $existing[0]['project_id'] !== $evidence['project_id']
                || $existing[0]['claim'] !== $claim || $existing[0]['evidence_type'] !== $evidence['evidence_type']) {
                throw new ToolException('IDEMPOTENCY_CONFLICT', 'Evidence ID was reused with different content', false, ['evidence_id' => $id]);
            }
            return ['id' => $id, 'created' => false];
        }

        return ['id' => $id, 'created' => true];
    }

    /** @return array{created:bool,evidence:array<string,mixed>,experience:array<string,mixed>} */
    public function attachEvidence(string $experienceId, string $evidenceId, string $relation, string $actor): array
    {
        $relation = strtolower(trim($relation));
        $actor = trim($actor);
        if (!in_array($relation, ['supports', 'contradicts'], true) || $actor === '') {
            throw new ToolException('VALIDATION_FAILED', 'relation must be supports or contradicts, and actor is required');
        }
        $experience = $this->getExperience($experienceId);
        $items = $this->requireEvidence((string) $experience['project_id'], [$evidenceId]);
        $created = $this->execute(
            'INSERT OR IGNORE INTO experience_evidence(experience_id, evidence_id, relation) VALUES(?, ?, ?)',
            [$experienceId, $evidenceId, $relation],
        )->rowCount() === 1;
        $this->writeAudit($actor, 'attach_evidence', 'experience', $experienceId, [
            'evidence_id' => $evidenceId,
            'relation' => $relation,
            'created' => $created,
        ]);

        return ['created' => $created, 'evidence' => $items[0], 'experience' => $this->getExperience($experienceId)];
    }

    /** @param list<string> $ids
     *  @return list<array<string, mixed>>
     */
    public function requireEvidence(string $projectId, array $ids): array
    {
        $ids = Text::uniqueStrings($ids);
        if ($ids === []) {
            return [];
        }
        $items = $this->evidence($ids);
        $found = array_column($items, 'evidence_id');
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            throw new ToolException('EVIDENCE_NOT_FOUND', 'Referenced evidence does not exist', false, ['evidence_ids' => $missing]);
        }
        foreach ($items as $item) {
            if ($item['project_id'] !== $projectId) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Evidence belongs to a different project', false, ['evidence_id' => $item['evidence_id']]);
            }
        }

        return $items;
    }

    /** @param list<string> $ids
     *  @return list<array<string, mixed>>
     */
    public function evidence(array $ids): array
    {
        $ids = Text::uniqueStrings($ids);
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->all(
            'SELECT id, project_id, COALESCE(session_id, \'\') AS session_id, evidence_type,
                COALESCE(source_event_id, \'\') AS source_event_id, COALESCE(artifact_id, \'\') AS artifact_id,
                claim, polarity, strength, locator_json, verified, created_at
             FROM evidence WHERE id IN (' . $placeholders . ') ORDER BY created_at, id',
            $ids,
        );
        foreach ($rows as &$row) {
            $row = [
                'evidence_id' => $row['id'],
                'project_id' => $row['project_id'],
                'session_id' => $row['session_id'],
                'evidence_type' => $row['evidence_type'],
                'source_event_id' => $row['source_event_id'],
                'artifact_id' => $row['artifact_id'],
                'claim' => $row['claim'],
                'polarity' => $row['polarity'],
                'strength' => (float) $row['strength'],
                'locator' => Json::decode((string) $row['locator_json'], []),
                'verified' => (bool) $row['verified'],
                'created_at' => $row['created_at'],
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $experience
     *  @return array{experience:array<string,mixed>,created:bool}
     */
    public function upsertExperience(array $experience): array
    {
        $experience = $this->normalizeExperience($experience);
        $this->assertExperience($experience);
        foreach ($experience['source_session_ids'] as $sessionId) {
            $session = $this->getSession($sessionId);
            if ($session['project_id'] !== $experience['project_id']) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Experience source session belongs to a different project');
            }
        }
        $this->requireEvidence($experience['project_id'], $experience['evidence_ids']);
        $existing = $this->one(
            'SELECT id FROM experiences WHERE project_id = ? AND fingerprint = ?',
            [$experience['project_id'], $experience['fingerprint']],
        );
        if ($existing === null) {
            $this->insertExperience($experience);
            return ['experience' => $this->getExperience($experience['experience_id']), 'created' => true];
        }

        $stored = $this->getExperience((string) $existing['id']);
        $merged = $stored;
        foreach (['wrong_approaches', 'corrections', 'verification'] as $key) {
            $merged[$key] = self::uniqueObjects(array_merge($stored[$key], $experience[$key]));
        }
        $merged['exceptions'] = Text::uniqueStrings(array_merge($stored['exceptions'], $experience['exceptions']));
        $merged['source_session_ids'] = Text::uniqueStrings(array_merge($stored['source_session_ids'], $experience['source_session_ids']));
        $merged['evidence_ids'] = Text::uniqueStrings(array_merge($stored['evidence_ids'], $experience['evidence_ids']));
        $merged['source_session_count'] = count($merged['source_session_ids']);
        $merged['confidence'] = max((float) $stored['confidence'], (float) $experience['confidence']);
        $storedMetadata = is_array($stored['metadata'] ?? null) ? $stored['metadata'] : [];
        $candidateMetadata = is_array($experience['metadata'] ?? null) ? $experience['metadata'] : [];
        $storedClassification = is_array($storedMetadata['learning_classification'] ?? null)
            ? $storedMetadata['learning_classification']
            : [];
        $candidateClassification = is_array($candidateMetadata['learning_classification'] ?? null)
            ? array_filter(
                $candidateMetadata['learning_classification'],
                static fn(mixed $value): bool => $value !== '' && $value !== [],
            )
            : [];
        $merged['metadata'] = $storedMetadata;
        if ($candidateClassification !== []) {
            $merged['metadata']['learning_classification'] = array_replace(
                $storedClassification,
                $candidateClassification,
            );
        }
        $merged['last_seen_at'] = self::now();
        $changed = Json::canonical([
            $stored['wrong_approaches'], $stored['corrections'], $stored['verification'], $stored['exceptions'],
            $stored['source_session_ids'], $stored['evidence_ids'], $stored['confidence'], $stored['metadata'],
        ]) !== Json::canonical([
            $merged['wrong_approaches'], $merged['corrections'], $merged['verification'], $merged['exceptions'],
            $merged['source_session_ids'], $merged['evidence_ids'], $merged['confidence'], $merged['metadata'],
        ]);
        if (!$changed) {
            return ['experience' => $stored, 'created' => false];
        }
        $merged['version'] = (int) $stored['version'] + 1;
        $merged['updated_at'] = self::now();
        $this->updateExperience($merged, 'merged additional source or evidence');

        return ['experience' => $this->getExperience($merged['experience_id']), 'created' => false];
    }

    /** @return array<string, mixed> */
    public function getExperience(string $id): array
    {
        $row = $this->one(self::experienceSelect() . ' WHERE e.id = ?', [$id]);
        if ($row === null) {
            throw new ToolException('NOT_FOUND', 'Experience not found', false, ['experience_id' => $id]);
        }
        $experience = $this->rowToExperience($row);
        $experience['source_session_ids'] = array_map(
            static fn(array $item): string => (string) $item['session_id'],
            $this->all('SELECT session_id FROM experience_sources WHERE experience_id = ? ORDER BY session_id', [$id]),
        );
        $experience['evidence_ids'] = array_map(
            static fn(array $item): string => (string) $item['evidence_id'],
            $this->all('SELECT evidence_id FROM experience_evidence WHERE experience_id = ? ORDER BY evidence_id', [$id]),
        );

        return $experience;
    }

    /** @return array{experiences:list<array<string,mixed>>,next_cursor:string} */
    public function searchExperiences(
        string $projectId,
        string $query = '',
        array $categories = [],
        array $statuses = [],
        array $paths = [],
        int $limit = 20,
        int $offset = 0,
    ): array {
        $limit = max(1, min(100, $limit > 0 ? $limit : 20));
        $categories = Text::uniqueStrings($categories);
        $statuses = Text::uniqueStrings($statuses);
        $where = ['e.project_id = ?'];
        $params = [$projectId];
        if ($categories !== []) {
            $where[] = 'e.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
            array_push($params, ...$categories);
        }
        if ($statuses !== []) {
            $where[] = 'e.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            array_push($params, ...$statuses);
        }
        $from = ' FROM experiences e';
        $order = ' ORDER BY e.confidence DESC, e.updated_at DESC';
        $fts = self::ftsQuery($query);
        if ($fts !== '') {
            $from .= ' JOIN experiences_fts ON experiences_fts.rowid = e.rowid';
            $where[] = 'experiences_fts MATCH ?';
            $params[] = $fts;
            $order = ' ORDER BY bm25(experiences_fts), e.confidence DESC';
        }
        $fetchLimit = min(500, max($limit * 5, $limit + 1));
        $params[] = $fetchLimit;
        $params[] = max(0, $offset);
        try {
            $rows = $this->all(
                self::experienceSelect($from) . ' WHERE ' . implode(' AND ', $where) . $order . ' LIMIT ? OFFSET ?',
                $params,
            );
        } catch (PDOException) {
            $rows = [];
        }
        $usedFallback = false;
        if ($rows === [] && trim($query) !== '') {
            $usedFallback = true;
            $where = array_values(array_filter($where, static fn(string $item): bool => !str_contains($item, 'MATCH')));
            array_pop($params);
            array_pop($params);
            if ($fts !== '') {
                array_pop($params);
            }
            $params[] = min(500, max(100, $limit * 10));
            $params[] = max(0, $offset);
            $rows = $this->all(
                self::experienceSelect() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY e.confidence DESC, e.updated_at DESC LIMIT ? OFFSET ?',
                $params,
            );
        }
        $experiences = [];
        foreach ($rows as $row) {
            $experience = $this->rowToExperience($row);
            $scopePaths = is_array($experience['scope']['paths'] ?? null) ? $experience['scope']['paths'] : [];
            if ($paths !== [] && !Text::anyPathMatches($scopePaths, $paths)) {
                continue;
            }
            if ($usedFallback && Text::similarity($query, self::experienceText($experience)) < 0.15) {
                continue;
            }
            $experiences[] = $this->getExperience((string) $experience['experience_id']);
        }
        if ($usedFallback) {
            usort($experiences, static function (array $left, array $right) use ($query): int {
                $score = Text::similarity($query, self::experienceText($right)) <=> Text::similarity($query, self::experienceText($left));
                return $score !== 0 ? $score : ((float) $right['confidence'] <=> (float) $left['confidence']);
            });
        }
        $hasMore = count($experiences) > $limit || count($rows) === $fetchLimit;
        $experiences = array_slice($experiences, 0, $limit);

        return [
            'experiences' => $experiences,
            'next_cursor' => $hasMore ? (string) ($offset + $limit) : '',
        ];
    }

    /** @return array{experiences:list<array<string,mixed>>,next_cursor:string} */
    public function listCandidates(string $projectId, int $limit = 20, int $offset = 0): array
    {
        return $this->searchExperiences(
            $projectId,
            '',
            [],
            ['candidate', 'corroborated', 'contested', 'revised', 'promotion_eligible'],
            [],
            $limit,
            $offset,
        );
    }

    /** @return array<string, mixed> */
    public function explainExperience(string $id): array
    {
        $experience = $this->getExperience($id);
        $evidence = $this->evidence($experience['evidence_ids']);
        $feedbackRows = $this->all(
            'SELECT id, project_id, COALESCE(session_id, \'\') AS session_id,
                COALESCE(experience_id, \'\') AS experience_id, COALESCE(rule_id, \'\') AS rule_id,
                actor, result, applied, COALESCE(comment, \'\') AS comment, evidence_ids_json,
                user_confirmed, idempotency_key, created_at
             FROM feedback WHERE experience_id = ? ORDER BY created_at',
            [$id],
        );
        $feedback = array_map(self::rowToFeedback(...), $feedbackRows);
        $contradictions = $this->contradictions($id);

        return compact('experience', 'evidence', 'feedback', 'contradictions');
    }

    /** @return array<string, mixed> */
    public function markExperience(string $id, string $target, string $actor, string $reason): array
    {
        $target = strtolower(trim($target));
        $actor = trim($actor);
        $reason = trim($reason);
        if ($actor === '' || $reason === '') {
            throw new ToolException('VALIDATION_FAILED', 'actor and reason are required');
        }
        if ($target === 'promoted') {
            throw new ToolException('STATUS_GATE_FAILED', 'Direct promotion is prohibited; create and externally approve a proposal');
        }
        $details = $this->explainExperience($id);
        $experience = $details['experience'];
        $from = (string) $experience['status'];
        if (!self::canTransition($from, $target)) {
            throw new ToolException('STATUS_GATE_FAILED', sprintf('Invalid experience transition: %s -> %s', $from, $target));
        }
        $this->validateStatusGate($target, $experience, $details['evidence'], $details['contradictions']);
        if ($from === $target) {
            $this->writeAudit($actor, 'review_experience_noop', 'experience', $id, ['status' => $target, 'reason' => $reason]);
            return $experience;
        }
        $before = Ids::hash(Json::canonical($experience));
        $experience['status'] = $target;
        $experience['version'] = (int) $experience['version'] + 1;
        $experience['updated_at'] = self::now();
        $this->updateExperience($experience, $reason);
        $stored = $this->getExperience($id);
        $this->writeAudit($actor, 'mark_experience', 'experience', $id, [
            'from' => $from,
            'to' => $target,
            'reason' => $reason,
            'before_hash' => $before,
            'after_hash' => Ids::hash(Json::canonical($stored)),
        ]);

        return $stored;
    }

    /** @param array<string, mixed> $details
     *  @return array<string, mixed>
     */
    public function recordContradiction(string $leftId, string $rightId, array $details = []): array
    {
        if ($leftId === $rightId) {
            throw new ToolException('VALIDATION_FAILED', 'A contradiction requires two different experiences');
        }
        $left = $this->getExperience($leftId);
        $right = $this->getExperience($rightId);
        if ($left['project_id'] !== $right['project_id']) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Contradicting experiences must belong to the same project');
        }
        $ids = [$leftId, $rightId];
        sort($ids, SORT_STRING);
        $contradictionId = Ids::deterministic(
            'contradiction',
            (string) $left['project_id'] . "\n" . $ids[0] . "\n" . $ids[1],
        );
        $existing = $this->one(
            'SELECT id, project_id, left_experience_id, right_experience_id, status,
                COALESCE(resolution_json, \'{}\') AS resolution_json, created_at, resolved_at
             FROM contradictions WHERE id = ?',
            [$contradictionId],
        );
        if ($existing !== null) {
            $existing['resolution'] = Json::decode((string) $existing['resolution_json'], []);
            unset($existing['resolution_json']);

            return $existing;
        }
        [$details] = Redactor::value($details);
        $createdAt = self::now();
        $this->execute(
            'INSERT INTO contradictions(id, project_id, left_experience_id, right_experience_id,
                status, resolution_json, created_at, resolved_at)
             VALUES(?, ?, ?, ?, \'open\', ?, ?, NULL)',
            [
                $contradictionId,
                $left['project_id'],
                $ids[0],
                $ids[1],
                Json::encode(is_array($details) ? $details : []),
                $createdAt,
            ],
        );
        $this->writeAudit('automatic-learning', 'record_contradiction', 'contradiction', $contradictionId, [
            'left_experience_id' => $ids[0],
            'right_experience_id' => $ids[1],
            'details' => is_array($details) ? $details : [],
        ]);

        return [
            'id' => $contradictionId,
            'project_id' => $left['project_id'],
            'left_experience_id' => $ids[0],
            'right_experience_id' => $ids[1],
            'status' => 'open',
            'resolution' => is_array($details) ? $details : [],
            'created_at' => $createdAt,
            'resolved_at' => '',
        ];
    }

    /** @param array<string, mixed> $feedback
     *  @return array{feedback:array<string,mixed>,created:bool}
     */
    public function recordFeedback(array $feedback): array
    {
        foreach (['project_id', 'experience_id', 'actor', 'result', 'idempotency_key'] as $field) {
            self::required($feedback, $field);
        }
        $experience = $this->getExperience((string) $feedback['experience_id']);
        if ($experience['project_id'] !== $feedback['project_id']) {
            throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Experience belongs to a different project');
        }
        $sessionId = trim((string) ($feedback['session_id'] ?? ''));
        if ($sessionId !== '') {
            $session = $this->getSession($sessionId);
            if ($session['project_id'] !== $feedback['project_id']) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Session belongs to a different project');
            }
        }
        $evidenceIds = Text::uniqueStrings(is_array($feedback['evidence_ids'] ?? null) ? $feedback['evidence_ids'] : []);
        $this->requireEvidence((string) $feedback['project_id'], $evidenceIds);
        [$comment] = Redactor::string((string) ($feedback['comment'] ?? ''));
        $item = [
            'feedback_id' => (string) ($feedback['feedback_id'] ?? Ids::make('fb')),
            'project_id' => (string) $feedback['project_id'],
            'session_id' => $sessionId,
            'experience_id' => (string) $feedback['experience_id'],
            'rule_id' => trim((string) ($feedback['rule_id'] ?? '')),
            'actor' => Text::truncate((string) $feedback['actor'], 200),
            'result' => strtolower(trim((string) $feedback['result'])),
            'applied' => !empty($feedback['applied']),
            'comment' => $comment,
            'evidence_ids' => $evidenceIds,
            'user_confirmed' => !empty($feedback['user_confirmed']),
            'idempotency_key' => (string) $feedback['idempotency_key'],
            'created_at' => self::now(),
        ];
        if (!in_array($item['result'], [
            'success', 'applied_successfully', 'applied_but_irrelevant', 'ignored', 'contradicted',
            'caused_regression', 'needs_narrower_scope', 'needs_update',
        ], true)) {
            throw new ToolException('VALIDATION_FAILED', 'Unsupported feedback result: ' . $item['result']);
        }
        $statement = $this->execute(
            'INSERT INTO feedback(id, project_id, session_id, experience_id, rule_id, actor, result, applied,
                comment, evidence_ids_json, user_confirmed, idempotency_key, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(idempotency_key) DO NOTHING',
            [
                $item['feedback_id'], $item['project_id'], self::nullable($item['session_id']),
                self::nullable($item['experience_id']), self::nullable($item['rule_id']), $item['actor'],
                $item['result'], $item['applied'] ? 1 : 0, self::nullable($item['comment']),
                Json::encode($item['evidence_ids']), $item['user_confirmed'] ? 1 : 0,
                $item['idempotency_key'], $item['created_at'],
            ],
        );
        if ($statement->rowCount() === 0) {
            $existingRow = $this->one(
                'SELECT id, project_id, COALESCE(session_id, \'\') AS session_id,
                    COALESCE(experience_id, \'\') AS experience_id, COALESCE(rule_id, \'\') AS rule_id,
                    actor, result, applied, COALESCE(comment, \'\') AS comment, evidence_ids_json,
                    user_confirmed, idempotency_key, created_at FROM feedback WHERE idempotency_key = ?',
                [$item['idempotency_key']],
            );
            if ($existingRow === null) {
                throw new RuntimeException('Unable to resolve duplicate feedback');
            }
            $existing = self::rowToFeedback($existingRow);
            $compare = static fn(array $value): array => array_intersect_key($value, array_flip([
                'project_id', 'session_id', 'experience_id', 'rule_id', 'actor', 'result', 'applied',
                'comment', 'evidence_ids', 'user_confirmed', 'idempotency_key',
            ]));
            if (Json::canonical($compare($existing)) !== Json::canonical($compare($item))) {
                throw new ToolException('IDEMPOTENCY_CONFLICT', 'Feedback idempotency key was reused with different content', false, ['idempotency_key' => $item['idempotency_key']]);
            }
            return ['feedback' => $existing, 'created' => false];
        }
        $this->writeAudit($item['actor'], 'record_feedback', 'feedback', $item['feedback_id'], [
            'project_id' => $item['project_id'], 'experience_id' => $item['experience_id'], 'result' => $item['result'],
        ]);

        return ['feedback' => $item, 'created' => true];
    }

    /** @param array<string, mixed> $proposal
     *  @return array{proposal:array<string,mixed>,created:bool}
     */
    public function createProposal(array $proposal): array
    {
        foreach (['project_id', 'target', 'proposed_rule', 'rollback'] as $field) {
            self::required($proposal, $field);
        }
        $sources = Text::uniqueStrings(is_array($proposal['source_experience_ids'] ?? null) ? $proposal['source_experience_ids'] : []);
        if ($sources === []) {
            throw new ToolException('VALIDATION_FAILED', 'At least one source experience is required');
        }
        $allowed = $this->config->get('promotion.allowed_targets', []);
        if (!in_array($proposal['target'], $allowed, true)) {
            throw new ToolException('VALIDATION_FAILED', 'Promotion target is not allowed', false, ['target' => $proposal['target']]);
        }
        foreach ($sources as $id) {
            $experience = $this->getExperience($id);
            if ($experience['project_id'] !== $proposal['project_id']) {
                throw new ToolException('PROJECT_SCOPE_VIOLATION', 'Source experience belongs to a different project', false, ['experience_id' => $id]);
            }
            if (!in_array($experience['status'], ['validated', 'promotion_eligible', 'promoted'], true)) {
                throw new ToolException('STATUS_GATE_FAILED', 'Validated or higher source experience is required', false, ['experience_id' => $id, 'status' => $experience['status']]);
            }
        }
        [$redacted] = Redactor::value($proposal);
        $proposal = is_array($redacted) ? $redacted : $proposal;
        $now = self::now();
        $item = [
            'proposal_id' => (string) ($proposal['proposal_id'] ?? Ids::make('prop')),
            'project_id' => (string) $proposal['project_id'],
            'schema_version' => 'proposal.v1',
            'source_experience_ids' => $sources,
            'target' => (string) $proposal['target'],
            'scope' => is_array($proposal['scope'] ?? null) ? $proposal['scope'] : [],
            'proposed_rule' => (string) $proposal['proposed_rule'],
            'rationale' => (string) ($proposal['rationale'] ?? ''),
            'exceptions' => Text::uniqueStrings(is_array($proposal['exceptions'] ?? null) ? $proposal['exceptions'] : []),
            'validation_plan' => Text::uniqueStrings(is_array($proposal['validation_plan'] ?? null) ? $proposal['validation_plan'] : []),
            'rollback' => (string) $proposal['rollback'],
            'status' => (string) ($proposal['status'] ?? 'pending_review'),
            'caller_suggestion' => (string) ($proposal['caller_suggestion'] ?? ''),
            'metadata' => is_array($proposal['metadata'] ?? null) ? $proposal['metadata'] : [],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $contentHash = Ids::hash($item['target'] . "\n" . $item['proposed_rule'] . "\n" . Json::canonical($item['scope']));
        $statement = $this->execute(
            'INSERT INTO proposals(id, project_id, schema_version, source_experience_ids_json, target,
                scope_json, proposed_rule, rationale, exceptions_json, validation_plan_json, rollback,
                status, suggestion, metadata_json, content_hash, created_at, updated_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(project_id, content_hash) DO NOTHING',
            [
                $item['proposal_id'], $item['project_id'], $item['schema_version'], Json::encode($sources),
                $item['target'], Json::encode($item['scope']), $item['proposed_rule'], $item['rationale'],
                Json::encode($item['exceptions']), Json::encode($item['validation_plan']), $item['rollback'],
                $item['status'], self::nullable($item['caller_suggestion']), Json::encode($item['metadata']),
                $contentHash, $now, $now,
            ],
        );
        if ($statement->rowCount() === 0) {
            $existing = $this->one(
                'SELECT id, project_id, schema_version, source_experience_ids_json, target, scope_json,
                    proposed_rule, rationale, exceptions_json, validation_plan_json, rollback, status,
                    COALESCE(suggestion, \'\') AS suggestion, metadata_json, created_at, updated_at
                 FROM proposals WHERE project_id = ? AND content_hash = ?',
                [$item['project_id'], $contentHash],
            );
            if ($existing === null) {
                throw new RuntimeException('Unable to resolve duplicate proposal');
            }
            return ['proposal' => self::rowToProposal($existing), 'created' => false];
        }
        $this->writeAudit('learning-mcp', 'create_proposal', 'proposal', $item['proposal_id'], [
            'project_id' => $item['project_id'], 'target' => $item['target'], 'source_experience_ids' => $sources,
        ]);

        return ['proposal' => $item, 'created' => true];
    }

    /** @return list<array<string, mixed>> */
    public function listProposals(string $projectId, int $limit = 50): array
    {
        $rows = $this->all(
            'SELECT id, project_id, schema_version, source_experience_ids_json, target, scope_json,
                proposed_rule, rationale, exceptions_json, validation_plan_json, rollback, status,
                COALESCE(suggestion, \'\') AS suggestion, metadata_json, created_at, updated_at
             FROM proposals WHERE project_id = ? ORDER BY created_at DESC LIMIT ?',
            [$projectId, max(1, min(100, $limit))],
        );

        return array_map(self::rowToProposal(...), $rows);
    }

    public function deleteSession(string $id, string $actor): void
    {
        $session = $this->getSession($id);
        $affected = array_map(
            static fn(array $row): string => (string) $row['experience_id'],
            $this->all('SELECT experience_id FROM experience_sources WHERE session_id = ?', [$id]),
        );
        $this->execute('DELETE FROM sessions WHERE id = ?', [$id]);
        foreach ($affected as $experienceId) {
            $experience = $this->getExperience($experienceId);
            $sourceCount = (int) $this->scalar('SELECT COUNT(*) FROM experience_sources WHERE experience_id = ?', [$experienceId]);
            $evidenceCount = (int) $this->scalar('SELECT COUNT(*) FROM experience_evidence WHERE experience_id = ?', [$experienceId]);
            $experience['source_session_count'] = $sourceCount;
            if (($sourceCount === 0 || $evidenceCount === 0)
                && in_array($experience['status'], ['candidate', 'corroborated', 'validated', 'promotion_eligible', 'promoted', 'revised'], true)) {
                $experience['status'] = 'contested';
            }
            $experience['version'] = (int) $experience['version'] + 1;
            $experience['updated_at'] = self::now();
            $this->updateExperience($experience, 'source session deleted');
        }
        $this->writeAudit($actor, 'delete_session', 'session', $id, ['project_id' => $session['project_id'], 'affected_experiences' => $affected]);
    }

    public function deleteProject(string $id, string $actor): void
    {
        if ((int) $this->scalar('SELECT COUNT(*) FROM projects WHERE id = ?', [$id]) === 0) {
            throw new ToolException('NOT_FOUND', 'Project not found', false, ['project_id' => $id]);
        }
        $this->execute('DELETE FROM projects WHERE id = ?', [$id]);
        $this->writeAudit($actor, 'delete_project', 'project', $id, []);
    }

    /** @return list<string> */
    public function trustedProjectContext(string $projectId, int $limit = 5): array
    {
        $rows = $this->all(
            self::experienceSelect() .
            ' WHERE e.project_id = ? AND (e.status = \'promoted\' OR (e.status = \'validated\' AND e.confidence >= 0.90))
                AND (e.valid_until IS NULL OR e.valid_until > ?)
                AND NOT EXISTS (
                    SELECT 1 FROM contradictions c
                    WHERE (c.left_experience_id = e.id OR c.right_experience_id = e.id)
                        AND c.status IN (\'open\', \'contested\')
                )
              ORDER BY CASE WHEN e.status = \'promoted\' THEN 0 ELSE 1 END, e.confidence DESC LIMIT ?',
            [$projectId, self::now(), max(1, min(20, $limit))],
        );
        $result = [];
        foreach ($rows as $row) {
            $experience = $this->rowToExperience($row);
            $scope = is_array($experience['scope']) ? $experience['scope'] : [];
            if (Text::uniqueStrings(is_array($scope['paths'] ?? null) ? $scope['paths'] : []) !== []
                || Text::uniqueStrings(is_array($scope['languages'] ?? null) ? $scope['languages'] : []) !== []
                || Text::uniqueStrings(is_array($scope['branches'] ?? null) ? $scope['branches'] : []) !== []
                || (is_array($scope['version_constraints'] ?? null) && $scope['version_constraints'] !== [])) {
                continue;
            }
            $result[] = sprintf('- [%s %.2f] %s', $experience['status'], $experience['confidence'], $experience['reusable_rule']);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $counts = [];
        foreach (['projects', 'sessions', 'events', 'experiences'] as $table) {
            $counts[$table] = (int) $this->db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $experienceByStatus = [];
        foreach ($this->all('SELECT status, COUNT(*) AS count FROM experiences GROUP BY status') as $row) {
            $experienceByStatus[$row['status']] = (int) $row['count'];
        }
        $jobsByStatus = [];
        foreach ($this->all('SELECT status, COUNT(*) AS count FROM analysis_jobs GROUP BY status') as $row) {
            $jobsByStatus[$row['status']] = (int) $row['count'];
        }

        return [
            'database_path' => $this->path,
            'schema_version' => $this->schemaVersion(),
            'projects' => $counts['projects'],
            'sessions' => $counts['sessions'],
            'events' => $counts['events'],
            'experiences' => $counts['experiences'],
            'experiences_by_status' => $experienceByStatus,
            'jobs_by_status' => $jobsByStatus,
            'open_contradictions' => (int) $this->scalar("SELECT COUNT(*) FROM contradictions WHERE status IN ('open', 'contested')"),
        ];
    }

    /** @param array<string, mixed> $details */
    public function writeAudit(string $actor, string $action, string $entityType, string $entityId, array $details): void
    {
        [$actor] = Redactor::string($actor);
        [$details] = Redactor::value($details);
        $this->execute(
            'INSERT INTO audit_log(id, actor, action, entity_type, entity_id, before_hash, after_hash, details_json, created_at)
             VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Ids::make('audit'), Text::truncate($actor, 200), $action, $entityType, $entityId,
                self::nullable($details['before_hash'] ?? ''), self::nullable($details['after_hash'] ?? ''),
                Json::encode($details), self::now(),
            ],
        );
    }

    private function migrate(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )',
        );
        $files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            if (!preg_match('/^(\d+)_/', $name, $matches)) {
                throw new RuntimeException('Invalid migration filename: ' . $name);
            }
            $version = (int) $matches[1];
            if ((int) $this->scalar('SELECT COUNT(*) FROM schema_migrations WHERE version = ?', [$version]) > 0) {
                continue;
            }
            $body = file_get_contents($file);
            if ($body === false) {
                throw new RuntimeException('Unable to read migration: ' . $name);
            }
            $this->db->beginTransaction();
            try {
                $this->db->exec($body);
                $this->execute(
                    'INSERT INTO schema_migrations(version, name, applied_at) VALUES(?, ?, ?)',
                    [$version, $name, self::now()],
                );
                $this->db->commit();
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ((int) $this->scalar('SELECT COUNT(*) FROM schema_migrations WHERE version = ?', [$version]) > 0) {
                    continue;
                }
                throw new RuntimeException(sprintf('Apply migration %d: %s', $version, $exception->getMessage()), 0, $exception);
            }
        }
    }

    /** @param array<string, mixed> $experience */
    private function insertExperience(array $experience): void
    {
        $this->db->beginTransaction();
        try {
            $this->execute(
                'INSERT INTO experiences(id, project_id, schema_version, version, fingerprint, title, category,
                    problem_pattern, trigger_text, root_cause, correct_approach, reusable_rule, wrong_paths_json,
                    corrections_json, verification_json, scope_json, exceptions_json, confidence, confidence_json,
                    status, source_session_count, first_seen_at, last_seen_at, valid_until, supersedes_id,
                    metadata_json, created_at, updated_at)
                 VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $this->experienceValues($experience),
            );
            $this->replaceExperienceLinks($experience);
            $this->insertExperienceVersion($experience, 'created');
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $experience */
    private function updateExperience(array $experience, string $reason): void
    {
        $this->db->beginTransaction();
        try {
            $values = $this->experienceValues($experience);
            $id = array_shift($values);
            array_shift($values);
            array_shift($values);
            $this->execute(
                'UPDATE experiences SET version = ?, fingerprint = ?, title = ?, category = ?, problem_pattern = ?,
                    trigger_text = ?, root_cause = ?, correct_approach = ?, reusable_rule = ?, wrong_paths_json = ?,
                    corrections_json = ?, verification_json = ?, scope_json = ?, exceptions_json = ?, confidence = ?,
                    confidence_json = ?, status = ?, source_session_count = ?, first_seen_at = ?, last_seen_at = ?,
                    valid_until = ?, supersedes_id = ?, metadata_json = ?, created_at = ?, updated_at = ? WHERE id = ?',
                array_merge($values, [$id]),
            );
            $this->replaceExperienceLinks($experience);
            $this->insertExperienceVersion($experience, $reason);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $experience
     *  @return list<mixed>
     */
    private function experienceValues(array $experience): array
    {
        return [
            $experience['experience_id'], $experience['project_id'], $experience['schema_version'],
            $experience['version'], $experience['fingerprint'], $experience['title'], $experience['category'],
            $experience['problem_pattern'], self::nullable($experience['trigger']), self::nullable($experience['root_cause']),
            $experience['correct_approach'], $experience['reusable_rule'], Json::encode($experience['wrong_approaches']),
            Json::encode($experience['corrections']), Json::encode($experience['verification']), Json::encode($experience['scope']),
            Json::encode($experience['exceptions']), $experience['confidence'], Json::encode($experience['confidence_breakdown']),
            $experience['status'], $experience['source_session_count'], $experience['first_seen_at'], $experience['last_seen_at'],
            self::nullable($experience['valid_until']), self::nullable($experience['supersedes_id']), Json::encode($experience['metadata']),
            $experience['created_at'], $experience['updated_at'],
        ];
    }

    /** @param array<string, mixed> $experience */
    private function replaceExperienceLinks(array $experience): void
    {
        foreach ($experience['source_session_ids'] as $sessionId) {
            $this->execute(
                'INSERT OR IGNORE INTO experience_sources(experience_id, session_id) VALUES(?, ?)',
                [$experience['experience_id'], $sessionId],
            );
        }
        foreach ($experience['evidence_ids'] as $evidenceId) {
            $this->execute(
                'INSERT OR IGNORE INTO experience_evidence(experience_id, evidence_id, relation) VALUES(?, ?, \'supports\')',
                [$experience['experience_id'], $evidenceId],
            );
        }
    }

    /** @param array<string, mixed> $experience */
    private function insertExperienceVersion(array $experience, string $reason): void
    {
        $this->execute(
            'INSERT INTO experience_versions(experience_id, version, snapshot_json, change_reason, created_at)
             VALUES(?, ?, ?, ?, ?)',
            [$experience['experience_id'], $experience['version'], Json::encode($experience), $reason, self::now()],
        );
    }

    /** @param array<string, mixed> $experience
     *  @return array<string, mixed>
     */
    private function normalizeExperience(array $experience): array
    {
        [$redacted] = Redactor::value($experience);
        $experience = is_array($redacted) ? $redacted : $experience;
        $now = self::now();
        $experience += [
            'experience_id' => Ids::make('exp'),
            'schema_version' => 'experience.v1',
            'version' => 1,
            'trigger' => '',
            'root_cause' => '',
            'wrong_approaches' => [],
            'corrections' => [],
            'verification' => [],
            'scope' => [],
            'exceptions' => [],
            'confidence_breakdown' => [],
            'status' => 'candidate',
            'source_session_ids' => [],
            'evidence_ids' => [],
            'valid_until' => '',
            'supersedes_id' => '',
            'metadata' => [],
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (['wrong_approaches', 'corrections', 'verification', 'exceptions', 'source_session_ids', 'evidence_ids'] as $key) {
            if (!is_array($experience[$key])) {
                $experience[$key] = [];
            }
        }
        foreach (['scope', 'confidence_breakdown', 'metadata'] as $key) {
            if (!is_array($experience[$key])) {
                $experience[$key] = [];
            }
        }
        $experience['source_session_ids'] = Text::uniqueStrings($experience['source_session_ids']);
        $experience['evidence_ids'] = Text::uniqueStrings($experience['evidence_ids']);
        $experience['exceptions'] = Text::uniqueStrings($experience['exceptions']);
        $experience['source_session_count'] = count($experience['source_session_ids']);

        return $experience;
    }

    /** @param array<string, mixed> $experience */
    private function assertExperience(array $experience): void
    {
        foreach (['experience_id', 'project_id', 'fingerprint', 'title', 'category', 'problem_pattern', 'correct_approach', 'reusable_rule'] as $field) {
            self::required($experience, $field);
        }
        $categories = [
            'user_preference', 'project_constraint', 'project_fact', 'architecture_decision',
            'debugging_strategy', 'anti_pattern', 'workflow_rule', 'tool_usage', 'test_oracle',
            'security_boundary', 'temporary_context',
        ];
        if (!in_array($experience['category'], $categories, true)) {
            throw new RuntimeException('Unsupported experience category: ' . $experience['category']);
        }
        if (!self::isStatus((string) $experience['status'])) {
            throw new RuntimeException('Unsupported experience status: ' . $experience['status']);
        }
        if ((float) $experience['confidence'] < 0 || (float) $experience['confidence'] > 1) {
            throw new RuntimeException('Experience confidence must be between 0 and 1');
        }
        if ($experience['source_session_ids'] === []) {
            throw new RuntimeException('Experience requires at least one source session');
        }
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function rowToExperience(array $row): array
    {
        return [
            'experience_id' => $row['id'],
            'project_id' => $row['project_id'],
            'schema_version' => $row['schema_version'],
            'version' => (int) $row['version'],
            'fingerprint' => $row['fingerprint'],
            'title' => $row['title'],
            'category' => $row['category'],
            'problem_pattern' => $row['problem_pattern'],
            'trigger' => $row['trigger_text'],
            'root_cause' => $row['root_cause'],
            'correct_approach' => $row['correct_approach'],
            'reusable_rule' => $row['reusable_rule'],
            'wrong_approaches' => Json::decode((string) $row['wrong_paths_json'], []),
            'corrections' => Json::decode((string) $row['corrections_json'], []),
            'verification' => Json::decode((string) $row['verification_json'], []),
            'scope' => Json::decode((string) $row['scope_json'], []),
            'exceptions' => Json::decode((string) $row['exceptions_json'], []),
            'confidence' => (float) $row['confidence'],
            'confidence_breakdown' => Json::decode((string) $row['confidence_json'], []),
            'status' => $row['status'],
            'source_session_count' => (int) $row['source_session_count'],
            'source_session_ids' => [],
            'evidence_ids' => [],
            'first_seen_at' => $row['first_seen_at'],
            'last_seen_at' => $row['last_seen_at'],
            'valid_until' => $row['valid_until'] ?? '',
            'supersedes_id' => $row['supersedes_id'],
            'metadata' => Json::decode((string) $row['metadata_json'], []),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private static function experienceSelect(?string $from = null): string
    {
        $from ??= ' FROM experiences e';
        return 'SELECT e.id, e.project_id, e.schema_version, e.version, e.fingerprint, e.title, e.category,
            e.problem_pattern, COALESCE(e.trigger_text, \'\') AS trigger_text, COALESCE(e.root_cause, \'\') AS root_cause,
            e.correct_approach, e.reusable_rule, e.wrong_paths_json, e.corrections_json, e.verification_json,
            e.scope_json, e.exceptions_json, e.confidence, e.confidence_json, e.status, e.source_session_count,
            e.first_seen_at, e.last_seen_at, e.valid_until, COALESCE(e.supersedes_id, \'\') AS supersedes_id,
            e.metadata_json, e.created_at, e.updated_at' . $from;
    }

    /** @return list<array<string, mixed>> */
    private function contradictions(string $experienceId): array
    {
        $rows = $this->all(
            'SELECT id, left_experience_id, right_experience_id, status,
                COALESCE(resolution_json, \'{}\') AS resolution_json, created_at, resolved_at
             FROM contradictions WHERE left_experience_id = ? OR right_experience_id = ? ORDER BY created_at',
            [$experienceId, $experienceId],
        );
        foreach ($rows as &$row) {
            $row = [
                'contradiction_id' => $row['id'],
                'left_experience_id' => $row['left_experience_id'],
                'right_experience_id' => $row['right_experience_id'],
                'status' => $row['status'],
                'resolution' => Json::decode((string) $row['resolution_json'], []),
                'created_at' => $row['created_at'],
                'resolved_at' => $row['resolved_at'] ?? '',
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $experience
     *  @param list<array<string, mixed>> $evidence
     *  @param list<array<string, mixed>> $contradictions
     */
    private function validateStatusGate(string $target, array $experience, array $evidence, array $contradictions): void
    {
        if (in_array($target, ['validated', 'promotion_eligible'], true)) {
            if ((float) $experience['confidence'] < 0.78) {
                throw new ToolException('STATUS_GATE_FAILED', sprintf('Confidence %.2f is below validated threshold 0.78', $experience['confidence']));
            }
            if (self::technicalCategory((string) $experience['category'])) {
                $nonModel = false;
                $outcome = false;
                foreach ($evidence as $item) {
                    if (empty($item['verified'])) {
                        continue;
                    }
                    if (!in_array($item['evidence_type'], ['model_assessment', 'assistant_claim'], true)) {
                        $nonModel = true;
                    }
                    if (in_array($item['evidence_type'], [
                        'test_result', 'build_result', 'lint_result', 'browser_result', 'runtime_observation',
                        'user_confirmation', 'ci_result',
                    ], true)) {
                        $outcome = true;
                    }
                }
                if (!$nonModel || !$outcome) {
                    throw new ToolException('STATUS_GATE_FAILED', 'Technical experience requires verified non-model evidence and outcome validation');
                }
            } elseif ($evidence === []) {
                throw new ToolException('STATUS_GATE_FAILED', 'Validated experience requires evidence');
            }
        }
        if ($target === 'corroborated' && (float) $experience['confidence'] < 0.65) {
            throw new ToolException('STATUS_GATE_FAILED', sprintf('Confidence %.2f is below corroborated threshold 0.65', $experience['confidence']));
        }
        if ($target === 'promotion_eligible') {
            if ((float) $experience['confidence'] < 0.90) {
                throw new ToolException('STATUS_GATE_FAILED', sprintf('Confidence %.2f is below promotion threshold 0.90', $experience['confidence']));
            }
            foreach ($contradictions as $contradiction) {
                if (in_array($contradiction['status'], ['open', 'contested'], true)) {
                    throw new ToolException('STATUS_GATE_FAILED', 'Unresolved contradiction blocks promotion', false, ['contradiction_id' => $contradiction['contradiction_id']]);
                }
            }
            if (empty($experience['scope']['project_ids'])) {
                throw new ToolException('STATUS_GATE_FAILED', 'Promotion-eligible experience requires an explicit project scope');
            }
        }
    }

    private static function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return self::isStatus($to);
        }
        $allowed = [
            'candidate' => ['corroborated', 'validated', 'rejected', 'contested', 'deprecated'],
            'corroborated' => ['validated', 'rejected', 'contested', 'deprecated'],
            'validated' => ['promotion_eligible', 'contested', 'deprecated', 'revised'],
            'promotion_eligible' => ['validated', 'contested', 'deprecated'],
            'promoted' => ['contested', 'deprecated', 'revised'],
            'contested' => ['revised', 'deprecated', 'rejected'],
            'revised' => ['validated', 'rejected', 'contested'],
        ];

        return in_array($to, $allowed[$from] ?? [], true);
    }

    private static function isStatus(string $status): bool
    {
        return in_array($status, [
            'candidate', 'corroborated', 'validated', 'promotion_eligible', 'promoted',
            'contested', 'revised', 'deprecated', 'rejected',
        ], true);
    }

    private static function technicalCategory(string $category): bool
    {
        return in_array($category, [
            'project_fact', 'architecture_decision', 'debugging_strategy', 'anti_pattern',
            'workflow_rule', 'tool_usage', 'test_oracle', 'security_boundary',
        ], true);
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function rowToFeedback(array $row): array
    {
        return [
            'feedback_id' => $row['id'],
            'project_id' => $row['project_id'],
            'session_id' => $row['session_id'],
            'experience_id' => $row['experience_id'],
            'rule_id' => $row['rule_id'],
            'actor' => $row['actor'],
            'result' => $row['result'],
            'applied' => (bool) $row['applied'],
            'comment' => $row['comment'],
            'evidence_ids' => Json::decode((string) $row['evidence_ids_json'], []),
            'user_confirmed' => (bool) $row['user_confirmed'],
            'idempotency_key' => $row['idempotency_key'],
            'created_at' => $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private static function rowToProposal(array $row): array
    {
        return [
            'proposal_id' => $row['id'],
            'project_id' => $row['project_id'],
            'schema_version' => $row['schema_version'],
            'source_experience_ids' => Json::decode((string) $row['source_experience_ids_json'], []),
            'target' => $row['target'],
            'scope' => Json::decode((string) $row['scope_json'], []),
            'proposed_rule' => $row['proposed_rule'],
            'rationale' => $row['rationale'],
            'exceptions' => Json::decode((string) $row['exceptions_json'], []),
            'validation_plan' => Json::decode((string) $row['validation_plan_json'], []),
            'rollback' => $row['rollback'],
            'status' => $row['status'],
            'caller_suggestion' => $row['suggestion'],
            'metadata' => Json::decode((string) $row['metadata_json'], []),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** @param list<array<string, mixed>> $values
     *  @return list<array<string, mixed>>
     */
    private static function uniqueObjects(array $values): array
    {
        $seen = [];
        $result = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            $key = Json::canonical($value);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $value;
        }

        return $result;
    }

    /** @param array<string, mixed> $experience */
    private static function experienceText(array $experience): string
    {
        return implode(' ', [
            $experience['title'] ?? '', $experience['problem_pattern'] ?? '', $experience['trigger'] ?? '',
            $experience['correct_approach'] ?? '', $experience['reusable_rule'] ?? '',
        ]);
    }

    private static function ftsQuery(string $query): string
    {
        preg_match_all('/[\p{L}\p{N}_-]+/u', mb_strtolower($query, 'UTF-8'), $matches);
        $tokens = Text::uniqueStrings(array_slice($matches[0] ?? [], 0, 12));
        return implode(' OR ', array_map(static fn(string $token): string => '"' . str_replace('"', '""', $token) . '"', $tokens));
    }

    private static function required(array $value, string $key): string
    {
        $text = trim((string) ($value[$key] ?? ''));
        if ($text === '') {
            throw new ToolException('VALIDATION_FAILED', $key . ' is required');
        }

        return $text;
    }

    private static function nullable(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private static function now(): string
    {
        return Clock::now();
    }

    private static function timeAfter(int $seconds): string
    {
        $time = microtime(true) + $seconds;
        $milliseconds = (int) (($time - floor($time)) * 1000);
        return gmdate('Y-m-d\TH:i:s', (int) $time) . sprintf('.%03dZ', $milliseconds);
    }

    /** @param list<mixed> $params */
    private function execute(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->db->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();

        return $statement;
    }

    /** @param list<mixed> $params
     *  @return array<string, mixed>|null
     */
    private function one(string $sql, array $params = []): ?array
    {
        $row = $this->execute($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param list<mixed> $params
     *  @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $rows = $this->execute($sql, $params)->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        return $this->execute($sql, $params)->fetchColumn();
    }
}
