<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Async;

use Weline\Framework\Api\Event\AsyncPayloadMapperInterface;
use Weline\Framework\Event\Async\Exception\AsyncEventValidationException;
use Weline\Framework\Manager\ObjectManager;

final class AsyncPayloadMapperResolver
{
    /** @var array<class-string<AsyncPayloadMapperInterface>,AsyncPayloadMapperInterface> */
    private array $resolved = [];

    public function resolve(string $mapperClass, string $eventName, int $schemaVersion): AsyncPayloadMapperInterface
    {
        $mapperClass = ltrim(trim($mapperClass), '\\');
        if ($mapperClass === '') {
            throw new AsyncEventValidationException(__('异步事件 %{1} 未声明 PayloadMapper', [$eventName]));
        }
        $mapper = $this->resolved[$mapperClass] ??= ObjectManager::getInstance($mapperClass);
        if (!$mapper instanceof AsyncPayloadMapperInterface) {
            throw new AsyncEventValidationException(__('异步事件 Mapper 必须实现 %{1}', [AsyncPayloadMapperInterface::class]));
        }
        if ($mapper->eventName() !== $eventName || $mapper->schemaVersion() !== $schemaVersion) {
            throw new AsyncEventValidationException(__('异步事件 Mapper 的事件名或 schema 版本不匹配'));
        }
        return $mapper;
    }
}
