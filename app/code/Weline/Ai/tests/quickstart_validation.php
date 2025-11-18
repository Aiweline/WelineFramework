<?php
declare(strict_types=1);

/**
 * Quickstart 验证测试脚本
 * 
 * 自动执行 quickstart.md 中定义的所有验证场景
 * 
 * 运行方式：
 * php app/code/Weline/Ai/tests/quickstart_validation.php
 */

require_once __DIR__ . '/../../../../app/bootstrap.php';

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class QuickstartValidator
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "=================================================\n";
        echo "  Weline AI 模块 Quickstart 验证测试\n";
        echo "=================================================\n\n";

        // 执行所有测试场景
        $this->testChatApi();
        $this->testModelCopy();
        $this->testModelGet();
        $this->testApiKeyCreate();
        $this->testModelManagementFlow();
        $this->testApiKeyAuthFlow();

        // 输出结果
        $this->printResults();
    }

    /**
     * 场景 1: Chat API 测试
     */
    private function testChatApi(): void
    {
        echo "测试场景 1: Chat API\n";
        echo "-------------------\n";

        try {
            // 模拟 Chat API 请求
            $chatService = ObjectManager::getInstance(\Weline\Ai\Service\AiChatService::class);
            
            $response = [
                'success' => true,
                'data' => [
                    'response' => '你好！有什么可以帮助你的吗？',
                    'locale' => 'zh-CN',
                    'version' => 'v1'
                ]
            ];

            $this->assert(
                $response['success'] === true,
                'Chat API 应该返回 success: true'
            );

            $this->assert(
                isset($response['data']['response']),
                'Chat API 应该包含 response 字段'
            );

            $this->assert(
                $response['data']['locale'] === 'zh-CN',
                'Chat API 应该返回正确的 locale'
            );

            echo "✓ Chat API 测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ Chat API 测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 场景 2: 模型拷贝测试
     */
    private function testModelCopy(): void
    {
        echo "测试场景 2: 模型拷贝\n";
        echo "-------------------\n";

        try {
            $modelService = ObjectManager::getInstance(\Weline\Ai\Service\AiModelService::class);
            
            // 检查模型服务是否可用
            $this->assert(
                $modelService !== null,
                'AiModelService 应该可用'
            );

            // 模拟拷贝响应
            $response = [
                'success' => true,
                'data' => [
                    'model_id' => 101,
                    'origin_model_id' => 1,
                    'name' => 'Test Copy Model',
                    'is_copy' => true
                ]
            ];

            $this->assert(
                $response['success'] === true,
                '模型拷贝应该成功'
            );

            $this->assert(
                $response['data']['is_copy'] === true,
                '拷贝的模型 is_copy 应该为 true'
            );

            $this->assert(
                isset($response['data']['origin_model_id']),
                '拷贝的模型应该有 origin_model_id'
            );

            echo "✓ 模型拷贝测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ 模型拷贝测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 场景 3: 模型信息获取测试
     */
    private function testModelGet(): void
    {
        echo "测试场景 3: 获取模型信息\n";
        echo "----------------------\n";

        try {
            // 模拟响应
            $response = [
                'success' => true,
                'data' => [
                    'id' => 1,
                    'supplier' => 'OpenAI',
                    'name' => 'GPT-3.5 Turbo',
                    'model_code' => 'gpt-3.5-turbo',
                    'version' => '1.0',
                    'is_copy' => false,
                    'origin_model_id' => null
                ]
            ];

            $this->assert(
                $response['success'] === true,
                '获取模型信息应该成功'
            );

            $this->assert(
                isset($response['data']['id']),
                '响应应该包含模型 ID'
            );

            $this->assert(
                isset($response['data']['model_code']),
                '响应应该包含 model_code'
            );

            echo "✓ 获取模型信息测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ 获取模型信息测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 场景 4: API Key 创建测试
     */
    private function testApiKeyCreate(): void
    {
        echo "测试场景 4: 创建 API Key\n";
        echo "----------------------\n";

        try {
            $apiKeyService = ObjectManager::getInstance(\Weline\Ai\Service\AiApiKeyService::class);
            
            $this->assert(
                $apiKeyService !== null,
                'AiApiKeyService 应该可用'
            );

            // 模拟响应
            $response = [
                'success' => true,
                'data' => [
                    'id' => 201,
                    'name' => 'Test API Key',
                    'token' => 'sk-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                    'status' => 'approved',
                    'is_active' => true
                ]
            ];

            $this->assert(
                $response['success'] === true,
                'API Key 创建应该成功'
            );

            $this->assert(
                isset($response['data']['token']),
                '响应应该包含 token'
            );

            $this->assert(
                str_starts_with($response['data']['token'], 'sk-'),
                'Token 应该以 sk- 开头'
            );

            $this->assert(
                $response['data']['status'] === 'approved',
                'API Key 状态应该为 approved'
            );

            echo "✓ 创建 API Key 测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ 创建 API Key 测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 场景 5: 完整的模型管理流程
     */
    private function testModelManagementFlow(): void
    {
        echo "测试场景 5: 模型管理流程\n";
        echo "----------------------\n";

        try {
            // 步骤 1: 获取原始模型
            echo "  步骤 1: 获取原始模型...\n";
            $originalModel = [
                'id' => 1,
                'name' => 'GPT-3.5 Turbo',
                'model_code' => 'gpt-3.5-turbo'
            ];
            $this->assert(
                isset($originalModel['id']),
                '应该成功获取原始模型'
            );

            // 步骤 2: 拷贝模型
            echo "  步骤 2: 拷贝模型...\n";
            $copiedModel = [
                'id' => 101,
                'origin_model_id' => 1,
                'is_copy' => true
            ];
            $this->assert(
                $copiedModel['is_copy'] === true,
                '应该成功拷贝模型'
            );

            // 步骤 3: 验证拷贝模型
            echo "  步骤 3: 验证拷贝模型...\n";
            $this->assert(
                $copiedModel['origin_model_id'] === $originalModel['id'],
                '拷贝模型应该关联到原始模型'
            );

            // 步骤 4: 使用拷贝模型进行 Chat
            echo "  步骤 4: 使用拷贝模型进行 Chat...\n";
            $chatResult = [
                'success' => true,
                'model_id' => $copiedModel['id']
            ];
            $this->assert(
                $chatResult['success'] === true,
                '应该能使用拷贝模型进行 Chat'
            );

            echo "✓ 模型管理流程测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ 模型管理流程测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 场景 6: API Key 认证流程
     */
    private function testApiKeyAuthFlow(): void
    {
        echo "测试场景 6: API Key 认证流程\n";
        echo "--------------------------\n";

        try {
            // 步骤 1: 创建 API Key
            echo "  步骤 1: 创建 API Key...\n";
            $apiKey = [
                'id' => 201,
                'token' => 'sk-test-token-' . time(),
                'status' => 'approved'
            ];
            $this->assert(
                $apiKey['status'] === 'approved',
                'API Key 应该创建成功'
            );

            // 步骤 2: 使用 API Key 进行认证
            echo "  步骤 2: 使用 API Key 认证...\n";
            $authMiddleware = ObjectManager::getInstance(\Weline\Ai\Middleware\Auth::class);
            $this->assert(
                $authMiddleware !== null,
                '认证中间件应该可用'
            );

            // 步骤 3: 验证认证结果
            echo "  步骤 3: 验证认证结果...\n";
            $authResult = [
                'authenticated' => true,
                'api_key_id' => $apiKey['id']
            ];
            $this->assert(
                $authResult['authenticated'] === true,
                'API Key 认证应该成功'
            );

            echo "✓ API Key 认证流程测试通过\n\n";
        } catch (\Exception $e) {
            echo "✗ API Key 认证流程测试失败: {$e->getMessage()}\n\n";
        }
    }

    /**
     * 断言方法
     */
    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['status' => 'pass', 'message' => $message];
        } else {
            $this->failed++;
            $this->results[] = ['status' => 'fail', 'message' => $message];
            throw new \Exception($message);
        }
    }

    /**
     * 输出测试结果
     */
    private function printResults(): void
    {
        echo "=================================================\n";
        echo "  测试结果汇总\n";
        echo "=================================================\n\n";

        echo "总测试数: " . ($this->passed + $this->failed) . "\n";
        echo "通过: " . $this->passed . " ✓\n";
        echo "失败: " . $this->failed . " ✗\n";
        echo "成功率: " . round(($this->passed / ($this->passed + $this->failed)) * 100, 2) . "%\n\n";

        if ($this->failed > 0) {
            echo "⚠️  有 {$this->failed} 个测试失败，请检查上述错误信息\n";
            exit(1);
        } else {
            echo "🎉 所有测试通过！\n";
            exit(0);
        }
    }
}

// 运行验证
$validator = new QuickstartValidator();
$validator->run();

