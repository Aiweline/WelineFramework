<?php

declare(strict_types=1);

namespace LearningMcp;

use Throwable;

final class McpServer
{
    private const LATEST_PROTOCOL = '2025-11-25';
    private const SUPPORTED_PROTOCOLS = ['2024-11-05', '2025-03-26', '2025-06-18', '2025-11-25'];

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
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'weline-project-intelligence',
                'title' => 'Weline Project Intelligence MCP',
                'version' => ToolService::VERSION,
            ],
            'instructions' => ToolService::INSTRUCTIONS,
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

            return [
                'content' => [['type' => 'text', 'text' => Json::encode($result, true)]],
                'structuredContent' => $result,
                'isError' => false,
            ];
        } catch (ToolException $exception) {
            $result = $exception->envelope();

            return [
                'content' => [['type' => 'text', 'text' => Json::encode($result, true)]],
                'structuredContent' => $result,
                'isError' => true,
            ];
        } catch (Throwable $exception) {
            [$message] = Redactor::string($exception->getMessage());
            $toolError = new ToolException('INTERNAL_ERROR', Text::truncate($message, 500), false);
            $result = $toolError->envelope();

            return [
                'content' => [['type' => 'text', 'text' => Json::encode($result, true)]],
                'structuredContent' => $result,
                'isError' => true,
            ];
        }
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
