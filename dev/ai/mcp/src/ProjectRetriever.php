<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use RuntimeException;
use Throwable;

final class ProjectRetriever
{
    public function __construct(
        private readonly ProjectIndex $index,
        private readonly SparseVectorizer $vectorizer,
        private readonly Config $config,
    ) {
    }

    /** @param array<string,mixed> $options
     *  @return array<string,mixed>
     */
    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Search query is required');
        }
        if (mb_strlen($query, 'UTF-8') > 20_000) {
            throw new RuntimeException('Search query exceeds 20000 characters');
        }
        $limit = max(1, min(50, (int) ($options['limit'] ?? 8)));
        $tokenBudget = max(128, min(32_000, (int) (
            $options['token_budget'] ?? $this->config->get('index.context_token_budget', 6_000)
        )));
        $candidateLimit = min(500, max(80, $limit * 12));
        $queryId = Ids::make('query');
        $started = microtime(true);
        $this->beginQuery($queryId, $query, $options);

        try {
            $scores = [];
            $warnings = [];
            $this->addChannel($scores, $this->exactCandidates($query, $options, $candidateLimit), 'exact', 3.0);
            $this->addChannel($scores, $this->ftsCandidates($query, $options, $candidateLimit), 'fts', 1.7);

            $state = $this->index->state();
            $trigramAvailable = (bool) ($state['trigram_available'] ?? false);
            if ($trigramAvailable && mb_strlen($query, 'UTF-8') >= 3) {
                $this->addChannel($scores, $this->trigramCandidates($query, $options, $candidateLimit), 'trigram', 1.4);
            } elseif (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $query) === 1
                && mb_strlen($query, 'UTF-8') <= 32) {
                $this->addChannel($scores, $this->cjkLikeCandidates($query, $options, $candidateLimit), 'cjk_fallback', 0.8);
                $warnings[] = 'Trigram FTS is unavailable or the query is shorter than three characters; bounded SQLite CJK fallback was used.';
            }
            $this->addChannel($scores, $this->sparseCandidates($query, $options, $candidateLimit), 'sparse', 2.0);

            if ($scores === []) {
                return $this->completeSearch($queryId, $query, $options, [], $tokenBudget, 0, $started, $warnings);
            }
            uasort($scores, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $scores = array_slice($scores, 0, min(900, count($scores)), true);
            $this->applyFeedbackBoost($scores);
            uasort($scores, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

            $rows = $this->loadChunks(array_keys($scores));
            $results = [];
            $usedTokens = 0;
            foreach (array_keys($scores) as $chunkId) {
                if (count($results) >= $limit || !isset($rows[$chunkId])) {
                    continue;
                }
                $remaining = $tokenBudget - $usedTokens;
                if ($remaining < 32 && $results !== []) {
                    break;
                }
                $result = $this->formatChunk(
                    $rows[$chunkId],
                    $query,
                    max(32, $remaining),
                    (float) $scores[$chunkId]['score'],
                    $scores[$chunkId]['components']
                );
                $usedTokens += (int) $result['token_estimate'];
                $results[] = $result;
            }

            return $this->completeSearch(
                $queryId,
                $query,
                $options,
                $results,
                $tokenBudget,
                min($usedTokens, $tokenBudget),
                $started,
                $warnings,
            );
        } catch (Throwable $exception) {
            $this->failQuery($queryId, $started, $exception->getMessage());
            throw $exception;
        }
    }

    /** @param array<string,mixed> $options
     *  @return array<string,mixed>
     */
    public function resolveContext(string $task, array $options = []): array
    {
        $result = $this->search($task, $options);
        $groups = ['code' => [], 'documents' => [], 'rules' => [], 'skills' => [], 'configuration' => []];
        foreach ($result['results'] as $item) {
            $group = match ($item['file_kind']) {
                'doc' => 'documents',
                'rule' => 'rules',
                'skill' => 'skills',
                'config' => 'configuration',
                default => 'code',
            };
            $groups[$group][] = $item;
        }
        $resultCount = count($result['results']);
        unset($result['results']);
        $skillRoute = $this->resolveSkill([
            'task' => $task,
            'module' => $options['module'] ?? null,
            'path' => $options['path'] ?? null,
            'limit' => min(5, max(1, (int) ($options['skill_limit'] ?? 3))),
            'include_content' => (bool) ($options['include_skill_content'] ?? false),
            'token_budget' => min(4_000, max(128, (int) ($options['token_budget'] ?? 2_000))),
        ]);

        return array_replace($result, [
            'task' => $task,
            'result_count' => $resultCount,
            'context' => $groups,
            'skill_route' => $skillRoute['skills'],
            'graph' => [
                'engine' => 'sqlite_overlay',
                'external_graph_available' => (bool) ($this->index->state()['external_graph_available'] ?? false),
                'note' => 'No external graph CLI is invoked during retrieval.',
            ],
        ]);
    }

    /** @return array<string,mixed> */
    public function inspectSymbol(string $name, string $mode = 'context'): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Symbol name is required');
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['context', 'references', 'impact', 'upstream', 'downstream', 'callers', 'callees'], true)) {
            throw new RuntimeException('Symbol mode must be context, references, impact, upstream, downstream, callers, or callees');
        }
        $queryId = $this->standaloneQuery('symbol:' . $name, ['mode' => $mode]);
        $statement = $this->index->pdo()->prepare(
            "SELECT s.*, f.path, f.content_hash AS file_hash, f.indexed_at, c.content AS chunk_content
               FROM symbols AS s
               JOIN indexed_files AS f ON f.id = s.file_id
          LEFT JOIN chunks AS c ON c.chunk_id = s.chunk_id
              WHERE s.name = :name COLLATE NOCASE
                 OR s.fq_name = :name COLLATE NOCASE
                 OR s.fq_name LIKE :suffix ESCAPE '\\'
           ORDER BY CASE WHEN s.fq_name = :name COLLATE NOCASE THEN 0
                         WHEN s.name = :name COLLATE NOCASE THEN 1 ELSE 2 END,
                    length(s.fq_name), f.path
              LIMIT 20"
        );
        $statement->execute(['name' => $name, 'suffix' => '%\\' . $this->escapeLike($name)]);
        $rows = $statement->fetchAll();
        $symbols = [];
        $uids = [];
        $names = [];
        foreach ($rows as $row) {
            $uids[] = (string) $row['symbol_uid'];
            $names[] = (string) $row['fq_name'];
            $snippet = $this->snippet((string) ($row['chunk_content'] ?? $row['signature']), $name, 600);
            $symbols[] = [
                'symbol_uid' => $row['symbol_uid'],
                'name' => $row['name'],
                'fq_name' => $row['fq_name'],
                'kind' => $row['kind'],
                'signature' => $row['signature'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'file_hash' => $row['file_hash'],
                'body_hash' => $row['body_hash'],
                'start_line' => (int) $row['start_line'],
                'end_line' => (int) $row['end_line'],
                'snippet' => $snippet['text'],
            ];
        }
        $relations = $this->symbolRelations($uids, $names, $mode);
        $impactFiles = array_values(array_unique(array_map(
            static fn (array $relation): string => (string) ($relation['relative_path'] ?? ''),
            array_filter($relations, static fn (array $relation): bool => ($relation['direction'] ?? '') === 'upstream'),
        )));
        $impactCount = count($impactFiles);
        $chunkIds = array_values(array_filter(array_map(static fn (array $row): mixed => $row['chunk_id'], $rows)));
        $this->saveStandaloneResults($queryId, $chunkIds);

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'mode' => $mode,
            'symbols' => $symbols,
            'relations' => $relations,
            'impact' => [
                'upstream_files' => $impactFiles,
                'upstream_file_count' => $impactCount,
                'risk_level' => $impactCount >= 20 ? 'high' : ($impactCount >= 5 ? 'medium' : 'low'),
                'conservative' => true,
            ],
            'warnings' => [
                'Relations are token-derived lexical extends/implements/use/new/static/method/static/function-call references, not a type-resolved runtime call graph.',
                'Import resolution across multiple bracketed namespaces is conservative.',
            ],
        ];
    }

    /** @param array<string,mixed> $selector
     *  @return array<string,mixed>
     */
    public function getDocument(array $selector): array
    {
        $query = trim((string) ($selector['query'] ?? ''));
        if ($query !== '') {
            $options = [
                'limit' => max(1, min(30, (int) ($selector['limit'] ?? 10))),
                'token_budget' => $selector['token_budget'] ?? $this->config->get('index.context_token_budget', 6_000),
                'file_kinds' => ['doc', 'rule'],
            ];
            foreach (['path', 'paths', 'module', 'modules'] as $key) {
                if (array_key_exists($key, $selector)) {
                    $options[$key] = $selector[$key];
                }
            }
            $result = $this->search($query, $options);

            return [
                'query_id' => $result['query_id'],
                'index_db' => $result['index_db'],
                'revision' => $result['revision'],
                'freshness' => $result['freshness'],
                'documents' => $result['results'],
                'token_budget' => $result['token_budget'],
                'warnings' => $result['warnings'],
            ];
        }

        $queryId = $this->standaloneQuery('document', $selector);
        $limit = max(1, min(30, (int) ($selector['limit'] ?? 10)));
        $tokenBudget = max(128, min(32_000, (int) (
            $selector['token_budget'] ?? $this->config->get('index.context_token_budget', 6_000)
        )));
        $maxChars = max(128, min(100_000, (int) ($selector['max_chars'] ?? $tokenBudget * 4)));
        $tokenBudget = min($tokenBudget, max(32, (int) ceil($maxChars / 4)));
        [$where, $params] = $this->documentSelector($selector);
        $statement = $this->index->pdo()->prepare(
            "SELECT c.*, f.path, f.kind AS file_kind, f.language, f.module_vendor, f.module_name,
                    f.content_hash AS file_hash, f.revision AS file_revision, f.indexed_at, '' AS symbol_name
               FROM indexed_files AS f JOIN chunks AS c ON c.file_id = f.id
              WHERE f.kind IN ('doc', 'rule')" . $where . '
           ORDER BY f.path, c.start_line LIMIT ' . (int) ($limit * 20)
        );
        $statement->execute($params);
        $documents = [];
        $used = 0;
        foreach ($statement->fetchAll() as $row) {
            if (count($documents) >= $limit || $used >= $tokenBudget) {
                break;
            }
            $formatted = $this->formatChunk($row, '', $tokenBudget - $used, 1.0, ['document' => ['rank' => count($documents) + 1]]);
            if (mb_strlen((string) $formatted['snippet'], 'UTF-8') > $maxChars) {
                $formatted['snippet'] = mb_substr((string) $formatted['snippet'], 0, $maxChars, 'UTF-8');
                $formatted['end_line'] = (int) $formatted['start_line'] + substr_count((string) $formatted['snippet'], "\n");
                $formatted['token_estimate'] = max(1, (int) ceil(mb_strlen((string) $formatted['snippet'], 'UTF-8') / 4));
            }
            $used += (int) $formatted['token_estimate'];
            $documents[] = $formatted;
        }
        if ($documents === [] && isset($selector['path'])) {
            throw new ToolException(
                'DOCUMENT_NOT_FOUND_OR_STALE',
                'The indexed document, heading, or expected hash did not match the current index',
                true,
            );
        }
        $this->saveStandaloneResults($queryId, array_column($documents, 'chunk_id'));

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'documents' => $documents,
            'token_budget' => ['requested' => $tokenBudget, 'used' => min($used, $tokenBudget)],
            'warnings' => [],
        ];
    }

    /** @param array<string,mixed> $selector
     *  @return array<string,mixed>
     */
    public function getFiles(array $selector): array
    {
        $paths = $this->stringList($selector['paths'] ?? []);
        if (isset($selector['path']) && is_string($selector['path']) && trim($selector['path']) !== '') {
            $paths[] = trim($selector['path']);
        }
        $paths = array_values(array_unique(array_map(
            fn (string $path): string => $this->index->normalizeRelativePath($path),
            $paths,
        )));
        if ($paths === []) {
            throw new ToolException('VALIDATION_FAILED', 'paths must contain at least one exact indexed path');
        }
        if (count($paths) > 50) {
            throw new ToolException('VALIDATION_FAILED', 'A batch can read at most 50 indexed files');
        }

        $maxPerFile = max(128, min(524_288, (int) ($selector['max_chars_per_file'] ?? 120_000)));
        $maxTotal = max(128, min(1_000_000, (int) ($selector['max_total_chars'] ?? 400_000)));
        $expectedHashes = [];
        if (isset($selector['expected_hashes'])) {
            if (!is_array($selector['expected_hashes']) || array_is_list($selector['expected_hashes'])) {
                throw new ToolException('VALIDATION_FAILED', 'expected_hashes must be an object keyed by path');
            }
            foreach ($selector['expected_hashes'] as $path => $hash) {
                if (!is_string($path) || !is_string($hash)) {
                    throw new ToolException('VALIDATION_FAILED', 'expected_hashes keys and values must be strings');
                }
                $normalizedHash = strtolower(trim($hash));
                $normalizedHash = str_starts_with($normalizedHash, 'sha256:')
                    ? $normalizedHash
                    : 'sha256:' . $normalizedHash;
                if (preg_match('/^sha256:[a-f0-9]{64}$/D', $normalizedHash) !== 1) {
                    throw new ToolException('VALIDATION_FAILED', 'expected_hashes contains an invalid SHA-256 digest');
                }
                $expectedHashes[$this->index->normalizeRelativePath($path)] = $normalizedHash;
            }
        }

        $queryId = $this->standaloneQuery('indexed-files-batch', [
            'paths' => $paths,
            'max_chars_per_file' => $maxPerFile,
            'max_total_chars' => $maxTotal,
        ]);
        $placeholders = [];
        $params = [];
        foreach ($paths as $index => $path) {
            $placeholders[] = ':batch_path_' . $index;
            $params['batch_path_' . $index] = $path;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT f.path, f.kind, f.language, f.module_vendor, f.module_name,
                    f.content_hash AS file_hash, f.revision AS file_revision, f.indexed_at,
                    c.content_blob, c.encoding, c.original_bytes, c.stored_bytes,
                    c.revision AS content_revision, c.indexed_at AS content_indexed_at,
                    (SELECT ch.chunk_id FROM chunks AS ch WHERE ch.file_id = f.id
                      ORDER BY ch.start_line, ch.rowid LIMIT 1) AS first_chunk_id
               FROM indexed_files AS f
               JOIN indexed_file_contents AS c ON c.file_id = f.id
              WHERE f.path IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['path']] = $row;
        }

        $files = [];
        $missingPaths = [];
        $budgetOmittedPaths = [];
        $chunkIds = [];
        $usedChars = 0;
        foreach ($paths as $path) {
            $row = $rows[$path] ?? null;
            if (!is_array($row)) {
                $missingPaths[] = $path;
                continue;
            }
            $expectedHash = $expectedHashes[$path] ?? '';
            if ($expectedHash !== '' && !hash_equals($expectedHash, strtolower((string) $row['file_hash']))) {
                throw new ToolException(
                    'INDEXED_FILE_HASH_MISMATCH',
                    'The indexed file hash no longer matches the requested guard: ' . $path,
                    true,
                    ['path' => $path, 'expected_hash' => $expectedHash, 'actual_hash' => $row['file_hash']],
                );
            }
            $remaining = $maxTotal - $usedChars;
            if ($remaining <= 0) {
                $budgetOmittedPaths[] = $path;
                continue;
            }
            $content = $this->decodeStoredContent($row['content_blob'], (string) $row['encoding'], $path);
            $contentChars = mb_strlen($content, 'UTF-8');
            $returnedChars = min($contentChars, $maxPerFile, $remaining);
            $returned = $returnedChars < $contentChars
                ? mb_substr($content, 0, $returnedChars, 'UTF-8')
                : $content;
            $usedChars += $returnedChars;
            $chunkId = trim((string) ($row['first_chunk_id'] ?? ''));
            if ($chunkId !== '') {
                $chunkIds[] = $chunkId;
            }
            $files[] = [
                'relative_path' => $path,
                'absolute_path' => $this->index->absolutePath($path),
                'file_hash' => $row['file_hash'],
                'file_kind' => $row['kind'],
                'language' => $row['language'],
                'module' => trim($row['module_vendor'] . '/' . $row['module_name'], '/'),
                'content' => $returned,
                'content_chars' => $contentChars,
                'returned_chars' => $returnedChars,
                'token_estimate' => max(1, (int) ceil($returnedChars / 4)),
                'line_count' => $content === '' ? 0 : substr_count($content, "\n") + 1,
                'truncated' => $returnedChars < $contentChars,
                'storage' => [
                    'engine' => 'project_sqlite_vector_content_store',
                    'encoding' => $row['encoding'],
                    'original_bytes' => (int) $row['original_bytes'],
                    'stored_bytes' => (int) $row['stored_bytes'],
                ],
                'freshness' => [
                    'state' => $this->freshness(),
                    'index_revision' => $this->index->revision(),
                    'file_revision' => (int) $row['file_revision'],
                    'content_revision' => (int) $row['content_revision'],
                    'indexed_at' => $row['content_indexed_at'],
                ],
            ];
        }
        $this->saveStandaloneResults($queryId, $chunkIds);

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'requested_count' => count($paths),
            'returned_count' => count($files),
            'files' => $files,
            'missing_paths' => $missingPaths,
            'budget_omitted_paths' => $budgetOmittedPaths,
            'budget' => [
                'max_chars_per_file' => $maxPerFile,
                'max_total_chars' => $maxTotal,
                'used_chars' => $usedChars,
            ],
            'retrieval' => [
                'filesystem_scanned' => false,
                'filesystem_content_read' => false,
                'database_round_trips' => 1,
                'content_source' => 'indexed_file_contents',
            ],
            'warnings' => $missingPaths === [] ? [] : [
                'Some requested paths were not present in the current indexed content store.',
            ],
        ];
    }

    private function decodeStoredContent(mixed $value, string $encoding, string $path): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (!is_string($value)) {
            throw new ToolException('INDEX_CONTENT_CORRUPT', 'Indexed content is unreadable: ' . $path, true);
        }
        if ($encoding === 'raw') {
            return $value;
        }
        if ($encoding === 'gzip') {
            $decoded = gzdecode($value);
            if (is_string($decoded)) {
                return $decoded;
            }
        }

        throw new ToolException('INDEX_CONTENT_CORRUPT', 'Indexed content encoding is invalid: ' . $path, true);
    }

    /** @param array<string,mixed> $selector
     *  @return array<string,mixed>
     */
    public function resolveSkill(array $selector): array
    {
        $task = trim((string) ($selector['task'] ?? $selector['query'] ?? ''));
        $limit = max(1, min(20, (int) ($selector['limit'] ?? 5)));
        $queryId = $this->standaloneQuery('skill-route:' . $task, $selector);
        $sql = 'SELECT s.*, f.content_hash AS file_hash, f.indexed_at,
                       (SELECT c.chunk_id FROM chunks c WHERE c.file_id = s.file_id ORDER BY c.start_line LIMIT 1) AS chunk_id
                  FROM skills AS s JOIN indexed_files AS f ON f.id = s.file_id
                 WHERE s.status IN (\'canonical\', \'validated\')';
        $params = [];
        $module = trim((string) ($selector['module'] ?? ''));
        if ($module !== '') {
            $sql .= " AND (s.module_vendor || '_' || s.module_name = :module COLLATE NOCASE
                           OR s.module_vendor || '/' || s.module_name = :module COLLATE NOCASE)";
            $params['module'] = $module;
        }
        if (isset($selector['path']) && is_string($selector['path']) && trim($selector['path']) !== '') {
            $path = $this->index->normalizeRelativePath($selector['path']);
            $sql .= " AND (s.path = :path OR s.path LIKE :path_prefix ESCAPE '\\')";
            $params['path'] = $path;
            $params['path_prefix'] = $this->escapeLike(rtrim($path, '/')) . '/%';
        }
        $sql .= ' ORDER BY CASE WHEN s.status = \'canonical\' THEN 0 ELSE 1 END, s.name LIMIT 500';
        $statement = $this->index->pdo()->prepare($sql);
        $statement->execute($params);
        $taskVector = $this->vectorizer->vectorize($task);
        $skills = [];
        foreach ($statement->fetchAll() as $row) {
            $triggers = Json::decode((string) $row['triggers_json'], []);
            $text = implode("\n", [
                (string) $row['name'],
                (string) $row['description'],
                is_array($triggers) ? implode(' ', $triggers) : '',
                (string) $row['path'],
            ]);
            $score = $task === '' ? 0.1 : max(0.0, $this->vectorizer->dot($taskVector, $this->vectorizer->vectorize($text)));
            if ($task !== '' && mb_stripos($text, $task, 0, 'UTF-8') !== false) {
                $score += 0.75;
            }
            if ($row['status'] === 'canonical') {
                $score += 0.05;
            }
            $skill = [
                'skill_id' => $row['skill_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'status' => $row['status'],
                'triggers' => is_array($triggers) ? $triggers : [],
                'module' => trim($row['module_vendor'] . '/' . $row['module_name'], '/'),
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'file_hash' => $row['file_hash'],
                'score' => round($score, 6),
                'chunk_id' => $row['chunk_id'],
                'actionable' => true,
            ];
            if (!empty($selector['include_content'])) {
                $budget = max(128, min(4_000, (int) ($selector['token_budget'] ?? 2_000)));
                $chunks = $this->index->pdo()->prepare(
                    'SELECT content FROM chunks WHERE file_id = :file_id ORDER BY start_line'
                );
                $chunks->execute(['file_id' => $row['file_id']]);
                $content = '';
                foreach ($chunks->fetchAll() as $chunk) {
                    $remaining = $budget * 4 - mb_strlen($content, 'UTF-8');
                    if ($remaining <= 0) {
                        break;
                    }
                    $content .= mb_substr((string) $chunk['content'], 0, $remaining, 'UTF-8');
                }
                $skill['content'] = $content;
                $skill['token_estimate'] = max(1, (int) ceil(mb_strlen($content, 'UTF-8') / 4));
            }
            $skills[] = $skill;
        }
        usort($skills, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $skills = array_slice($skills, 0, $limit);
        $this->saveStandaloneResults($queryId, array_values(array_filter(array_column($skills, 'chunk_id'))));

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'skills' => $skills,
            'warnings' => [],
        ];
    }

    /** @param array<string,mixed> $selector
     *  @return array<string,mixed>
     */
    public function getSkill(array $selector): array
    {
        $conditions = [];
        $params = [];
        foreach (['skill_id', 'name'] as $key) {
            if (isset($selector[$key]) && is_string($selector[$key]) && trim($selector[$key]) !== '') {
                $conditions[] = 's.' . $key . ' = :' . $key . ' COLLATE NOCASE';
                $params[$key] = trim($selector[$key]);
            }
        }
        if (isset($selector['path']) && is_string($selector['path']) && trim($selector['path']) !== '') {
            $conditions[] = 's.path = :path';
            $params['path'] = $this->index->normalizeRelativePath($selector['path']);
        }
        if ($conditions === []) {
            throw new RuntimeException('getSkill requires skill_id, path, or name');
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT s.*, f.content_hash AS file_hash, f.indexed_at FROM skills s
              JOIN indexed_files f ON f.id = s.file_id WHERE ' . implode(' OR ', $conditions) . '
              ORDER BY CASE WHEN s.status = \'canonical\' THEN 0 ELSE 1 END LIMIT 1'
        );
        $statement->execute($params);
        $skill = $statement->fetch();
        $queryId = $this->standaloneQuery('skill:' . implode('|', $params), $selector);
        if (!is_array($skill)) {
            return [
                'query_id' => $queryId,
                'index_db' => $this->index->path(),
                'revision' => $this->index->revision(),
                'freshness' => $this->freshness(),
                'skill' => null,
                'warnings' => ['Skill was not found in the current index.'],
            ];
        }
        $expectedHash = trim((string) ($selector['expected_hash'] ?? ''));
        if ($expectedHash !== '') {
            $expectedHash = str_starts_with(strtolower($expectedHash), 'sha256:')
                ? strtolower($expectedHash)
                : 'sha256:' . strtolower($expectedHash);
            if (!hash_equals($expectedHash, strtolower((string) $skill['file_hash']))) {
                throw new ToolException('SKILL_HASH_STALE', 'The expected skill hash does not match the current index', true);
            }
        }
        $budget = max(128, min(32_000, (int) (
            $selector['token_budget'] ?? $this->config->get('index.context_token_budget', 6_000)
        )));
        $chunks = $this->index->pdo()->prepare('SELECT chunk_id, content FROM chunks WHERE file_id = :file_id ORDER BY start_line');
        $chunks->execute(['file_id' => $skill['file_id']]);
        $content = '';
        $chunkIds = [];
        foreach ($chunks->fetchAll() as $chunk) {
            $remaining = $budget * 4 - mb_strlen($content, 'UTF-8');
            if ($remaining <= 0) {
                break;
            }
            $content .= mb_substr((string) $chunk['content'], 0, $remaining, 'UTF-8');
            $chunkIds[] = (string) $chunk['chunk_id'];
        }
        $this->saveStandaloneResults($queryId, $chunkIds);

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'skill' => [
                'skill_id' => $skill['skill_id'],
                'name' => $skill['name'],
                'description' => $skill['description'],
                'status' => $skill['status'],
                'triggers' => Json::decode((string) $skill['triggers_json'], []),
                'module' => trim($skill['module_vendor'] . '/' . $skill['module_name'], '/'),
                'relative_path' => $skill['path'],
                'absolute_path' => $this->index->absolutePath((string) $skill['path']),
                'file_hash' => $skill['file_hash'],
                'source_hash' => $skill['source_hash'],
                'content' => $content,
                'token_estimate' => (int) ceil(mb_strlen($content, 'UTF-8') / 4),
                'actionable' => in_array((string) $skill['status'], ['canonical', 'validated'], true),
            ],
            'warnings' => in_array((string) $skill['status'], ['canonical', 'validated'], true)
                ? []
                : ['This is a candidate skill and must not be treated as promoted policy.'],
        ];
    }

    /** @param array<string,mixed> $feedback
     *  @return array<string,mixed>
     */
    public function recordFeedback(array $feedback): array
    {
        $queryId = trim((string) ($feedback['query_id'] ?? ''));
        $outcome = strtolower(trim((string) ($feedback['outcome'] ?? '')));
        if ($queryId === '' || !in_array($outcome, [
            'helpful', 'not_helpful', 'applied', 'ignored', 'outdated', 'incorrect', 'relevant',
        ], true)) {
            throw new RuntimeException('Feedback requires query_id and a supported outcome');
        }
        $exists = $this->index->pdo()->prepare('SELECT 1 FROM query_log WHERE query_id = :query_id');
        $exists->execute(['query_id' => $queryId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException('Feedback query_id does not exist');
        }
        $chunkId = trim((string) ($feedback['chunk_id'] ?? ''));
        if ($chunkId !== '') {
            $result = $this->index->pdo()->prepare(
                'SELECT 1 FROM query_results WHERE query_id = :query_id AND chunk_id = :chunk_id'
            );
            $result->execute(['query_id' => $queryId, 'chunk_id' => $chunkId]);
            if ($result->fetchColumn() === false) {
                throw new RuntimeException('Feedback chunk_id was not returned by this query');
            }
        }
        $feedbackId = trim((string) ($feedback['feedback_id'] ?? '')) ?: Ids::make('qfb');
        $statement = $this->index->pdo()->prepare(
            'INSERT INTO query_feedback(feedback_id, query_id, chunk_id, outcome, comment, actor, created_at)
             VALUES(:feedback_id, :query_id, :chunk_id, :outcome, :comment, :actor, :created_at)
             ON CONFLICT(feedback_id) DO NOTHING'
        );
        $statement->execute([
            'feedback_id' => $feedbackId,
            'query_id' => $queryId,
            'chunk_id' => $chunkId === '' ? null : $chunkId,
            'outcome' => $outcome,
            'comment' => mb_substr(trim((string) ($feedback['comment'] ?? '')), 0, 4_000, 'UTF-8'),
            'actor' => mb_substr(trim((string) ($feedback['actor'] ?? 'user')) ?: 'user', 0, 120, 'UTF-8'),
            'created_at' => Clock::now(),
        ]);

        return [
            'feedback_id' => $feedbackId,
            'recorded' => $statement->rowCount() === 1,
            'query_id' => $queryId,
            'chunk_id' => $chunkId === '' ? null : $chunkId,
            'outcome' => $outcome,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
        ];
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function exactCandidates(string $query, array $options, int $limit): array
    {
        [$filter, $params] = $this->filterSql($options, 'f', 'exact');
        $params['exact_query'] = $query;
        $params['exact_contains'] = '%' . $this->escapeLike($query) . '%';
        $statement = $this->index->pdo()->prepare(
            "SELECT c.chunk_id,
                    MAX(CASE WHEN f.path = :exact_query COLLATE NOCASE THEN 12
                             WHEN s.fq_name = :exact_query COLLATE NOCASE THEN 11
                             WHEN s.name = :exact_query COLLATE NOCASE THEN 10
                             WHEN f.module_vendor || '_' || f.module_name = :exact_query COLLATE NOCASE THEN 9
                             WHEN f.module_vendor || '/' || f.module_name = :exact_query COLLATE NOCASE THEN 9
                             WHEN f.path LIKE :exact_contains ESCAPE '\\' THEN 5 ELSE 1 END) AS raw
               FROM chunks AS c JOIN indexed_files AS f ON f.id = c.file_id
          LEFT JOIN symbols AS s ON s.file_id = f.id AND (s.chunk_id = c.chunk_id OR s.symbol_uid = c.symbol_uid)
              WHERE (f.path LIKE :exact_contains ESCAPE '\\'
                 OR s.name = :exact_query COLLATE NOCASE OR s.fq_name = :exact_query COLLATE NOCASE
                 OR s.fq_name LIKE :exact_contains ESCAPE '\\'
                 OR f.module_vendor || '_' || f.module_name = :exact_query COLLATE NOCASE
                 OR f.module_vendor || '/' || f.module_name = :exact_query COLLATE NOCASE)" . $filter . '
           GROUP BY c.chunk_id ORDER BY raw DESC, c.chunk_id LIMIT ' . $limit
        );
        $statement->execute($params);

        return $this->candidateRows($statement->fetchAll(), 'raw');
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function ftsCandidates(string $query, array $options, int $limit): array
    {
        $match = $this->ftsExpression($query);
        if ($match === '') {
            return [];
        }
        [$filter, $params] = $this->filterSql($options, 'f', 'fts');
        $params['fts_match'] = $match;
        try {
            $statement = $this->index->pdo()->prepare(
                'SELECT c.chunk_id, -bm25(chunk_fts, 1.0, 0.6, 1.5, 1.4, 0.8) AS raw
                   FROM chunk_fts JOIN chunks c ON c.rowid = chunk_fts.rowid
                   JOIN indexed_files f ON f.id = c.file_id
                  WHERE chunk_fts MATCH :fts_match' . $filter . '
               ORDER BY bm25(chunk_fts, 1.0, 0.6, 1.5, 1.4, 0.8) LIMIT ' . $limit
            );
            $statement->execute($params);

            return $this->candidateRows($statement->fetchAll(), 'raw');
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function trigramCandidates(string $query, array $options, int $limit): array
    {
        [$filter, $params] = $this->filterSql($options, 'f', 'tri');
        $params['tri_match'] = '"' . str_replace('"', '""', mb_substr($query, 0, 256, 'UTF-8')) . '"';
        try {
            $statement = $this->index->pdo()->prepare(
                'SELECT c.chunk_id, -bm25(chunk_trigram, 1.0, 0.7, 1.2, 1.2, 0.8) AS raw
                   FROM chunk_trigram JOIN chunks c ON c.rowid = chunk_trigram.rowid
                   JOIN indexed_files f ON f.id = c.file_id
                  WHERE chunk_trigram MATCH :tri_match' . $filter . '
               ORDER BY bm25(chunk_trigram, 1.0, 0.7, 1.2, 1.2, 0.8) LIMIT ' . $limit
            );
            $statement->execute($params);

            return $this->candidateRows($statement->fetchAll(), 'raw');
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function cjkLikeCandidates(string $query, array $options, int $limit): array
    {
        [$filter, $params] = $this->filterSql($options, 'f', 'cjk');
        $params['cjk_like'] = '%' . $this->escapeLike($query) . '%';
        $statement = $this->index->pdo()->prepare(
            "SELECT c.chunk_id, CASE WHEN c.title LIKE :cjk_like ESCAPE '\\' THEN 2.0 ELSE 1.0 END AS raw
               FROM chunks c JOIN indexed_files f ON f.id = c.file_id
              WHERE (c.content LIKE :cjk_like ESCAPE '\\' OR c.title LIKE :cjk_like ESCAPE '\\')" . $filter . '
           ORDER BY raw DESC, c.chunk_id LIMIT ' . $limit
        );
        $statement->execute($params);

        return $this->candidateRows($statement->fetchAll(), 'raw');
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function sparseCandidates(string $query, array $options, int $limit): array
    {
        $vector = $this->vectorizer->vectorize($query);
        if ($vector === []) {
            return [];
        }
        $values = [];
        $params = [];
        $ordinal = 0;
        foreach ($vector as $hash => $weight) {
            $values[] = '(:sparse_hash_' . $ordinal . ', :sparse_weight_' . $ordinal . ')';
            $params['sparse_hash_' . $ordinal] = $hash;
            $params['sparse_weight_' . $ordinal] = $weight;
            ++$ordinal;
        }
        [$filter, $filterParams] = $this->filterSql($options, 'f', 'sparse');
        $params = array_replace($params, $filterParams);
        $statement = $this->index->pdo()->prepare(
            'WITH query_terms(term_hash, weight) AS (VALUES ' . implode(',', $values) . ')
             SELECT v.chunk_id, SUM(v.weight * q.weight) AS raw
               FROM query_terms q JOIN chunk_vector_terms v ON v.term_hash = q.term_hash
               JOIN chunks c ON c.chunk_id = v.chunk_id
               JOIN indexed_files f ON f.id = c.file_id
              WHERE 1=1' . $filter . '
           GROUP BY v.chunk_id HAVING raw > 0 ORDER BY raw DESC LIMIT ' . $limit
        );
        $statement->execute($params);

        return $this->candidateRows($statement->fetchAll(), 'raw');
    }

    /** @param array<string,array{score:float,components:array<string,mixed>}> $scores
     *  @param list<array{chunk_id:string,raw:float}> $rows
     */
    private function addChannel(array &$scores, array $rows, string $channel, float $weight): void
    {
        foreach ($rows as $index => $row) {
            $rank = $index + 1;
            $rrf = $weight / (60 + $rank);
            $chunkId = $row['chunk_id'];
            $scores[$chunkId] ??= ['score' => 0.0, 'components' => []];
            $scores[$chunkId]['score'] += $rrf;
            $scores[$chunkId]['components'][$channel] = [
                'rank' => $rank,
                'raw' => round($row['raw'], 6),
                'rrf' => round($rrf, 6),
            ];
        }
    }

    /** @param array<string,array{score:float,components:array<string,mixed>}> $scores */
    private function applyFeedbackBoost(array &$scores): void
    {
        if ($scores === []) {
            return;
        }
        $ids = array_keys($scores);
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':feedback_' . $index;
            $params['feedback_' . $index] = $id;
        }
        $statement = $this->index->pdo()->prepare(
            "SELECT chunk_id, SUM(CASE WHEN outcome IN ('helpful', 'applied', 'relevant') THEN 1
                                      WHEN outcome IN ('not_helpful', 'outdated', 'incorrect') THEN -1 ELSE 0 END) AS signal
               FROM query_feedback WHERE chunk_id IN (" . implode(',', $placeholders) . ') GROUP BY chunk_id'
        );
        $statement->execute($params);
        foreach ($statement->fetchAll() as $row) {
            $chunkId = (string) $row['chunk_id'];
            if (!isset($scores[$chunkId])) {
                continue;
            }
            $signal = max(-3, min(3, (int) $row['signal']));
            $boost = $signal * 0.003;
            $scores[$chunkId]['score'] += $boost;
            $scores[$chunkId]['components']['feedback'] = ['signal' => $signal, 'boost' => $boost];
        }
    }

    /** @param list<string> $chunkIds
     *  @return array<string,array<string,mixed>>
     */
    private function loadChunks(array $chunkIds): array
    {
        if ($chunkIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($chunkIds as $index => $chunkId) {
            $placeholders[] = ':chunk_' . $index;
            $params['chunk_' . $index] = $chunkId;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT c.*, f.path, f.kind AS file_kind, f.language, f.module_vendor, f.module_name,
                    f.content_hash AS file_hash, f.revision AS file_revision, f.indexed_at,
                    COALESCE(s.fq_name, s.name, \'\') AS symbol_name
               FROM chunks c JOIN indexed_files f ON f.id = c.file_id
          LEFT JOIN symbols s ON s.symbol_uid = c.symbol_uid
              WHERE c.chunk_id IN (' . implode(',', $placeholders) . ')'
        );
        $statement->execute($params);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['chunk_id']] = $row;
        }

        return $rows;
    }

    /** @param array<string,mixed> $row
     *  @param array<string,mixed> $components
     *  @return array<string,mixed>
     */
    private function formatChunk(array $row, string $query, int $tokenBudget, float $score, array $components): array
    {
        $snippet = $this->snippet((string) $row['content'], $query, $tokenBudget);
        $startLine = (int) $row['start_line'] + $snippet['line_offset'];

        return [
            'chunk_id' => $row['chunk_id'],
            'score' => round($score, 8),
            'score_components' => $components,
            'relative_path' => $row['path'],
            'absolute_path' => $this->index->absolutePath((string) $row['path']),
            'file_hash' => $row['file_hash'],
            'content_hash' => $row['content_hash'],
            'file_kind' => $row['file_kind'],
            'chunk_kind' => $row['kind'],
            'language' => $row['language'],
            'module' => trim($row['module_vendor'] . '/' . $row['module_name'], '/'),
            'title' => $row['title'],
            'symbol_uid' => $row['symbol_uid'],
            'symbol_name' => $row['symbol_name'] ?? '',
            'start_line' => $startLine,
            'end_line' => $startLine + substr_count($snippet['text'], "\n"),
            'snippet' => $snippet['text'],
            'token_estimate' => $snippet['tokens'],
            'freshness' => [
                'state' => $this->freshness(),
                'index_revision' => $this->index->revision(),
                'file_revision' => (int) $row['file_revision'],
                'indexed_at' => $row['indexed_at'],
            ],
        ];
    }

    /** @return array{text:string,tokens:int,line_offset:int} */
    private function snippet(string $content, string $query, int $tokenBudget): array
    {
        $characters = max(64, $tokenBudget * 4);
        if (mb_strlen($content, 'UTF-8') <= $characters) {
            return [
                'text' => $content,
                'tokens' => max(1, (int) ceil(mb_strlen($content, 'UTF-8') / 4)),
                'line_offset' => 0,
            ];
        }
        $position = $query === '' ? 0 : mb_stripos($content, $query, 0, 'UTF-8');
        $position = $position === false ? 0 : (int) $position;
        $start = max(0, $position - (int) floor($characters / 3));
        $prefix = mb_substr($content, 0, $start, 'UTF-8');
        $text = mb_substr($content, $start, $characters, 'UTF-8');
        if ($start > 0) {
            $text = "…\n" . $text;
        }
        if ($start + $characters < mb_strlen($content, 'UTF-8')) {
            $text .= "\n…";
        }

        return [
            'text' => $text,
            'tokens' => max(1, min($tokenBudget, (int) ceil(mb_strlen($text, 'UTF-8') / 4))),
            'line_offset' => substr_count($prefix, "\n"),
        ];
    }

    /** @param array<string,mixed> $options
     *  @return array{0:string,1:array<string,mixed>}
     */
    private function filterSql(array $options, string $alias, string $prefix): array
    {
        $clauses = [];
        $params = [];
        $paths = $this->stringList($options['paths'] ?? []);
        if (isset($options['path']) && is_string($options['path']) && trim($options['path']) !== '') {
            $paths[] = trim($options['path']);
        }
        if ($paths !== []) {
            $parts = [];
            foreach (array_values(array_unique($paths)) as $index => $path) {
                $path = $this->index->normalizeRelativePath($path);
                $parts[] = '(' . $alias . '.path = :' . $prefix . '_path_' . $index
                    . ' OR ' . $alias . ".path LIKE :" . $prefix . "_path_prefix_" . $index . " ESCAPE '\\')";
                $params[$prefix . '_path_' . $index] = $path;
                $params[$prefix . '_path_prefix_' . $index] = $this->escapeLike(rtrim($path, '/')) . '/%';
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }
        $modules = $this->stringList($options['modules'] ?? []);
        if (isset($options['module']) && is_string($options['module']) && trim($options['module']) !== '') {
            $modules[] = trim($options['module']);
        }
        if ($modules !== []) {
            $parts = [];
            foreach (array_values(array_unique($modules)) as $index => $module) {
                $key = $prefix . '_module_' . $index;
                $parts[] = '(' . $alias . ".module_vendor || '_' || " . $alias . '.module_name = :' . $key
                    . ' COLLATE NOCASE OR ' . $alias . ".module_vendor || '/' || " . $alias . '.module_name = :' . $key . ' COLLATE NOCASE)';
                $params[$key] = $module;
            }
            $clauses[] = '(' . implode(' OR ', $parts) . ')';
        }
        $kinds = $this->stringList($options['file_kinds'] ?? $options['kinds'] ?? []);
        if ($kinds !== []) {
            $parts = [];
            foreach ($kinds as $index => $kind) {
                $key = $prefix . '_kind_' . $index;
                $parts[] = ':' . $key;
                $params[$key] = $kind;
            }
            $clauses[] = $alias . '.kind IN (' . implode(',', $parts) . ')';
        }
        $languages = $this->stringList($options['languages'] ?? []);
        if ($languages !== []) {
            $parts = [];
            foreach ($languages as $index => $language) {
                $key = $prefix . '_language_' . $index;
                $parts[] = ':' . $key;
                $params[$key] = $language;
            }
            $clauses[] = $alias . '.language IN (' . implode(',', $parts) . ')';
        }

        return [$clauses === [] ? '' : ' AND ' . implode(' AND ', $clauses), $params];
    }

    /** @param array<string,mixed> $selector
     *  @return array{0:string,1:array<string,mixed>}
     */
    private function documentSelector(array $selector): array
    {
        $where = '';
        $params = [];
        if (isset($selector['path']) && is_string($selector['path']) && trim($selector['path']) !== '') {
            $where .= ' AND f.path = :doc_path';
            $params['doc_path'] = $this->index->normalizeRelativePath($selector['path']);
        }
        if (isset($selector['module']) && is_string($selector['module']) && trim($selector['module']) !== '') {
            $where .= " AND (f.module_vendor || '_' || f.module_name = :doc_module COLLATE NOCASE
                              OR f.module_vendor || '/' || f.module_name = :doc_module COLLATE NOCASE)";
            $params['doc_module'] = trim($selector['module']);
        }
        if (isset($selector['heading']) && is_string($selector['heading']) && trim($selector['heading']) !== '') {
            $where .= ' AND c.title = :doc_heading COLLATE NOCASE';
            $params['doc_heading'] = trim((string) preg_replace('/^#{1,6}\s+/', '', $selector['heading']));
        }
        if (isset($selector['expected_hash']) && is_string($selector['expected_hash']) && trim($selector['expected_hash']) !== '') {
            $hash = strtolower(trim($selector['expected_hash']));
            $hash = str_starts_with($hash, 'sha256:') ? $hash : 'sha256:' . $hash;
            if (preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new ToolException('DOCUMENT_HASH_INVALID', 'expected_hash must be a SHA-256 digest');
            }
            $where .= ' AND lower(f.content_hash) = :doc_hash';
            $params['doc_hash'] = $hash;
        }

        return [$where, $params];
    }

    /** @param list<string> $uids
     *  @param list<string> $names
     *  @return list<array<string,mixed>>
     */
    private function symbolRelations(array $uids, array $names, string $mode): array
    {
        if ($uids === []) {
            return [];
        }
        $parts = [];
        $params = [];
        foreach ($uids as $index => $uid) {
            $parts[] = ':relation_uid_' . $index;
            $params['relation_uid_' . $index] = $uid;
        }
        $nameParts = [];
        foreach ($names as $index => $name) {
            $nameParts[] = ':relation_name_' . $index;
            $params['relation_name_' . $index] = $name;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT r.*, f.path, source.fq_name AS source_name, target.fq_name AS resolved_target_name
               FROM relations r JOIN indexed_files f ON f.id = r.file_id
          LEFT JOIN symbols source ON source.symbol_uid = r.source_symbol_uid
          LEFT JOIN symbols target ON target.symbol_uid = r.target_symbol_uid
              WHERE r.source_symbol_uid IN (' . implode(',', $parts) . ')
                 OR r.target_symbol_uid IN (' . implode(',', $parts) . ')
                 OR r.target_name IN (' . implode(',', $nameParts) . ')
           ORDER BY r.relation_kind, f.path, r.line LIMIT 500'
        );
        $statement->execute($params);
        $uidSet = array_fill_keys($uids, true);
        $results = [];
        foreach ($statement->fetchAll() as $row) {
            $direction = isset($uidSet[(string) ($row['source_symbol_uid'] ?? '')]) ? 'downstream' : 'upstream';
            if (in_array($mode, ['impact', 'upstream', 'callers'], true) && $direction !== 'upstream') {
                continue;
            }
            if (in_array($mode, ['downstream', 'callees'], true) && $direction !== 'downstream') {
                continue;
            }
            $results[] = [
                'direction' => $direction,
                'kind' => $row['relation_kind'],
                'source_symbol_uid' => $row['source_symbol_uid'],
                'source_name' => $row['source_name'],
                'target_symbol_uid' => $row['target_symbol_uid'],
                'target_name' => $row['resolved_target_name'] ?: $row['target_name'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'line' => (int) $row['line'],
                'confidence' => (float) $row['confidence'],
            ];
        }

        return $results;
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function candidateRows(array $rows, string $scoreColumn): array
    {
        return array_map(static fn (array $row): array => [
            'chunk_id' => (string) $row['chunk_id'],
            'raw' => (float) ($row[$scoreColumn] ?? 0.0),
        ], $rows);
    }

    private function ftsExpression(string $query): string
    {
        if (preg_match_all('/[\p{L}\p{N}_\\:-]+/u', mb_strtolower($query, 'UTF-8'), $matches) === false) {
            return '';
        }
        $terms = [];
        foreach (array_slice(array_values(array_unique($matches[0] ?? [])), 0, 16) as $term) {
            $term = trim($term, "\\:-_");
            if ($term === '') {
                continue;
            }
            $quoted = '"' . str_replace('"', '""', $term) . '"';
            if (preg_match('/^[a-z0-9_:-]+$/i', $term) === 1 && strlen($term) >= 2) {
                $quoted .= '*';
            }
            $terms[] = $quoted;
        }

        return implode(' OR ', $terms);
    }

    /** @param array<string,mixed> $options */
    private function beginQuery(string $queryId, string $query, array $options): void
    {
        [$redactedQuery] = Redactor::string($query);
        [$redactedOptions] = Redactor::value($options);
        $retention = $this->config->duration('privacy.raw_retention');
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $retention);
        $cleanup = $this->index->pdo()->prepare('DELETE FROM query_log WHERE created_at < :cutoff');
        $cleanup->execute(['cutoff' => $cutoff]);
        $statement = $this->index->pdo()->prepare(
            'INSERT INTO query_log(query_id, query_text, options_json, revision, status, timings_json, created_at)
             VALUES(:query_id, :query_text, :options_json, :revision, :status, :timings_json, :created_at)'
        );
        $statement->execute([
            'query_id' => $queryId,
            'query_text' => 'sha256:' . hash('sha256', $redactedQuery),
            'options_json' => Json::encode($redactedOptions),
            'revision' => $this->index->revision(),
            'status' => 'running',
            'timings_json' => '{}',
            'created_at' => Clock::now(),
        ]);
    }

    /** @param array<string,mixed> $options
     *  @param list<array<string,mixed>> $results
     *  @param list<string> $warnings
     *  @return array<string,mixed>
     */
    private function completeSearch(
        string $queryId,
        string $query,
        array $options,
        array $results,
        int $tokenBudget,
        int $usedTokens,
        float $started,
        array $warnings,
    ): array {
        $durationMs = (int) round((microtime(true) - $started) * 1_000);
        $this->index->transaction(function (PDO $database) use ($queryId, $results, $durationMs): void {
            $insert = $database->prepare(
                'INSERT OR IGNORE INTO query_results(query_id, result_rank, chunk_id, score, components_json)
                 VALUES(:query_id, :rank, :chunk_id, :score, :components_json)'
            );
            foreach ($results as $index => $result) {
                $insert->execute([
                    'query_id' => $queryId,
                    'rank' => $index + 1,
                    'chunk_id' => $result['chunk_id'],
                    'score' => $result['score'],
                    'components_json' => Json::encode($result['score_components']),
                ]);
            }
            $update = $database->prepare(
                'UPDATE query_log SET status = :status, timings_json = :timings, completed_at = :completed_at
                  WHERE query_id = :query_id'
            );
            $update->execute([
                'status' => 'completed',
                'timings' => Json::encode(['duration_ms' => $durationMs, 'results' => count($results)]),
                'completed_at' => Clock::now(),
                'query_id' => $queryId,
            ]);
        });

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'project_id' => $this->index->projectId(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'query' => $query,
            'results' => $results,
            'token_budget' => [
                'requested' => $tokenBudget,
                'used' => $usedTokens,
                'truncated' => count($results) > 0 && $usedTokens >= $tokenBudget,
            ],
            'retrieval' => [
                'strategy' => 'exact+fts5+trigram_cjk+sparse_feature_hash+rrf+feedback',
                'neural_embeddings' => false,
                'filesystem_scanned' => false,
                'external_graph_invoked' => false,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function failQuery(string $queryId, float $started, string $error): void
    {
        $statement = $this->index->pdo()->prepare(
            'UPDATE query_log SET status = :status, timings_json = :timings, completed_at = :completed_at
              WHERE query_id = :query_id'
        );
        $statement->execute([
            'status' => 'failed',
            'timings' => Json::encode([
                'duration_ms' => (int) round((microtime(true) - $started) * 1_000),
                'error' => mb_substr($error, 0, 2_000, 'UTF-8'),
            ]),
            'completed_at' => Clock::now(),
            'query_id' => $queryId,
        ]);
    }

    /** @param array<string,mixed> $options */
    private function standaloneQuery(string $query, array $options): string
    {
        $queryId = Ids::make('query');
        $this->beginQuery($queryId, $query, $options);
        $statement = $this->index->pdo()->prepare(
            'UPDATE query_log SET status = :status, timings_json = :timings, completed_at = :completed_at
              WHERE query_id = :query_id'
        );
        $statement->execute([
            'status' => 'completed',
            'timings' => '{}',
            'completed_at' => Clock::now(),
            'query_id' => $queryId,
        ]);

        return $queryId;
    }

    /** @param list<mixed> $chunkIds */
    private function saveStandaloneResults(string $queryId, array $chunkIds): void
    {
        $statement = $this->index->pdo()->prepare(
            'INSERT OR IGNORE INTO query_results(query_id, result_rank, chunk_id, score, components_json)
             VALUES(:query_id, :rank, :chunk_id, :score, :components_json)'
        );
        $rank = 0;
        foreach (array_values(array_unique(array_filter($chunkIds, 'is_string'))) as $chunkId) {
            if ($chunkId === '') {
                continue;
            }
            ++$rank;
            $statement->execute([
                'query_id' => $queryId,
                'rank' => $rank,
                'chunk_id' => $chunkId,
                'score' => 1.0 / $rank,
                'components_json' => '{}',
            ]);
        }
    }

    private function freshness(): string
    {
        return (string) ($this->index->state()['freshness'] ?? 'unknown');
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return array_values(array_unique($result));
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
