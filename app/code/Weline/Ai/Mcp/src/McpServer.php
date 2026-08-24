<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

final class McpServer
{
    private const SERVER_NAME = 'weline-project-intelligence';
    private const RESPONSE_PREFIX = 'Weline：';
    private const LATEST_PROTOCOL = '2025-11-25';
    private const SUPPORTED_PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];
    private const MCP_APP_MIME = 'text/html;profile=mcp-app';

    public function __construct(private readonly ToolService $tools)
    {
    }

    /** @param resource $input
     *  @param resource $output
     */
    public function run($input, $output): void
    {
        while (($line = fgets($input)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $request = json_decode($line, true);
            if (!is_array($request) || array_is_list($request)) {
                $this->write($output, $this->error(null, -32700, 'Parse error'));
                continue;
            }
            $hasId = array_key_exists('id', $request);
            $id = $hasId ? $request['id'] : null;
            try {
                $response = $this->handle($request, $hasId);
                if ($response !== null) {
                    $this->write($output, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $response]);
                }
            } catch (JsonRpcException $exception) {
                if ($hasId) {
                    $this->write($output, $this->error($id, $exception->rpcCode, $exception->getMessage(), $exception->data));
                }
            } catch (Throwable $exception) {
                if ($hasId) {
                    [$message] = Redactor::string($exception->getMessage());
                    $this->write($output, $this->error($id, -32603, Text::truncate($message, 500)));
                }
            }
        }
    }

    /** @param array<string, mixed> $request */
    private function handle(array $request, bool $hasId): mixed
    {
        if (($request['jsonrpc'] ?? '') !== '2.0' || !is_string($request['method'] ?? null)) {
            throw new JsonRpcException(-32600, 'Invalid Request');
        }
        $method = $request['method'];
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            throw new JsonRpcException(-32602, 'Invalid params');
        }
        if (!$hasId) {
            return null;
        }
        return match ($method) {
            'initialize' => $this->initialize($params),
            'ping' => (object) [],
            'tools/list' => ['tools' => $this->tools->definitions()],
            'tools/call' => $this->callTool($params),
            'resources/list' => $this->listResources(),
            'resources/read' => $this->readResource($params),
            default => throw new JsonRpcException(-32601, 'Method not found', ['method' => $method]),
        };
    }

    /** @param array<string, mixed> $params */
    private function initialize(array $params): array
    {
        $requested = trim((string) ($params['protocolVersion'] ?? ''));
        $protocol = in_array($requested, self::SUPPORTED_PROTOCOLS, true) ? $requested : self::LATEST_PROTOCOL;
        return [
            'protocolVersion' => $protocol,
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'title' => 'Weline Project Intelligence MCP',
                'version' => ToolService::VERSION,
            ],
            'instructions' => ToolService::INSTRUCTIONS,
        ];
    }

    /** @return array<string, mixed> */

    /**
     * Add stable closed-loop state to every primary response without another repository read.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decorateClosedLoopResult(string $tool, array $result, bool $isError): array
    {
        $projectId = trim((string) ($result['project_id'] ?? ''));
        $revision = (int) ($result['project_revision'] ?? $result['index_revision'] ?? 0);

        if ($tool === 'get_edit_bundle' && !$isError) {
            $taskDigest = (string) ($result['task_digest'] ?? Ids::hash(''));
            $result['task_id'] = (string) ($result['task_id'] ?? (
                'task-' . substr(hash('sha256', $projectId . "\0" . $taskDigest), 0, 24)
            ));
            $result['bundle_id'] = (string) ($result['bundle_id'] ?? (
                'bundle-' . substr(hash('sha256', $projectId . "\0" . $revision . "\0" . $taskDigest), 0, 24)
            ));
            $result['project_revision'] = $revision;
            $result['workflow_budget'] = array_replace([
                'get_edit_bundle' => 1,
                'successful_apply_compact_edit' => 1,
                'conflict_replans_max' => 2,
                'impact_expansion_depth_max' => 2,
                'validation_repairs_max' => 2,
                'same_error_stop_count' => 3,
                'native_source_reads' => 0,
                'direct_writes' => 0,
                'intermediate_user_inquiries' => 0,
            ], is_array($result['workflow_budget'] ?? null) ? $result['workflow_budget'] : []);
            $result['validation_plan'] = is_array($result['validation_plan'] ?? null)
                ? $result['validation_plan']
                : [
                    'fixed_checks_in_apply' => ['syntax', 'diff_check', 'server_approved_regression', 'targeted_reindex'],
                    'regression_entry' => 'apply_compact_edit:server_approved_batch',
                    'regression_runs_max' => 1,
                    'runtime_entry_runs_max' => 1,
                ];
        }

        if ($tool === 'apply_compact_edit' && !$isError) {
            $reportFiles = $result['change_report']['files'] ?? $result['files'] ?? [];
            $changedPaths = [];
            if (is_array($reportFiles)) {
                foreach ($reportFiles as $file) {
                    if (!is_array($file)) {
                        continue;
                    }
                    $path = trim((string) ($file['path'] ?? ''));
                    if ($path !== '') {
                        $changedPaths[$path] = true;
                    }
                }
            }
            $impactDelta = array_replace([
                'requires_followup' => false,
                'new_affected_paths' => [],
                'new_affected_symbols' => [],
                'reason' => 'Apply did not return post-index impact evidence.',
                'related_regions' => [],
                'changed_paths' => array_keys($changedPaths),
                'depth' => 0,
                'max_depth' => 2,
                'status' => 'unavailable',
                'next_state_when_required' => 'IMPACT_EXPANSION',
            ], is_array($result['impact_delta'] ?? null) ? $result['impact_delta'] : []);
            $result['impact_delta'] = $impactDelta;
            $runState = is_array($result['execution_run'] ?? null)
                ? strtoupper(trim((string) ($result['execution_run']['workflow_state'] ?? '')))
                : '';
            $result['workflow_state'] = $runState !== ''
                ? $runState
                : ((bool) ($impactDelta['requires_followup'] ?? false) ? 'IMPACT_EXPANSION' : 'COMPLETED');
            $reviewContract = is_array($result['review_contract'] ?? null)
                ? $result['review_contract']
                : (is_array($result['change_report']['review_contract'] ?? null)
                    ? $result['change_report']['review_contract']
                    : []);
            $hasMoreReviewPages = (bool) ($reviewContract['has_more'] ?? false);
            $result['review_contract'] = array_replace([
                'single_logical_read_only_pass' => true,
                'single_read_only_pass' => !$hasMoreReviewPages,
                'source' => 'change_report.files[].diff',
                'all_changed_files_required' => true,
            ], $reviewContract);
        }

        if ($tool === 'get_edit_status' && !$isError) {
            $reviewContract = is_array($result['review_contract'] ?? null)
                ? $result['review_contract']
                : (is_array($result['change_report']['review_contract'] ?? null)
                    ? $result['change_report']['review_contract']
                    : []);
            $result['review_contract'] = array_replace([
                'single_logical_read_only_pass' => true,
                'single_read_only_pass' => !(bool) ($reviewContract['has_more'] ?? false),
                'source' => 'change_report.files[].diff',
                'all_changed_files_required' => true,
            ], $reviewContract);
        }

        if ($tool === 'validate_change' && !$isError) {
            $status = strtolower(trim((string) (
                $result['validation']['status'] ?? $result['status'] ?? 'passed'
            )));
            $passed = !in_array($status, ['failed', 'error', 'invalid'], true);
            $result['workflow_state'] = $passed ? 'VALIDATED' : 'VALIDATION_REPAIR';
            $result['validation_envelope'] = [
                'passed' => $passed,
                'failed_stage' => $passed ? null : (string) ($result['failed_stage'] ?? 'regression'),
                'evidence' => $result['evidence'] ?? $result['checks'] ?? $result['validation'] ?? [],
                'related_regions' => $result['related_regions'] ?? [],
                'suggested_scope' => $passed ? [] : ($result['suggested_scope'] ?? ['failed validation targets']),
                'repair_attempts_max' => 2,
            ];
        }

        if (!$isError) {
            return $result;
        }

        $nested = is_array($result['error'] ?? null);
        $error = $nested ? $result['error'] : $result;
        $code = strtoupper(trim((string) ($error['code'] ?? $result['code'] ?? '')));
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        if ($code === 'EDIT_REPLAN_REQUIRED') {
            $failed = $details['failed_operations'] ?? [];
            if (!is_array($failed) || $failed === []) {
                $failed = isset($details['failed_operation']) ? [$details['failed_operation']] : [];
            }
            $details['workflow_state'] = 'CONFLICT_REPLAN';
            $details['failed_operations'] = array_values($failed);
            $details['unchanged_operations'] = is_array($details['unchanged_operations'] ?? null)
                ? array_values($details['unchanged_operations'])
                : [];
            $details['semantic_diff_from_bundle'] = $details['semantic_diff_from_bundle'] ?? [
                'latest_region_count' => is_array($details['latest_regions'] ?? null)
                    ? count($details['latest_regions'])
                    : 0,
                'project_revision' => (int) ($details['project_revision'] ?? $revision),
            ];
            $details['project_revision'] = (int) ($details['project_revision'] ?? $revision);
            $details['retry_budget'] = [
                'max_replans' => 2,
                'same_conflict_stop_count' => 3,
                'preserve_unchanged_operations' => true,
            ];
        } elseif (str_contains($code, 'VALIDATION')) {
            $details['workflow_state'] = 'VALIDATION_REPAIR';
            $details['failed_stage'] = (string) ($details['failed_stage'] ?? 'fixed_validation');
            $details['evidence'] = $details['evidence'] ?? $details['validation'] ?? [];
            $details['related_regions'] = $details['related_regions'] ?? [];
            $details['suggested_scope'] = $details['suggested_scope'] ?? ['validation failure targets'];
            $details['repair_attempts_max'] = 2;
        } elseif (in_array($code, ['INDEX_NOT_READY', 'INDEX_SYMBOL_QUERY_FAILED', 'INDEX_SYMBOL_REFRESH_FAILED'], true)) {
            $details['workflow_state'] = 'CONTEXT_INDEX_RETRY';
            $details['model_continuation_allowed'] = true;
        } elseif (in_array($code, ['CONTEXT_INCOMPLETE', 'CONTEXT_TARGET_AMBIGUOUS'], true)) {
            $details['workflow_state'] = $code;
            $details['model_continuation_allowed'] = false;
        }

        $error['details'] = $details;
        if ($nested) {
            $result['error'] = $error;
        } else {
            $result = $error;
        }
        foreach (['project_id', 'project_revision', 'task_id', 'bundle_id', 'workflow_state'] as $key) {
            if (array_key_exists($key, $details)) {
                $result[$key] = $details[$key];
            }
        }
        if (!isset($result['task_digest']) && is_scalar($details['original_task'] ?? null)) {
            $result['task_digest'] = Ids::hash((string) $details['original_task']);
        }

        return $result;
    }

    private function listResources(): array
    {
        $ui = [
            'prefersBorder' => true,
            'csp' => ['connectDomains' => [], 'resourceDomains' => []],
        ];

        return [
            'resources' => [
                [
                    'uri' => ToolService::EXECUTION_RUN_RESOURCE_URI,
                    'name' => 'weline-execution-run',
                    'title' => 'Weline execution run',
                    'description' => 'Compact live task timeline with clickable candidate files, exact regions, validation, rollback and bounded diffs.',
                    'mimeType' => self::MCP_APP_MIME,
                    '_meta' => ['ui' => $ui],
                ],
                [
                    'uri' => ToolService::EDIT_REPORT_RESOURCE_URI,
                    'name' => 'weline-edit-report',
                    'title' => 'Weline change report',
                    'description' => 'Compatibility change report for historical edit transactions.',
                    'mimeType' => self::MCP_APP_MIME,
                    '_meta' => ['ui' => $ui],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function readResource(array $params): array
    {
        $uri = trim((string) ($params['uri'] ?? ''));
        $resources = [
            ToolService::EXECUTION_RUN_RESOURCE_URI => 'execution-run-v1.html',
            ToolService::EDIT_REPORT_RESOURCE_URI => 'edit-report-v2.html',
        ];
        if (!isset($resources[$uri])) {
            throw new JsonRpcException(-32602, 'Unknown resource URI', ['uri' => $uri]);
        }
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . $resources[$uri];
        $html = @file_get_contents($path);
        if (!is_string($html) || $html === '') {
            throw new JsonRpcException(-32603, 'MCP App resource is unavailable');
        }

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => self::MCP_APP_MIME,
                'text' => $html,
                '_meta' => [
                    'ui' => [
                        'prefersBorder' => true,
                        'csp' => ['connectDomains' => [], 'resourceDomains' => []],
                    ],
                ],
            ]],
        ];
    }

    /** @param array<string, mixed> $params */
    private function callTool(array $params): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            throw new JsonRpcException(-32602, 'Tool name is required');
        }
        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw new JsonRpcException(-32602, 'Tool arguments must be an object');
        }
        try {
            $result = $this->tools->call($name, $arguments);

            return $this->toolResponse($name, $result, false);
        } catch (ToolException $exception) {
            return $this->toolResponse($name, $exception->envelope(), true);
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            $toolError = new ToolException('INTERNAL_ERROR', Text::truncate($message, 500), false);

            return $this->toolResponse($name, $toolError->envelope(), true);
        }
    }

    /** @param array<string, mixed> $result */
    private function toolResponse(string $tool, array $result, bool $isError): array
    {
        $receiptId = Ids::make('weline-mcp');
        $result = $this->decorateClosedLoopResult($tool, $result, $isError);
        $mirrorFullResultInContent = in_array(
            $tool,
            ['get_edit_bundle', 'apply_compact_edit', 'validate_change', 'get_edit_status', 'get_run_status', 'get_run_trace'],
            true,
        ) || $isError;

        static $workflowAuditByProject = [];
        $projectId = trim((string) ($result['project_id'] ?? ''));
        $auditKey = $projectId !== '' ? $projectId : '__unscoped__';
        if ($auditKey === '__unscoped__' && count($workflowAuditByProject) === 1) {
            $auditKey = (string) array_key_first($workflowAuditByProject);
            if ($auditKey !== '__unscoped__') {
                $projectId = $auditKey;
            }
        }
        if ($tool === 'get_edit_bundle') {
            $workflowAuditByProject[$auditKey] = [
                'task_digest' => (string) ($result['task_digest'] ?? ''),
                'get_edit_bundle_calls' => 1,
                'apply_compact_edit_calls' => 0,
                'native_file_read_fallback_count' => 0,
                'intermediate_user_inquiry_count' => 0,
                'automatic_rollback_count' => 0,
                'validate_change_calls' => 0,
                'runtime_validation_calls' => 0,
                'recursion_counts' => [
                    'CONFLICT_REPLAN' => 0,
                    'IMPACT_EXPANSION' => 0,
                    'VALIDATION_REPAIR' => 0,
                    'USER_SCOPE_CHANGE' => 0,
                ],
                'writer_mode' => 'single_writer',
                'observation_scope' => 'mcp_tools_and_routing_guard',
            ];
        } elseif (!isset($workflowAuditByProject[$auditKey])) {
            $workflowAuditByProject[$auditKey] = [
                'task_digest' => '',
                'get_edit_bundle_calls' => 0,
                'apply_compact_edit_calls' => 0,
                'native_file_read_fallback_count' => 0,
                'intermediate_user_inquiry_count' => 0,
                'automatic_rollback_count' => 0,
                'validate_change_calls' => 0,
                'runtime_validation_calls' => 0,
                'recursion_counts' => [
                    'CONFLICT_REPLAN' => 0,
                    'IMPACT_EXPANSION' => 0,
                    'VALIDATION_REPAIR' => 0,
                    'USER_SCOPE_CHANGE' => 0,
                ],
                'writer_mode' => 'single_writer',
                'observation_scope' => 'mcp_tools_and_routing_guard',
            ];
        }
        if ($tool === 'validate_change') {
            $workflowAuditByProject[$auditKey]['validate_change_calls'] =
                (int) ($workflowAuditByProject[$auditKey]['validate_change_calls'] ?? 0) + 1;
        }
        $workflowState = strtoupper(trim((string) ($result['workflow_state'] ?? '')));
        if (isset($workflowAuditByProject[$auditKey]['recursion_counts'][$workflowState])) {
            $workflowAuditByProject[$auditKey]['recursion_counts'][$workflowState]++;
        }

        $resultIndexRevision = (int) ($result['index_revision'] ?? $result['project_revision'] ?? 0);
        if ($resultIndexRevision > 0) {
            $workflowAuditByProject[$auditKey]['index_revision'] = $resultIndexRevision;
        }
        if ($tool === 'apply_compact_edit') {
            $workflowAuditByProject[$auditKey]['apply_compact_edit_calls']++;
        }
        $validation = is_array($result['validation'] ?? null) ? $result['validation'] : [];
        $rollback = is_array($result['rollback'] ?? null) ? $result['rollback'] : [];
        $automaticRollback = (bool) ($result['rolled_back'] ?? false)
            || (bool) ($validation['rolled_back'] ?? false)
            || (bool) ($rollback['performed'] ?? false);
        if ($automaticRollback) {
            $workflowAuditByProject[$auditKey]['automatic_rollback_count']++;
        }
        $audit = $workflowAuditByProject[$auditKey];
        $result['workflow_audit'] = [
            'receipt_id' => $receiptId,
            'mcp_called' => true,
            'project_id' => $projectId,
            'index_revision' => $resultIndexRevision > 0
                ? $resultIndexRevision
                : (int) ($audit['index_revision'] ?? 0),
            'task_digest' => $audit['task_digest'],
            'get_edit_bundle_calls' => $audit['get_edit_bundle_calls'],
            'apply_compact_edit_calls' => $audit['apply_compact_edit_calls'],
            'native_file_read_fallback' => $audit['native_file_read_fallback_count'] > 0,
            'native_file_read_fallback_count' => $audit['native_file_read_fallback_count'],
            'intermediate_user_inquiry' => $audit['intermediate_user_inquiry_count'] > 0,
            'intermediate_user_inquiry_count' => $audit['intermediate_user_inquiry_count'],
            'automatic_rollback' => $automaticRollback,
            'automatic_rollback_count' => $audit['automatic_rollback_count'],
            'validate_change_calls' => (int) ($audit['validate_change_calls'] ?? 0),
            'runtime_validation_calls' => (int) ($audit['runtime_validation_calls'] ?? 0),
            'recursion_counts' => is_array($audit['recursion_counts'] ?? null)
                ? $audit['recursion_counts']
                : [],
            'writer_mode' => (string) ($audit['writer_mode'] ?? 'single_writer'),
            'task_id' => (string) ($result['task_id'] ?? ''),
            'bundle_id' => (string) ($result['bundle_id'] ?? ''),
            'run_id' => (string) ($result['run_id'] ?? $result['execution_run']['run_id'] ?? ''),
            'trace_id' => (string) ($result['trace_id'] ?? $result['execution_run']['trace_id'] ?? ''),
            'durable_storage' => 'sqlite',
            'project_revision' => (int) ($result['project_revision'] ?? $resultIndexRevision),
            'observation_scope' => (string) ($audit['observation_scope'] ?? 'mcp_process_and_routing_guard'),
            'host_only_events_observable' => false,
        ];

        $usageLine = $this->toolUsageReceiptLine($tool, $result, $isError, $receiptId);
        $resultDigest = Ids::hash(Json::canonical($result));

        $result['_weline_mcp'] = [
            'used' => true,
            'server' => self::SERVER_NAME,
            'version' => ToolService::VERSION,
            'tool' => $tool,
            'called_at' => Clock::now(),
            'receipt_id' => $receiptId,
            'result_digest' => $resultDigest,
            'is_error' => $isError,
            'response_format' => 'structuredContent',
            'legacy_content' => $mirrorFullResultInContent
                ? ($isError
                    ? 'full_error_mirror'
                    : ($tool === 'get_edit_bundle' ? 'full_bundle_mirror' : 'full_result_mirror'))
                : 'summary_only',
            'usage_line' => $usageLine,
            'response_prefix' => self::RESPONSE_PREFIX,
            'report_contract' => 'Begin every subsequent user-visible progress update and the final report in this turn with the exact response_prefix. content[0].text repeats usage_line as runtime proof.',
        ];
        $contentPayload = $mirrorFullResultInContent
            ? $result
            : $this->legacyToolSummary($tool, $result, $isError, $receiptId);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $usageLine,
                ],
                [
                    'type' => 'text',
                    'text' => Json::encode($contentPayload),
                ],
            ],
            'structuredContent' => $result,
            'isError' => $isError,
        ];
    }

    /** @param array<string, mixed> $result */
    private function toolUsageReceiptLine(string $tool, array $result, bool $isError, string $receiptId): string
    {
        $segments = [self::RESPONSE_PREFIX . '已调用 ' . $tool];
        if ($isError) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : $result;
            $code = trim((string) ($error['code'] ?? ''));
            $message = Text::truncate(trim((string) ($error['message'] ?? 'Tool call failed')), 120);
            $segments[] = '失败';
            if ($code !== '') {
                $segments[] = $code;
            }
            if ($message !== '' && $message !== $code) {
                $segments[] = $message;
            }
        } else {
            $segments[] = $this->toolUsageHighlight($tool, $result);
        }
        $segments[] = 'receipt=' . $receiptId;

        return implode('，', array_values(array_filter(
            $segments,
            static fn (string $part): bool => $part !== '',
        )));
    }

    /** @param array<string, mixed> $result */
    private function toolUsageHighlight(string $tool, array $result): string
    {
        $status = trim((string) ($result['status'] ?? ''));

        return match ($tool) {
            'prepare_project' => $this->joinUsageHighlightParts([
                $status !== '' ? 'status=' . $status : '',
                isset($result['readiness_id'])
                    ? 'readiness_id=' . Text::truncate((string) $result['readiness_id'], 48)
                    : '',
                isset($result['git_branch']) ? 'branch=' . (string) $result['git_branch'] : '',
            ]),
            'resolve_task_context' => $this->joinUsageHighlightParts([
                $status !== '' ? 'status=' . $status : '',
                is_array($result['guidance_bundle'] ?? null)
                    ? 'fragments=' . count($result['guidance_bundle']['fragments'] ?? [])
                    : '',
            ]),
            'get_edit_bundle' => $this->joinUsageHighlightParts([
                array_key_exists('ready_for_edit', $result)
                    ? 'ready_for_edit=' . (($result['ready_for_edit'] ?? false) ? 'true' : 'false')
                    : '',
                isset($result['state']) ? 'state=' . (string) $result['state'] : '',
            ]),
            'apply_compact_edit' => $this->joinUsageHighlightParts([
                isset($result['state']) ? 'state=' . (string) $result['state'] : '',
                !empty($result['rolled_back']) ? 'rolled_back=true' : '',
            ]),
            'health' => $status !== '' ? 'status=' . $status : 'ok',
            default => $status !== '' ? 'status=' . $status : 'ok',
        };
    }

    /** @param list<string> $parts */
    private function joinUsageHighlightParts(array $parts): string
    {
        $filtered = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        return $filtered !== [] ? implode('，', $filtered) : 'ok';
    }

    /** @param array<string,mixed> $result
     *  @return array<string,mixed>
     */
    private function legacyToolSummary(string $tool, array $result, bool $isError, string $receiptId): array
    {
        $summary = [
            'status' => $isError ? 'error' : 'ok',
            'tool' => $tool,
            'receipt_id' => $receiptId,
            'use' => 'structuredContent',
        ];
        foreach (['request_id', 'query_id', 'edit_id', 'state', 'region_count', 'impact_risk', 'index_revision'] as $key) {
            if (isset($result[$key]) && (is_scalar($result[$key]) || $result[$key] === null)) {
                $summary[$key] = $result[$key];
            }
        }
        if (isset($result['paths']) && is_array($result['paths'])) {
            $summary['path_count'] = count($result['paths']);
        }
        if ($isError) {
            $error = is_array($result['error'] ?? null) ? $result['error'] : $result;
            $summary['code'] = Text::truncate((string) ($error['code'] ?? 'ERROR'), 80);
            $summary['message'] = Text::truncate((string) ($error['message'] ?? 'Tool call failed'), 240);
        }

        return $summary;
    }

    /** @param resource $output
     *  @param array<string, mixed> $message
     */
    private function write($output, array $message): void
    {
        fwrite($output, Json::encode($message) . "\n");
        fflush($output);
    }

    /** @param array<string, mixed> $data
     *  @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message, array $data = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== []) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }
}

final class JsonRpcException extends \RuntimeException
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $rpcCode,
        string $message,
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}
