<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

final class FrontendQueryGateway
{
    private const GRAPH_MAX_COST = 20;
    private const GRAPH_MAX_OPERATIONS = 10;

    public function __construct(
        private readonly FrameworkQueryService $queryService,
        private readonly QueryProviderRegistry $registry,
        private readonly FrontendWorkerSessionService $workerSessionService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(array $payload, string $capability): mixed
    {
        $type = (string)($payload['type'] ?? 'call');

        return match ($type) {
            'call' => $this->executeCall($payload, $capability),
            'graph' => $this->executeGraph($payload, $capability),
            'stream-ticket' => $this->createStreamTicket($payload, $capability),
            default => throw new FrontendQueryException('protocol_error', 'Unsupported worker query type.', 400),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function executeCall(array $payload, string $capability): mixed
    {
        $provider = (string)($payload['provider'] ?? '');
        $operation = (string)($payload['operation'] ?? '');
        $params = $this->normalizeParams($payload['params'] ?? []);
        $descriptor = $this->requireOperation($provider, $operation);

        $expectedCapability = $provider . '.' . $operation;
        if ($capability !== $expectedCapability) {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not match operation.', 403);
        }

        $mode = (string)($descriptor['mode'] ?? '');
        if ($mode === 'stream') {
            throw new FrontendQueryException('capability_denied', 'Stream operation requires Weline.Api.stream().', 403);
        }

        $params = $this->validateParams($params, $descriptor);
        return $this->queryService->execute($provider, $operation, $params, 'frontend_worker');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeGraph(array $payload, string $capability): array
    {
        if ($capability !== 'graph') {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not allow graph.', 403);
        }

        $operations = $this->normalizeGraphOperations($payload['graph'] ?? $payload['operations'] ?? []);
        if ($operations === []) {
            throw new FrontendQueryException('validation_error', 'Graph operations cannot be empty.', 422);
        }
        if (\count($operations) > self::GRAPH_MAX_OPERATIONS) {
            throw new FrontendQueryException('validation_error', 'Graph operation count exceeds limit.', 422);
        }

        $totalCost = 0;
        $result = [];
        foreach ($operations as $node) {
            $provider = (string)($node['provider'] ?? '');
            $operation = (string)($node['operation'] ?? '');
            $alias = (string)($node['as'] ?? ($provider . '.' . $operation));
            $params = $this->normalizeParams($node['params'] ?? []);
            $descriptor = $this->requireOperation($provider, $operation);

            if (($descriptor['mode'] ?? '') !== 'read' || ($descriptor['graph'] ?? false) !== true) {
                throw new FrontendQueryException('capability_denied', 'Only read graph operations are allowed.', 403);
            }

            $totalCost += max(1, (int)($descriptor['cost'] ?? 1));
            if ($totalCost > self::GRAPH_MAX_COST) {
                throw new FrontendQueryException('capability_denied', 'Graph cost exceeds limit.', 403);
            }

            $result[$alias] = $this->queryService->execute(
                $provider,
                $operation,
                $this->validateParams($params, $descriptor),
                'frontend_worker'
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createStreamTicket(array $payload, string $capability): array
    {
        if ($capability !== 'stream-ticket') {
            throw new FrontendQueryException('capability_denied', 'Worker capability does not allow stream tickets.', 403);
        }

        $channel = (string)($payload['channel'] ?? '');
        if (!\preg_match('/^[a-z][a-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $channel)) {
            throw new FrontendQueryException('validation_error', 'Invalid stream channel.', 422);
        }

        [$provider, $operation] = \explode('.', $channel, 2);
        $descriptor = $this->requireOperation($provider, $operation);
        if (($descriptor['mode'] ?? '') !== 'stream') {
            throw new FrontendQueryException('capability_denied', 'Operation is not a frontend stream.', 403);
        }

        $params = $this->validateParams($this->normalizeParams($payload['params'] ?? []), $descriptor);
        return $this->workerSessionService->createStreamTicket($channel, $params);
    }

    /**
     * @param array{channel:string, params:array<string, mixed>, expires_at:int} $ticket
     * @return iterable<int|string, mixed>
     */
    public function executeStream(array $ticket): iterable
    {
        $channel = (string)($ticket['channel'] ?? '');
        if (!\preg_match('/^[a-z][a-z0-9_]*\.[A-Za-z][A-Za-z0-9_]*$/', $channel)) {
            throw new FrontendQueryException('validation_error', 'Invalid stream channel.', 422);
        }

        [$provider, $operation] = \explode('.', $channel, 2);
        $descriptor = $this->requireOperation($provider, $operation);
        if (($descriptor['mode'] ?? '') !== 'stream') {
            throw new FrontendQueryException('capability_denied', 'Operation is not a frontend stream.', 403);
        }

        $params = $this->validateParams($this->normalizeParams($ticket['params'] ?? []), $descriptor);
        $result = $this->queryService->execute($provider, $operation, $params, 'frontend_worker_stream');

        if ($result instanceof \Traversable) {
            yield from $result;
            return;
        }

        if (\is_array($result) && \array_is_list($result)) {
            yield from $result;
            return;
        }

        yield [
            'event' => 'message',
            'data' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOperation(string $provider, string $operation): array
    {
        if ($provider === '' || $operation === '') {
            throw new FrontendQueryException('validation_error', 'Provider and operation are required.', 422);
        }
        if ($provider === 'framework' || $provider === 'crud') {
            throw new FrontendQueryException('capability_denied', 'Provider is not exposed to frontend worker API.', 403);
        }

        foreach ($this->registry->getAllDescriptors() as $providerDescriptor) {
            if (($providerDescriptor['provider'] ?? '') !== $provider) {
                continue;
            }

            foreach (($providerDescriptor['operations'] ?? []) as $operationDescriptor) {
                if (($operationDescriptor['name'] ?? '') !== $operation) {
                    continue;
                }
                if (($operationDescriptor['frontend'] ?? false) !== true) {
                    throw new FrontendQueryException('capability_denied', 'Operation is not exposed to frontend worker API.', 403);
                }
                if (!isset($operationDescriptor['mode']) || (string)$operationDescriptor['mode'] === '') {
                    throw new FrontendQueryException('capability_denied', 'Frontend operation is missing explicit mode.', 403);
                }

                return $operationDescriptor;
            }
        }

        throw new FrontendQueryException('capability_denied', 'Frontend worker operation is not allowed.', 403);
    }

    /**
     * @param mixed $params
     * @return array<string, mixed>
     */
    private function normalizeParams(mixed $params): array
    {
        if ($params === null) {
            return [];
        }
        if (!\is_array($params)) {
            throw new FrontendQueryException('validation_error', 'Operation params must be a map.', 422);
        }
        if (\array_is_list($params) && $params !== []) {
            throw new FrontendQueryException('validation_error', 'Operation params must be a map.', 422);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $operationDescriptor
     * @return array<string, mixed>
     */
    private function validateParams(array $params, array $operationDescriptor): array
    {
        $rules = $this->normalizeParamRules($operationDescriptor['params'] ?? []);
        foreach ($params as $name => $_value) {
            if (!\array_key_exists((string)$name, $rules)) {
                throw new FrontendQueryException('validation_error', 'Unknown frontend worker param: ' . (string)$name, 422);
            }
        }

        foreach ($rules as $name => $rule) {
            $required = (bool)($rule['required'] ?? false);
            if (!\array_key_exists($name, $params)) {
                if ($required) {
                    throw new FrontendQueryException('validation_error', 'Missing required param: ' . $name, 422);
                }
                continue;
            }

            $this->validateParamValue($name, $params[$name], $rule);
        }

        return $params;
    }

    /**
     * @param mixed $paramsDescriptor
     * @return array<string, array<string, mixed>>
     */
    private function normalizeParamRules(mixed $paramsDescriptor): array
    {
        if (!\is_array($paramsDescriptor)) {
            return [];
        }

        $rules = [];
        foreach ($paramsDescriptor as $key => $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $name = \is_string($key) ? $key : (string)($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $rule['name'] = $name;
            $rules[$name] = $rule;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function validateParamValue(string $name, mixed $value, array $rule): void
    {
        if ($value === null && ($rule['nullable'] ?? false) === true) {
            return;
        }

        $type = \strtolower((string)($rule['type'] ?? 'mixed'));
        $valid = match ($type) {
            'int', 'integer' => \is_int($value),
            'float', 'double', 'number' => \is_int($value) || \is_float($value),
            'string' => \is_string($value),
            'bool', 'boolean' => \is_bool($value),
            'list' => \is_array($value) && \array_is_list($value),
            'array' => \is_array($value),
            'map', 'object' => \is_array($value) && !\array_is_list($value),
            'mixed' => true,
            default => true,
        };
        if (!$valid) {
            throw new FrontendQueryException('validation_error', 'Invalid param type: ' . $name, 422);
        }

        if (\is_int($value) || \is_float($value)) {
            if (isset($rule['max']) && $value > (float)$rule['max']) {
                throw new FrontendQueryException('validation_error', 'Param exceeds max: ' . $name, 422);
            }
            if (isset($rule['min']) && $value < (float)$rule['min']) {
                throw new FrontendQueryException('validation_error', 'Param below min: ' . $name, 422);
            }
        }

        if (\is_string($value) && isset($rule['max_length']) && \strlen($value) > (int)$rule['max_length']) {
            throw new FrontendQueryException('validation_error', 'Param string exceeds max length: ' . $name, 422);
        }

        if (\is_array($value) && isset($rule['max_items']) && \count($value) > (int)$rule['max_items']) {
            throw new FrontendQueryException('validation_error', 'Param list exceeds max items: ' . $name, 422);
        }
    }

    /**
     * @param mixed $graph
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGraphOperations(mixed $graph): array
    {
        if (!\is_array($graph)) {
            return [];
        }
        if (\array_is_list($graph)) {
            return \array_values(\array_filter($graph, static fn(mixed $node): bool => \is_array($node)));
        }

        $operations = [];
        foreach ($graph as $provider => $providerOperations) {
            if (!\is_array($providerOperations)) {
                continue;
            }
            foreach ($providerOperations as $operation => $params) {
                $operations[] = [
                    'provider' => (string)$provider,
                    'operation' => (string)$operation,
                    'params' => \is_array($params) ? $params : [],
                    'as' => (string)$provider . '.' . (string)$operation,
                ];
            }
        }

        return $operations;
    }
}
