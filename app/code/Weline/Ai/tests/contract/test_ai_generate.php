<?php
declare(strict_types=1);

/**
 * AI生成接口合约测试
 * 
 * 测试端点: POST /api/ai/generate
 * 测试场景: AI内容生成功能
 */

use PHPUnit\Framework\TestCase;

class AiGenerateContractTest extends TestCase
{
    private string $baseUrl = 'http://localhost/api';
    private string $authToken = 'test-token';
    private string $tenantCode = 'test-company';

    /**
     * 测试基础AI内容生成
     */
    public function testBasicAiGeneration(): void
    {
        $requestData = [
            'prompt' => '请帮我写一个PHP函数',
            'model_code' => 'gpt-3.5-turbo',
            'scenario_code' => 'code_generation',
            'locale' => 'zh_Hans_CN'
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData);
        
        // 验证响应状态码
        $this->assertEquals(200, $response['status_code']);
        
        // 验证响应结构
        $this->assertArrayHasKey('success', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertTrue($response['body']['success']);
        
        // 验证数据字段
        $data = $response['body']['data'];
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('model', $data);
        $this->assertArrayHasKey('usage', $data);
        
        // 验证内容不为空
        $this->assertNotEmpty($data['content']);
        $this->assertEquals('gpt-3.5-turbo', $data['model']);
        
        // 验证使用量统计
        $usage = $data['usage'];
        $this->assertArrayHasKey('input_tokens', $usage);
        $this->assertArrayHasKey('output_tokens', $usage);
        $this->assertArrayHasKey('total_tokens', $usage);
        $this->assertGreaterThan(0, $usage['total_tokens']);
    }

    /**
     * 测试翻译场景
     */
    public function testTranslationScenario(): void
    {
        $requestData = [
            'prompt' => 'Hello, how are you?',
            'model_code' => 'gpt-3.5-turbo',
            'scenario_code' => 'translation',
            'locale' => 'zh_Hans_CN',
            'params' => [
                'target_language' => '中文',
                'source_language' => '英文'
            ]
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData);
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $this->assertNotEmpty($data['content']);
        $this->assertStringContainsString('你好', $data['content']);
    }

    /**
     * 测试无效请求参数
     */
    public function testInvalidRequest(): void
    {
        $requestData = [
            // 缺少必需的prompt参数
            'model_code' => 'gpt-3.5-turbo'
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertArrayHasKey('error', $response['body']);
        
        $error = $response['body']['error'];
        $this->assertEquals('INVALID_REQUEST', $error['code']);
        $this->assertStringContainsString('prompt', $error['message']);
    }

    /**
     * 测试认证失败
     */
    public function testUnauthorizedRequest(): void
    {
        $requestData = [
            'prompt' => '测试请求'
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData, [
            'Authorization' => 'Bearer invalid-token'
        ]);
        
        $this->assertEquals(401, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('UNAUTHORIZED', $response['body']['error']['code']);
    }

    /**
     * 测试请求频率超限
     */
    public function testRateLimitExceeded(): void
    {
        $requestData = [
            'prompt' => '测试请求'
        ];

        // 模拟高频请求
        for ($i = 0; $i < 100; $i++) {
            $response = $this->makeRequest('POST', '/ai/generate', $requestData);
            
            if ($response['status_code'] === 429) {
                $this->assertEquals(429, $response['status_code']);
                $this->assertFalse($response['body']['success']);
                $this->assertEquals('RATE_LIMIT_EXCEEDED', $response['body']['error']['code']);
                return;
            }
        }
        
        $this->fail('未触发频率限制');
    }

    /**
     * 测试不支持的模型
     */
    public function testUnsupportedModel(): void
    {
        $requestData = [
            'prompt' => '测试请求',
            'model_code' => 'unsupported-model'
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('UNSUPPORTED_MODEL', $response['body']['error']['code']);
    }

    /**
     * 测试配额超限
     */
    public function testQuotaExceeded(): void
    {
        $requestData = [
            'prompt' => '测试请求'
        ];

        $response = $this->makeRequest('POST', '/ai/generate', $requestData);
        
        if ($response['status_code'] === 429) {
            $this->assertEquals(429, $response['status_code']);
            $this->assertFalse($response['body']['success']);
            $this->assertEquals('QUOTA_EXCEEDED', $response['body']['error']['code']);
        }
    }

    /**
     * 发送HTTP请求
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        
        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->authToken,
            'X-Tenant-Code' => $this->tenantCode
        ];
        
        $headers = array_merge($defaultHeaders, $headers);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->formatHeaders($headers));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'status_code' => $httpCode,
            'body' => json_decode($response, true)
        ];
    }

    /**
     * 格式化请求头
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = $key . ': ' . $value;
        }
        return $formatted;
    }
}
