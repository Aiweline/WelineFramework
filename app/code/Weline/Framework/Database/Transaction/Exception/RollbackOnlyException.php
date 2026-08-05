<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Transaction\Exception;

use Throwable;

final class RollbackOnlyException extends TransactionStateException
{
    public function __construct(?Throwable $cause = null)
    {
        parent::__construct(__('事务已标记为仅回滚，无法提交'), 0, $cause);
    }
}
