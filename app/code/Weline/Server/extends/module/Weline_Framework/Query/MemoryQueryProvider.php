<?php

declare(strict_types=1);

namespace Weline\Server\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Server\Service\MemoryStateFacade;

class MemoryQueryProvider implements QueryProviderInterface
{
    private ?MemoryStateFacade $memoryFacade = null;

    public function __construct(?MemoryStateFacade $memoryFacade = null)
    {
        $this->memoryFacade = $memoryFacade;
    }

    public function getProviderName(): string
    {
        return 'memory';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $namespace = (string) ($params['namespace'] ?? $params['ns'] ?? '');
        $key = (string) ($params['key'] ?? '');

        return match ($operation) {
            'get' => $this->memoryFacade()->get($namespace, $key),
            'set' => $this->memoryFacade()->set($namespace, $key, $params['value'] ?? null, (int) ($params['ttl'] ?? 0)),
            'delete' => $this->memoryFacade()->delete($namespace, $key),
            'exists' => $this->memoryFacade()->exists($namespace, $key),
            'touch' => $this->memoryFacade()->touch($namespace, $key, (int) ($params['ttl'] ?? 0)),
            'mget' => $this->memoryFacade()->mget($namespace, \is_array($params['keys'] ?? null) ? $params['keys'] : []),
            'mset' => $this->memoryFacade()->mset($namespace, \is_array($params['data'] ?? null) ? $params['data'] : [], (int) ($params['ttl'] ?? 0)),
            'clearNamespace' => $this->memoryFacade()->clearNamespace($namespace),
            'incr' => $this->memoryFacade()->incr($namespace, $key, (int) ($params['delta'] ?? 1), (int) ($params['ttl'] ?? 0)),
            'decr' => $this->memoryFacade()->decr($namespace, $key, (int) ($params['delta'] ?? 1), (int) ($params['ttl'] ?? 0)),
            'append' => $this->memoryFacade()->append($namespace, $key, $params['value'] ?? null, (int) ($params['ttl'] ?? 0)),
            'cas' => $this->memoryFacade()->cas($namespace, $key, $params['expected'] ?? null, $params['value'] ?? null, (int) ($params['ttl'] ?? 0)),
            'list' => $this->memoryFacade()->list([
                'filter' => \is_array($params['filter'] ?? null) ? $params['filter'] : [],
                'limit' => (int) ($params['limit'] ?? 50),
            ]),
            'getAll' => $this->memoryFacade()->getAll($namespace),
            'gc' => $this->memoryFacade()->gc((int) ($params['max_lifetime'] ?? 3600)),
            'persist' => $this->memoryFacade()->persist(),
            'ping' => $this->memoryFacade()->ping(),
            'stats' => $this->memoryFacade()->getStats(),
            'runtime' => $this->memoryFacade()->getRuntime(),
            default => throw new \InvalidArgumentException(
                (string) __('Memory query provider unsupported operation: %{1}', $operation)
            ),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'memory',
            'name' => __('Memory Query'),
            'description' => __('Unified shared memory facade query entry'),
            'module' => 'Weline_Server',
            'operations' => [
                ['name' => 'get', 'description' => __('Get namespace key')],
                ['name' => 'set', 'description' => __('Set namespace key')],
                ['name' => 'delete', 'description' => __('Delete namespace key')],
                ['name' => 'exists', 'description' => __('Check namespace key exists')],
                ['name' => 'touch', 'description' => __('Touch namespace key ttl')],
                ['name' => 'mget', 'description' => __('Multi get')],
                ['name' => 'mset', 'description' => __('Multi set')],
                ['name' => 'clearNamespace', 'description' => __('Clear namespace')],
                ['name' => 'incr', 'description' => __('Increment value')],
                ['name' => 'decr', 'description' => __('Decrement value')],
                ['name' => 'append', 'description' => __('Append value')],
                ['name' => 'cas', 'description' => __('Compare and set')],
                ['name' => 'list', 'description' => __('List namespaces')],
                ['name' => 'getAll', 'description' => __('Get all namespace data')],
                ['name' => 'gc', 'description' => __('Run memory gc')],
                ['name' => 'persist', 'description' => __('Persist memory store')],
                ['name' => 'ping', 'description' => __('Ping memory service')],
                ['name' => 'stats', 'description' => __('Get memory stats')],
                ['name' => 'runtime', 'description' => __('Get memory runtime')],
            ],
        ];
    }

    private function memoryFacade(): MemoryStateFacade
    {
        if ($this->memoryFacade === null) {
            $this->memoryFacade = new MemoryStateFacade();
        }

        return $this->memoryFacade;
    }
}
