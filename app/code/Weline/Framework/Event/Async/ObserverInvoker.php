<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Api\Event\AsyncObserverInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Async\Exception\NonRetryableAsyncEventException;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventRegistryInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Registry\Service\RegistryModulePresence;

final class ObserverInvoker
{
    public function __construct(private readonly EventRegistryInterface $registry)
    {
    }

    /** @return array<string,mixed> */
    public function resolve(string $eventName, string $observerKey, int $schemaVersion): array
    {
        $registry = $this->registry->getRegistry();
        $event = $registry['events'][$eventName] ?? null;
        if (!is_array($event)) {
            throw new NonRetryableAsyncEventException('observer_event_missing', __('异步事件已从当前注册表删除'));
        }
        foreach ((array)($event['observers'] ?? []) as $observer) {
            if (!is_array($observer) || (string)($observer['observer_key'] ?? '') !== $observerKey) {
                continue;
            }
            if ((string)($observer['disabled'] ?? 'false') === 'true'
                || (string)($observer['delivery'] ?? 'sync') !== 'async') {
                throw new NonRetryableAsyncEventException('observer_disabled', __('异步 Observer 已禁用或不再声明为 async'));
            }
            $module = (string)($observer['module'] ?? '');
            if ($module === '' || !RegistryModulePresence::isActivePresent($module, Env::getInstance())) {
                throw new NonRetryableAsyncEventException('observer_module_inactive', __('异步 Observer 所属模块未启用'));
            }
            $instance = ltrim(trim((string)($observer['instance'] ?? '')), '\\');
            if ($instance === '' || !RegistryModulePresence::classExists($instance)) {
                throw new NonRetryableAsyncEventException('observer_missing', __('异步 Observer 实现不存在'));
            }
            $service = ObjectManager::getInstance($instance);
            if (!$service instanceof AsyncObserverInterface
                || !$service->supportsAsyncEvent($eventName, $schemaVersion)) {
                throw new NonRetryableAsyncEventException(
                    'observer_schema_unsupported',
                    __('异步 Observer 不支持当前事件 schema'),
                );
            }
            $observer['_resolved_service'] = $service;
            return $observer;
        }
        throw new NonRetryableAsyncEventException('observer_missing', __('异步 Observer 已从当前注册表删除'));
    }

    /** @param array<string,mixed> $observer */
    public function invoke(string $eventName, mixed $data, array $observer): void
    {
        $service = $observer['_resolved_service'] ?? null;
        if (!$service instanceof AsyncObserverInterface) {
            throw new NonRetryableAsyncEventException('observer_contract_mismatch', __('异步 Observer 契约无效'));
        }
        unset($observer['_resolved_service']);
        $event = (new Event([
            'data' => $data,
            'observers' => [],
        ]))->setName($eventName);
        $service->execute($event);
    }
}
