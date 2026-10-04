<?php
declare(strict_types=1);

namespace Weline\Framework\Cache\Exception;

/** 原子写请求已发送，但未收到本次写入的提交或冲突回复。 */
final class AtomicWriteOutcomeUnknownException extends \RuntimeException
{
}
