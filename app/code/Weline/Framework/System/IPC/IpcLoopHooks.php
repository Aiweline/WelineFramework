<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * IPC 事件循环生命周期钩子容器
 *
 * 用于在子进程主循环的 tick 前/后注入自定义逻辑。
 */
final class IpcLoopHooks
{
    /** @var callable[] tick 前执行的回调列表 */
    public array $beforeTick = [];

    /** @var callable[] tick 后执行的回调列表 */
    public array $afterTick = [];

    public function addBeforeTick(callable $cb): void
    {
        $this->beforeTick[] = $cb;
    }

    public function addAfterTick(callable $cb): void
    {
        $this->afterTick[] = $cb;
    }

    public function runBeforeTick(): void
    {
        foreach ($this->beforeTick as $cb) {
            ($cb)();
        }
    }

    public function runAfterTick(): void
    {
        foreach ($this->afterTick as $cb) {
            ($cb)();
        }
    }
}
