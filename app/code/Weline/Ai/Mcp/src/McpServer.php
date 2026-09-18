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
        $this->prepareStdio($input, $output);
        while (true) {
            $line = fgets($input);
            if ($line === false) {
                if ($this->stdioEnded($input)) {
                    break;
                }
                usleep(20_000);
                continue;
            }
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
            'instructions' => ToolService::instructions(),
        ];
    }

    /** @return array<string, mixed> */
    private function listResources(): array
    {
        return ['resources' => []];
    }

    /** @param array<string, mixed> $params
     *  @return array<string, mixed>
     */
    private function readResource(array $params): array
    {
        $uri = trim((string) ($params['uri'] ?? ''));
        throw new JsonRpcException(-32602, 'Unknown resource URI', ['uri' => $uri]);
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
        $mirrorFullResultInContent = getenv('WELINE_MCP_RESPONSE_FORMAT') === 'legacy_mirror';

        $projectId = trim((string) ($result['project_id'] ?? ''));
        $resultIndexRevision = (int) ($result['index_revision'] ?? $result['project_revision'] ?? 0);
        $result['workflow_audit'] = [
            'receipt_id' => $receiptId,
            'mcp_called' => true,
            'project_id' => $projectId,
            'index_revision' => $resultIndexRevision,
            'observation_scope' => 'mcp_index_and_skills',
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
                ? ($isError ? 'full_error_mirror' : 'full_result_mirror')
                : 'summary_only',
            'usage_line' => $usageLine,
            'response_prefix' => self::RESPONSE_PREFIX,
            'report_contract' => 'Begin every subsequent user-visible progress update and the final report in this turn with the exact response_prefix. content[0].text repeats usage_line as runtime proof.',
        ];
        $envelope = function (array $body) use ($mirrorFullResultInContent, $tool, $isError, $receiptId, $usageLine): array {
            $contentPayload = $mirrorFullResultInContent
                ? $body
                : $this->legacyToolSummary($tool, $body, $isError, $receiptId);
            return [
                'content' => [
                    ['type' => 'text', 'text' => $usageLine],
                    ['type' => 'text', 'text' => Json::encode($contentPayload)],
                ],
                'structuredContent' => $body,
                'isError' => $isError,
            ];
        };
        if (($result['schema_version'] ?? '') === 'guidance-bundle.v1') {
            $result = ContextResponseBudget::fit($result, (int) $result['token_usage']['budget'], $envelope);
            $digestBody = $result;
            unset($digestBody['_weline_mcp']);
            $result['_weline_mcp']['result_digest'] = Ids::hash(Json::canonical($digestBody));
        }
        return $envelope($result);
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
        foreach (['request_id', 'query_id', 'state', 'region_count', 'impact_risk', 'index_revision'] as $key) {
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

    /**
     * Cursor/Electron STDIO is a Unix socketpair. PHP's default_socket_timeout
     * (60s) makes fgets() return false on idle, which must not be treated as EOF.
     *
     * @param resource $input
     * @param resource $output
     */
    private function prepareStdio($input, $output): void
    {
        // STDOUT is JSON-RPC only; PHP notices/warnings must never land on it.
        @ini_set('display_errors', 'stderr');
        @ini_set('display_startup_errors', '0');
        @ini_set('html_errors', '0');
        @ini_set('log_errors', '1');
        @ini_set('default_socket_timeout', '-1');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (is_resource($input)) {
            @stream_set_blocking($input, true);
            @stream_set_timeout($input, 365 * 24 * 3600);
        }
        if (is_resource($output) && $output !== $input) {
            @stream_set_blocking($output, true);
        }
    }

    /** @param resource $input */
    private function stdioEnded($input): bool
    {
        return !is_resource($input) || feof($input);
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
