<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Service\Query\Attribute\BinQueryCache;
use Weline\Framework\Service\Query\Attribute\BinQueryExample;
use Weline\Framework\Service\Query\Attribute\BinQueryOperation;
use Weline\Framework\Service\Query\Attribute\BinQueryParam;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class BinQueryDescriptorAttributeResolver
{
    /**
     * @param array<string, mixed> $descriptor
     * @return array<string, mixed>
     */
    public function merge(QueryProviderInterface $provider, array $descriptor): array
    {
        try {
            $reflection = new \ReflectionObject($provider);
        } catch (\ReflectionException) {
            return $descriptor;
        }

        $operations = $this->normalizeOperations($descriptor['operations'] ?? []);
        $order = \array_keys($operations);

        foreach ($reflection->getMethods() as $method) {
            $operationAttributes = $method->getAttributes(BinQueryOperation::class);
            if ($operationAttributes === []) {
                continue;
            }

            /** @var BinQueryOperation $operationAttribute */
            $operationAttribute = $operationAttributes[0]->newInstance();
            $operationName = \trim($operationAttribute->name) !== ''
                ? \trim($operationAttribute->name)
                : $method->getName();
            if ($operationName === '') {
                continue;
            }

            $existing = $operations[$operationName] ?? ['name' => $operationName];
            if (!isset($operations[$operationName])) {
                $order[] = $operationName;
            }

            $attributeDescriptor = $operationAttribute->toDescriptor();
            $attributeDescriptor['name'] = $operationName;
            $merged = \array_replace($existing, $attributeDescriptor);

            $merged['params'] = $this->mergeParams(
                $existing['params'] ?? [],
                $this->collectParamDescriptors($method)
            );

            $cache = $this->collectCacheDescriptor($method, $merged);
            if ($cache !== null) {
                $cacheKeyParams = $this->collectCacheKeyParams($merged['params'] ?? []);
                if (($cache['key_params'] ?? []) === [] && $cacheKeyParams !== []) {
                    $cache['key_params'] = $cacheKeyParams;
                }
                $merged['cache'] = $cache;
            }

            $examples = $this->collectExampleDescriptors($method);
            if ($examples !== []) {
                $existingExamples = $existing['examples'] ?? [];
                $merged['examples'] = \array_values(\array_merge(
                    \is_array($existingExamples) ? $existingExamples : [],
                    $examples
                ));
            }

            $operations[$operationName] = $merged;
        }

        $descriptor['operations'] = [];
        foreach ($order as $name) {
            if (isset($operations[$name])) {
                $descriptor['operations'][] = $operations[$name];
            }
        }

        return $descriptor;
    }

    /**
     * @param mixed $operations
     * @return array<string, array<string, mixed>>
     */
    private function normalizeOperations(mixed $operations): array
    {
        if (!\is_array($operations)) {
            return [];
        }

        $normalized = [];
        foreach ($operations as $operation) {
            if (!\is_array($operation)) {
                continue;
            }
            $name = (string)($operation['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $normalized[$name] = $operation;
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectParamDescriptors(\ReflectionMethod $method): array
    {
        $params = [];
        foreach ($method->getAttributes(BinQueryParam::class) as $attribute) {
            /** @var BinQueryParam $param */
            $param = $attribute->newInstance();
            if (\trim($param->name) === '') {
                continue;
            }
            $params[] = $param->toDescriptor();
        }

        return $params;
    }

    /**
     * @param mixed $existingParams
     * @param array<int, array<string, mixed>> $attributeParams
     * @return array<int, array<string, mixed>>
     */
    private function mergeParams(mixed $existingParams, array $attributeParams): array
    {
        $params = [];
        $order = [];

        if (\is_array($existingParams)) {
            foreach ($existingParams as $key => $param) {
                if (!\is_array($param)) {
                    continue;
                }
                $name = \is_string($key) ? $key : (string)($param['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $param['name'] = $name;
                $params[$name] = $param;
                $order[] = $name;
            }
        }

        foreach ($attributeParams as $param) {
            $name = (string)($param['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (!isset($params[$name])) {
                $order[] = $name;
            }
            $params[$name] = \array_replace($params[$name] ?? [], $param);
        }

        $merged = [];
        foreach (\array_values(\array_unique($order)) as $name) {
            if (isset($params[$name])) {
                $merged[] = $params[$name];
            }
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>|null
     */
    private function collectCacheDescriptor(\ReflectionMethod $method, array $operation): ?array
    {
        $attributes = $method->getAttributes(BinQueryCache::class);
        if ($attributes === []) {
            return null;
        }

        if (($operation['external'] ?? false) !== true || (string)($operation['mode'] ?? '') !== 'read') {
            return null;
        }

        /** @var BinQueryCache $cache */
        $cache = $attributes[0]->newInstance();
        if ($cache->visibility !== 'public') {
            return null;
        }

        return $cache->toDescriptor();
    }

    /**
     * @param mixed $params
     * @return array<int, string>
     */
    private function collectCacheKeyParams(mixed $params): array
    {
        if (!\is_array($params)) {
            return [];
        }

        $keys = [];
        foreach ($params as $param) {
            if (!\is_array($param) || ($param['cache_key'] ?? false) !== true) {
                continue;
            }
            $name = (string)($param['name'] ?? '');
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        return \array_values(\array_unique($keys));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectExampleDescriptors(\ReflectionMethod $method): array
    {
        $examples = [];
        foreach ($method->getAttributes(BinQueryExample::class) as $attribute) {
            /** @var BinQueryExample $example */
            $example = $attribute->newInstance();
            $examples[] = $example->toDescriptor();
        }

        return $examples;
    }
}
