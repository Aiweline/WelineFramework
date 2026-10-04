<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** 本调用上下文内最后一次 CAS 是否收到后端的提交或冲突回复。 */
interface AtomicCacheConfirmationInterface extends AtomicCacheAdapterInterface
{
    public function isLastCompareAndSetReplyConfirmed(): bool;

    /** 仅当可以证明本次 CAS 未进入写入调用时返回 true；不确定时为 false。 */
    public function wasLastCompareAndSetNotDispatched(): bool;
}
