<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;
use Weline\TwoFactorAuth\Observer\CheckUserLogin;

// Prefer the canonical in-tree module over the packaged Composer copy.
require_once dirname(__DIR__, 3) . '/Observer/CheckUserLogin.php';

final class CheckUserLoginTest extends TestCase
{
    public function testEarlyFrontendLifecycleAcceptsMissingParsedPath(): void
    {
        $observer = (new ReflectionClass(CheckUserLogin::class))->newInstanceWithoutConstructor();
        $requestProperty = new ReflectionProperty(CheckUserLogin::class, 'request');
        $requestProperty->setValue($observer, new Request(['path_info' => null]));

        $event = new Event();
        $observer->execute($event);

        $this->addToAssertionCount(1);
    }
}
