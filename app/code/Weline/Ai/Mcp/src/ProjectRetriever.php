<?php

declare(strict_types=1);

namespace LearningMcp;

use PDO;
use RuntimeException;
use Throwable;

final class ProjectRetriever
{
    private const SYMBOL_QUERY_BATCH_SIZE = 24;
    private const CONTEXT_BATCH_PATH_LIMIT = 40;
    private const CONTEXT_BATCH_SYMBOL_LIMIT = 20;

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
        $maxChunksPerFile = max(1, min(50, (int) ($options['max_chunks_per_file'] ?? 50)));
        $perResultTokenBudget = max(32, min($tokenBudget, (int) ($options['per_result_token_budget'] ?? $tokenBudget)));
        $candidateLimit = min(500, max(80, $limit * 12));
        $queryId = Ids::make('query');
        $started = microtime(true);
        $this->beginQuery($queryId, $query, $options);

        try {
            $scores = [];
            $warnings = [];
            $preloadedRows = [];
            $requestedPaths = $this->searchPaths($options);
            $telemetry = [
                'strategy' => 'exact+fts5+trigram_cjk+sparse_feature_hash+rrf+feedback',
                'phases_ms' => [],
                'cache' => [
                    'source' => 'project_sqlite_index',
                    'revision' => $this->index->revision(),
                    'scope' => $requestedPaths === [] ? 'global' : 'paths',
                    'requested_paths' => count($requestedPaths),
                ],
            ];

            if ($requestedPaths !== []) {
                $phaseStarted = hrtime(true);
                $batch = $this->pathScopedBatch($query, $options, $candidateLimit, $requestedPaths);
                $this->addChannel($scores, $batch['candidates'], 'path_scope', 3.2);
                $preloadedRows = $batch['rows'];
                $warnings = array_merge($warnings, $batch['warnings']);
                $telemetry['strategy'] = 'path_scoped_batch+local_sparse+rrf+feedback';
                $telemetry['phases_ms']['path_materialize_rank'] = $this->elapsedMilliseconds($phaseStarted);
                $telemetry['cache'] = array_replace($telemetry['cache'], $batch['cache']);
            } else {
                $phaseStarted = hrtime(true);
                $this->addChannel($scores, $this->exactCandidates($query, $options, $candidateLimit), 'exact', 3.0);
                $exactMilliseconds = $this->elapsedMilliseconds($phaseStarted);
                $telemetry['phases_ms']['exact'] = $exactMilliseconds;

                $phaseStarted = hrtime(true);
                $this->addChannel($scores, $this->ftsCandidates($query, $options, $candidateLimit), 'fts', 1.7);
                $ftsMilliseconds = $this->elapsedMilliseconds($phaseStarted);
                $telemetry['phases_ms']['fts'] = $ftsMilliseconds;
                $telemetry['phases_ms']['exact_fts'] = round($exactMilliseconds + $ftsMilliseconds, 3);

                $phaseStarted = hrtime(true);
                $state = $this->index->state();
                $trigramAvailable = (bool) ($state['trigram_available'] ?? false);
                if ($trigramAvailable && mb_strlen($query, 'UTF-8') >= 3) {
                    $this->addChannel($scores, $this->trigramCandidates($query, $options, $candidateLimit), 'trigram', 1.4);
                } elseif (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $query) === 1
                    && mb_strlen($query, 'UTF-8') <= 32) {
                    $this->addChannel($scores, $this->cjkLikeCandidates($query, $options, $candidateLimit), 'cjk_fallback', 0.8);
                    $warnings[] = 'Trigram FTS is unavailable or the query is shorter than three characters; bounded SQLite CJK fallback was used.';
                }
                $telemetry['phases_ms']['trigram_cjk'] = $this->elapsedMilliseconds($phaseStarted);

                $phaseStarted = hrtime(true);
                $sparseMeta = [];
                $this->addChannel(
                    $scores,
                    $this->sparseCandidates($query, $options, $candidateLimit, $sparseMeta),
                    'sparse',
                    2.0,
                );
                $telemetry['phases_ms']['sparse'] = $this->elapsedMilliseconds($phaseStarted);
                $telemetry['sparse'] = $sparseMeta;
            }

            if ($scores === []) {
                return $this->completeSearch(
                    $queryId,
                    $query,
                    $options,
                    [],
                    $tokenBudget,
                    0,
                    $started,
                    $warnings,
                    $telemetry,
                );
            }
            uasort($scores, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $scores = array_slice($scores, 0, min(900, count($scores)), true);
            $phaseStarted = hrtime(true);
            $this->applyFeedbackBoost($scores);
            uasort($scores, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
            $telemetry['phases_ms']['feedback_rerank'] = $this->elapsedMilliseconds($phaseStarted);

            $phaseStarted = hrtime(true);
            $rows = $preloadedRows === []
                ? $this->loadChunks(array_keys($scores))
                : array_intersect_key($preloadedRows, $scores);
            $results = [];
            $pathCounts = [];
            $usedTokens = 0;
            foreach (array_keys($scores) as $chunkId) {
                if (count($results) >= $limit || !isset($rows[$chunkId])) {
                    continue;
                }
                $path = (string) ($rows[$chunkId]['path'] ?? '');
                if (($pathCounts[$path] ?? 0) >= $maxChunksPerFile) {
                    continue;
                }
                $remaining = $tokenBudget - $usedTokens;
                if ($remaining < 32 && $results !== []) {
                    break;
                }
                $result = $this->formatChunk(
                    $rows[$chunkId],
                    $query,
                    min($perResultTokenBudget, max(32, $remaining)),
                    (float) $scores[$chunkId]['score'],
                    $scores[$chunkId]['components']
                );
                $usedTokens += (int) $result['token_estimate'];
                $results[] = $result;
                $pathCounts[$path] = ($pathCounts[$path] ?? 0) + 1;
            }
            $telemetry['phases_ms']['materialize_format'] = $this->elapsedMilliseconds($phaseStarted);

            return $this->completeSearch(
                $queryId,
                $query,
                $options,
                $results,
                $tokenBudget,
                min($usedTokens, $tokenBudget),
                $started,
                $warnings,
                $telemetry,
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

    /**
     * Return the smallest edit-ready context contract for one model round trip.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function getEditBundle(string $task, array $options = []): array
    {
        $bundleStarted = hrtime(true);
        $phaseTimings = [];
        $task = trim($task);
        if ($task === '') {
            throw new ToolException('VALIDATION_FAILED', 'task is required');
        }
        $materializationPaths = $this->normalizeEditBundlePaths($options['paths'] ?? []);
        $requestedPaths = $this->normalizeEditBundlePaths(
            $options['requested_paths'] ?? $materializationPaths,
        );
        $scopeRoots = $this->normalizeEditBundlePaths($options['scope_roots'] ?? []);
        $rolePaths = $this->normalizeEditBundlePaths($options['role_paths'] ?? []);
        $exactSymbolPaths = $this->normalizeEditBundlePaths($options['exact_symbol_paths'] ?? []);
        $exactSymbolScopeProven = (bool) ($options['exact_symbol_scope_proven'] ?? false);
        $forbiddenPatterns = $this->stringList($options['forbidden_patterns'] ?? []);
        $defaultMaxRegions = $materializationPaths === []
            ? 24
            : min(48, max(24, count($materializationPaths) * 2));
        $defaultTokenBudget = $materializationPaths === []
            ? 8_000
            : min(24_000, max(8_000, count($materializationPaths) * 900));
        $maxRegions = max(1, min(48, (int) ($options['max_regions'] ?? $defaultMaxRegions)));
        $tokenBudget = max(256, min(24_000, (int) ($options['token_budget'] ?? $defaultTokenBudget)));
        $maxChunksPerFile = max(1, min(8, (int) ($options['max_chunks_per_file'] ?? 4)));
        $symbols = $this->stringList($options['symbols'] ?? []);
        $kinds = $this->stringList($options['kinds'] ?? []);
        if ($kinds === []) {
            $kinds = ['code', 'config', 'rule'];
            if ((bool) ($options['include_docs'] ?? true)) {
                $kinds[] = 'doc';
            }
        }
        $kinds = array_values(array_diff($kinds, ['skill']));

        $includeSkills = (bool) ($options['include_skills'] ?? true);
        $skillModules = $this->stringList($options['modules'] ?? []);
        $explicitModule = trim((string) ($options['module'] ?? ''));
        if ($explicitModule !== '') {
            $skillModules[] = $explicitModule;
        }
        foreach ($materializationPaths as $requestedPath) {
            $module = $this->index->moduleForPath($requestedPath);
            if (is_array($module)) {
                $skillModules[] = (string) $module['vendor'] . '/' . (string) $module['module'];
            }
        }
        $skillModules = Text::uniqueStrings($skillModules);
        $skillReserve = $includeSkills && $tokenBudget >= 768
            ? min(1_600, max(384, (int) floor($tokenBudget * 0.35)))
            : 0;
        $regionBudget = max(256, $tokenBudget - $skillReserve);
        $symbolReserve = $symbols === [] ? 0 : min((int) floor($regionBudget * 0.3), 1_200);
        $searchBudget = max(256, $regionBudget - $symbolReserve);
        $candidateLimit = $materializationPaths === []
            ? min(96, max(48, $maxRegions * 3))
            : min(96, max($maxRegions, count($materializationPaths) * $maxChunksPerFile));
        $perResultDivisor = $materializationPaths === [] ? $candidateLimit : $maxRegions;
        $perRegionBudget = max(48, min(
            600,
            (int) floor($searchBudget / max(1, $perResultDivisor)),
        ));
        $includeDocs = (bool) ($options['include_docs'] ?? true);
        $expectedContextRoles = $this->stringList($options['expected_roles'] ?? []);
        if ($expectedContextRoles === []) {
            $expectedContextRoles = $this->expectedContextRoles(
                $task,
                true,
                true,
                $includeDocs,
            );
        } elseif (!$includeDocs) {
            $expectedContextRoles = array_values(array_diff(
                $expectedContextRoles,
                ['documentation'],
            ));
        }
        $requiredSymbols = $this->requiredSymbolTargets($symbols);
        $retrievalQuery = $this->expandRetrievalQuery($task)
            . ' '
            . $this->contextRoleSearchTerms($expectedContextRoles);
        if ($symbols !== []) {
            $retrievalQuery .= ' ' . implode(' ', $symbols);
        }
        $phaseStarted = hrtime(true);
        $search = $this->searchEditPathBatches($retrievalQuery, $materializationPaths, [
            'module' => trim((string) ($options['module'] ?? '')),
            'kinds' => $kinds,
            'limit' => $candidateLimit,
            'token_budget' => $searchBudget,
            'max_chunks_per_file' => $maxChunksPerFile,
            'per_result_token_budget' => $perRegionBudget,
        ]);
        $roleSearchResults = [];
        if ($rolePaths !== []) {
            $roleSearch = $this->searchEditPathBatches($retrievalQuery, $rolePaths, [
                'module' => trim((string) ($options['module'] ?? '')),
                'kinds' => $kinds,
                'limit' => min(50, max(count($rolePaths), count($rolePaths) * 2)),
                'token_budget' => min(
                    $searchBudget,
                    max(512, count($rolePaths) * $perRegionBudget * 2),
                ),
                'max_chunks_per_file' => 2,
                'per_result_token_budget' => $perRegionBudget,
            ]);
            $roleSearchResults = is_array($roleSearch['results'] ?? null)
                ? $roleSearch['results']
                : [];
        }
        $phaseTimings['retrieve_regions'] = $this->elapsedMilliseconds($phaseStarted);

        $phaseStarted = hrtime(true);
        $regions = [];
        $regionKeys = [];
        $requestedRegionSymbolUids = [];
        $usedTokens = 0;
        $exactSymbolLookupCompleted = false;
        $resolvedRequestedSymbols = [];
        $ambiguousRequestedSymbols = [];
        $maxRegions = min(48, max($maxRegions, count($requiredSymbols)));
        if ($requiredSymbols !== []) {
            try {
                $requestedInspection = $this->inspectSymbolsBatch($requiredSymbols);
                $exactSymbolLookupCompleted = true;
                $requestedDefinitions = [];
                foreach ($requiredSymbols as $requestedSymbol) {
                    $matchingDefinitions = [];
                    foreach ((array) (
                        $requestedInspection['inspections'][$requestedSymbol]['symbols'] ?? []
                    ) as $definition) {
                        if (!is_array($definition)
                            || !$this->pathAllowedForBundleScope(
                                (string) ($definition['relative_path'] ?? ''),
                                $scopeRoots,
                                $forbiddenPatterns,
                            )) {
                            continue;
                        }
                        $relativePath = (string) ($definition['relative_path'] ?? '');
                        if ($exactSymbolPaths !== []
                            && !$this->pathAllowedForBundleScope(
                                $relativePath,
                                $exactSymbolPaths,
                                [],
                            )) {
                            continue;
                        }
                        $definitionKey = trim((string) ($definition['symbol_uid'] ?? ''));
                        if ($definitionKey === '') {
                            $definitionKey = $relativePath
                                . ':'
                                . (int) ($definition['start_line'] ?? 0);
                        }
                        $matchingDefinitions[$definitionKey] = $definition;
                    }
                    $matchingDefinitions = array_values($matchingDefinitions);
                    if (count($matchingDefinitions) === 1) {
                        $resolvedRequestedSymbols[$requestedSymbol] = true;
                        $requestedDefinitions[$requestedSymbol] = $matchingDefinitions[0];
                    } elseif (count($matchingDefinitions) > 1) {
                        $ambiguousRequestedSymbols[$requestedSymbol] = array_map(
                            static fn (array $definition): array => [
                                'symbol_uid' => (string) ($definition['symbol_uid'] ?? ''),
                                'fq_name' => (string) ($definition['fq_name'] ?? ''),
                                'path' => (string) ($definition['relative_path'] ?? ''),
                                'start_line' => max(1, (int) ($definition['start_line'] ?? 1)),
                            ],
                            $matchingDefinitions,
                        );
                    }
                }
                foreach ($requestedDefinitions as $requestedSymbol => $definition) {
                    if (count($regions) >= $maxRegions || $usedTokens >= $regionBudget) {
                        break;
                    }
                    $remainingTargets = max(1, count($requestedDefinitions) - count($regions));
                    $remaining = max(32, min(
                        600,
                        (int) floor(($regionBudget - $usedTokens) / $remainingTargets),
                    ));
                    $region = $this->compactRequestedSymbolRegion(
                        $definition,
                        $requestedSymbol,
                        $remaining,
                    );
                    $key = $region['path'] . ':' . $region['start_line'] . ':' . $region['end_line'];
                    if (isset($regionKeys[$key])) {
                        continue;
                    }
                    $regions[] = $region;
                    $regionKeys[$key] = true;
                    $requestedRegionSymbolUid = trim((string) ($region['symbol_uid'] ?? ''));
                    if ($requestedRegionSymbolUid !== '') {
                        $requestedRegionSymbolUids[$requestedRegionSymbolUid] = true;
                    }
                    $usedTokens += (int) $region['token_estimate'];
                }
            } catch (Throwable $exception) {
                if ($exception instanceof ToolException) {
                    throw $exception;
                }
                [$message] = Redactor::string($exception->getMessage());
                throw new ToolException(
                    'INDEX_SYMBOL_QUERY_FAILED',
                    'Exact symbol lookup failed before context completeness could be evaluated',
                    true,
                    [
                        'requested_symbols' => $requiredSymbols,
                        'evidence' => Text::truncate($message, 400),
                        'index_revision' => $this->index->revision(),
                    ],
                );
            }
        }
        $searchResults = array_values(array_filter(
            array_merge(
                $roleSearchResults,
                is_array($search['results'] ?? null) ? $search['results'] : [],
            ),
            fn (mixed $result): bool => is_array($result)
                && $this->pathAllowedForBundleScope(
                    (string) ($result['relative_path'] ?? ''),
                    $scopeRoots,
                    $forbiddenPatterns,
                ),
        ));
        $rankedResults = $this->rankEditResults(
            $searchResults,
            $task,
            $rolePaths !== [] ? $rolePaths : $materializationPaths,
        );
        if ($materializationPaths === []) {
            foreach (array_slice($rankedResults, 0, 50) as $candidate) {
                $candidatePath = trim((string) ($candidate['relative_path'] ?? ''));
                if ($candidatePath === '') {
                    continue;
                }
                $candidateModule = $this->index->moduleForPath($candidatePath);
                if (is_array($candidateModule)) {
                    $skillModules[] = (string) $candidateModule['vendor']
                        . '/'
                        . (string) $candidateModule['module'];
                }
            }
            $skillModules = Text::uniqueStrings($skillModules);
        }
        // Materialize at least one exact region for every candidate path before
        // spending the remaining bounded budget on additional chunks. This folds
        // continuation path batches into the current MCP call.
        $priorityResults = [];
        $remainingResults = [];
        $firstResultByPath = [];
        foreach ($rankedResults as $item) {
            if (!is_array($item)) {
                continue;
            }
            $candidatePath = trim((string) ($item['relative_path'] ?? $item['path'] ?? ''));
            if ($candidatePath !== '' && !isset($firstResultByPath[$candidatePath])) {
                $priorityResults[] = $item;
                $firstResultByPath[$candidatePath] = true;
                continue;
            }
            $remainingResults[] = $item;
        }
        $maxRegions = min(48, max($maxRegions, count($regions) + count($priorityResults)));
        $rankedResults = array_merge($priorityResults, $remainingResults);
        foreach ($rankedResults as $item) {
            if (!is_array($item) || count($regions) >= $maxRegions || $usedTokens >= $regionBudget) {
                continue;
            }
            $region = $this->compactRegion($item, 'hybrid_index');
            $regionSymbolUid = trim((string) ($region['symbol_uid'] ?? ''));
            if ($regionSymbolUid !== '' && isset($requestedRegionSymbolUids[$regionSymbolUid])) {
                continue;
            }
            $key = $region['path'] . ':' . $region['start_line'] . ':' . $region['end_line'];
            if (isset($regionKeys[$key])) {
                continue;
            }
            $regions[] = $region;
            $regionKeys[$key] = true;
            $usedTokens += (int) $region['token_estimate'];
        }
        $phaseTimings['compact_regions'] = $this->elapsedMilliseconds($phaseStarted);

        $impactTargets = [];
        foreach ($requiredSymbols as $symbol) {
            $impactTargets[$symbol] = ['label' => $symbol, 'origin' => 'requested_symbol'];
        }
        foreach ($regions as $region) {
            if (count($impactTargets) >= 24) {
                break;
            }
            $symbolUid = trim((string) ($region['symbol_uid'] ?? ''));
            $symbolName = trim((string) ($region['symbol'] ?? ''));
            $target = $symbolUid !== '' ? $symbolUid : $symbolName;
            if ($target === '' || isset($impactTargets[$target])) {
                continue;
            }
            $impactTargets[$target] = [
                'label' => $symbolName !== '' ? $symbolName : $target,
                'origin' => 'selected_region',
            ];
        }

        $phaseStarted = hrtime(true);
        $impacts = [];
        $impactBatchMeta = ['sql_round_trips' => 0, 'target_count' => count($impactTargets)];
        try {
            $batchInspection = $this->inspectSymbolsBatch(
                array_keys($impactTargets),
                $retrievalQuery,
            );
            $impactBatchMeta = $batchInspection['meta'];
            foreach ($impactTargets as $symbol => $targetMetadata) {
                $inspection = $batchInspection['inspections'][$symbol] ?? ['symbols' => [], 'impact' => []];
                $impact = is_array($inspection['impact'] ?? null) ? $inspection['impact'] : [];
                $upstreamFiles = array_slice(array_values(array_filter(
                    is_array($impact['upstream_files'] ?? null) ? $impact['upstream_files'] : [],
                    fn (mixed $path): bool => $this->pathAllowedForBundleScope(
                        (string) $path,
                        $scopeRoots,
                        $forbiddenPatterns,
                    ),
                )), 0, 50);
                $impactModules = is_array($impact['modules'] ?? null) ? $impact['modules'] : [];
                if ($scopeRoots !== []) {
                    $impactModules = [];
                    foreach ($upstreamFiles as $upstreamFile) {
                        $upstreamModule = $this->index->moduleForPath((string) $upstreamFile);
                        if (is_array($upstreamModule)) {
                            $impactModules[] = (string) $upstreamModule['vendor']
                                . '/'
                                . (string) $upstreamModule['module'];
                        }
                    }
                    $impactModules = Text::uniqueStrings($impactModules);
                }
                $impacts[] = [
                    'symbol' => $targetMetadata['label'],
                    'origin' => $targetMetadata['origin'],
                    'risk_level' => $impact['risk_level'] ?? 'unknown',
                    'upstream_files' => $upstreamFiles,
                    'upstream_file_count' => count($upstreamFiles),
                    'upstream_symbol_count' => $impact['upstream_symbol_count'] ?? 0,
                    'module_count' => count($impactModules),
                    'modules' => $impactModules,
                ];
            }
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            foreach ($impactTargets as $targetMetadata) {
                $impacts[] = [
                    'symbol' => $targetMetadata['label'],
                    'origin' => $targetMetadata['origin'],
                    'risk_level' => 'unknown',
                    'warning' => Text::truncate($message, 240),
                ];
            }
        }
        $phaseTimings['batch_impact'] = $this->elapsedMilliseconds($phaseStarted);

        $phaseStarted = hrtime(true);
        $skills = [];
        if ($includeSkills) {
            $skillContentBudget = min($skillReserve, max(0, $tokenBudget - $usedTokens));
            $route = $this->resolveSkill([
                'task' => $task,
                'modules' => $skillModules,
                'limit' => 6,
                'include_content' => $skillContentBudget >= 128,
                'token_budget' => max(128, $skillContentBudget),
            ]);
            foreach ($route['skills'] ?? [] as $skill) {
                if (!is_array($skill)) {
                    continue;
                }
                $description = mb_strtolower((string) ($skill['description'] ?? ''), 'UTF-8');
                $moduleScoped = $skillModules !== [];
                $threshold = $moduleScoped ? 0.08 : 0.18;
                if ((float) ($skill['score'] ?? 0) < $threshold
                    || (!$moduleScoped && (float) ($skill['lexical_score'] ?? 0) < 0.12)
                    || str_contains($description, 'deprecated compatibility alias')) {
                    continue;
                }
                $skillContext = [
                    'name' => $skill['name'] ?? '',
                    'path' => $skill['relative_path'] ?? '',
                    'module' => $skill['module'] ?? '',
                    'scope' => $skill['scope'] ?? 'project',
                    'file_sha256' => $skill['file_hash'] ?? '',
                    'score' => $skill['score'] ?? 0,
                    'triggers' => array_slice(is_array($skill['triggers'] ?? null) ? $skill['triggers'] : [], 0, 6),
                ];
                if (isset($skill['content']) && is_string($skill['content'])) {
                    $skillContext['content'] = $skill['content'];
                    $skillContext['token_estimate'] = (int) ($skill['token_estimate'] ?? 0);
                    $skillContext['content_truncated'] = (bool) ($skill['content_truncated'] ?? false);
                    $usedTokens += (int) $skillContext['token_estimate'];
                }
                $skills[] = $skillContext;
                if (count($skills) >= 4) {
                    break;
                }
            }
        }
        $phaseTimings['route_skills'] = $this->elapsedMilliseconds($phaseStarted);

        $riskOrder = ['none' => 0, 'unknown' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5];
        $impactRisk = 'none';
        foreach ($impacts as $impact) {
            $candidate = strtolower((string) ($impact['risk_level'] ?? 'unknown'));
            if (($riskOrder[$candidate] ?? 1) > ($riskOrder[$impactRisk] ?? 0)) {
                $impactRisk = $candidate;
            }
        }
        $totalMilliseconds = $this->elapsedMilliseconds($bundleStarted);
        $manifest = $this->buildCandidateManifest(
            $task,
            $rankedResults,
            $regions,
            $requestedPaths,
            $impacts,
            $expectedContextRoles,
            $scopeRoots,
            $forbiddenPatterns,
        );

        $materializedPathLookup = [];
        foreach ($regions as $region) {
            if (!is_array($region)) {
                continue;
            }
            $path = trim((string) ($region['path'] ?? ''));
            if ($path !== '') {
                $materializedPathLookup[$path] = true;
            }
        }
        $missingPaths = array_values(array_filter(
            $requestedPaths,
            static fn (string $path): bool => !isset($materializedPathLookup[$path]),
        ));

        $missingSymbols = [];
        foreach ($requiredSymbols as $requestedSymbol) {
            $requestedSymbol = trim((string) $requestedSymbol);
            if ($requestedSymbol === '') {
                continue;
            }
            if (isset($ambiguousRequestedSymbols[$requestedSymbol])) {
                $missingSymbols[] = $requestedSymbol;
                continue;
            }
            $matched = false;
            foreach ($regions as $region) {
                $materializedSymbol = trim((string) ($region['symbol'] ?? $region['target_ref'] ?? ''));
                if ($this->symbolTargetsMatch($materializedSymbol, $requestedSymbol)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $missingSymbols[] = $requestedSymbol;
            }
        }
        $missingSymbols = Text::uniqueStrings($missingSymbols);
        $ambiguousSymbols = array_keys($ambiguousRequestedSymbols);
        $unresolvedSymbols = $exactSymbolLookupCompleted && $exactSymbolScopeProven
            ? array_values(array_filter(
                $missingSymbols,
                static fn (string $symbol): bool =>
                    !isset($resolvedRequestedSymbols[$symbol])
                    && !isset($ambiguousRequestedSymbols[$symbol]),
            ))
            : [];
        $unverifiedSymbols = $exactSymbolLookupCompleted && !$exactSymbolScopeProven
            ? array_values(array_filter(
                $missingSymbols,
                static fn (string $symbol): bool =>
                    !isset($resolvedRequestedSymbols[$symbol])
                    && !isset($ambiguousRequestedSymbols[$symbol]),
            ))
            : [];

        $revision = $this->index->revision();
        if ($revision <= 0 && $regions === []) {
            $waitStartedAt = hrtime(true);
            do {
                usleep(50_000);
                $revision = $this->index->revision();
            } while ($revision <= 0 && $this->elapsedMilliseconds($waitStartedAt) < 1_000);

            throw new ToolException(
                'INDEX_NOT_READY',
                'Project index is not ready for an editable bundle',
                true,
                [
                    'status' => 'INDEX_NOT_READY',
                    'retryable' => true,
                    'retry_after_ms' => 1_000,
                    'index_revision' => $revision,
                    'ready_for_edit' => false,
                    'coverage_status' => 'unavailable',
                    'continuation_needed' => false,
                    'missing_paths' => $requestedPaths,
                    'missing_symbols' => $symbols,
                    'native_file_read_fallback_allowed' => false,
                    'intermediate_user_confirmation_required' => false,
                ],
            );
        }

        $architecture = is_array($manifest['architecture'] ?? null) ? $manifest['architecture'] : [];
        $expectedRoles = Text::uniqueStrings((array) ($architecture['expected_roles'] ?? []));
        $coveredRoles = Text::uniqueStrings((array) ($architecture['covered_roles'] ?? []));
        $missingRoles = Text::uniqueStrings((array) ($architecture['missing_roles'] ?? []));

        $candidateFiles = [];
        $selectedFiles = [];
        $excludedFiles = [];
        foreach ((array) ($manifest['candidate_paths'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $path = trim((string) ($candidate['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $materialized = (bool) ($candidate['materialized'] ?? false);
            $entry = $candidate;
            $entry['path'] = $path;
            $entry['materialized'] = $materialized;
            $entry['selected'] = $materialized;
            $entry['reasons'] = Text::uniqueStrings(array_merge(
                (array) ($candidate['evidence'] ?? []),
                (array) ($candidate['sources'] ?? []),
            ), false);
            if (!$materialized) {
                $entry['excluded_reason'] = 'Ranked candidate was not required by the final bounded edit context.';
            }
            $candidateFiles[] = $entry;
            if ($materialized) {
                $selectedFiles[] = $entry;
            } else {
                $excludedFiles[] = $entry;
            }
        }

        $expectedFileHashes = [];
        foreach ($regions as $region) {
            if (!is_array($region)) {
                continue;
            }
            $path = trim((string) ($region['path'] ?? ''));
            $hash = trim((string) ($region['expected_file_sha256'] ?? $region['file_sha256'] ?? ''));
            if ($path !== '' && $hash !== '') {
                $expectedFileHashes[$path] = $hash;
            }
        }
        ksort($expectedFileHashes, SORT_STRING);

        $dependencyEdges = [];
        foreach ($impacts as $impact) {
            if (!is_array($impact)) {
                continue;
            }
            $symbol = trim((string) ($impact['symbol'] ?? ''));
            foreach (array_slice((array) ($impact['upstream_files'] ?? []), 0, 50) as $upstreamPath) {
                $upstreamPath = trim((string) $upstreamPath);
                if ($upstreamPath === '') {
                    continue;
                }
                $dependencyEdges[] = [
                    'from' => $symbol,
                    'to' => $upstreamPath,
                    'kind' => 'upstream_consumer',
                    'risk' => (string) ($impact['risk_level'] ?? 'unknown'),
                ];
            }
        }

        $hasRole = static fn (string $role): bool => in_array($role, $coveredRoles, true);
        $needsRole = static fn (string $role): bool => in_array($role, $expectedRoles, true);
        $dimensions = [
            'architecture' => $missingRoles === [],
            'definitions' => $regions !== [],
            'requested_paths' => $missingPaths === [],
            'requested_symbols' => $missingSymbols === [],
            'symbol_graph' => $requiredSymbols === [] || $impacts !== [],
            'contracts' => !$needsRole('contract') || $hasRole('contract') || $hasRole('tool_contract'),
            'configuration' => !$needsRole('configuration') || $hasRole('configuration') || $hasRole('plugin_manifest'),
            'tests' => !$needsRole('test') || $hasRole('test'),
            'documentation' => !(bool) ($options['include_docs'] ?? true)
                || !$needsRole('documentation')
                || $hasRole('documentation'),
            'consumers' => $requiredSymbols === [] || $dependencyEdges !== [] || $impacts !== [],
        ];
        $coveredDimensions = array_keys(array_filter($dimensions));
        $missingDimensions = array_keys(array_filter($dimensions, static fn (bool $covered): bool => !$covered));
        $readyForEdit = $revision > 0 && $missingDimensions === [];
        $contextCompleteness = [
            'status' => $readyForEdit ? 'complete' : 'incomplete',
            'score' => (int) round((count($coveredDimensions) / max(1, count($dimensions))) * 100),
            'dimensions' => $dimensions,
            'covered' => $coveredDimensions,
            'missing' => $missingDimensions,
            'expected_roles' => $expectedRoles,
            'covered_roles' => $coveredRoles,
            'missing_roles' => $missingRoles,
        ];

        $coverage = is_array($manifest['coverage'] ?? null) ? $manifest['coverage'] : [];
        $coverage['status'] = $readyForEdit ? 'complete' : 'partial';
        $coverage['missing_paths'] = $missingPaths;
        $coverage['missing_symbols'] = $missingSymbols;
        $coverage['unresolved_symbols'] = $unresolvedSymbols;
        $coverage['unverified_symbols'] = $unverifiedSymbols;
        $coverage['ambiguous_symbols'] = $ambiguousSymbols;
        $coverage['missing_roles'] = $missingRoles;
        $coverage['context_score'] = $contextCompleteness['score'];
        $targetAmbiguous = $ambiguousRequestedSymbols !== [];
        $targetNotFound = $unresolvedSymbols !== [];
        $terminalContextFailure = $targetAmbiguous || $targetNotFound;
        $terminalContextStatus = $targetAmbiguous
            ? 'CONTEXT_TARGET_AMBIGUOUS'
            : ($targetNotFound ? 'CONTEXT_TARGET_NOT_FOUND' : '');
        $initialContinuation = is_array($manifest['continuation'] ?? null)
            ? $manifest['continuation']
            : [];
        $nextPathBatches = [];
        $plannedPathLookup = [];
        foreach ((array) ($initialContinuation['next_path_batches'] ?? []) as $pathBatch) {
            if (!is_array($pathBatch)) {
                continue;
            }
            $normalizedBatch = Text::uniqueStrings($pathBatch);
            if ($normalizedBatch === []) {
                continue;
            }
            $nextPathBatches[] = $normalizedBatch;
            foreach ($normalizedBatch as $plannedPath) {
                $plannedPathLookup[$plannedPath] = true;
            }
        }
        $unplannedMissingPaths = array_values(array_filter(
            $missingPaths,
            static fn (string $path): bool => !isset($plannedPathLookup[$path]),
        ));
        foreach (array_chunk($unplannedMissingPaths, self::CONTEXT_BATCH_PATH_LIMIT) as $pathBatch) {
            if ($pathBatch !== []) {
                $nextPathBatches[] = $pathBatch;
            }
        }
        $boundedPathBatches = [];
        foreach ($nextPathBatches as $pathBatch) {
            foreach (array_chunk($pathBatch, self::CONTEXT_BATCH_PATH_LIMIT) as $boundedPathBatch) {
                if ($boundedPathBatch !== []) {
                    $boundedPathBatches[] = $boundedPathBatch;
                }
            }
        }
        $nextPathBatches = $boundedPathBatches;
        $nextSearchGoals = Text::uniqueStrings(
            (array) ($initialContinuation['next_search_goals'] ?? []),
            false,
        );
        $capacityMissingSymbols = array_values(array_diff(
            $missingSymbols,
            $unresolvedSymbols,
            $ambiguousSymbols,
        ));
        $nextSymbolBatches = array_chunk(
            $capacityMissingSymbols,
            self::CONTEXT_BATCH_SYMBOL_LIMIT,
        );
        if (!$readyForEdit
            && !$terminalContextFailure
            && $nextPathBatches === []
            && $nextSymbolBatches === []
            && $nextSearchGoals === []) {
            $nextSearchGoals[] = 'Resolve remaining context dimensions ['
                . implode(', ', $missingDimensions)
                . '] inside the original task scope.';
        }
        if ($terminalContextFailure) {
            $nextPathBatches = [];
            $nextSymbolBatches = [];
            $nextSearchGoals = [];
        }
        $nextSearchGoals = Text::uniqueStrings($nextSearchGoals, false);
        $batchCount = count($nextPathBatches)
            + count($nextSymbolBatches)
            + count($nextSearchGoals);
        $continuationNeeded = !$readyForEdit && !$terminalContextFailure;
        $batchPlan = [
            'schema_version' => 'context-batch-plan.v1',
            'status' => $readyForEdit
                ? 'complete'
                : ($terminalContextFailure ? 'not_applicable' : 'planned'),
            'strategy' => 'bounded_parent_with_child_batches',
            'execution_mode' => 'ordered_child_execution_runs',
            'batch_count' => $batchCount,
            'max_paths_per_batch' => self::CONTEXT_BATCH_PATH_LIMIT,
            'max_symbols_per_batch' => self::CONTEXT_BATCH_SYMBOL_LIMIT,
            'path_batches' => $nextPathBatches,
            'symbol_batches' => $nextSymbolBatches,
            'search_goals' => $nextSearchGoals,
            'remaining_path_count' => array_sum(array_map('count', $nextPathBatches)),
            'missing_symbol_count' => count($missingSymbols),
            'requires_user_confirmation' => false,
            'parent_apply_allowed' => false,
            'parent_terminal' => $continuationNeeded || $terminalContextFailure,
            'task_terminal' => $terminalContextFailure,
            'terminal' => $continuationNeeded || $terminalContextFailure,
            'retry_budget' => [
                'replans_max' => 2,
                'same_error_stop_count' => 3,
            ],
        ];
        $continuation = $initialContinuation;
        $continuation['needed'] = $continuationNeeded;
        $continuation['reason'] = $readyForEdit
            ? 'Candidate path batches and semantic role goals were aggregated inside this call.'
            : ($terminalContextFailure
                ? ($targetAmbiguous
                    ? 'One or more short requested symbols resolve to multiple in-scope definitions.'
                    : 'One or more exact requested symbols do not exist in the refreshed in-scope index.')
                : 'The bounded parent reached its context capacity; executable child requests cover every remaining target.');
        $continuation['next_path_batches'] = $nextPathBatches;
        $continuation['next_symbol_batches'] = $nextSymbolBatches;
        $continuation['next_search_goals'] = $nextSearchGoals;
        $continuation['external_followup_allowed'] = $continuationNeeded;
        $continuation['batch_plan'] = $batchPlan;

        return [
            'request_id' => Ids::make('req'),
            'task_id' => 'task-' . substr(hash('sha256', $this->index->projectId() . "\0" . $task), 0, 24),
            'bundle_id' => 'bundle-' . substr(hash('sha256', $this->index->projectId() . "\0" . $revision . "\0" . $task), 0, 24),
            'query_id' => $search['query_id'] ?? '',
            'project_id' => $this->index->projectId(),
            'project_revision' => $revision,
            'index_revision' => $revision,
            'freshness' => $this->freshness(),
            'task_digest' => Ids::hash($task),
            'task_summary' => Text::truncate($task, 160),
            'status' => $readyForEdit
                ? 'READY_FOR_EDIT'
                : ($terminalContextFailure ? $terminalContextStatus : 'CONTEXT_BATCH_PLANNED'),
            'ready_for_edit' => $readyForEdit,
            'coverage_status' => $coverage['status'],
            'continuation_needed' => $continuationNeeded,
            'missing_paths' => $missingPaths,
            'missing_symbols' => $missingSymbols,
            'unresolved_targets' => [
                'symbols' => array_map(
                    static fn (string $symbol): array => [
                        'symbol' => $symbol,
                        'reason' => 'exact_symbol_not_found_in_refreshed_scope',
                        'retryable' => false,
                    ],
                    $unresolvedSymbols,
                ),
            ],
            'unverified_targets' => [
                'symbols' => array_map(
                    static fn (string $symbol): array => [
                        'symbol' => $symbol,
                        'reason' => 'exact_symbol_scope_not_yet_refreshed',
                        'retryable' => true,
                    ],
                    $unverifiedSymbols,
                ),
            ],
            'ambiguous_targets' => [
                'symbols' => array_map(
                    static fn (string $symbol, array $candidates): array => [
                        'symbol' => $symbol,
                        'reason' => 'short_symbol_matches_multiple_in_scope_definitions',
                        'retryable' => true,
                        'resolution' => 'Use a fully-qualified symbol or narrow the explicit paths.',
                        'candidates' => $candidates,
                    ],
                    array_keys($ambiguousRequestedSymbols),
                    array_values($ambiguousRequestedSymbols),
                ),
            ],
            'required_symbols' => $requiredSymbols,
            'symbol_hints' => array_values(array_diff($symbols, $requiredSymbols)),
            'context_completeness' => $contextCompleteness,
            'context_closure' => $dimensions + [
                'callers_and_callees' => $dimensions['symbol_graph'],
                'continuation_aggregated_server_side' => $readyForEdit,
                'continuation_planned_server_side' => $continuationNeeded,
            ],
            'validation_plan' => [
                'fixed_checks_in_apply' => ['syntax', 'diff_check', 'server_approved_regression', 'targeted_reindex'],
                'regression_entry' => 'apply_compact_edit:server_approved_batch',
                'regression_runs_max' => 1,
                'runtime_entry_runs_max' => 1,
                'failure_fields' => ['failed_stage', 'evidence', 'related_regions', 'suggested_scope'],
            ],
            'workflow_budget' => [
                'get_edit_bundle' => 1,
                'successful_apply_compact_edit' => 1,
                'conflict_replans_max' => 2,
            'context_batches_max' => max(8, $batchCount),
                'edit_batches_max' => 4,
                'impact_expansion_depth_max' => 2,
                'validation_repairs_max' => 2,
                'same_error_stop_count' => 3,
                'native_source_reads' => 0,
                'direct_writes' => 0,
                'intermediate_user_inquiries' => 0,
            ],
            'architecture' => $architecture,
            'architecture_summary' => [
                'phase' => $architecture['phase'] ?? ($requestedPaths === [] ? 'discovery' : 'materialization'),
                'layer_order' => $architecture['layer_order'] ?? [],
                'expected_roles' => $expectedRoles,
                'covered_roles' => $coveredRoles,
                'missing_roles' => $missingRoles,
            ],
            'candidate_paths' => $manifest['candidate_paths'],
            'candidate_files' => $candidateFiles,
            'selected_files' => $selectedFiles,
            'excluded_files' => $excludedFiles,
            'candidate_roles' => $manifest['candidate_roles'],
            'exact_regions' => $regions,
            'expected_file_hashes' => $expectedFileHashes,
            'dependency_edges' => $dependencyEdges,
            'impact_summary' => [
                'risk' => $impactRisk,
                'symbol_count' => count($impacts),
                'dependency_edge_count' => count($dependencyEdges),
                'consumer_file_count' => count(array_unique(array_column($dependencyEdges, 'to'))),
            ],
            'coverage' => $coverage,
            'continuation' => $continuation,
            'server_aggregation' => [
                'bounded' => true,
                'candidate_path_count' => count($manifest['candidate_paths'] ?? []),
                'continuation_path_batch_count' => count($initialContinuation['next_path_batches'] ?? []),
                'continuation_search_goal_count' => count($initialContinuation['next_search_goals'] ?? []),
                'external_tool_round_trip_required' => $continuationNeeded,
            ],
            'regions' => $regions,
            'region_count' => count($regions),
            'impacts' => $impacts,
            'impact_risk' => $impactRisk,
            'skills' => $skills,
            'budget' => [
                'requested_tokens' => $tokenBudget,
                'estimated_used_tokens' => min($usedTokens, $tokenBudget),
                'max_regions' => $maxRegions,
                'candidate_limit' => $candidateLimit,
                'max_chunks_per_file' => $maxChunksPerFile,
                'skill_reserved_tokens' => $skillReserve,
                'skill_count' => count($skills),
            ],
            'edit_plan_contract' => [
                'copy_region_guards' => [
                    'path',
                    'expected_file_sha256',
                    'symbol_uid_or_target_ref',
                    'expected_digest',
                ],
                'digest_rule' => 'Use expected_digest from the exact selected symbol region; content_sha256 is only the returned snippet digest and is not a symbol guard.',
                'replan_rule' => 'On EDIT_REPLAN_REQUIRED, preserve unchanged operations and replace only failed operations from matching latest_regions guards.',
            ],
            'source' => 'project_sqlite_index',
            'selection' => $requestedPaths === []
                ? 'hybrid_semantic_with_path_intent'
                : 'path_scoped_batch_regions',
            'performance' => [
                'total_ms' => $totalMilliseconds,
                'phases_ms' => $phaseTimings,
                'retrieval' => [
                    'strategy' => $search['retrieval']['strategy'] ?? '',
                    'timing_ms' => is_array($search['retrieval']['timing_ms'] ?? null)
                        ? $search['retrieval']['timing_ms']
                        : [],
                ],
                'cache' => [
                    'path_scope' => $search['retrieval']['cache']['status'] ?? 'not_requested',
                    'index_revision' => $this->index->revision(),
                    'impact_sql_round_trips' => (int) ($impactBatchMeta['sql_round_trips'] ?? 0),
                    'impact_targets' => (int) ($impactBatchMeta['target_count'] ?? count($impactTargets)),
                ],
            ],
            'warnings' => $search['warnings'] ?? [],
        ];
    }

    /** @param list<array<string,mixed>> $results
     *  @param list<string> $requestedPaths
     *  @return list<array<string,mixed>>
     */
    private function rankEditResults(array $results, string $task, array $requestedPaths): array
    {
        if ($results === []) {
            return [];
        }
        if ($requestedPaths !== []) {
            $queues = array_fill_keys($requestedPaths, []);
            $unscoped = [];
            foreach ($results as $result) {
                $path = (string) ($result['relative_path'] ?? '');
                if (isset($queues[$path])) {
                    $queues[$path][] = $result;
                } else {
                    $unscoped[] = $result;
                }
            }
            $ordered = [];
            do {
                $added = false;
                foreach ($requestedPaths as $path) {
                    if (($queues[$path] ?? []) === []) {
                        continue;
                    }
                    $ordered[] = array_shift($queues[$path]);
                    $added = true;
                }
            } while ($added);

            return array_merge($ordered, $this->diversifyEditResults($unscoped, $task));
        }
        $lowerTask = mb_strtolower($task, 'UTF-8');
        $containsAny = static function (string $value, array $needles): bool {
            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    return true;
                }
            }

            return false;
        };
        $documentationIntent = $containsAny($lowerTask, ['文档', 'documentation', 'readme', ' doc']);
        $codeIntent = $containsAny($lowerTask, [
            '代码', '方法', '函数', '符号', 'sql', '性能', '耗时', '延迟', '报错', '错误',
            'bug', 'fix', 'implement', 'refactor', 'latency', 'slow',
        ]) || (!$documentationIntent && $containsAny($lowerTask, ['优化', '修复', '实现', '重构', '修改']));

        $signals = [];
        $groups = [
            ['needles' => ['搜索', '检索', 'search'], 'paths' => ['search', 'query', 'filter', '检索', '过滤']],
            ['needles' => ['过滤', '筛选', 'filter'], 'paths' => ['filter', 'query', '过滤', '筛选']],
            ['needles' => ['列表', 'listing', ' list'], 'paths' => ['listing', 'list', '列表']],
            ['needles' => ['分页', 'pagination'], 'paths' => ['pagination', 'page', '分页']],
            ['needles' => ['后台', 'backend', 'admin'], 'paths' => ['backend', 'admin', '后台']],
            ['needles' => ['界面', '交互', ' ui', 'view'], 'paths' => ['view', 'template', '.phtml', 'ui', '界面']],
            ['needles' => [
                '前端开发', '前端规范', '部件', 'widget', '主题开发', 'theme',
                'section', 'phtml', '模板', 'layout', '布局',
            ], 'paths' => [
                'Theme开发总指南',
                '部件开发指南',
                'frontend-section-weline-code',
                'theme-css-variables-only',
                'widgets/',
                '.phtml',
                '/theme/doc/',
            ]],
            ['needles' => ['统一配置中心', 'systemconfig', 'weline_systemconfig'], 'paths' => ['/weline/systemconfig/', 'systemconfig', 'weline_systemconfig']],
            ['needles' => ['站点', 'website'], 'paths' => ['/weline/websites/', 'website', 'scope']],
            ['needles' => ['主题', '暗色', 'theme', 'dark'], 'paths' => ['/weline/theme/', 'theme', 'dark', '.css']],
            ['needles' => ['mcp'], 'paths' => ['/mcp/', 'mcp']],
        ];
        if ($documentationIntent && !$codeIntent) {
            $groups[] = ['needles' => ['文档', 'documentation', 'readme', ' doc'], 'paths' => ['/doc/', 'readme', '.md']];
        }
        foreach ($groups as $group) {
            foreach ($group['needles'] as $needle) {
                if (str_contains($lowerTask, $needle)) {
                    $signals = array_merge($signals, $group['paths']);
                    break;
                }
            }
        }
        $signals = array_values(array_unique($signals));
        if ($signals === [] && !$codeIntent && !$documentationIntent) {
            return $results;
        }

        $identifierMatches = [];
        preg_match_all(
            '/[A-Za-z][A-Za-z0-9_]*(?:::[A-Za-z][A-Za-z0-9_]*)?/',
            $task,
            $identifierMatches,
        );
        $exactIdentifiers = array_values(array_unique(array_filter(
            array_map(
                static fn (string $identifier): string => mb_strtolower($identifier, 'UTF-8'),
                $identifierMatches[0] ?? [],
            ),
            static fn (string $identifier): bool =>
                str_contains($identifier, '_') || str_contains($identifier, '::'),
        )));

        $ranked = [];
        foreach ($results as $position => $result) {
            $path = mb_strtolower((string) ($result['relative_path'] ?? ''), 'UTF-8');
            $kind = mb_strtolower((string) ($result['file_kind'] ?? ''), 'UTF-8');
            $symbol = mb_strtolower((string) ($result['symbol_name'] ?? ''), 'UTF-8');
            $title = mb_strtolower((string) ($result['title'] ?? ''), 'UTF-8');
            $haystack = $path . ' ' . $symbol . ' ' . $title;
            $boost = 0;
            $identifierMatched = false;
            foreach ($exactIdentifiers as $identifier) {
                if (str_contains($haystack, $identifier)) {
                    $identifierMatched = true;
                    $boost += 6;
                }
            }
            if ($exactIdentifiers !== []
                && !$identifierMatched
                && preg_match('/::(?:edit|index|save|list|view)$/', $symbol) === 1) {
                $boost -= 3;
            }
            foreach ($signals as $signal) {
                if (str_contains($path, mb_strtolower($signal, 'UTF-8'))) {
                    ++$boost;
                }
            }
            if ($codeIntent && in_array($kind, ['code', 'config', 'rule'], true)) {
                $boost += 2;
            } elseif ($documentationIntent && $kind === 'doc') {
                $boost += 2;
            }
            $ranked[] = ['item' => $result, 'boost' => $boost, 'position' => $position];
        }
        usort($ranked, static fn (array $left, array $right): int =>
            [$right['boost'], $left['position']] <=> [$left['boost'], $right['position']]
        );
        return $this->diversifyEditResults(
            array_map(static fn (array $entry): array => $entry['item'], $ranked),
            $task,
        );
    }


    /**
     * Build an architecture-first manifest from semantic results, explicit
     * paths, materialized regions, and concrete upstream graph paths.
     *
     * @param list<array<string,mixed>> $results
     * @param list<array<string,mixed>> $regions
     * @param list<string> $requestedPaths
     * @param list<array<string,mixed>> $impacts
     * @param list<string> $expectedRoles
     * @param list<string> $scopeRoots
     * @param list<string> $forbiddenPatterns
     * @return array<string,mixed>
     */
    private function buildCandidateManifest(
        string $task,
        array $results,
        array $regions,
        array $requestedPaths,
        array $impacts,
        array $expectedRoles,
        array $scopeRoots,
        array $forbiddenPatterns,
    ): array {
        $regionCounts = [];
        foreach ($regions as $region) {
            $path = trim((string) ($region['path'] ?? ''));
            if ($path !== '') {
                $regionCounts[$path] = ($regionCounts[$path] ?? 0) + 1;
            }
        }
        $requestedLookup = array_fill_keys($requestedPaths, true);
        $candidates = [];
        $addCandidate = function (
            string $path,
            string $kind,
            string $symbol,
            float $score,
            array $evidence,
            string $source,
        ) use (
            &$candidates,
            $regionCounts,
            $requestedLookup,
            $scopeRoots,
            $forbiddenPatterns,
        ): void {
            $path = trim($path);
            if ($path === ''
                || !$this->pathAllowedForBundleScope(
                    $path,
                    $scopeRoots,
                    $forbiddenPatterns,
                )) {
                return;
            }
            $roles = $this->contextRolesForPath($path, $kind, $symbol);
            $cleanEvidence = [];
            foreach ($evidence as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $cleanEvidence[] = Text::truncate($item, 160);
                }
            }
            if (!isset($candidates[$path])) {
                $candidates[$path] = [
                    'path' => $path,
                    'score' => round($score, 6),
                    'roles' => $roles,
                    'evidence' => array_slice(array_values(array_unique($cleanEvidence)), 0, 4),
                    'sources' => [$source],
                    'requested' => isset($requestedLookup[$path]),
                    'region_count' => (int) ($regionCounts[$path] ?? 0),
                    'materialized' => isset($regionCounts[$path]),
                ];

                return;
            }
            $candidate = $candidates[$path];
            $candidate['score'] = round(max((float) ($candidate['score'] ?? 0), $score), 6);
            $candidate['roles'] = array_values(array_unique(array_merge(
                is_array($candidate['roles'] ?? null) ? $candidate['roles'] : [],
                $roles,
            )));
            $candidate['evidence'] = array_slice(array_values(array_unique(array_merge(
                is_array($candidate['evidence'] ?? null) ? $candidate['evidence'] : [],
                $cleanEvidence,
            ))), 0, 4);
            $candidate['sources'] = array_values(array_unique(array_merge(
                is_array($candidate['sources'] ?? null) ? $candidate['sources'] : [],
                [$source],
            )));
            $candidate['requested'] = (bool) ($candidate['requested'] ?? false)
                || isset($requestedLookup[$path]);
            $candidates[$path] = $candidate;
        };

        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $addCandidate(
                (string) ($result['relative_path'] ?? ''),
                (string) ($result['file_kind'] ?? ''),
                (string) ($result['symbol_name'] ?? ''),
                (float) ($result['score'] ?? $result['rrf_score'] ?? $result['combined_score'] ?? 0),
                [
                    $result['symbol_name'] ?? '',
                    $result['title'] ?? '',
                    $result['retrieval_source'] ?? '',
                ],
                'semantic_index',
            );
        }
        foreach ($requestedPaths as $path) {
            $addCandidate($path, '', '', 1.0, ['explicit path'], 'explicit_path');
        }
        foreach ($impacts as $impact) {
            if (!is_array($impact)) {
                continue;
            }
            $impactSymbol = trim((string) ($impact['symbol'] ?? ''));
            $symbolParts = explode('::', str_replace('\\', '::', $impactSymbol));
            $symbolBase = mb_strtolower((string) end($symbolParts), 'UTF-8');
            if (($impact['origin'] ?? '') !== 'requested_symbol'
                && in_array($symbolBase, [
                    'config',
                    'edit',
                    'get',
                    'index',
                    'list',
                    'provider',
                    'query',
                    'save',
                    'scope',
                    'set',
                    'view',
                ], true)) {
                continue;
            }
            foreach (array_slice(
                is_array($impact['upstream_files'] ?? null) ? $impact['upstream_files'] : [],
                0,
                24,
            ) as $path) {
                if (count($candidates) >= 120 && !isset($candidates[(string) $path])) {
                    continue;
                }
                $addCandidate(
                    (string) $path,
                    'code',
                    '',
                    0.0,
                    ['upstream of ' . $impactSymbol],
                    'code_graph_upstream',
                );
            }
        }

        $allCandidates = array_values($candidates);
        $coveredRoles = [];
        $candidateRoles = [];
        $unmaterializedCandidates = [];
        foreach ($allCandidates as $candidate) {
            $materialized = (bool) ($candidate['materialized'] ?? false);
            foreach (is_array($candidate['roles'] ?? null) ? $candidate['roles'] : [] as $role) {
                if ($materialized) {
                    $coveredRoles[] = $role;
                }
                if (count($candidateRoles[$role] ?? []) < 50) {
                    $candidateRoles[$role][] = $candidate['path'];
                }
            }
            if (!$materialized) {
                $unmaterializedCandidates[] = $candidate;
            }
        }
        $coveredRoles = array_values(array_unique($coveredRoles));
        $missingRoles = array_values(array_diff($expectedRoles, $coveredRoles));
        $remainingPaths = [];
        foreach ($unmaterializedCandidates as $candidate) {
            if ((bool) ($candidate['requested'] ?? false)) {
                $remainingPaths[] = (string) $candidate['path'];
            }
        }
        foreach ($missingRoles as $missingRole) {
            foreach ($unmaterializedCandidates as $candidate) {
                if (!in_array($missingRole, (array) ($candidate['roles'] ?? []), true)) {
                    continue;
                }
                $remainingPaths[] = (string) $candidate['path'];
                break;
            }
        }
        $remainingPaths = Text::uniqueStrings($remainingPaths);
        $nextSearchGoals = [];
        if ($missingRoles !== []) {
            $nextSearchGoals[] = 'In one discovery batch, find all files for missing roles ['
                . implode(', ', $missingRoles)
                . '] using: '
                . $this->contextRoleSearchTerms($missingRoles)
                . '. Original task: '
                . Text::truncate($task, 240);
        }
        $needsContinuation = $remainingPaths !== [] || $missingRoles !== [];

        return [
            'architecture' => [
                'phase' => $requestedPaths === [] ? 'discovery' : 'materialization',
                'layer_order' => [
                    'architecture_and_rules',
                    'semantic_and_symbol_graph_candidates',
                    'batched_exact_regions',
                    'impact_and_edit_plan',
                ],
                'expected_roles' => $expectedRoles,
                'covered_roles' => $coveredRoles,
                'missing_roles' => $missingRoles,
            ],
            'candidate_paths' => array_slice($allCandidates, 0, 80),
            'candidate_roles' => $candidateRoles,
            'coverage' => [
                'status' => $needsContinuation ? 'partial' : 'likely_complete',
                'candidate_file_count' => count($allCandidates),
                'returned_candidate_count' => min(80, count($allCandidates)),
                'materialized_file_count' => count($regionCounts),
                'requested_file_count' => count($requestedPaths),
                'unmaterialized_candidate_count' => count($remainingPaths),
                'expected_role_count' => count($expectedRoles),
                'covered_role_count' => count(array_intersect($expectedRoles, $coveredRoles)),
                'missing_roles' => $missingRoles,
            ],
            'continuation' => [
                'needed' => $needsContinuation,
                'reason' => $needsContinuation
                    ? 'Known candidates or expected architecture roles remain outside the current exact regions.'
                    : 'Candidate roles and exact regions cover the inferred architecture.',
                'next_path_batches' => array_chunk(
                    array_slice($remainingPaths, 0, 100),
                    self::CONTEXT_BATCH_PATH_LIMIT,
                ),
                'next_search_goals' => $nextSearchGoals,
                'single_file_round_trips' => false,
                'batch_policy' => 'Submit each non-empty path batch together. Combine all search goals into one discovery call; never query one file or one role at a time.',
            ],
        ];
    }

    /**
     * @param list<string> $paths
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function searchEditPathBatches(string $query, array $paths, array $options): array
    {
        $pathBatches = $paths === [] ? [[]] : array_chunk($paths, 50);
        $batchCount = count($pathBatches);
        $requestedLimit = max(1, min(96, (int) ($options['limit'] ?? 48)));
        $requestedTokenBudget = max(256, min(
            24_000,
            (int) ($options['token_budget'] ?? 8_000),
        ));
        $merged = null;
        $results = [];
        $queryIds = [];
        foreach ($pathBatches as $pathBatch) {
            $batchOptions = $options;
            $batchOptions['paths'] = $pathBatch;
            $batchOptions['limit'] = min(96, max(
                count($pathBatch),
                (int) ceil($requestedLimit / max(1, $batchCount)),
            ));
            $batchOptions['token_budget'] = max(
                256,
                (int) floor($requestedTokenBudget / max(1, $batchCount)),
            );
            $batchResult = $this->search($query, $batchOptions);
            $merged ??= $batchResult;
            $queryId = trim((string) ($batchResult['query_id'] ?? ''));
            if ($queryId !== '') {
                $queryIds[] = $queryId;
            }
            foreach ((array) ($batchResult['results'] ?? []) as $result) {
                if (is_array($result)) {
                    $results[] = $result;
                }
            }
        }
        $merged ??= [];
        $merged['results'] = $results;
        $merged['path_batching'] = [
            'schema_version' => 'context-search-batches.v1',
            'batch_count' => $batchCount,
            'max_paths_per_batch' => 50,
            'input_path_count' => count($paths),
            'query_ids' => $queryIds,
        ];

        return $merged;
    }

    /** @return list<string> */
    private function normalizeEditBundlePaths(mixed $paths): array
    {
        $normalized = [];
        foreach (array_chunk($this->stringList($paths), 50) as $pathBatch) {
            $normalized = array_merge(
                $normalized,
                $this->searchPaths(['paths' => $pathBatch]),
            );
        }

        return Text::uniqueStrings($normalized);
    }

    /** @param array<string,mixed> $options
     *  @return list<string>
     */
    public function expectedContextRolesForTask(string $task, array $options = []): array
    {
        return $this->expectedContextRoles(
            $task,
            (bool) ($options['include_mcp_infrastructure'] ?? true),
            (bool) ($options['include_validation_role'] ?? true),
            (bool) ($options['include_docs'] ?? true),
        );
    }

    /** @return list<string> */
    private function expectedContextRoles(
        string $task,
        bool $includeMcpInfrastructure = true,
        bool $includeValidationRole = true,
        bool $includeDocumentationRole = true,
    ): array {
        $lowerTask = mb_strtolower($task, 'UTF-8');
        $roles = [];
        $containsAny = static function (string $value, array $needles): bool {
            foreach ($needles as $needle) {
                if (str_contains($value, $needle)) {
                    return true;
                }
            }

            return false;
        };
        $add = static function (array $items) use (&$roles, $includeDocumentationRole): void {
            if (!$includeDocumentationRole) {
                $items = array_values(array_diff($items, ['documentation']));
            }
            $roles = array_values(array_unique(array_merge($roles, $items)));
        };

        if ($includeMcpInfrastructure
            && $containsAny($lowerTask, ['mcp', 'get_edit_bundle', '项目智能', '代码图'])) {
            $add(['tool_contract', 'retrieval', 'service', 'plugin_manifest', 'documentation']);
        }
        if ($containsAny($lowerTask, [
            '界面', '主题', '暗色', '亮色', '白色', '交互', '选择', '下拉', '按钮',
            '图片', '缩略图', '列表', ' ui', 'theme', 'dark', 'light', 'select',
            'dropdown', 'image', 'thumbnail', 'listing',
            '前端开发', '前端规范', '部件', 'widget', 'phtml', 'section', '模板', '布局', 'layout',
        ])) {
            $add(['entrypoint', 'view_template', 'service', 'documentation']);
        }
        if ($containsAny($lowerTask, [
            '配置', 'scope', '站点', 'website', 'locale', 'provider',
        ])) {
            $add(['entrypoint', 'view_template', 'provider_query', 'configuration', 'service']);
        }
        if ($containsAny($lowerTask, ['api', '接口', 'controller', '控制器', 'route', '路由'])) {
            $add(['entrypoint', 'service', 'contract']);
        }
        if ($containsAny($lowerTask, ['数据库', '模型', 'model', 'schema', 'orm'])) {
            $add(['model', 'service', 'provider_query']);
        }
        if ($containsAny($lowerTask, ['文档', 'documentation', 'readme', '.md'])) {
            $add(['documentation']);
        }
        if ($containsAny($lowerTask, ['测试', 'test', 'regression'])
            || ($includeValidationRole
                && $containsAny($lowerTask, ['验证', '验收', 'validation']))) {
            $add(['test']);
        }
        if ($roles === []) {
            $roles[] = 'implementation';
        }

        return $roles;
    }

    /** @param list<string> $symbols
     *  @return list<string>
     */
    private function requiredSymbolTargets(array $symbols): array
    {
        return array_values(array_filter(
            $symbols,
            static fn (string $symbol): bool =>
                str_contains($symbol, '::')
                || str_contains($symbol, '\\')
                || preg_match('~^[A-Z][A-Za-z0-9_]*$~D', $symbol) === 1,
        ));
    }

    /** @param list<string> $scopeRoots
     *  @param list<string> $forbiddenPatterns
     */
    private function pathAllowedForBundleScope(
        string $path,
        array $scopeRoots,
        array $forbiddenPatterns,
    ): bool {
        try {
            $path = $this->index->normalizeRelativePath($path);
        } catch (Throwable) {
            return false;
        }
        if ($path === '') {
            return false;
        }
        if ($scopeRoots !== []) {
            $insideScope = false;
            foreach ($scopeRoots as $scopeRoot) {
                $scopeRoot = trim(str_replace('\\', '/', $scopeRoot), '/');
                if ($scopeRoot !== ''
                    && ($path === $scopeRoot || str_starts_with($path, $scopeRoot . '/'))) {
                    $insideScope = true;
                    break;
                }
            }
            if (!$insideScope) {
                return false;
            }
        }
        foreach ($forbiddenPatterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', $pattern), '/');
            $literal = preg_match('~^([^*?\[]+)/\*\*$~D', $pattern, $matches) === 1
                ? rtrim($matches[1], '/')
                : (strpbrk($pattern, '*?[') === false ? $pattern : '');
            if ($pattern !== ''
                && (Text::globMatches($pattern, $path)
                    || ($literal !== ''
                        && ($path === $literal || str_starts_with($path, $literal . '/'))))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $roles */
    private function contextRoleSearchTerms(array $roles): string
    {
        $terms = [
            'entrypoint' => 'controller route action command console entrypoint',
            'view_template' => 'view template phtml layout block taglib css javascript',
            'provider_query' => 'provider query resolver registry options datasource',
            'service' => 'service handler manager coordinator',
            'contract' => 'interface contract api dto',
            'configuration' => 'config configuration etc xml env module manifest',
            'model' => 'model entity repository schema orm',
            'documentation' => 'documentation doc readme markdown',
            'tool_contract' => 'tool schema definitions inputSchema get_edit_bundle MCP',
            'retrieval' => 'retriever search rank candidate index graph',
            'plugin_manifest' => 'plugin manifest plugin.json marketplace MCP hooks installer install',
            'test' => 'test tests spec fixture acceptance validation regression',
            'implementation' => 'implementation class method function',
        ];
        $selected = [];
        foreach ($roles as $role) {
            if (isset($terms[$role])) {
                $selected[] = $terms[$role];
            }
        }

        return implode(' ', array_values(array_unique($selected)));
    }

    /** @return list<string> */
    private function contextRolesForPath(string $path, string $kind = '', string $symbol = ''): array
    {
        $value = mb_strtolower($path . ' ' . $symbol, 'UTF-8');
        $kind = mb_strtolower($kind, 'UTF-8');
        $roles = [];
        if ($kind === 'doc' || str_ends_with($value, '.md') || str_contains($value, '/doc/')) {
            $roles[] = 'documentation';
        }
        if (str_contains($value, '.codex-plugin/plugin.json')
            || str_ends_with($value, '.mcp.json')
            || str_ends_with($value, '/plugin.json')
            || str_contains($value, 'marketplace.json')
            || str_contains($value, '/plugins/')
            || str_contains($value, '/scripts/install.')
            || str_starts_with($value, 'scripts/install.')
            || str_ends_with($value, '/install.sh')
            || str_ends_with($value, '/install.ps1')) {
            $roles[] = 'plugin_manifest';
        }
        if (str_contains($value, '/tests/')
            || str_contains($value, '/test/')
            || str_starts_with($value, 'tests/')
            || str_starts_with($value, 'test/')
            || str_contains($value, '.spec.')
            || str_contains($value, '.test.')) {
            $roles[] = 'test';
        }
        if (str_contains($value, 'toolservice')
            || str_contains($value, 'tooldefinitions')
            || str_contains($value, 'inputschema')) {
            $roles[] = 'tool_contract';
        }
        if (str_contains($value, 'retriever')
            || str_contains($value, '/search/')
            || str_contains($value, '/index/')) {
            $roles[] = 'retrieval';
        }
        if (str_contains($value, '/bin/')
            || str_starts_with($value, 'bin/')
            || str_contains($value, '/controller/')
            || str_contains($value, '/console/')
            || str_contains($value, '/route')
            || str_contains($value, 'mcpserver')
            || str_ends_with($value, '/cli.php')) {
            $roles[] = 'entrypoint';
        }
        if (str_contains($value, '/ui/')
            || str_starts_with($value, 'ui/')
            || str_contains($value, '/view/')
            || str_contains($value, '/templates/')
            || str_contains($value, '/layout/')
            || str_contains($value, '/block/')
            || str_contains($value, '/taglib/')
            || str_contains($value, '.html')
            || str_contains($value, '.phtml')
            || str_contains($value, '.css')
            || str_contains($value, '.js')) {
            $roles[] = 'view_template';
        }
        if (str_contains($value, 'provider')
            || str_contains($value, '/query/')
            || str_contains($value, 'resolver')
            || str_contains($value, 'registry')
            || str_contains($value, 'options')
            || str_contains($value, 'datasource')) {
            $roles[] = 'provider_query';
        }
        if (str_contains($value, '/service/')
            || str_contains($value, 'service::')
            || str_ends_with($value, 'service.php')
            || str_contains($value, 'handler')
            || str_contains($value, 'manager')) {
            $roles[] = 'service';
        }
        if (str_contains($value, '/interface/')
            || str_contains($value, '/api/')
            || str_contains($value, 'interface')
            || str_contains($value, 'contract')
            || str_contains($value, '/dto/')) {
            $roles[] = 'contract';
        }
        if ($kind === 'config'
            || str_contains($value, '/etc/')
            || str_contains($value, '/config/')
            || str_contains($value, 'composer.json')
            || str_contains($value, 'module.php')
            || str_contains($value, '.xml')
            || str_contains($value, '.env')) {
            $roles[] = 'configuration';
        }
        if (str_contains($value, '/model/')
            || str_contains($value, '/entity/')
            || str_contains($value, '/repository/')) {
            $roles[] = 'model';
        }
        if (str_contains($value, '/test/') || str_contains($value, '/tests/')) {
            $roles[] = 'test';
        }
        if ($kind === 'rule') {
            $roles[] = 'rule';
        }
        if ($roles === []) {
            $roles[] = 'implementation';
        }

        return array_values(array_unique($roles));
    }

    /**
     * Keep semantic ranking, but guarantee that the leading region set samples
     * every inferred architecture role before returning the remaining score order.
     *
     * @param list<array<string,mixed>> $results
     * @return list<array<string,mixed>>
     */
    private function diversifyEditResults(array $results, string $task): array
    {
        if (count($results) < 2) {
            return $results;
        }
        $roleOrder = array_values(array_unique(array_merge(
            $this->expectedContextRoles($task),
            [
                'entrypoint',
                'view_template',
                'provider_query',
                'service',
                'contract',
                'configuration',
                'model',
                'tool_contract',
                'retrieval',
                'plugin_manifest',
                'documentation',
                'implementation',
            ],
        )));
        $selected = [];
        $ordered = [];
        foreach ($roleOrder as $role) {
            foreach ($results as $index => $result) {
                if (isset($selected[$index])) {
                    continue;
                }
                $roles = $this->contextRolesForPath(
                    (string) ($result['relative_path'] ?? ''),
                    (string) ($result['file_kind'] ?? ''),
                    (string) ($result['symbol_name'] ?? ''),
                );
                if (!in_array($role, $roles, true)) {
                    continue;
                }
                $ordered[] = $result;
                $selected[$index] = true;
                break;
            }
        }
        foreach ($results as $index => $result) {
            if (!isset($selected[$index])) {
                $ordered[] = $result;
            }
        }

        return $ordered;
    }

    private function expandRetrievalQuery(string $task): string
    {
        $expanded = [$task];
        $translations = [
            '搜索' => 'search query keyword',
            '筛选' => 'filter',
            '过滤' => 'filter',
            '列表' => 'list listing grid',
            '分页' => 'page pagination pageSize',
            '后台' => 'backend admin',
            '管理' => 'manager management',
            '界面' => 'view template phtml ui',
            '主题' => 'theme dark light css template',
            '暗色' => 'dark theme css color',
            '适配' => 'theme compatibility css template',
            '交互' => 'interaction javascript button',
            '选择' => 'select option provider query',
            '站点' => 'website scope provider selector',
            '模块' => 'module provider selector config',
            '按钮' => 'button action',
            '路由' => 'route controller',
            '控制器' => 'controller',
            '文档' => 'doc readme markdown',
            '索引' => 'index search retrieval',
            '编辑' => 'edit replace update',
            '修改' => 'change update replacement patch',
            '会话' => 'session conversation',
            '学习' => 'learning skill knowledge',
            '技能' => 'skill guidance',
            '测试' => 'test testing phpunit pest vitest playwright browser e2e',
            '支付' => 'payment provider checkout refund payable',
            '统一配置中心' => 'Weline_SystemConfig SystemConfig system configuration center',
            '配置' => 'config configuration system scope',
            '队列' => 'queue job worker consumer',
            '部署' => 'deploy deployment release',
            '发布' => 'release deploy version tag',
        ];
        foreach ($translations as $needle => $terms) {
            if (str_contains($task, $needle)) {
                $expanded[] = $terms;
            }
        }

        return implode(' ', array_values(array_unique($expanded)));
    }

    /** @param array<string,mixed> $row
     *  @param list<mixed> $triggers
     */
    private function skillLexicalBoost(string $task, array $row, array $triggers): float
    {
        $task = mb_strtolower($task, 'UTF-8');
        if ($task === '') {
            return 0.0;
        }
        $name = mb_strtolower(trim((string) ($row['name'] ?? '')), 'UTF-8');
        $description = mb_strtolower((string) ($row['description'] ?? ''), 'UTF-8');
        $path = mb_strtolower((string) ($row['path'] ?? ''), 'UTF-8');
        $boost = $name !== '' && str_contains($task, $name) ? 0.8 : 0.0;
        foreach ($triggers as $trigger) {
            if (!is_string($trigger)) {
                continue;
            }
            $trigger = mb_strtolower(trim($trigger), 'UTF-8');
            if ($trigger !== '' && (str_contains($task, $trigger) || str_contains($trigger, $task))) {
                $boost += 0.45;
            }
        }
        preg_match_all('/[a-z0-9][a-z0-9_.:-]{2,}/i', $task, $taskMatches);
        preg_match_all('/[a-z0-9][a-z0-9_.:-]{2,}/i', $name . ' ' . $description . ' ' . $path, $skillMatches);
        $stopWords = array_fill_keys([
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'use', 'when', 'how', 'weline',
            'guide', 'skill', 'development', 'module', 'current', 'asks', 'user',
        ], true);
        $taskTokens = array_values(array_filter(
            array_unique(array_map('strtolower', $taskMatches[0] ?? [])),
            static fn (string $token): bool => !isset($stopWords[$token]),
        ));
        $skillTokens = array_fill_keys(array_map('strtolower', $skillMatches[0] ?? []), true);
        $overlap = 0;
        foreach ($taskTokens as $token) {
            if (isset($skillTokens[$token])) {
                ++$overlap;
            }
        }

        return $boost + min(0.72, $overlap * 0.12);
    }

    /**
     * Resolve definitions once, load the union upstream graph in at most three
     * relation queries, then derive each target's conservative impact in PHP.
     *
     * @param list<string> $targets
     * @return array{
     *   inspections:array<string,array{symbols:list<array<string,mixed>>,relations:list<array<string,mixed>>,impact:array<string,mixed>}>,
     *   meta:array<string,int>
     * }
     */
    private function inspectSymbolsBatch(array $targets, string $contextQuery = ''): array
    {
        $targets = array_values(array_unique(array_filter(
            array_map('trim', $targets),
            static fn (string $target): bool => $target !== '',
        )));
        if ($targets === []) {
            return [
                'inspections' => [],
                'meta' => [
                    'sql_round_trips' => 0,
                    'target_count' => 0,
                    'definition_count' => 0,
                    'context_definition_count' => 0,
                ],
            ];
        }
        if (count($targets) > self::SYMBOL_QUERY_BATCH_SIZE) {
            $combined = [
                'inspections' => [],
                'meta' => [
                    'sql_round_trips' => 0,
                    'target_count' => count($targets),
                    'definition_count' => 0,
                    'context_definition_count' => 0,
                    'relation_count' => 0,
                    'query_batch_count' => 0,
                ],
            ];
            foreach (array_chunk($targets, self::SYMBOL_QUERY_BATCH_SIZE) as $targetBatch) {
                $partial = $this->inspectSymbolsBatch($targetBatch, $contextQuery);
                $combined['inspections'] = array_replace(
                    $combined['inspections'],
                    (array) ($partial['inspections'] ?? []),
                );
                foreach ([
                    'sql_round_trips',
                    'definition_count',
                    'context_definition_count',
                    'relation_count',
                ] as $metric) {
                    $combined['meta'][$metric] += (int) ($partial['meta'][$metric] ?? 0);
                }
                ++$combined['meta']['query_batch_count'];
            }

            return $combined;
        }

        $exactPlaceholders = [];
        $foldedPlaceholders = [];
        $suffixConditions = [];
        $params = [];
        foreach ($targets as $index => $target) {
            $exactPlaceholders[] = ':batch_symbol_exact_' . $index;
            $foldedPlaceholders[] = ':batch_symbol_folded_' . $index;
            $suffixKey = 'batch_symbol_suffix_' . $index;
            $suffixConditions[] = "lower(s.fq_name) LIKE :{$suffixKey} ESCAPE '\\'";
            $params['batch_symbol_exact_' . $index] = $target;
            $foldedTarget = mb_strtolower(ltrim($target, '\\'), 'UTF-8');
            $params['batch_symbol_folded_' . $index] = $foldedTarget;
            $params[$suffixKey] = '%\\' . $this->escapeLike($foldedTarget);
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT s.*, f.path, f.content_hash AS file_hash, f.indexed_at, c.content AS chunk_content
               FROM symbols s JOIN indexed_files f ON f.id = s.file_id
          LEFT JOIN chunks c ON c.chunk_id = s.chunk_id
              WHERE s.symbol_uid IN (' . implode(',', $exactPlaceholders) . ')
                 OR lower(s.name) IN (' . implode(',', $foldedPlaceholders) . ')
                 OR lower(s.fq_name) IN (' . implode(',', $foldedPlaceholders) . ')
                 OR (' . implode(' OR ', $suffixConditions) . ')
           ORDER BY f.path, s.start_line LIMIT ' . (count($targets) * 100)
        );
        $statement->execute($params);
        $allRows = [];
        $rowsByTarget = array_fill_keys($targets, []);
        foreach ($statement->fetchAll() as $row) {
            $uid = (string) $row['symbol_uid'];
            $allRows[$uid] = $row;
            foreach ($targets as $target) {
                if ($this->definitionMatchesTarget($row, $target) && count($rowsByTarget[$target]) < 32) {
                    $rowsByTarget[$target][$uid] = $row;
                }
            }
        }

        $classRows = array_filter(
            $allRows,
            static fn (array $row): bool => in_array(
                (string) ($row['kind'] ?? ''),
                ['class', 'interface', 'trait', 'enum'],
                true,
            ),
        );
        $membersByParent = [];
        $memberRoundTrips = 0;
        if ($contextQuery !== '' && $classRows !== []) {
            $parentPlaceholders = [];
            $memberParams = [];
            foreach (array_keys($classRows) as $index => $parentUid) {
                $key = 'batch_parent_uid_' . $index;
                $parentPlaceholders[] = ':' . $key;
                $memberParams[$key] = $parentUid;
            }
            $memberStatement = $this->index->pdo()->prepare(
                'SELECT s.*, f.path, f.content_hash AS file_hash, f.indexed_at, c.content AS chunk_content
                   FROM symbols s JOIN indexed_files f ON f.id = s.file_id
              LEFT JOIN chunks c ON c.chunk_id = s.chunk_id
                  WHERE s.parent_uid IN (' . implode(',', $parentPlaceholders) . ')
                    AND s.kind = \'method\'
               ORDER BY s.parent_uid, s.start_line
                  LIMIT ' . max(1, count($classRows) * 24)
            );
            $memberStatement->execute($memberParams);
            $memberRoundTrips = 1;
            foreach ($memberStatement->fetchAll() as $memberRow) {
                $membersByParent[(string) ($memberRow['parent_uid'] ?? '')][] = $memberRow;
            }
        }

        [$graph, $graphRoundTrips] = $this->batchUpstreamGraph(array_values($allRows));
        $inspections = [];
        $contextDefinitionCount = 0;
        foreach ($targets as $target) {
            $definitionRows = array_values($rowsByTarget[$target]);
            $definitionUids = [];
            $contextRows = [];
            foreach ($definitionRows as $definitionRow) {
                $uid = (string) $definitionRow['symbol_uid'];
                $definitionUids[$uid] = true;
                $contextRows[$uid] = $definitionRow;
                if (!in_array(
                    (string) ($definitionRow['kind'] ?? ''),
                    ['class', 'interface', 'trait', 'enum'],
                    true,
                )) {
                    continue;
                }
                $memberRows = $this->rankClassMemberRows(
                    $membersByParent[$uid] ?? [],
                    $contextQuery,
                );
                foreach (array_slice($memberRows, 0, 4) as $memberRow) {
                    $contextRows[(string) $memberRow['symbol_uid']] = $memberRow;
                }
            }
            $definitions = [];
            foreach ($contextRows as $uid => $row) {
                $definitions[] = $this->formatBatchSymbol(
                    $row,
                    isset($definitionUids[$uid]) ? $target : $contextQuery,
                );
            }
            $contextDefinitionCount += count($definitions);
            $relations = $this->relationsForBatchTarget($definitionRows, $graph);
            $inspections[$target] = [
                'symbols' => $definitions,
                'relations' => $relations,
                'impact' => $this->summarizeBatchImpact($relations),
            ];
        }

        return [
            'inspections' => $inspections,
            'meta' => [
                'sql_round_trips' => 1 + $memberRoundTrips + $graphRoundTrips,
                'target_count' => count($targets),
                'definition_count' => count($allRows),
                'context_definition_count' => $contextDefinitionCount,
                'relation_count' => count($graph),
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function rankClassMemberRows(array $rows, string $query): array
    {
        if ($rows === []) {
            return [];
        }
        $lowerQuery = mb_strtolower($query, 'UTF-8');
        $terms = [];
        if (preg_match_all('/[\p{L}\p{N}_]{3,}/u', $lowerQuery, $matches) !== false) {
            $terms = array_values(array_unique($matches[0] ?? []));
        }
        $ranked = [];
        foreach ($rows as $position => $row) {
            $name = mb_strtolower((string) ($row['name'] ?? ''), 'UTF-8');
            $signature = mb_strtolower((string) ($row['signature'] ?? ''), 'UTF-8');
            $score = $name !== '' && str_contains($lowerQuery, $name) ? 100 : 0;
            foreach ($terms as $term) {
                if ($term === $name) {
                    $score += 20;
                } elseif ($name !== '' && (str_contains($name, $term) || str_contains($term, $name))) {
                    $score += 4;
                } elseif (str_contains($signature, $term)) {
                    ++$score;
                }
            }
            if (preg_match('/\bpublic\b/i', $signature) === 1) {
                $score += 2;
            } elseif (preg_match('/\bprotected\b/i', $signature) === 1) {
                ++$score;
            }
            $ranked[] = [
                'row' => $row,
                'score' => $score,
                'start_line' => (int) ($row['start_line'] ?? PHP_INT_MAX),
                'position' => $position,
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            if ($left['score'] !== $right['score']) {
                return $right['score'] <=> $left['score'];
            }
            if ($left['start_line'] !== $right['start_line']) {
                return $left['start_line'] <=> $right['start_line'];
            }

            return $left['position'] <=> $right['position'];
        });

        return array_values(array_map(
            static fn (array $item): array => $item['row'],
            $ranked,
        ));
    }

    /** @param array<string,mixed> $row */
    private function definitionMatchesTarget(array $row, string $target): bool
    {
        return $this->symbolTargetsMatch((string) $row['symbol_uid'], $target)
            || $this->symbolTargetsMatch((string) $row['name'], $target)
            || $this->symbolTargetsMatch((string) $row['fq_name'], $target);
    }

    private function symbolTargetsMatch(string $left, string $right): bool
    {
        $left = mb_strtolower(ltrim(trim($left), '\\'), 'UTF-8');
        $right = mb_strtolower(ltrim(trim($right), '\\'), 'UTF-8');
        if ($left === '' || $right === '') {
            return false;
        }
        if ($left === $right) {
            return true;
        }
        if (str_contains($right, '\\')) {
            return false;
        }
        $leftShort = strrchr($left, '\\');

        return $leftShort !== false && ltrim($leftShort, '\\') === $right;
    }

    /** @param array<string,mixed> $definition
     *  @return array<string,mixed>
     */
    private function compactRequestedSymbolRegion(
        array $definition,
        string $symbol,
        int $tokenBudget,
    ): array {
        $snippet = $this->snippet((string) ($definition['snippet'] ?? ''), $symbol, $tokenBudget);

        return [
            'path' => $definition['relative_path'] ?? '',
            'file_sha256' => $definition['file_hash'] ?? '',
            'expected_file_sha256' => $definition['file_hash'] ?? '',
            'kind' => 'code',
            'language' => '',
            'module' => '',
            'start_line' => (int) ($definition['start_line'] ?? 1)
                + (int) ($snippet['line_offset'] ?? 0),
            'end_line' => min(
                (int) ($definition['end_line'] ?? $definition['start_line'] ?? 1),
                (int) ($definition['start_line'] ?? 1)
                    + (int) ($snippet['line_offset'] ?? 0)
                    + max(0, substr_count((string) $snippet['text'], "\n")),
            ),
            'symbol_uid' => $definition['symbol_uid'] ?? '',
            'symbol' => $definition['fq_name'] ?? $definition['name'] ?? $symbol,
            'target_ref' => $definition['fq_name'] ?? $definition['name'] ?? $symbol,
            'expected_digest' => $definition['body_hash'] ?? '',
            'reason' => 'requested_symbol',
            'content' => $snippet['text'],
            'token_estimate' => $snippet['tokens'],
        ];
    }

    /** @param array<string,mixed> $row
     *  @return array<string,mixed>
     */
    private function formatBatchSymbol(array $row, string $query): array
    {
        $snippet = $this->snippet((string) ($row['chunk_content'] ?? $row['signature']), $query, 600);

        return [
            'symbol_uid' => $row['symbol_uid'],
            'name' => $row['name'],
            'fq_name' => $row['fq_name'],
            'kind' => $row['kind'],
            'signature' => $row['signature'],
            'relative_path' => $row['path'],
            'absolute_path' => $this->index->absolutePath((string) $row['path']),
            'file_hash' => $row['file_hash'],
            'body_hash' => $row['body_hash'],
            'start_line' => (int) $row['start_line'] + (int) ($snippet['line_offset'] ?? 0),
            'end_line' => min(
                (int) $row['end_line'],
                (int) $row['start_line']
                    + (int) ($snippet['line_offset'] ?? 0)
                    + max(0, substr_count((string) $snippet['text'], "\n")),
            ),
            'snippet' => $snippet['text'],
        ];
    }

    /** @param list<array<string,mixed>> $definitions
     *  @return array{0:list<array<string,mixed>>,1:int}
     */
    private function batchUpstreamGraph(array $definitions): array
    {
        if ($definitions === []) {
            return [[], 0];
        }
        $uids = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['symbol_uid'],
            $definitions,
        )));
        $names = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['fq_name'],
            $definitions,
        )));
        $relations = $this->directUpstreamRelations($uids, $names);
        $roundTrips = 1;
        $relationCap = min(6_000, max(500, count($definitions) * 500));
        $seen = [];
        foreach ($relations as $relation) {
            $seen[$this->relationKey($relation)] = true;
        }
        $visited = array_fill_keys($uids, true);
        $frontier = [];
        foreach ($relations as $relation) {
            $sourceUid = trim((string) ($relation['source_symbol_uid'] ?? ''));
            if ($sourceUid !== '' && !isset($visited[$sourceUid])) {
                $frontier[$sourceUid] = true;
            }
        }
        for ($depth = 2; $depth <= 3 && $frontier !== [] && count($relations) < $relationCap; ++$depth) {
            $targetUids = array_keys($frontier);
            foreach ($targetUids as $uid) {
                $visited[$uid] = true;
            }
            $frontier = [];
            $nextRelations = $this->batchUpstreamRelations($targetUids, $depth, $relationCap);
            ++$roundTrips;
            foreach ($nextRelations as $relation) {
                $sourceUid = trim((string) ($relation['source_symbol_uid'] ?? ''));
                if ($sourceUid === '' || isset($visited[$sourceUid])) {
                    continue;
                }
                $key = $this->relationKey($relation);
                if (!isset($seen[$key])) {
                    $relations[] = $relation;
                    $seen[$key] = true;
                }
                $frontier[$sourceUid] = true;
                if (count($relations) >= $relationCap) {
                    break 2;
                }
            }
        }

        return [$relations, $roundTrips];
    }

    /** @param list<string> $uids
     *  @param list<string> $names
     *  @return list<array<string,mixed>>
     */
    private function directUpstreamRelations(array $uids, array $names): array
    {
        $uidPlaceholders = [];
        $namePlaceholders = [];
        $params = [];
        foreach ($uids as $index => $uid) {
            $uidPlaceholders[] = ':batch_upstream_uid_' . $index;
            $params['batch_upstream_uid_' . $index] = $uid;
        }
        foreach ($names as $index => $name) {
            $namePlaceholders[] = ':batch_upstream_name_' . $index;
            $params['batch_upstream_name_' . $index] = $name;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT r.*, f.path, f.module_vendor, f.module_name,
                    source.fq_name AS source_name, target.fq_name AS resolved_target_name
               FROM relations r JOIN indexed_files f ON f.id = r.file_id
          LEFT JOIN symbols source ON source.symbol_uid = r.source_symbol_uid
          LEFT JOIN symbols target ON target.symbol_uid = r.target_symbol_uid
              WHERE r.target_symbol_uid IN (' . implode(',', $uidPlaceholders) . ')
                 OR r.target_name COLLATE NOCASE IN (' . implode(',', $namePlaceholders) . ')
           ORDER BY r.relation_kind, f.path, r.line LIMIT ' . min(6_000, max(500, count($uids) * 500))
        );
        $statement->execute($params);
        $relations = [];
        foreach ($statement->fetchAll() as $row) {
            $relations[] = [
                'direction' => 'upstream',
                'depth' => 1,
                'kind' => $row['relation_kind'],
                'source_symbol_uid' => $row['source_symbol_uid'],
                'source_name' => $row['source_name'],
                'target_symbol_uid' => $row['target_symbol_uid'],
                'target_name' => $row['resolved_target_name'] ?: $row['target_name'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'module' => trim((string) $row['module_vendor'] . '/' . (string) $row['module_name'], '/'),
                'line' => (int) $row['line'],
                'confidence' => (float) $row['confidence'],
            ];
        }

        return $relations;
    }

    /** @param list<string> $targetUids
     *  @return list<array<string,mixed>>
     */
    private function batchUpstreamRelations(array $targetUids, int $depth, int $limit): array
    {
        if ($targetUids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($targetUids)) as $index => $uid) {
            $placeholders[] = ':batch_depth_uid_' . $index;
            $params['batch_depth_uid_' . $index] = $uid;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT r.*, f.path, f.module_vendor, f.module_name,
                    source.fq_name AS source_name, target.fq_name AS resolved_target_name
               FROM relations r JOIN indexed_files f ON f.id = r.file_id
          LEFT JOIN symbols source ON source.symbol_uid = r.source_symbol_uid
          LEFT JOIN symbols target ON target.symbol_uid = r.target_symbol_uid
              WHERE r.target_symbol_uid IN (' . implode(',', $placeholders) . ')
           ORDER BY r.relation_kind, f.path, r.line LIMIT ' . min(6_000, max(500, $limit))
        );
        $statement->execute($params);
        $relations = [];
        foreach ($statement->fetchAll() as $row) {
            $relations[] = [
                'direction' => 'upstream',
                'depth' => $depth,
                'kind' => $row['relation_kind'],
                'source_symbol_uid' => $row['source_symbol_uid'],
                'source_name' => $row['source_name'],
                'target_symbol_uid' => $row['target_symbol_uid'],
                'target_name' => $row['resolved_target_name'] ?: $row['target_name'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'module' => trim((string) $row['module_vendor'] . '/' . (string) $row['module_name'], '/'),
                'line' => (int) $row['line'],
                'confidence' => (float) $row['confidence'],
            ];
        }

        return $relations;
    }

    /** @param list<array<string,mixed>> $definitions
     *  @param list<array<string,mixed>> $graph
     *  @return list<array<string,mixed>>
     */
    private function relationsForBatchTarget(array $definitions, array $graph): array
    {
        if ($definitions === [] || $graph === []) {
            return [];
        }
        $byUid = [];
        $byName = [];
        foreach ($graph as $relation) {
            $targetUid = trim((string) ($relation['target_symbol_uid'] ?? ''));
            if ($targetUid !== '') {
                $byUid[$targetUid][] = $relation;
            }
            $targetName = mb_strtolower(trim((string) ($relation['target_name'] ?? '')), 'UTF-8');
            if ($targetName !== '') {
                $byName[$targetName][] = $relation;
            }
        }
        $frontier = [];
        $rootNames = [];
        foreach ($definitions as $definition) {
            $uid = (string) $definition['symbol_uid'];
            $frontier[$uid] = true;
            $rootNames[$uid] = mb_strtolower((string) $definition['fq_name'], 'UTF-8');
        }
        $visited = array_fill_keys(array_keys($frontier), true);
        $results = [];
        $seen = [];
        for ($depth = 1; $depth <= 3 && $frontier !== [] && count($results) < 500; ++$depth) {
            $next = [];
            foreach (array_keys($frontier) as $targetUid) {
                $candidates = $byUid[$targetUid] ?? [];
                if ($depth === 1 && isset($rootNames[$targetUid])) {
                    $candidates = array_merge($candidates, $byName[$rootNames[$targetUid]] ?? []);
                }
                foreach ($candidates as $relation) {
                    $sourceUid = trim((string) ($relation['source_symbol_uid'] ?? ''));
                    if ($sourceUid === '' || isset($visited[$sourceUid])) {
                        continue;
                    }
                    $relation['depth'] = $depth;
                    $key = $this->relationKey($relation);
                    if (!isset($seen[$key])) {
                        $results[] = $relation;
                        $seen[$key] = true;
                    }
                    $next[$sourceUid] = true;
                }
            }
            foreach (array_keys($next) as $uid) {
                $visited[$uid] = true;
            }
            $frontier = $next;
        }

        return $results;
    }

    /** @param list<array<string,mixed>> $relations
     *  @return array<string,mixed>
     */
    private function summarizeBatchImpact(array $relations): array
    {
        $impactFiles = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) ($relation['relative_path'] ?? ''),
            $relations,
        ))));
        $impactSymbols = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) (
                $relation['source_symbol_uid'] ?? $relation['source_name'] ?? ''
            ),
            $relations,
        ))));
        $impactModules = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) ($relation['module'] ?? ''),
            $relations,
        ))));
        $depthCounts = [];
        foreach ($relations as $relation) {
            $depth = (string) ((int) ($relation['depth'] ?? 1));
            $depthCounts[$depth] = ($depthCounts[$depth] ?? 0) + 1;
        }
        ksort($depthCounts);
        $fileCount = count($impactFiles);
        $symbolCount = count($impactSymbols);
        $moduleCount = count($impactModules);
        $risk = $symbolCount >= 20 || $fileCount >= 10 || $moduleCount >= 3
            ? 'high'
            : ($symbolCount >= 5 || $fileCount >= 3 || $moduleCount >= 2 ? 'medium' : 'low');

        return [
            'upstream_files' => $impactFiles,
            'upstream_file_count' => $fileCount,
            'upstream_symbol_count' => $symbolCount,
            'module_count' => $moduleCount,
            'modules' => $impactModules,
            'depth_counts' => $depthCounts,
            'max_depth' => $depthCounts === [] ? 0 : max(array_map('intval', array_keys($depthCounts))),
            'risk_level' => $risk,
            'conservative' => true,
        ];
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
        $upstreamRelations = array_values(array_filter(
            $relations,
            static fn (array $relation): bool => ($relation['direction'] ?? '') === 'upstream',
        ));
        $impactFiles = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) ($relation['relative_path'] ?? ''),
            $upstreamRelations,
        ))));
        $impactCount = count($impactFiles);
        $impactSymbols = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) (
                $relation['source_symbol_uid'] ?? $relation['source_name'] ?? ''
            ),
            $upstreamRelations,
        ))));
        $impactModules = array_values(array_filter(array_unique(array_map(
            static fn (array $relation): string => (string) ($relation['module'] ?? ''),
            $upstreamRelations,
        ))));
        $depthCounts = [];
        foreach ($upstreamRelations as $relation) {
            $depth = (string) ((int) ($relation['depth'] ?? 1));
            $depthCounts[$depth] = ($depthCounts[$depth] ?? 0) + 1;
        }
        ksort($depthCounts);
        $impactSymbolCount = count($impactSymbols);
        $moduleCount = count($impactModules);
        $risk = $impactSymbolCount >= 20 || $impactCount >= 10 || $moduleCount >= 3
            ? 'high'
            : ($impactSymbolCount >= 5 || $impactCount >= 3 || $moduleCount >= 2 ? 'medium' : 'low');
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
                'upstream_symbol_count' => $impactSymbolCount,
                'module_count' => $moduleCount,
                'modules' => $impactModules,
                'depth_counts' => $depthCounts,
                'max_depth' => $depthCounts === [] ? 0 : max(array_map('intval', array_keys($depthCounts))),
                'risk_level' => $risk,
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

        $moduleSelectors = $this->stringList($selector['modules'] ?? []);
        $legacyModule = trim((string) ($selector['module'] ?? ''));
        if ($legacyModule !== '') {
            $moduleSelectors[] = $legacyModule;
        }
        $modulePairs = [];
        $moduleKeys = [];
        foreach (Text::uniqueStrings($moduleSelectors) as $moduleSelector) {
            $parts = str_contains($moduleSelector, '/')
                ? explode('/', $moduleSelector, 2)
                : explode('_', $moduleSelector, 2);
            if (count($parts) !== 2
                || preg_match('~^[A-Za-z0-9_.-]+$~D', $parts[0]) !== 1
                || preg_match('~^[A-Za-z0-9_.-]+$~D', $parts[1]) !== 1) {
                continue;
            }
            $modulePairs[] = ['vendor' => $parts[0], 'module' => $parts[1]];
            $moduleKeys[] = mb_strtolower($parts[0] . '/' . $parts[1], 'UTF-8');
        }

        $sql = 'SELECT s.*, f.content_hash AS file_hash, f.indexed_at,
                       (SELECT c.chunk_id FROM chunks c WHERE c.file_id = s.file_id ORDER BY c.start_line LIMIT 1) AS chunk_id
                  FROM skills AS s JOIN indexed_files AS f ON f.id = s.file_id
                 WHERE s.status IN (\'canonical\', \'validated\')';
        $params = [];
        if ($modulePairs !== []) {
            $moduleConditions = ["(s.module_vendor = '' AND s.module_name = '')"];
            foreach ($modulePairs as $index => $modulePair) {
                $vendorParam = 'module_vendor_' . $index;
                $moduleParam = 'module_name_' . $index;
                $moduleConditions[] = sprintf(
                    '(s.module_vendor = :%s COLLATE NOCASE AND s.module_name = :%s COLLATE NOCASE)',
                    $vendorParam,
                    $moduleParam,
                );
                $params[$vendorParam] = $modulePair['vendor'];
                $params[$moduleParam] = $modulePair['module'];
            }
            $sql .= ' AND (' . implode(' OR ', $moduleConditions) . ')';
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

        $expandedTask = $this->expandRetrievalQuery($task);
        $taskVector = $this->vectorizer->vectorize($expandedTask);
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
            $lexicalScore = $this->skillLexicalBoost($expandedTask, $row, is_array($triggers) ? $triggers : []);
            $score += $lexicalScore;
            if ($task !== '' && mb_stripos($text, $task, 0, 'UTF-8') !== false) {
                $score += 0.75;
            }
            $rowModule = trim((string) $row['module_vendor'] . '/' . (string) $row['module_name'], '/');
            $moduleMatch = $rowModule !== ''
                && in_array(mb_strtolower($rowModule, 'UTF-8'), $moduleKeys, true);
            if ($moduleMatch) {
                $score += 0.35;
            } elseif ($modulePairs !== [] && $rowModule === '') {
                $score += 0.05;
            }
            if (str_contains(mb_strtolower((string) $row['description'], 'UTF-8'), 'deprecated compatibility alias')) {
                $score -= 1.0;
            }
            if ($row['status'] === 'canonical') {
                $score += 0.05;
            }
            $skills[] = [
                'skill_id' => $row['skill_id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'status' => $row['status'],
                'triggers' => is_array($triggers) ? $triggers : [],
                'module' => $rowModule,
                'scope' => $rowModule === '' ? 'project' : 'module',
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'file_hash' => $row['file_hash'],
                'score' => round($score, 6),
                'lexical_score' => round($lexicalScore, 6),
                'chunk_id' => $row['chunk_id'],
                'actionable' => true,
                '_file_id' => (int) $row['file_id'],
            ];
        }
        usort($skills, static fn(array $left, array $right): int => $right['score'] <=> $left['score']);

        $deduplicated = [];
        $seenNames = [];
        foreach ($skills as $skill) {
            $key = mb_strtolower(trim((string) $skill['name']), 'UTF-8');
            if ($key === '') {
                $key = (string) $skill['relative_path'];
            }
            if (isset($seenNames[$key])) {
                continue;
            }
            $seenNames[$key] = true;
            $deduplicated[] = $skill;
        }
        $skills = array_slice($deduplicated, 0, $limit);

        $contentBudget = 0;
        $contentEstimate = 0;
        if (!empty($selector['include_content']) && $skills !== []) {
            $contentBudget = max(128, min(4_000, (int) ($selector['token_budget'] ?? 2_000)));
            $fileIds = array_values(array_unique(array_map(
                static fn(array $skill): int => (int) $skill['_file_id'],
                $skills
            )));
            $placeholders = [];
            $contentParams = [];
            foreach ($fileIds as $index => $fileId) {
                $parameter = 'skill_file_' . $index;
                $placeholders[] = ':' . $parameter;
                $contentParams[$parameter] = $fileId;
            }
            $chunks = $this->index->pdo()->prepare(
                'SELECT file_id, content FROM chunks WHERE file_id IN (' . implode(', ', $placeholders) . ')
                  ORDER BY file_id, start_line'
            );
            $chunks->execute($contentParams);
            $contentByFile = [];
            foreach ($chunks->fetchAll() as $chunk) {
                $fileId = (int) $chunk['file_id'];
                $contentByFile[$fileId] = ($contentByFile[$fileId] ?? '') . (string) $chunk['content'];
            }

            $remainingTokens = $contentBudget;
            $skillCount = count($skills);
            foreach ($skills as $index => &$skill) {
                $remainingSkills = max(1, $skillCount - $index);
                $share = max(1, intdiv($remainingTokens, $remainingSkills));
                $raw = (string) ($contentByFile[(int) $skill['_file_id']] ?? '');
                $content = mb_substr($raw, 0, $share * 4, 'UTF-8');
                $estimate = $content === '' ? 0 : max(1, (int) ceil(mb_strlen($content, 'UTF-8') / 4));
                $skill['content'] = $content;
                $skill['token_estimate'] = $estimate;
                $skill['content_truncated'] = mb_strlen($content, 'UTF-8') < mb_strlen($raw, 'UTF-8');
                $contentEstimate += $estimate;
                $remainingTokens = max(0, $remainingTokens - $estimate);
                unset($skill['_file_id']);
            }
            unset($skill);
        } else {
            foreach ($skills as &$skill) {
                unset($skill['_file_id']);
            }
            unset($skill);
        }

        $this->saveStandaloneResults($queryId, array_values(array_filter(array_column($skills, 'chunk_id'))));

        return [
            'query_id' => $queryId,
            'index_db' => $this->index->path(),
            'revision' => $this->index->revision(),
            'freshness' => $this->freshness(),
            'skills' => $skills,
            'content_token_budget' => $contentBudget,
            'content_token_estimate' => $contentEstimate,
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

    /** @param array<string,mixed> $options
     *  @return list<string>
     */
    private function searchPaths(array $options): array
    {
        $paths = $this->stringList($options['paths'] ?? []);
        if (isset($options['path']) && is_string($options['path']) && trim($options['path']) !== '') {
            $paths[] = trim($options['path']);
        }
        $paths = array_values(array_unique(array_map(
            fn (string $path): string => $this->index->normalizeRelativePath($path),
            $paths,
        )));
        if (count($paths) > 50) {
            throw new ToolException('VALIDATION_FAILED', 'Path-scoped search accepts at most 50 indexed paths');
        }

        return $paths;
    }

    /**
     * Materialize a known path scope in one SQLite query and rank only those
     * cached chunks in PHP. This avoids global FTS and sparse postings scans.
     *
     * @param array<string,mixed> $options
     * @param list<string> $requestedPaths
     * @return array{
     *   candidates:list<array{chunk_id:string,raw:float}>,
     *   rows:array<string,array<string,mixed>>,
     *   warnings:list<string>,
     *   cache:array<string,mixed>
     * }
     */
    private function pathScopedBatch(string $query, array $options, int $candidateLimit, array $requestedPaths): array
    {
        [$filter, $params] = $this->filterSql($options, 'f', 'path_scope');
        $materializeLimit = min(6_000, max(500, $candidateLimit * 20));
        $statement = $this->index->pdo()->prepare(
            'SELECT c.*, f.path, f.kind AS file_kind, f.language, f.module_vendor, f.module_name,
                    f.content_hash AS file_hash, f.revision AS file_revision, f.indexed_at,
                    COALESCE(s.fq_name, s.name, \'\') AS symbol_name,
                    COALESCE(s.body_hash, \'\') AS symbol_body_hash
               FROM chunks c JOIN indexed_files f ON f.id = c.file_id
          LEFT JOIN symbols s ON s.symbol_uid = c.symbol_uid
              WHERE 1=1' . $filter . '
           ORDER BY f.path, c.start_line, c.rowid LIMIT ' . $materializeLimit
        );
        $statement->execute($params);
        $rows = [];
        $matchedPaths = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[(string) $row['chunk_id']] = $row;
            $matchedPaths[(string) $row['path']] = true;
        }

        $queryVector = $this->vectorizer->vectorize($query);
        $terms = $this->rankingTerms($query);
        $candidates = [];
        foreach ($rows as $chunkId => $row) {
            $text = implode("\n", [
                (string) $row['path'],
                (string) $row['title'],
                (string) $row['symbol_name'],
                (string) $row['content'],
            ]);
            $lower = mb_strtolower($text, 'UTF-8');
            $overlap = 0;
            $titleBoost = 0.0;
            $titleAndPath = mb_strtolower(
                (string) $row['path'] . "\n" . (string) $row['title'] . "\n" . (string) $row['symbol_name'],
                'UTF-8'
            );
            foreach ($terms as $term) {
                if (!str_contains($lower, $term)) {
                    continue;
                }
                ++$overlap;
                if (str_contains($titleAndPath, $term)) {
                    $titleBoost += 0.35;
                }
            }
            $dot = $overlap === 0 || $queryVector === []
                ? 0.0
                : max(0.0, $this->vectorizer->dot($queryVector, $this->vectorizer->vectorize($text)));
            $exactBoost = mb_stripos($text, $query, 0, 'UTF-8') === false ? 0.0 : 2.0;
            $raw = 1.0 + min(8, $overlap) * 0.7 + min(2.0, $titleBoost) + $dot * 3.0 + $exactBoost;
            $candidates[] = ['chunk_id' => $chunkId, 'raw' => $raw];
        }
        usort($candidates, static fn (array $left, array $right): int =>
            ($right['raw'] <=> $left['raw']) ?: strcmp($left['chunk_id'], $right['chunk_id'])
        );
        // A path-scoped bundle is a materialization request, not only a semantic
        // ranking request. Put one indexed chunk for every exact path/scope ahead
        // of repeat chunks from highly relevant files so a large service or doc
        // cannot crowd a small entrypoint out of the bounded result set.
        $rankedCandidates = $candidates;
        $anchoredCandidates = [];
        $anchoredChunkIds = [];
        foreach ($requestedPaths as $scope) {
            foreach ($rankedCandidates as $candidate) {
                $chunkId = (string) ($candidate['chunk_id'] ?? '');
                $row = $rows[$chunkId] ?? null;
                if ($chunkId === '' || !is_array($row) || isset($anchoredChunkIds[$chunkId])) {
                    continue;
                }
                $path = (string) ($row['path'] ?? '');
                if ($path !== $scope && !str_starts_with($path, rtrim($scope, '/') . '/')) {
                    continue;
                }
                $anchoredCandidates[] = $candidate;
                $anchoredChunkIds[$chunkId] = true;
                break;
            }
        }
        $anchoredPathCount = count($anchoredCandidates);
        foreach ($rankedCandidates as $candidate) {
            if (count($anchoredCandidates) >= $candidateLimit) {
                break;
            }
            $chunkId = (string) ($candidate['chunk_id'] ?? '');
            if ($chunkId === '' || isset($anchoredChunkIds[$chunkId])) {
                continue;
            }
            $anchoredCandidates[] = $candidate;
            $anchoredChunkIds[$chunkId] = true;
        }
        $candidates = array_slice($anchoredCandidates, 0, $candidateLimit);

        $matchedScopes = 0;
        $missingScopes = [];
        foreach ($requestedPaths as $scope) {
            $matched = false;
            foreach (array_keys($matchedPaths) as $path) {
                if ($path === $scope || str_starts_with($path, rtrim($scope, '/') . '/')) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                ++$matchedScopes;
            } else {
                $missingScopes[] = $scope;
            }
        }
        $truncated = count($rows) >= $materializeLimit;
        $warnings = [];
        if ($missingScopes !== []) {
            $warnings[] = 'Some requested paths were not present in the current indexed path scope: '
                . implode(', ', array_slice($missingScopes, 0, 8));
        }
        if ($truncated) {
            $warnings[] = 'Path-scoped chunk materialization reached its safety cap; narrow the supplied paths for complete coverage.';
        }

        return [
            'candidates' => $candidates,
            'rows' => $rows,
            'warnings' => $warnings,
            'cache' => [
                'status' => $matchedScopes === count($requestedPaths)
                    ? 'hit'
                    : ($matchedScopes > 0 ? 'partial' : 'miss'),
                'matched_paths' => $matchedScopes,
                'anchored_paths' => $anchoredPathCount,
                'materialized_chunks' => count($rows),
                'database_round_trips' => 1,
                'truncated' => $truncated,
            ],
        ];
    }

    /** @return list<string> */
    private function rankingTerms(string $query): array
    {
        $terms = [];
        foreach ($this->vectorizer->tokens($query) as $token) {
            [$kind, $value] = array_pad(explode(':', $token, 2), 2, '');
            if ($value === '' || $kind === 'cjk1' || mb_strlen($value, 'UTF-8') < 2) {
                continue;
            }
            $normalized = mb_strtolower($value, 'UTF-8');
            $terms['term:' . $normalized] = $normalized;
            if (count($terms) >= 24) {
                break;
            }
        }

        return array_values($terms);
    }

    private function elapsedMilliseconds(int $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 3);
    }

    /** @return list<string> */
    private function exactLookupTerms(string $query): array
    {
        $terms = [];
        $append = static function (string $value) use (&$terms): void {
            $value = trim($value, " \t\n\r\0\x0B\"'()[]{}<>,;");
            if ($value === '' || mb_strlen($value, 'UTF-8') > 256) {
                return;
            }
            $key = mb_strtolower($value, 'UTF-8');
            $terms[$key] ??= $value;
        };

        $trimmed = trim($query);
        if ($trimmed !== '' && mb_strlen($trimmed, 'UTF-8') <= 256
            && preg_match('/\s/u', $trimmed) !== 1) {
            $append($trimmed);
        }
        if (preg_match_all('~[A-Za-z_][A-Za-z0-9_]*(?:(?:\\\\|::|/|\.|-)[A-Za-z0-9_]+)*~', $query, $matches) === false) {
            return array_values($terms);
        }
        foreach ($matches[0] ?? [] as $candidate) {
            $qualified = preg_match('~(?:\\\\|::|/|\.|_|-)~', $candidate) === 1;
            $identifier = preg_match('/[a-z0-9][A-Z]/', $candidate) === 1
                || preg_match('/^[A-Z][A-Za-z0-9]{2,}$/', $candidate) === 1;
            if (!$qualified && !$identifier) {
                continue;
            }
            $append($candidate);
            if ($qualified) {
                $parts = preg_split('~(?:\\\\|::|/|\.|_|-)+~', $candidate, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach (array_slice($parts, -2) as $part) {
                    if (mb_strlen($part, 'UTF-8') >= 3) {
                        $append($part);
                    }
                }
            }
            if (count($terms) >= 12) {
                break;
            }
        }

        return array_slice(array_values($terms), 0, 12);
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function exactCandidates(string $query, array $options, int $limit): array
    {
        $terms = $this->exactLookupTerms($query);
        if ($terms === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach ($terms as $index => $term) {
            $key = 'exact_term_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $term;
        }
        $termList = implode(',', $placeholders);

        $moduleConditions = [];
        $moduleKeys = [];
        foreach ($terms as $index => $term) {
            if (preg_match('~^([A-Z][A-Za-z0-9]*)[/_]([A-Z][A-Za-z0-9]*)$~D', $term, $matches) !== 1) {
                continue;
            }
            $moduleKey = $matches[1] . '/' . $matches[2];
            if (isset($moduleKeys[$moduleKey])) {
                continue;
            }
            $moduleKeys[$moduleKey] = true;
            $vendorKey = 'exact_module_vendor_' . $index;
            $nameKey = 'exact_module_name_' . $index;
            $moduleConditions[] = '(ef.module_vendor = :' . $vendorKey
                . ' AND ef.module_name = :' . $nameKey . ')';
            $params[$vendorKey] = $matches[1];
            $params[$nameKey] = $matches[2];
        }
        $moduleCandidates = '';
        if ($moduleConditions !== []) {
            $moduleCandidates = '
                UNION ALL
                SELECT c.chunk_id, 9.0
                  FROM indexed_files ef JOIN chunks c ON c.file_id = ef.id
                 WHERE ' . implode(' OR ', $moduleConditions);
        }

        [$filter, $filterParams] = $this->filterSql($options, 'f', 'exact');
        $params = array_replace($params, $filterParams);
        $statement = $this->index->pdo()->prepare(
            'WITH candidates(chunk_id, raw) AS (
                SELECT c.chunk_id,
                       CASE WHEN s.fq_name COLLATE NOCASE IN (' . $termList . ') THEN 11.0 ELSE 10.0 END
                  FROM symbols s JOIN chunks c ON c.chunk_id = s.chunk_id
                 WHERE s.fq_name COLLATE NOCASE IN (' . $termList . ')
                    OR s.name COLLATE NOCASE IN (' . $termList . ')
                UNION ALL
                SELECT c.chunk_id, 12.0
                  FROM indexed_files ef JOIN chunks c ON c.file_id = ef.id
                 WHERE ef.path IN (' . $termList . ')' . $moduleCandidates . '
            )
            SELECT candidate.chunk_id, MAX(candidate.raw) AS raw
              FROM candidates candidate
              JOIN chunks c ON c.chunk_id = candidate.chunk_id
              JOIN indexed_files f ON f.id = c.file_id
             WHERE 1=1' . $filter . '
          GROUP BY candidate.chunk_id
          ORDER BY raw DESC, candidate.chunk_id
             LIMIT ' . $limit
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

    /** @return list<string> */
    private function focusedSparseTokens(string $query): array
    {
        $ranked = [];
        foreach ($this->vectorizer->tokens($query) as $position => $token) {
            [$kind, $value] = array_pad(explode(':', $token, 2), 2, '');
            if ($value === '' || $kind === 'cjk1') {
                continue;
            }
            $priority = match (true) {
                $kind === 'raw' && preg_match('~[\\\\:/. _-]~', str_replace(' ', '', $value)) === 1 => 6,
                $kind === 'word' && preg_match('/^[a-z0-9_]{3,}$/i', $value) === 1 => 5,
                $kind === 'raw' && preg_match('/^[a-z0-9_]{3,}$/i', $value) === 1 => 4,
                $kind === 'cjk3' => 3,
                $kind === 'word' && mb_strlen($value, 'UTF-8') <= 8 => 3,
                $kind === 'cjk2' => 2,
                $kind === 'raw' => 1,
                default => 0,
            };
            if ($priority === 0) {
                continue;
            }
            $key = mb_strtolower($value, 'UTF-8');
            if (isset($ranked[$key]) && $ranked[$key]['priority'] >= $priority) {
                continue;
            }
            $ranked[$key] = [
                'token' => $token,
                'priority' => $priority,
                'position' => isset($ranked[$key])
                    ? min($ranked[$key]['position'], $position)
                    : $position,
            ];
        }
        uasort($ranked, static fn (array $left, array $right): int =>
            [$right['priority'], $left['position']] <=> [$left['priority'], $right['position']]
        );

        return array_slice(array_column($ranked, 'token'), 0, 12);
    }

    /** @return list<array{chunk_id:string,raw:float}> */
    private function sparseCandidates(
        string $query,
        array $options,
        int $limit,
        ?array &$meta = null,
    ): array {
        $vector = $this->vectorizer->vectorizeTokens($this->focusedSparseTokens($query), 12);
        $meta = [
            'query_terms' => count($vector),
            'selected_terms' => 0,
            'dropped_terms' => 0,
            'query_postings' => 0,
            'selected_postings' => 0,
            'posting_budget' => 0,
            'posting_stats_ms' => 0.0,
            'candidate_score_ms' => 0.0,
            'candidate_count' => 0,
        ];
        if ($vector === []) {
            return [];
        }

        [$vector, $selectionMeta] = $this->pruneSparseVector($vector, $limit);
        $meta = array_replace($meta, $selectionMeta);
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
        $scoreStarted = hrtime(true);
        $statement->execute($params);
        $rows = $this->candidateRows($statement->fetchAll(), 'raw');
        $meta['candidate_score_ms'] = $this->elapsedMilliseconds($scoreStarted);
        $meta['candidate_count'] = count($rows);

        return $rows;
    }

    /**
     * Keep the lowest-fanout feature hashes so collisions and broad words do
     * not force every sparse query through tens of thousands of postings.
     *
     * @param array<int,float> $vector
     * @return array{0:array<int,float>,1:array<string,int|float>}
     */
    private function pruneSparseVector(array $vector, int $candidateLimit): array
    {
        $started = hrtime(true);
        $placeholders = [];
        $params = [];
        foreach (array_keys($vector) as $index => $hash) {
            $key = 'sparse_df_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $hash;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT term_hash, COUNT(*) AS posting_count
               FROM chunk_vector_terms
              WHERE term_hash IN (' . implode(',', $placeholders) . ')
           GROUP BY term_hash'
        );
        $statement->execute($params);
        $postingCounts = [];
        foreach ($statement->fetchAll() as $row) {
            $postingCounts[(int) $row['term_hash']] = (int) $row['posting_count'];
        }

        $ranked = [];
        $queryPostings = 0;
        foreach ($vector as $hash => $weight) {
            $postings = (int) ($postingCounts[(int) $hash] ?? 0);
            $queryPostings += $postings;
            if ($postings <= 0) {
                continue;
            }
            $ranked[] = [
                'hash' => (int) $hash,
                'weight' => (float) $weight,
                'postings' => $postings,
                'position' => count($ranked),
            ];
        }
        usort($ranked, static function (array $left, array $right): int {
            if ($left['postings'] !== $right['postings']) {
                return $left['postings'] <=> $right['postings'];
            }

            return $left['position'] <=> $right['position'];
        });

        $minimumTerms = min(1, count($ranked));
        $maximumTerms = min(8, count($ranked));
        $postingBudget = max(2_500, min(20_000, $candidateLimit * 40));
        $selected = [];
        $selectedPostings = 0;
        foreach ($ranked as $item) {
            if (count($selected) >= $maximumTerms) {
                break;
            }
            if (count($selected) >= $minimumTerms
                && $selectedPostings + $item['postings'] > $postingBudget) {
                break;
            }
            $selected[$item['hash']] = $item['weight'];
            $selectedPostings += $item['postings'];
        }

        return [
            $selected,
            [
                'query_terms' => count($vector),
                'selected_terms' => count($selected),
                'dropped_terms' => count($vector) - count($selected),
                'query_postings' => $queryPostings,
                'selected_postings' => $selectedPostings,
                'posting_budget' => $postingBudget,
                'posting_stats_ms' => $this->elapsedMilliseconds($started),
            ],
        ];
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
                    COALESCE(s.fq_name, s.name, \'\') AS symbol_name,
                    COALESCE(s.body_hash, \'\') AS symbol_body_hash
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
            'symbol_body_hash' => $row['symbol_body_hash'] ?? '',
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

    /** @param array<string,mixed> $item
     *  @return array<string,mixed>
     */
    private function compactRegion(array $item, string $reason): array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $symbol = trim((string) ($item['symbol_name'] ?? ''));
        $symbolUid = trim((string) ($item['symbol_uid'] ?? ''));
        $fileHash = (string) ($item['file_hash'] ?? '');
        $expectedDigest = (string) ($item['symbol_body_hash'] ?? '');

        $region = [
            'path' => (string) ($item['relative_path'] ?? ''),
            'file_sha256' => $fileHash,
            'expected_file_sha256' => $fileHash,
            'content_sha256' => (string) ($item['content_hash'] ?? ''),
            'kind' => (string) ($item['file_kind'] ?? ''),
            'language' => (string) ($item['language'] ?? ''),
            'module' => (string) ($item['module'] ?? ''),
            'start_line' => (int) ($item['start_line'] ?? 1),
            'end_line' => (int) ($item['end_line'] ?? $item['start_line'] ?? 1),
            'symbol_uid' => $symbolUid,
            'symbol' => $symbol,
            'reason' => $title !== '' ? $title : ($symbol !== '' ? $symbol : $reason),
            'content' => (string) ($item['snippet'] ?? ''),
            'token_estimate' => (int) ($item['token_estimate'] ?? 0),
        ];
        if ($symbolUid !== '' && $expectedDigest !== '') {
            $region['target_ref'] = $symbol !== '' ? $symbol : $symbolUid;
            $region['expected_digest'] = $expectedDigest;
        }

        return $region;
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
        if ($position === false && $query !== '') {
            $terms = $this->rankingTerms($query);
            usort($terms, static fn (string $left, string $right): int =>
                mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8')
            );
            foreach ($terms as $term) {
                $position = mb_stripos($content, $term, 0, 'UTF-8');
                if ($position !== false) {
                    break;
                }
            }
        }
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
            'SELECT r.*, f.path, f.module_vendor, f.module_name,
                    source.fq_name AS source_name, target.fq_name AS resolved_target_name
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
        $seen = [];
        foreach ($statement->fetchAll() as $row) {
            $direction = isset($uidSet[(string) ($row['source_symbol_uid'] ?? '')]) ? 'downstream' : 'upstream';
            if (in_array($mode, ['impact', 'upstream', 'callers'], true) && $direction !== 'upstream') {
                continue;
            }
            if (in_array($mode, ['downstream', 'callees'], true) && $direction !== 'downstream') {
                continue;
            }
            $relation = [
                'direction' => $direction,
                'depth' => 1,
                'kind' => $row['relation_kind'],
                'source_symbol_uid' => $row['source_symbol_uid'],
                'source_name' => $row['source_name'],
                'target_symbol_uid' => $row['target_symbol_uid'],
                'target_name' => $row['resolved_target_name'] ?: $row['target_name'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'module' => trim((string) $row['module_vendor'] . '/' . (string) $row['module_name'], '/'),
                'line' => (int) $row['line'],
                'confidence' => (float) $row['confidence'],
            ];
            $key = $this->relationKey($relation);
            if (!isset($seen[$key])) {
                $results[] = $relation;
                $seen[$key] = true;
            }
        }

        if (in_array($mode, ['impact', 'upstream', 'callers'], true)) {
            $visited = array_fill_keys($uids, true);
            $frontier = [];
            foreach ($results as $relation) {
                $sourceUid = trim((string) ($relation['source_symbol_uid'] ?? ''));
                if (($relation['direction'] ?? '') === 'upstream' && $sourceUid !== '' && !isset($visited[$sourceUid])) {
                    $frontier[$sourceUid] = true;
                }
            }
            for ($depth = 2; $depth <= 3 && $frontier !== [] && count($results) < 500; ++$depth) {
                $targetUids = array_keys($frontier);
                foreach ($targetUids as $uid) {
                    $visited[$uid] = true;
                }
                $frontier = [];
                foreach ($this->upstreamRelations($targetUids, $depth) as $relation) {
                    $sourceUid = trim((string) ($relation['source_symbol_uid'] ?? ''));
                    if ($sourceUid === '' || isset($visited[$sourceUid])) {
                        continue;
                    }
                    $key = $this->relationKey($relation);
                    if (!isset($seen[$key])) {
                        $results[] = $relation;
                        $seen[$key] = true;
                    }
                    $frontier[$sourceUid] = true;
                    if (count($results) >= 500) {
                        break 2;
                    }
                }
            }
        }

        return $results;
    }

    /** @param list<string> $targetUids
     *  @return list<array<string,mixed>>
     */
    private function upstreamRelations(array $targetUids, int $depth): array
    {
        if ($targetUids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique($targetUids)) as $index => $uid) {
            $placeholders[] = ':upstream_uid_' . $index;
            $params['upstream_uid_' . $index] = $uid;
        }
        $statement = $this->index->pdo()->prepare(
            'SELECT r.*, f.path, f.module_vendor, f.module_name,
                    source.fq_name AS source_name, target.fq_name AS resolved_target_name
               FROM relations r JOIN indexed_files f ON f.id = r.file_id
          LEFT JOIN symbols source ON source.symbol_uid = r.source_symbol_uid
          LEFT JOIN symbols target ON target.symbol_uid = r.target_symbol_uid
              WHERE r.target_symbol_uid IN (' . implode(',', $placeholders) . ')
           ORDER BY r.relation_kind, f.path, r.line LIMIT 500'
        );
        $statement->execute($params);
        $relations = [];
        foreach ($statement->fetchAll() as $row) {
            $relations[] = [
                'direction' => 'upstream',
                'depth' => $depth,
                'kind' => $row['relation_kind'],
                'source_symbol_uid' => $row['source_symbol_uid'],
                'source_name' => $row['source_name'],
                'target_symbol_uid' => $row['target_symbol_uid'],
                'target_name' => $row['resolved_target_name'] ?: $row['target_name'],
                'relative_path' => $row['path'],
                'absolute_path' => $this->index->absolutePath((string) $row['path']),
                'module' => trim((string) $row['module_vendor'] . '/' . (string) $row['module_name'], '/'),
                'line' => (int) $row['line'],
                'confidence' => (float) $row['confidence'],
            ];
        }

        return $relations;
    }

    /** @param array<string,mixed> $relation */
    private function relationKey(array $relation): string
    {
        return implode('|', [
            (string) ($relation['direction'] ?? ''),
            (string) ($relation['source_symbol_uid'] ?? ''),
            (string) ($relation['target_symbol_uid'] ?? $relation['target_name'] ?? ''),
            (string) ($relation['kind'] ?? ''),
            (string) ($relation['relative_path'] ?? ''),
            (string) ($relation['line'] ?? ''),
        ]);
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
        $retention = $this->config->duration('privacy.query_log_ttl');
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
     *  @param array<string,mixed> $telemetry
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
        array $telemetry = [],
    ): array {
        $durationMs = (int) round((microtime(true) - $started) * 1_000);
        $timings = [
            'duration_ms' => $durationMs,
            'results' => count($results),
            'phases_ms' => $telemetry['phases_ms'] ?? [],
            'cache' => $telemetry['cache'] ?? [],
        ];
        $this->index->transaction(function (PDO $database) use ($queryId, $results, $timings): void {
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
                'timings' => Json::encode($timings),
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
                'strategy' => $telemetry['strategy'] ?? 'exact+fts5+trigram_cjk+sparse_feature_hash+rrf+feedback',
                'neural_embeddings' => false,
                'filesystem_scanned' => false,
                'external_graph_invoked' => false,
                'timing_ms' => array_replace(['total' => $durationMs], $telemetry['phases_ms'] ?? []),
                'sparse' => is_array($telemetry['sparse'] ?? null) ? $telemetry['sparse'] : [],
                'cache' => $telemetry['cache'] ?? [
                    'source' => 'project_sqlite_index',
                    'revision' => $this->index->revision(),
                    'scope' => 'global',
                ],
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
