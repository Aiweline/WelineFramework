<?php

declare(strict_types=1);

namespace Weline\Framework\Container;

use Weline\Framework\Context;
use Weline\Framework\Manager\ContainerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\ServiceScope;
use Weline\Framework\Runtime\RequestContext;

/**
 * Runtime for deterministic constructor factories emitted by
 * framework:compile. Compiled service creation performs no reflection.
 */
final class CompiledContainer implements ContainerInterface
{
    public const DEFAULT_REGISTRY_FILE = BP . 'generated' . DS . 'framework' . DS . 'container.php';

    /** @var array<string, array{class:class-string, scope:string, init:bool, factory:\Closure}> */
    private array $definitions = [];

    /** @var array<string, object> */
    private array $processInstances = [];

    /** @var array<string, array<string, object>> */
    private array $requestInstances = [];

    /** @var \WeakMap<\Fiber, array{scope_key:string, instances:array<string, object>}>|null */
    private ?\WeakMap $fiberInstances = null;

    /** @var array<string, array<string, object>> */
    private array $mainFiberInstances = [];

    /** @var array<string, true> */
    private array $requestCleanupRegistered = [];

    private bool $allowObjectManagerFallback;

    private string $registryDigest = '';

    public function __construct(
        ?string $registryFile = null,
        ?bool $allowObjectManagerFallback = null,
    ) {
        $this->allowObjectManagerFallback = $allowObjectManagerFallback
            ?? self::developmentFallbackAllowed();
        $this->load($registryFile ?? self::DEFAULT_REGISTRY_FILE);
    }

    public function has(string $id): bool
    {
        if (isset($this->definitions[$id])) {
            return true;
        }

        return $this->allowObjectManagerFallback
            && (\class_exists($id) || \interface_exists($id));
    }

    public function get(string $id): object
    {
        $definition = $this->definitions[$id] ?? null;
        if ($definition === null) {
            return $this->fallback($id, [], true);
        }

        $scope = ServiceScope::from($definition['scope']);
        return match ($scope) {
            ServiceScope::PROCESS => $this->processInstances[$id]
                ??= $this->createFromDefinition($id, $definition),
            ServiceScope::REQUEST => $this->requestScoped($id, $definition),
            ServiceScope::FIBER => $this->fiberScoped($id, $definition),
            ServiceScope::PROTOTYPE => $this->createFromDefinition($id, $definition),
        };
    }

    public function create(string $id, array $arguments = []): object
    {
        $definition = $this->definitions[$id] ?? null;
        if ($definition === null) {
            return $this->fallback($id, $arguments, false);
        }

        return $this->createFromDefinition($id, $definition, $arguments);
    }

    public function reset(ServiceScope $scope): void
    {
        switch ($scope) {
            case ServiceScope::PROCESS:
                $this->processInstances = [];
                break;

            case ServiceScope::REQUEST:
                $this->clearRequestScope($this->requestScopeKey());
                break;

            case ServiceScope::FIBER:
                $fiber = \Fiber::getCurrent();
                if ($fiber instanceof \Fiber && $this->fiberInstances !== null) {
                    unset($this->fiberInstances[$fiber]);
                    break;
                }
                unset($this->mainFiberInstances[$this->requestScopeKey()]);
                break;

            case ServiceScope::PROTOTYPE:
                // Prototype services are never retained.
                break;
        }
    }

    /**
     * @return array{process:int, request_scopes:int, request_instances:int, fiber_scopes:int, fiber_instances:int, prototype_retained:int}
     */
    public function stats(): array
    {
        $requestInstances = 0;
        foreach ($this->requestInstances as $instances) {
            $requestInstances += \count($instances);
        }

        $fiberScopes = \count($this->mainFiberInstances);
        $fiberInstanceCount = 0;
        foreach ($this->mainFiberInstances as $instances) {
            $fiberInstanceCount += \count($instances);
        }
        if ($this->fiberInstances !== null) {
            foreach ($this->fiberInstances as $bucket) {
                $fiberScopes++;
                $fiberInstanceCount += \count($bucket['instances']);
            }
        }

        return [
            'process' => \count($this->processInstances),
            'request_scopes' => \count($this->requestInstances),
            'request_instances' => $requestInstances,
            'fiber_scopes' => $fiberScopes,
            'fiber_instances' => $fiberInstanceCount,
            'prototype_retained' => 0,
        ];
    }

    public function registryDigest(): string
    {
        return $this->registryDigest;
    }

    /**
     * @param array{class:class-string, scope:string, init:bool, factory:\Closure} $definition
     */
    private function requestScoped(string $id, array $definition): object
    {
        $scopeKey = $this->requestScopeKey();
        $this->registerRequestCleanup($scopeKey);
        return $this->requestInstances[$scopeKey][$id]
            ??= $this->createFromDefinition($id, $definition);
    }

    /**
     * @param array{class:class-string, scope:string, init:bool, factory:\Closure} $definition
     */
    private function fiberScoped(string $id, array $definition): object
    {
        $scopeKey = $this->requestScopeKey();
        $this->registerRequestCleanup($scopeKey);
        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return $this->mainFiberInstances[$scopeKey][$id]
                ??= $this->createFromDefinition($id, $definition);
        }

        $this->fiberInstances ??= new \WeakMap();
        $bucket = $this->fiberInstances[$fiber] ?? null;
        if (!\is_array($bucket) || ($bucket['scope_key'] ?? null) !== $scopeKey) {
            $bucket = [
                'scope_key' => $scopeKey,
                'instances' => [],
            ];
        }
        $instances = $bucket['instances'];
        if (!isset($instances[$id])) {
            $instances[$id] = $this->createFromDefinition($id, $definition);
            $bucket['instances'] = $instances;
            $this->fiberInstances[$fiber] = $bucket;
        }

        return $instances[$id];
    }

    /**
     * @param array{class:class-string, scope:string, init:bool, factory:\Closure} $definition
     * @param array<string, mixed> $arguments
     */
    private function createFromDefinition(string $id, array $definition, array $arguments = []): object
    {
        $factory = $definition['factory'];
        $object = $factory($this, $arguments);
        $class = $definition['class'];
        if (!$object instanceof $class) {
            $actualClass = $object::class;
            throw new ContainerException(
                "Compiled service factory {$id} returned {$actualClass}; expected {$class}.",
            );
        }

        if ($definition['init']) {
            $object->__init();
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function fallback(string $id, array $arguments, bool $shared): object
    {
        if (!$this->allowObjectManagerFallback) {
            throw new ContainerException(
                "Service {$id} is not present in the compiled container. Run: php bin/w framework:compile",
            );
        }

        $object = ObjectManager::getInstance($id, $arguments, $shared);
        if (!\is_object($object)) {
            throw new ContainerException("ObjectManager fallback did not return an object for {$id}.");
        }

        return $object;
    }

    private function requestScopeKey(): string
    {
        $requestId = RequestContext::getId();
        if (\is_string($requestId) && $requestId !== '') {
            return 'request:' . $requestId;
        }

        $context = Context::getCurrent();
        return $context instanceof Context
            ? 'context:' . \spl_object_id($context)
            : 'main';
    }

    private function registerRequestCleanup(string $scopeKey): void
    {
        if (isset($this->requestCleanupRegistered[$scopeKey]) || !RequestContext::isInitialized()) {
            return;
        }
        $this->requestCleanupRegistered[$scopeKey] = true;
        RequestContext::onCleanup(function () use ($scopeKey): void {
            $this->clearRequestScope($scopeKey);
        }, 'compiled_container.' . \spl_object_id($this));
    }

    private function clearRequestScope(string $scopeKey): void
    {
        if ($this->fiberInstances !== null) {
            $fibersToClear = [];
            foreach ($this->fiberInstances as $fiber => $bucket) {
                if (($bucket['scope_key'] ?? null) === $scopeKey) {
                    $fibersToClear[] = $fiber;
                }
            }
            foreach ($fibersToClear as $fiber) {
                unset($this->fiberInstances[$fiber]);
            }
        }
        unset(
            $this->requestInstances[$scopeKey],
            $this->mainFiberInstances[$scopeKey],
            $this->requestCleanupRegistered[$scopeKey],
        );
    }

    private function load(string $registryFile): void
    {
        $this->definitions = [];
        $this->registryDigest = '';
        if (!\is_file($registryFile)) {
            if ($this->allowObjectManagerFallback) {
                return;
            }
            throw new ContainerException(
                "Compiled container registry is missing: {$registryFile}. Run: php bin/w framework:compile",
            );
        }

        $digestBeforeLoad = @\hash_file('sha256', $registryFile);
        if (!\is_string($digestBeforeLoad) || \preg_match('/^[a-f0-9]{64}$/D', $digestBeforeLoad) !== 1) {
            if ($this->allowObjectManagerFallback) {
                return;
            }
            throw new ContainerException("Compiled container registry cannot be hashed: {$registryFile}.");
        }

        try {
            $registry = require $registryFile;
        } catch (\Throwable $error) {
            if ($this->allowObjectManagerFallback) {
                return;
            }
            throw new ContainerException(
                "Compiled container registry cannot be loaded: {$registryFile}.",
                0,
                $error,
            );
        }
        $valid = \is_array($registry)
            && ($registry['format'] ?? null) === \Weline\Framework\Compilation\ContainerCompiler::FORMAT_VERSION
            && \is_array($registry['services'] ?? null);
        if (!$valid) {
            if ($this->allowObjectManagerFallback) {
                return;
            }
            throw new ContainerException(
                "Compiled container registry is invalid: {$registryFile}. Re-run: php bin/w framework:compile",
            );
        }

        foreach ($registry['services'] as $id => $definition) {
            if (!\is_string($id)
                || !\is_array($definition)
                || !\is_string($definition['class'] ?? null)
                || !\is_string($definition['scope'] ?? null)
                || !\is_bool($definition['init'] ?? null)
                || !($definition['factory'] ?? null) instanceof \Closure
            ) {
                if ($this->allowObjectManagerFallback) {
                    $this->definitions = [];
                    return;
                }
                throw new ContainerException("Compiled service definition {$id} is invalid.");
            }
            try {
                ServiceScope::from($definition['scope']);
            } catch (\ValueError $error) {
                if ($this->allowObjectManagerFallback) {
                    $this->definitions = [];
                    return;
                }
                throw new ContainerException("Compiled service {$id} has an invalid scope.", 0, $error);
            }
            $this->definitions[$id] = $definition;
        }

        \clearstatcache(true, $registryFile);
        $digestAfterLoad = @\hash_file('sha256', $registryFile);
        if (!\is_string($digestAfterLoad)
            || \preg_match('/^[a-f0-9]{64}$/D', $digestAfterLoad) !== 1
            || !\hash_equals($digestBeforeLoad, $digestAfterLoad)
        ) {
            $this->definitions = [];
            if ($this->allowObjectManagerFallback) {
                return;
            }
            throw new ContainerException("Compiled container registry changed while loading: {$registryFile}.");
        }
        $this->registryDigest = $digestAfterLoad;
    }

    private static function developmentFallbackAllowed(): bool
    {
        if (\defined('WLS_MODE') && WLS_MODE) {
            return false;
        }
        if (\defined('PROD') && PROD) {
            return false;
        }
        return !\defined('DEV') || DEV;
    }
}
