<?php

declare(strict_types=1);

namespace Weline\Server\Test\Session;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionProtocol;

/**
 * SessionProtocol NDJSON 协议测试
 */
class SessionProtocolTest extends TestCase
{
    /**
     * 测试编码请求消息
     */
    public function testEncodeRequest(): void
    {
        $request = SessionProtocol::encodeRequest('get', ['sid' => 'abc123', 'key' => 'user_id']);
        $this->assertStringEndsWith("\n", $request);
        
        $decoded = \json_decode(\trim($request), true);
        $this->assertEquals('get', $decoded['cmd']);
        $this->assertEquals('abc123', $decoded['sid']);
        $this->assertEquals('user_id', $decoded['key']);
    }

    /**
     * 测试编码成功响应
     */
    public function testEncodeSuccess(): void
    {
        $response = SessionProtocol::encodeSuccess(['user_id' => 123]);
        $this->assertStringEndsWith("\n", $response);
        
        $decoded = \json_decode(\trim($response), true);
        $this->assertTrue($decoded['ok']);
        $this->assertEquals(['user_id' => 123], $decoded['data']);
    }

    /**
     * 测试编码成功响应（无数据）
     */
    public function testEncodeSuccessNoData(): void
    {
        $response = SessionProtocol::encodeSuccess();
        $decoded = \json_decode(\trim($response), true);
        $this->assertTrue($decoded['ok']);
        $this->assertArrayNotHasKey('data', $decoded);
    }

    /**
     * 测试编码错误响应
     */
    public function testEncodeError(): void
    {
        $response = SessionProtocol::encodeError('Session not found', 'NOT_FOUND');
        $decoded = \json_decode(\trim($response), true);
        
        $this->assertFalse($decoded['ok']);
        $this->assertEquals('Session not found', $decoded['err']);
        $this->assertEquals('NOT_FOUND', $decoded['code']);
    }

    /**
     * 测试解码消息
     */
    public function testDecode(): void
    {
        $json = '{"cmd":"get","sid":"abc123"}';
        $decoded = SessionProtocol::decode($json);
        
        $this->assertIsArray($decoded);
        $this->assertEquals('get', $decoded['cmd']);
        $this->assertEquals('abc123', $decoded['sid']);
    }

    /**
     * 测试解码空消息
     */
    public function testDecodeEmpty(): void
    {
        $this->assertNull(SessionProtocol::decode(''));
        $this->assertNull(SessionProtocol::decode('   '));
    }

    /**
     * 测试解码无效 JSON
     */
    public function testDecodeInvalidJson(): void
    {
        $this->assertNull(SessionProtocol::decode('not json'));
        $this->assertNull(SessionProtocol::decode('{invalid}'));
    }

    /**
     * 测试从缓冲区提取消息
     */
    public function testExtractMessages(): void
    {
        $buffer = "{\"cmd\":\"get\"}\n{\"cmd\":\"set\"}\n";
        $messages = SessionProtocol::extractMessages($buffer);
        
        $this->assertCount(2, $messages);
        $this->assertEquals('get', $messages[0]['cmd']);
        $this->assertEquals('set', $messages[1]['cmd']);
        $this->assertEquals('', $buffer);
    }

    /**
     * 测试从缓冲区提取不完整消息
     */
    public function testExtractMessagesIncomplete(): void
    {
        $buffer = "{\"cmd\":\"get\"}\n{\"cmd\":\"set";
        $messages = SessionProtocol::extractMessages($buffer);
        
        $this->assertCount(1, $messages);
        $this->assertEquals('get', $messages[0]['cmd']);
        $this->assertEquals('{"cmd":"set', $buffer);
    }

    /**
     * 测试构建 GET 请求
     */
    public function testBuildGet(): void
    {
        $request = SessionProtocol::buildGet('session123', 'user');
        $decoded = \json_decode(\trim($request), true);
        
        $this->assertEquals(SessionProtocol::CMD_GET, $decoded['cmd']);
        $this->assertEquals('session123', $decoded['sid']);
        $this->assertEquals('user', $decoded['key']);
    }

    /**
     * 测试构建 SET 请求
     */
    public function testBuildSet(): void
    {
        $request = SessionProtocol::buildSet('session123', 'user', ['name' => 'test'], 7200);
        $decoded = \json_decode(\trim($request), true);
        
        $this->assertEquals(SessionProtocol::CMD_SET, $decoded['cmd']);
        $this->assertEquals('session123', $decoded['sid']);
        $this->assertEquals('user', $decoded['key']);
        $this->assertEquals(['name' => 'test'], $decoded['val']);
        $this->assertEquals(7200, $decoded['ttl']);
    }

    /**
     * 测试响应解析
     */
    public function testResponseParsing(): void
    {
        $successResponse = ['ok' => true, 'data' => 'test'];
        $this->assertTrue(SessionProtocol::isSuccess($successResponse));
        $this->assertEquals('test', SessionProtocol::getData($successResponse));
        $this->assertNull(SessionProtocol::getError($successResponse));
        
        $errorResponse = ['ok' => false, 'err' => 'Error message'];
        $this->assertFalse(SessionProtocol::isSuccess($errorResponse));
        $this->assertEquals('Error message', SessionProtocol::getError($errorResponse));
    }

    /**
     * 测试 PING 请求
     */
    public function testBuildPing(): void
    {
        $request = SessionProtocol::buildPing();
        $decoded = \json_decode(\trim($request), true);
        $this->assertEquals(SessionProtocol::CMD_PING, $decoded['cmd']);
    }

    /**
     * 测试 STATS 请求
     */
    public function testBuildStats(): void
    {
        $request = SessionProtocol::buildStats();
        $decoded = \json_decode(\trim($request), true);
        $this->assertEquals(SessionProtocol::CMD_STATS, $decoded['cmd']);
    }
}
