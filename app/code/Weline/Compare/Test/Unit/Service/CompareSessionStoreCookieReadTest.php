<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Compare\Service\CompareSessionStore;

final class CompareSessionStoreCookieReadTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['weline_compare_w0'], $_COOKIE['weline_compare']);
        parent::tearDown();
    }

    public function testListIdsReadsWebsiteScopedCookieFromSuperglobal(): void
    {
        $_COOKIE['weline_compare_w0'] = '[12,15]';

        $store = new CompareSessionStore();

        self::assertSame([12, 15], $store->listIds());
    }
}
