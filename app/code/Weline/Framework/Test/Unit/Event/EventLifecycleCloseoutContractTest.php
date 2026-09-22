<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event;

use PHPUnit\Framework\TestCase;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}

/**
 * 事件闭环契约：悬空 Observer 类、登录像素事件名、Server start_after 派发点。
 */
class EventLifecycleCloseoutContractTest extends TestCase
{
    public function testVisitorLoginPixelListensToCustomerLoginAfter(): void
    {
        $xml = (string)\file_get_contents(BP . 'app/code/Weline/Visitor/etc/event.xml');
        self::assertStringContainsString(
            'Weline_Customer_Account_Login::login_after',
            $xml,
            'LoginPixel must listen to Customer login_after'
        );
        self::assertStringNotContainsString(
            'Weline_Frontend_Account_Login::login_after',
            $xml,
            'Deprecated Frontend login_after must not remain registered'
        );
    }

    public function testCheckoutHasNoDanglingPaymentStatusSyncObserver(): void
    {
        $xml = (string)\file_get_contents(BP . 'app/code/Weline/Checkout/etc/event.xml');
        self::assertStringNotContainsString(
            'PaymentStatusSyncObserver',
            $xml,
            'Removed dangling PaymentStatusSyncObserver registration'
        );
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Checkout/Observer/PaymentStatusSyncObserver.php'
        );
    }

    public function testThemeHasNoDanglingLayoutCriticalCssObservers(): void
    {
        $xml = (string)\file_get_contents(BP . 'app/code/Weline/Theme/etc/event.xml');
        self::assertStringNotContainsString('LayoutCriticalCss', $xml);
    }

    public function testServerDispatchesStartAfterOnReady(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/ServiceOrchestrator.php'
        );
        self::assertStringContainsString("dispatch('Weline_Server::start_after'", $src);
        self::assertStringContainsString('dispatchServerStartAfterEvent', $src);

        $eventPhp = include BP . 'app/code/Weline/Server/event.php';
        self::assertIsArray($eventPhp);
        self::assertArrayHasKey('Weline_Server::start_after', $eventPhp);
    }

    public function testRegisteredObserverClassesExistInTouchedModules(): void
    {
        $files = [
            BP . 'app/code/Weline/Visitor/etc/event.xml',
            BP . 'app/code/Weline/Checkout/etc/event.xml',
            BP . 'app/code/Weline/Theme/etc/event.xml',
            BP . 'app/code/Weline/Framework/etc/event.xml',
        ];
        foreach ($files as $file) {
            $xml = (string)\file_get_contents($file);
            if (!\preg_match_all('/instance="([^"]+)"/', $xml, $m)) {
                continue;
            }
            foreach ($m[1] as $fqcn) {
                $path = BP . 'app/code/' . \str_replace('\\', '/', $fqcn) . '.php';
                self::assertFileExists($path, "Observer class missing for {$fqcn} in {$file}");
            }
        }
    }
}
