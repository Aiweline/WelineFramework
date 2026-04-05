<?php
declare(strict_types=1);

namespace Weline\Server\Test;

use PHPUnit\Framework\TestCase;
use Weline\Server\Log\Error\ErrorBootstrap;
use Weline\Server\Log\Error\ErrorHandler;
use Weline\Server\Log\Error\ExceptionHandler;
use Weline\Server\Log\Error\ShutdownHandler;
use Weline\Server\Log\Error\ErrorContext;
use Weline\Server\Log\Error\ErrorCollector;
use Weline\Server\Log\LogLevel;
use Weline\Server\Log\WlsLogger;

/**
 * ErrorBootstrap 单元测试
 */
class ErrorBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ErrorBootstrap::reset();
        WlsLogger::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        ErrorBootstrap::reset();
        WlsLogger::reset();
    }

    public function testInitSetsProcessTag(): void
    {
        ErrorBootstrap::init('TestWorker#1', ['port' => 9981]);
        
        $this->assertEquals('TestWorker#1', ErrorContext::getProcessTag());
    }

    public function testInitSetsContext(): void
    {
        ErrorBootstrap::init('TestWorker#1', [
            'port' => 9981,
            'instance' => 'test-instance',
        ]);
        
        $context = ErrorContext::getContext();
        
        $this->assertEquals(9981, $context['port']);
        $this->assertEquals('test-instance', $context['instance']);
    }

    public function testInitRegistersHandlers(): void
    {
        ErrorBootstrap::init('TestWorker#1');
        
        $this->assertTrue(ErrorBootstrap::isInitialized());
        $this->assertTrue(ErrorHandler::isRegistered());
        $this->assertTrue(ExceptionHandler::isRegistered());
        $this->assertTrue(ShutdownHandler::isRegistered());
    }

    public function testDoubleInitOnlyUpdatesContext(): void
    {
        ErrorBootstrap::init('Worker#1', ['port' => 9981]);
        ErrorBootstrap::init('Worker#2', ['port' => 9982]);
        
        $this->assertEquals('Worker#2', ErrorContext::getProcessTag());
        $context = ErrorContext::getContext();
        $this->assertEquals(9982, $context['port']);
    }

    public function testUpdateRequestContext(): void
    {
        ErrorBootstrap::init('TestWorker');
        ErrorBootstrap::updateRequestContext('/test/page', 'POST', '192.168.1.1');
        
        $context = ErrorContext::getContext();
        
        $this->assertEquals('/test/page', $context['request_uri']);
        $this->assertEquals('POST', $context['request_method']);
        $this->assertEquals('192.168.1.1', $context['client_ip']);
    }

    public function testClearRequestContext(): void
    {
        ErrorBootstrap::init('TestWorker');
        ErrorBootstrap::updateRequestContext('/test/page', 'GET', '127.0.0.1');
        ErrorBootstrap::clearRequestContext();
        
        $context = ErrorContext::getContext();
        
        $this->assertArrayNotHasKey('request_uri', $context);
        $this->assertArrayNotHasKey('request_method', $context);
        $this->assertArrayNotHasKey('client_ip', $context);
    }

    public function testInitFrontendMode(): void
    {
        ErrorBootstrap::initFrontend('TestWorker', ['port' => 9981]);
        
        $this->assertTrue(ErrorBootstrap::isInitialized());
    }

    public function testInitProductionMode(): void
    {
        ErrorBootstrap::initProduction('TestWorker', ['port' => 9981]);
        
        $this->assertTrue(ErrorBootstrap::isInitialized());
    }

    public function testErrorContextReset(): void
    {
        ErrorContext::setProcessTag('TestTag');
        ErrorContext::setContext(['key' => 'value']);
        
        ErrorContext::reset();
        
        $this->assertEquals('Unknown', ErrorContext::getProcessTag());
        $this->assertEmpty(ErrorContext::getContext());
    }

    public function testErrorContextFullContext(): void
    {
        ErrorContext::setProcessTag('TestWorker');
        ErrorContext::addContext('custom_key', 'custom_value');
        
        $fullContext = ErrorContext::getFullContext();
        
        $this->assertEquals('TestWorker', $fullContext['process_tag']);
        $this->assertArrayHasKey('pid', $fullContext);
        $this->assertArrayHasKey('memory_usage', $fullContext);
        $this->assertArrayHasKey('memory_peak', $fullContext);
        $this->assertEquals('custom_value', $fullContext['custom_key']);
    }

    public function testErrorCollectorGetErrorTypeName(): void
    {
        $this->assertEquals('E_ERROR', ErrorCollector::getErrorTypeName(E_ERROR));
        $this->assertEquals('E_WARNING', ErrorCollector::getErrorTypeName(E_WARNING));
        $this->assertEquals('E_NOTICE', ErrorCollector::getErrorTypeName(E_NOTICE));
        $this->assertEquals('E_PARSE', ErrorCollector::getErrorTypeName(E_PARSE));
        $this->assertStringContainsString('E_UNKNOWN', ErrorCollector::getErrorTypeName(99999));
    }

    public function testShutdownHandlerFatalErrorTypes(): void
    {
        $fatalTypes = ShutdownHandler::getFatalErrorTypes();
        
        $this->assertContains(E_ERROR, $fatalTypes);
        $this->assertContains(E_PARSE, $fatalTypes);
        $this->assertContains(E_CORE_ERROR, $fatalTypes);
        $this->assertContains(E_COMPILE_ERROR, $fatalTypes);
    }

    public function testShutdownHandlerIsFatalError(): void
    {
        $this->assertTrue(ShutdownHandler::isFatalError(E_ERROR));
        $this->assertTrue(ShutdownHandler::isFatalError(E_PARSE));
        $this->assertFalse(ShutdownHandler::isFatalError(E_WARNING));
        $this->assertFalse(ShutdownHandler::isFatalError(E_NOTICE));
    }

    public function testSuppressesVendorImplicitNullableDeprecatedNotice(): void
    {
        $handled = ErrorHandler::handle(
            E_DEPRECATED,
            'Endroid\QrCode\Writer\WriterInterface::write(): Implicitly marking parameter $label as nullable is deprecated, the explicit nullable type must be used instead',
            'E:\WelineFramework\DEV-workspace\vendor\endroid\qr-code\src\Writer\WriterInterface.php',
            15
        );

        $this->assertTrue($handled);
    }

    public function testDoesNotSuppressOtherDeprecatedNotice(): void
    {
        $handled = ErrorHandler::handle(
            E_DEPRECATED,
            'Deprecated: something else',
            'E:\WelineFramework\DEV-workspace\app\code\Demo\Example.php',
            10
        );

        $this->assertFalse($handled);
    }
}
