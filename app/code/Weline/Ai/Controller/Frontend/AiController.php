<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Frontend;

use Weline\Ai\Service\AiService;
use Weline\Ai\Service\ConfigResolver;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\App\Env;

/**
 * 前端AI服务控制器
 * 处理前端用户的AI调用请求
 */
class AiController extends FrontendController
{
    /**
     * 生成AI内容
     */
    public function generate()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }

        try {
            $data = $this->request->getParams();
            
            // 获取用户ID（需要根据您的用户系统调整）
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                return $this->jsonResponse(['success' => false, 'message' => __('请先登录')]);
            }

            // 验证必要参数
            $prompt = $data['prompt'] ?? '';
            if (empty($prompt)) {
                return $this->jsonResponse(['success' => false, 'message' => __('请输入提示词')]);
            }

            $modelCode = $data['model_code'] ?? '';
            $scenarioCode = $data['scenario_code'] ?? '';
            $locale = $data['locale'] ?? 'zh_Hans_CN';
            $userConfig = $data['user_config'] ?? [];

            // 调用AI服务
            /** @var AiService $aiService */
            $aiService = ObjectManager::getInstance(AiService::class);
            
            $response = $aiService->generate(
                $prompt,
                $modelCode ?: null,
                $scenarioCode ?: null,
                $locale,
                ['user_config' => $userConfig],
                $userId,
                false // 前端调用
            );

            return $this->jsonResponse([
                'success' => true,
                'message' => __('生成成功'),
                'data' => [
                    'content' => $response,
                    'model_code' => $modelCode,
                    'user_id' => $userId
                ]
            ]);

        } catch (\Exception $e) {
            Env::log('ai_frontend.log', "前端AI调用失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * 获取用户可用的模型列表
     */
    public function getModels()
    {
        try {
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                return $this->jsonResponse(['success' => false, 'message' => __('请先登录')]);
            }

            /** @var ConfigResolver $configResolver */
            $configResolver = ObjectManager::getInstance(ConfigResolver::class);
            
            // 获取用户可用的模型（这里需要根据您的业务逻辑实现）
            $availableModels = $this->getUserAvailableModels($userId);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('获取成功'),
                'data' => $availableModels
            ]);

        } catch (\Exception $e) {
            Env::log('ai_frontend.log', "获取用户模型列表失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取模型列表失败')
            ]);
        }
    }

    /**
     * 用户配置模型API密钥
     */
    public function setModelConfig()
    {
        if (!$this->request->isPost()) {
            return $this->jsonResponse(['success' => false, 'message' => __('仅支持POST请求')]);
        }

        try {
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                return $this->jsonResponse(['success' => false, 'message' => __('请先登录')]);
            }

            $data = $this->request->getParams();
            $modelCode = $data['model_code'] ?? '';
            $apiKey = $data['api_key'] ?? '';
            $baseUrl = $data['base_url'] ?? '';
            $config = $data['config'] ?? [];

            if (empty($modelCode)) {
                return $this->jsonResponse(['success' => false, 'message' => __('请选择模型')]);
            }

            // 保存用户模型配置（这里需要根据您的数据库结构实现）
            $this->saveUserModelConfig($userId, $modelCode, $apiKey, $baseUrl, $config);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('配置保存成功')
            ]);

        } catch (\Exception $e) {
            Env::log('ai_frontend.log', "保存用户模型配置失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => __('保存配置失败')
            ]);
        }
    }

    /**
     * 获取用户余额
     */
    public function getBalance()
    {
        try {
            $userId = $this->getCurrentUserId();
            if (!$userId) {
                return $this->jsonResponse(['success' => false, 'message' => __('请先登录')]);
            }

            // 获取用户余额（这里需要根据您的余额系统实现）
            $balance = $this->getUserBalance($userId);

            return $this->jsonResponse([
                'success' => true,
                'message' => __('获取成功'),
                'data' => [
                    'balance' => $balance,
                    'currency' => 'CNY'
                ]
            ]);

        } catch (\Exception $e) {
            Env::log('ai_frontend.log', "获取用户余额失败: " . $e->getMessage(), 'ERROR');
            
            return $this->jsonResponse([
                'success' => false,
                'message' => __('获取余额失败')
            ]);
        }
    }

    /**
     * 获取当前用户ID
     * 需要根据您的用户系统调整
     */
    private function getCurrentUserId(): ?int
    {
        // TODO: 实现获取当前用户ID的逻辑
        // 这里需要根据您的用户认证系统来实现
        
        // 示例：从session中获取
        // return $_SESSION['user_id'] ?? null;
        
        // 示例：从JWT token中获取
        // $token = $this->request->getHeader('Authorization');
        // return $this->parseUserIdFromToken($token);
        
        // 临时返回1，实际使用时需要替换
        return 1;
    }

    /**
     * 获取用户可用的模型列表
     */
    private function getUserAvailableModels(int $userId): array
    {
        // TODO: 实现获取用户可用模型的逻辑
        // 这里可以根据用户权限、余额等因素过滤模型
        
        return [
            [
                'code' => 'gpt-3.5-turbo',
                'name' => 'GPT-3.5 Turbo',
                'supplier' => 'openai',
                'version' => '1.0',
                'max_tokens' => 4096,
                'has_user_config' => $this->hasUserModelConfig($userId, 'gpt-3.5-turbo')
            ],
            [
                'code' => 'deepseek-v3',
                'name' => 'DeepSeek V3',
                'supplier' => 'deepseek',
                'version' => '3.0',
                'max_tokens' => 8192,
                'has_user_config' => $this->hasUserModelConfig($userId, 'deepseek-v3')
            ]
        ];
    }

    /**
     * 保存用户模型配置
     */
    private function saveUserModelConfig(int $userId, string $modelCode, string $apiKey, string $baseUrl, array $config): void
    {
        // TODO: 实现保存用户模型配置的逻辑
        // 这里需要根据您的数据库结构来实现
        
        Env::log('ai_frontend.log', "保存用户 {$userId} 模型 {$modelCode} 配置", 'INFO');
    }

    /**
     * 检查用户是否有模型配置
     */
    private function hasUserModelConfig(int $userId, string $modelCode): bool
    {
        // TODO: 实现检查用户模型配置的逻辑
        return false;
    }

    /**
     * 获取用户余额
     */
    private function getUserBalance(int $userId): float
    {
        // TODO: 实现获取用户余额的逻辑
        // 这里需要根据您的余额系统来实现
        
        return 100.0; // 临时返回100
    }

    /**
     * JSON响应
     */
    private function jsonResponse(array $data): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}
