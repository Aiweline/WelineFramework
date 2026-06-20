<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Api\Data\ApiAppTokenContext;
use Weline\Api\Service\ApiAppTokenService;
use Weline\Framework\Manager\ObjectManager;

final class BinQueryGateway
{
    public const PROTOCOL = 'binquery-v1';

    private const GRAPH_MAX_COST = 20;
    private const GRAPH_MAX_OPERATIONS = 10;
    private const DEFAULT_AREA = 'frontend';

    private ?ApiAppTokenService $apiAppTokenService;

    public function __construct(
        private readonly FrameworkQueryService $queryService,
        private readonly QueryProviderRegistry $registry,
        private readonly BinQueryCachePolicy $cachePolicy,
        ?ApiAppTokenService $apiAppTokenService = null
    ) {
        $this->apiAppTokenService = $apiAppTokenService;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data:mixed,status:int,headers:array<string, string>}
     */
    public function execute(array $payload, string $apiKey, string $cacheMarker = ''): array
    {
        $context = $this->authenticate($apiKey);
        $type = (string)($payload['type'] ?? '');
        $area = $this->normalizeArea($payload['area'] ?? $payload['context']['area'] ?? self::DEFAULT_AREA);

        return match ($type) {
            'connect' => [
                'data' => $this->connect($area, $context),
                'status' => 200,
                'headers' => $this->cachePolicy->noStoreHeaders('connect'),
            ],
            'query' => [
                'data' => $this->query($payload, $area, $context),
                'status' => 200,
                'headers' => $this->cachePolicy->noStoreHeaders('query'),
            ],
            'call' => $this->call($payload, $area, $context, $cacheMarker),
            'graph' => [
                'data' => $this->graph($payload, $area, $context),
                'status' => 200,
                'headers' => $this->cachePolicy->noStoreHeaders('graph'),
            ],
            default => throw new FrontendQueryException('protocol_error', 'Unsupported BinQuery request type.', 400),
        };
    }

    private function authenticate(string $apiKey): ApiAppTokenContext
    {
        $token = \trim($apiKey);
        if ($token === '') {
            throw new FrontendQueryException('auth_error', 'Missing BinQuery API key.', 401);
        }
        if (\str_starts_with(\strtolower($token), 'bearer ')) {
            $token = \trim(\substr($token, 7));
        }

        try {
            $context = $this->getApiAppTokenService()->resolveAccessToken($token);
        } catch (\Throwable $throwable) {
            throw new FrontendQueryException('auth_error', 'Unable to validate BinQuery API key.', 401, $throwable);
        }
        if (!$context instanceof ApiAppTokenContext) {
            throw new FrontendQueryException('auth_error', 'BinQuery API key is invalid or expired.', 401);
        }
        if (!$this->hasBinQueryScope($context, 'read')) {
            throw new FrontendQueryException('scope_denied', 'API key has no BinQuery scope.', 403);
        }

        return $context;
    }

    private function getApiAppTokenService(): ApiAppTokenService
    {
        if (!$this->apiAppTokenService instanceof ApiAppTokenService) {
            $this->apiAppTokenService = ObjectManager::getInstance(ApiAppTokenService::class);
        }

        return $this->apiAppTokenService;
    }

    private function connect(string $area, ApiAppTokenContext $context): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'binary' => [
                'magic' => 'WQB1',
                'version' => 1,
                'content_type' => \Weline\Framework\Binary\WelineBinaryCodec::CONTENT_TYPE,
            ],
            'area' => $area,
            'default_area' => self::DEFAULT_AREA,
            'capabilities' => ['connect', 'query', 'call', 'graph', 'help', 'docs', 'exists'],
            'cache' => [
                'mode' => 'auto',
                'marker_param' => BinQueryCachePolicy::MARKER_PARAM,
            ],
            'scope_count' => \count($context->getAccessSources()),
            'provider_count' => \count($this->externalDescriptors($area)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function query(array $payload, string $area, ApiAppTokenContext $context): mixed
    {
        $this->assertScope($context, 'read');
        $what = (string)($payload['what'] ?? $payload['query'] ?? 'providers');
        $provider = (string)($payload['provider'] ?? $payload['resource'] ?? '');
        $operation = (string)($payload['operation'] ?? '');

        return match ($what) {
            'providers', 'resources', 'help' => $this->providerSummaries($area),
            'provider', 'resource' => $this->providerDescriptor($provider, $area),
            'operations' => $this->providerOperations($provider, $area),
            'operation', 'docs' => $this->operationDescriptor($provider, $operation, $area),
            'exists' => $this->exists($provider, $operation, $area),
            default => throw new FrontendQueryException('validation_error', 'Unsupported BinQuery query target.', 422),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{data:mixed,status:int,headers:array<string, string>}
     */
    private function call(array $payload, string $area, ApiAppTokenContext $context, string $cacheMarker): array
    {
        $provider = (string)($payload['provider'] ?? '');
        $operation = (string)($payload['operation'] ?? '');
        $params = $this->normalizeParams($payload['params'] ?? []);
        $descriptor = $this->requireOperation($provider, $operation, $area);
        $this->assertScope($context, (string)($descriptor['mode'] ?? 'read'));

        $params = $this->validateParams($params, $descriptor);
        $result = $this->queryService->execute($provider, $operation, $params, $area);

        $headers = $this->cachePolicy->noStoreHeaders('no-marker');
        if ($this->cachePolicy->isCacheableOperation($descriptor)) {
            $expectedMarker = $this->cachePolicy->buildMarker($area, $provider, $operation, $params, $descriptor);
            $headers = \hash_equals($expectedMarker, $cacheMarker)
                ? $this->cachePolicy->cacheHeaders($descriptor, $expectedMarker)
                : $this->cachePolicy->noStoreHeaders($cacheMarker === '' ? 'missing-marker' : 'invalid-marker');
        }

        return [
            'data' => $result,
            'status' => 200,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function graph(array $payload, string $area, ApiAppTokenContext $context): array
    {
        $this->assertScope($context, 'read');
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
            $descriptor = $this->requireOperation($provider, $operation, $area);

            if (($descriptor['mode'] ?? '') !== 'read' || ($descriptor['graph'] ?? false) !== true) {
                throw new FrontendQueryException('capability_denied', 'Only read graph operations are allowed.', 403);
            }

            $totalCost += \max(1, (int)($descriptor['cost'] ?? 1));
            if ($totalCost > self::GRAPH_MAX_COST) {
                throw new FrontendQueryException('capability_denied', 'Graph cost exceeds limit.', 403);
            }

            $params = $this->validateParams($this->normalizeParams($node['params'] ?? []), $descriptor);
            $result[$alias] = $this->queryService->execute($provider, $operation, $params, $area);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providerSummaries(string $area): array
    {
        return \array_map(static fn(array $descriptor): array => [
            'provider' => (string)($descriptor['provider'] ?? ''),
            'name' => (string)($descriptor['name'] ?? ''),
            'description' => (string)($descriptor['description'] ?? ''),
            'module' => (string)($descriptor['module'] ?? ''),
            'operation_count' => \count($descriptor['operations'] ?? []),
        ], $this->externalDescriptors($area));
    }

    /**
     * @return array<string, mixed>
     */
    private function providerDescriptor(string $provider, string $area): array
    {
        if ($provider === '') {
            throw new FrontendQueryException('validation_error', 'Provider is required.', 422);
        }
        foreach ($this->externalDescriptors($area) as $descriptor) {
            if ((string)($descriptor['provider'] ?? '') === $provider) {
                return $descriptor;
            }
        }

        throw new FrontendQueryException('not_found', 'Provider does not exist or is not external.', 404);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function providerOperations(string $provider, string $area): array
    {
        return $this->providerDescriptor($provider, $area)['operations'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function operationDescriptor(string $provider, string $operation, string $area): array
    {
        return $this->requireOperation($provider, $operation, $area);
    }

    private function exists(string $provider, string $operation, string $area): array
    {
        if ($provider === '') {
            return ['provider' => false, 'operation' => false];
        }

        try {
            $descriptor = $this->providerDescriptor($provider, $area);
        } catch (FrontendQueryException) {
            return ['provider' => false, 'operation' => false];
        }

        if ($operation === '') {
            return ['provider' => true, 'operation' => false];
        }

        foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
            if (\is_array($operationDescriptor) && (string)($operationDescriptor['name'] ?? '') === $operation) {
                return ['provider' => true, 'operation' => true];
            }
        }

        return ['provider' => true, 'operation' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOperation(string $provider, string $operation, string $area): array
    {
        if ($provider === '' || $operation === '') {
            throw new FrontendQueryException('validation_error', 'Provider and operation are required.', 422);
        }

        foreach ($this->externalDescriptors($area) as $descriptor) {
            if ((string)($descriptor['provider'] ?? '') !== $provider) {
                continue;
            }
            foreach (($descriptor['operations'] ?? []) as $operationDescriptor) {
                if (\is_array($operationDescriptor) && (string)($operationDescriptor['name'] ?? '') === $operation) {
                    return $operationDescriptor;
                }
            }
        }

        throw new FrontendQueryException('capability_denied', 'BinQuery operation is not external.', 403);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function externalDescriptors(string $area): array
    {
        $descriptors = [];
        foreach ($this->registry->getAllDescriptors() as $descriptor) {
            if (!\is_array($descriptor)) {
                continue;
            }

            $operations = [];
            foreach (($descriptor['operations'] ?? []) as $operation) {
                if (!\is_array($operation) || !$this->isOperationExternalForArea($operation, $area)) {
                    continue;
                }
                $operations[] = $operation;
            }
            if ($operations === []) {
                continue;
            }

            $descriptor['operations'] = $operations;
            $descriptor['operation_count'] = \count($operations);
            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function isOperationExternalForArea(array $operation, string $area): bool
    {
        if (($operation['external'] ?? false) !== true) {
            return false;
        }

        if ($area === 'frontend') {
            return ($operation['frontend'] ?? false) === true;
        }

        if ($area === 'backend') {
            return ($operation['backend'] ?? false) === true;
        }

        return false;
    }

    private function normalizeArea(mixed $area): string
    {
        $normalized = \strtolower(\trim((string)$area));
        if ($normalized === '') {
            return self::DEFAULT_AREA;
        }
        if (!\in_array($normalized, ['frontend', 'backend'], true)) {
            throw new FrontendQueryException('validation_error', 'Unsupported BinQuery area.', 422);
        }

        return $normalized;
    }

    private function assertScope(ApiAppTokenContext $context, string $requiredMode): void
    {
        if (!$this->hasBinQueryScope($context, $requiredMode)) {
            throw new FrontendQueryException('scope_denied', 'API key scope does not allow this BinQuery operation.', 403);
        }
    }

    private function hasBinQueryScope(ApiAppTokenContext $context, string $requiredMode): bool
    {
        $requiresEdit = \strtolower($requiredMode) !== 'read';
        foreach ($context->getAccessSources() as $source) {
            if (!\is_array($source)) {
                continue;
            }

            $sourceId = \strtolower((string)($source['source_id'] ?? ''));
            $route = \trim((string)($source['route'] ?? ''), '/');
            if (
                $sourceId === '*'
                || $sourceId === 'weline_framework::*'
                || \str_contains($sourceId, 'binquery')
                || $route === 'bin/query'
            ) {
                $mode = \strtolower((string)($source['access_mode'] ?? 'edit'));
                if (!$requiresEdit || $mode !== 'read') {
                    return true;
                }
            }
        }

        return false;
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
        if (!\is_array($params) || (\array_is_list($params) && $params !== [])) {
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
                throw new FrontendQueryException('validation_error', 'Unknown BinQuery param: ' . (string)$name, 422);
            }
        }

        foreach ($rules as $name => $rule) {
            if (!\array_key_exists($name, $params)) {
                if (($rule['required'] ?? false) === true) {
                    throw new FrontendQueryException('validation_error', 'Missing required param: ' . $name, 422);
                }
                if (\array_key_exists('default', $rule)) {
                    $params[$name] = $rule['default'];
                }
                continue;
            }

            $params[$name] = $this->normalizeParamValue($params[$name], $rule);
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
    private function normalizeParamValue(mixed $value, array $rule): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        $type = \strtolower((string)($rule['type'] ?? 'mixed'));
        $trimmed = \trim($value);
        if (($type === 'int' || $type === 'integer') && \preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int)$trimmed;
        }
        if (\in_array($type, ['float', 'double', 'number'], true) && \is_numeric($trimmed)) {
            return (float)$trimmed;
        }
        if ($type === 'bool' || $type === 'boolean') {
            $normalized = \strtolower($trimmed);
            if (\in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $value;
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
