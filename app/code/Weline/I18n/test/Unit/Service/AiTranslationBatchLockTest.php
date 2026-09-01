<?php
declare(strict_types=1);

namespace Weline\I18n\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\AiTranslationBatchLock;

final class AiTranslationBatchLockTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
        }
    }

    public function testExclusiveLocaleLockBlocksSecondAcquire(): void
    {
        $lock = new AiTranslationBatchLock();
        $suffix = (string)getmypid() . '_' . bin2hex(random_bytes(4));
        $locale = 'test_lock_' . $suffix;

        $first = $lock->tryAcquire($locale, 'test:first');
        self::assertIsResource($first);

        $second = $lock->tryAcquire($locale, 'test:second');
        self::assertNull($second);

        $lock->release($first);

        $third = $lock->tryAcquire($locale, 'test:third');
        self::assertIsResource($third);
        $lock->release($third);
    }
}
