<?php
declare(strict_types=1);

/**
 * 多租户管理接口合约测试
 * 
 * 测试端点: GET /api/tenant/info, GET /api/tenant/users, POST /api/tenant/users
 * 测试场景: 多租户管理功能
 */

use PHPUnit\Framework\TestCase;

class TenantManagementContractTest extends TestCase
{
    private string $baseUrl = 'http://localhost/api';
    private string $authToken = 'test-token';
    private string $tenantCode = 'test-company';

    /**
     * 测试获取租户信息
     */
    public function testGetTenantInfo(): void
    {
        $response = $this->makeRequest('GET', '/tenant/info');
        
        // 验证响应状态码
        $this->assertEquals(200, $response['status_code']);
        
        // 验证响应结构
        $this->assertArrayHasKey('success', $response['body']);
        $this->assertArrayHasKey('data', $response['body']);
        $this->assertTrue($response['body']['success']);
        
        // 验证租户信息
        $data = $response['body']['data'];
        $this->assertArrayHasKey('tenant', $data);
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('quota', $data);
        
        // 验证租户字段
        $tenant = $data['tenant'];
        $this->assertArrayHasKey('id', $tenant);
        $this->assertArrayHasKey('name', $tenant);
        $this->assertArrayHasKey('code', $tenant);
        $this->assertArrayHasKey('type', $tenant);
        $this->assertArrayHasKey('status', $tenant);
        $this->assertArrayHasKey('plan', $tenant);
        
        // 验证租户代码
        $this->assertEquals($this->tenantCode, $tenant['code']);
        $this->assertEquals('active', $tenant['status']);
        
        // 验证用户统计
        $users = $data['users'];
        $this->assertArrayHasKey('total', $users);
        $this->assertArrayHasKey('active', $users);
        $this->assertGreaterThanOrEqual(0, $users['total']);
        $this->assertGreaterThanOrEqual(0, $users['active']);
        $this->assertLessThanOrEqual($users['total'], $users['active']);
        
        // 验证配额信息
        $quota = $data['quota'];
        $this->assertIsArray($quota);
    }

    /**
     * 测试获取租户用户列表
     */
    public function testGetTenantUsers(): void
    {
        $response = $this->makeRequest('GET', '/tenant/users');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $this->assertArrayHasKey('users', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertIsArray($data['users']);
        $this->assertIsInt($data['total']);
        
        // 验证用户信息结构
        if (!empty($data['users'])) {
            $user = $data['users'][0];
            $this->assertArrayHasKey('id', $user);
            $this->assertArrayHasKey('username', $user);
            $this->assertArrayHasKey('email', $user);
            $this->assertArrayHasKey('role', $user);
            $this->assertArrayHasKey('is_active', $user);
            $this->assertArrayHasKey('created_time', $user);
            
            // 验证角色值
            $this->assertContains($user['role'], ['admin', 'member', 'viewer']);
            $this->assertIsBool($user['is_active']);
        }
    }

    /**
     * 测试按角色过滤用户
     */
    public function testGetTenantUsersByRole(): void
    {
        $response = $this->makeRequest('GET', '/tenant/users?role=admin');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $users = $data['users'];
        
        // 验证所有返回的用户都是admin角色
        foreach ($users as $user) {
            $this->assertEquals('admin', $user['role']);
        }
    }

    /**
     * 测试仅获取激活用户
     */
    public function testGetActiveUsersOnly(): void
    {
        $response = $this->makeRequest('GET', '/tenant/users?active_only=true');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $users = $data['users'];
        
        // 验证所有返回的用户都是激活状态
        foreach ($users as $user) {
            $this->assertTrue($user['is_active']);
        }
    }

    /**
     * 测试添加用户到租户
     */
    public function testAddUserToTenant(): void
    {
        $requestData = [
            'user_id' => 123,
            'role' => 'member',
            'permissions' => ['read', 'write']
        ];

        $response = $this->makeRequest('POST', '/tenant/users', $requestData);
        
        $this->assertEquals(201, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('username', $data);
        $this->assertArrayHasKey('email', $data);
        $this->assertArrayHasKey('role', $data);
        $this->assertEquals('member', $data['role']);
    }

    /**
     * 测试添加用户时缺少必需参数
     */
    public function testAddUserMissingRequiredFields(): void
    {
        $requestData = [
            'user_id' => 123
            // 缺少role参数
        ];

        $response = $this->makeRequest('POST', '/tenant/users', $requestData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertArrayHasKey('error', $response['body']);
        
        $error = $response['body']['error'];
        $this->assertEquals('INVALID_REQUEST', $error['code']);
        $this->assertStringContainsString('role', $error['message']);
    }

    /**
     * 测试添加用户时使用无效角色
     */
    public function testAddUserWithInvalidRole(): void
    {
        $requestData = [
            'user_id' => 123,
            'role' => 'invalid_role'
        ];

        $response = $this->makeRequest('POST', '/tenant/users', $requestData);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INVALID_ROLE', $response['body']['error']['code']);
    }

    /**
     * 测试添加已存在的用户
     */
    public function testAddExistingUser(): void
    {
        $requestData = [
            'user_id' => 1, // 假设用户1已存在
            'role' => 'member'
        ];

        $response = $this->makeRequest('POST', '/tenant/users', $requestData);
        
        $this->assertEquals(409, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('USER_ALREADY_EXISTS', $response['body']['error']['code']);
    }

    /**
     * 测试无租户上下文访问
     */
    public function testAccessWithoutTenantContext(): void
    {
        $response = $this->makeRequest('GET', '/tenant/info', [], [
            'X-Tenant-Code' => '' // 清空租户代码
        ]);
        
        $this->assertEquals(400, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('MISSING_TENANT_CONTEXT', $response['body']['error']['code']);
    }

    /**
     * 测试无效租户代码
     */
    public function testInvalidTenantCode(): void
    {
        $response = $this->makeRequest('GET', '/tenant/info', [], [
            'X-Tenant-Code' => 'invalid-tenant'
        ]);
        
        $this->assertEquals(404, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('TENANT_NOT_FOUND', $response['body']['error']['code']);
    }

    /**
     * 测试租户权限不足
     */
    public function testInsufficientPermissions(): void
    {
        // 使用权限不足的用户token
        $response = $this->makeRequest('POST', '/tenant/users', [
            'user_id' => 123,
            'role' => 'member'
        ], [
            'Authorization' => 'Bearer low-permission-token'
        ]);
        
        $this->assertEquals(403, $response['status_code']);
        $this->assertFalse($response['body']['success']);
        $this->assertEquals('INSUFFICIENT_PERMISSIONS', $response['body']['error']['code']);
    }

    /**
     * 测试分页参数
     */
    public function testPaginationParameters(): void
    {
        $response = $this->makeRequest('GET', '/tenant/users?limit=5&offset=10');
        
        $this->assertEquals(200, $response['status_code']);
        $this->assertTrue($response['body']['success']);
        
        $data = $response['body']['data'];
        $users = $data['users'];
        
        // 验证返回数量不超过限制
        $this->assertLessThanOrEqual(5, count($users));
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
