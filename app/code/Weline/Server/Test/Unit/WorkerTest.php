<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Server\Worker;
use Weline\Server\Timer;
use Weline\Server\Event\Select;
use Weline\Server\Protocol\Request;
use Weline\Server\Protocol\Response;
use Weline\Server\Protocol\Http;

/**
 * Worker 单元测试
 */
class WorkerTest extends TestCase
{
    /**
     * 测试 Worker 创建
     */
    public function testWorkerCreation(): void
    {
        $worker = new Worker('http://127.0.0.1:9999');
        
        $this->assertInstanceOf(Worker::class, $worker);
        $this->assertEquals('http://127.0.0.1:9999', $worker->getSocketName());
        $this->assertEquals(1, $worker->count);
        $this->assertEquals('none', $worker->name);
    }
    
    /**
     * 测试 Worker 配置
     */
    public function testWorkerConfiguration(): void
    {
        $worker = new Worker('http://0.0.0.0:8080');
        $worker->count = 4;
        $worker->name = 'TestWorker';
        $worker->reloadable = false;
        
        $this->assertEquals(4, $worker->count);
        $this->assertEquals('TestWorker', $worker->name);
        $this->assertFalse($worker->reloadable);
    }
    
    /**
     * 测试 HTTP Request 解析
     */
    public function testHttpRequestParsing(): void
    {
        $rawRequest = "GET /test?name=value HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost:8080\r\n";
        $rawRequest .= "User-Agent: TestAgent\r\n";
        $rawRequest .= "Cookie: session=abc123\r\n";
        $rawRequest .= "\r\n";
        
        $request = new Request($rawRequest);
        
        $this->assertEquals('GET', $request->method());
        $this->assertEquals('/test', $request->path());
        $this->assertEquals('name=value', $request->queryString());
        $this->assertEquals('localhost:8080', $request->host());
        $this->assertEquals('TestAgent', $request->userAgent());
        $this->assertEquals('abc123', $request->cookie('session'));
        $this->assertEquals('value', $request->get('name'));
    }
    
    /**
     * 测试 POST 请求解析
     */
    public function testPostRequestParsing(): void
    {
        $rawRequest = "POST /api/submit HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost:8080\r\n";
        $rawRequest .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $rawRequest .= "Content-Length: 23\r\n";
        $rawRequest .= "\r\n";
        $rawRequest .= "username=test&age=25";
        
        $request = new Request($rawRequest);
        
        $this->assertEquals('POST', $request->method());
        $this->assertEquals('/api/submit', $request->path());
        $this->assertEquals('test', $request->post('username'));
        $this->assertEquals('25', $request->post('age'));
    }
    
    /**
     * 测试 JSON 请求解析
     */
    public function testJsonRequestParsing(): void
    {
        $jsonBody = json_encode(['name' => 'test', 'value' => 123]);
        $rawRequest = "POST /api/json HTTP/1.1\r\n";
        $rawRequest .= "Host: localhost:8080\r\n";
        $rawRequest .= "Content-Type: application/json\r\n";
        $rawRequest .= "Content-Length: " . strlen($jsonBody) . "\r\n";
        $rawRequest .= "\r\n";
        $rawRequest .= $jsonBody;
        
        $request = new Request($rawRequest);
        
        $this->assertEquals('POST', $request->method());
        $json = $request->json();
        $this->assertEquals('test', $json['name']);
        $this->assertEquals(123, $json['value']);
    }
    
    /**
     * 测试 Response 构建
     */
    public function testResponseBuilding(): void
    {
        $response = new Response(200, ['X-Custom' => 'Value'], 'Hello World');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello World', $response->getBody());
        $this->assertArrayHasKey('X-Custom', $response->getHeaders());
        
        $responseStr = (string) $response;
        $this->assertStringContainsString('HTTP/1.1 200 OK', $responseStr);
        $this->assertStringContainsString('X-Custom: Value', $responseStr);
        $this->assertStringContainsString('Hello World', $responseStr);
    }
    
    /**
     * 测试 JSON Response
     */
    public function testJsonResponse(): void
    {
        $response = Response::json(['success' => true, 'message' => '成功']);
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaders()['Content-Type']);
        
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['success']);
        $this->assertEquals('成功', $body['message']);
    }
    
    /**
     * 测试重定向 Response
     */
    public function testRedirectResponse(): void
    {
        $response = Response::redirect('/new-location', 301);
        
        $this->assertEquals(301, $response->getStatusCode());
        $this->assertEquals('/new-location', $response->getHeaders()['Location']);
    }
    
    /**
     * 测试 Cookie 设置
     */
    public function testCookieSetting(): void
    {
        $response = (new Response(200))
            ->withCookie('session', 'abc123', 3600, '/', '', false, true, 'Lax');
        
        $responseStr = (string) $response;
        $this->assertStringContainsString('Set-Cookie:', $responseStr);
        $this->assertStringContainsString('session=abc123', $responseStr);
        $this->assertStringContainsString('Max-Age=3600', $responseStr);
    }
    
    /**
     * 测试 Select 事件循环
     */
    public function testSelectEventLoop(): void
    {
        $select = new Select();
        
        $this->assertInstanceOf(Select::class, $select);
        $this->assertEquals(0, $select->getTimerCount());
    }
    
    /**
     * 测试定时器
     */
    public function testTimerCreation(): void
    {
        $select = new Select();
        Timer::init($select);
        
        $called = false;
        $timerId = Timer::add(0.1, function() use (&$called) {
            $called = true;
        }, [], false);
        
        $this->assertIsInt($timerId);
        $this->assertTrue(Timer::del($timerId));
    }
    
    /**
     * 测试 HTTP 协议包长度检测
     */
    public function testHttpInputParsing(): void
    {
        // 创建 mock connection
        $mockConnection = $this->createMockConnection();
        
        // 完整的 GET 请求
        $buffer = "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
        $length = Http::input($buffer, $mockConnection);
        $this->assertEquals(strlen($buffer), $length);
        
        // 不完整的请求
        $buffer = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        $length = Http::input($buffer, $mockConnection);
        $this->assertEquals(0, $length);
    }
    
    /**
     * 创建 Mock Connection
     */
    protected function createMockConnection()
    {
        $mock = $this->getMockBuilder(\Weline\Server\Connection\TcpConnection::class)
            ->disableOriginalConstructor()
            ->getMock();
        
        // close() 返回 void，不需要设置返回值
        $mock->method('close');
        
        return $mock;
    }
}
