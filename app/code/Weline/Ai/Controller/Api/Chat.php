<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：<?= date('Y/m/d H:i:s') ?>

 */

namespace Weline\Ai\Controller\Api;

use Weline\Ai\Service\AiService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI聊天API控制器
 * 
 * 功能：
 * - 提供AI聊天接口
 * - 支持流式和非流式响应
 * - 支持场景适配器
 * - 支持多语言
 */
class Chat extends FrontendController
{
    /**
     * @var AiService
     */
    private AiService $aiService;

    /**
     * 构造函数
     * 
     * @param AiService $aiService
     */
    public function __construct(AiService $aiService)
    {
        $this->aiService = $aiService;
        parent::__construct();
    }

    /**
     * 聊天接口（POST）
     * 
     * @return string
     */
    public function postGenerate(): string
    {
        try {
            // 获取请求参数
            $prompt = $this->request->getPost('prompt');
            $modelCode = $this->request->getPost('model_code');
            $scenarioCode = $this->request->getPost('scenario_code');
            $locale = $this->request->getPost('locale');
            $params = $this->request->getPost('params', []);

            // 验证必需参数
            if (empty($prompt)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '提示词不能为空',
                    'error_code' => 'MISSING_PROMPT'
                ], 400);
            }

            // 验证服务参数
            $validationErrors = $this->aiService->validateServiceParams([
                'model_code' => $modelCode,
                'scenario_code' => $scenarioCode,
                'locale' => $locale
            ]);

            if (!empty($validationErrors)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '参数验证失败',
                    'errors' => $validationErrors,
                    'error_code' => 'VALIDATION_ERROR'
                ], 400);
            }

            // 调用AI服务
            $response = $this->aiService->generate($prompt, $modelCode, $scenarioCode, $locale, $params);

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'response' => $response,
                    'model_code' => $modelCode,
                    'scenario_code' => $scenarioCode,
                    'locale' => $locale
                ]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'GENERATION_ERROR'
            ], 500);
        }
    }

    /**
     * 流式聊天接口（POST）
     * 
     * @return string
     */
    public function postStream(): string
    {
        try {
            // 获取请求参数
            $prompt = $this->request->getPost('prompt');
            $modelCode = $this->request->getPost('model_code');
            $scenarioCode = $this->request->getPost('scenario_code');
            $locale = $this->request->getPost('locale');
            $params = $this->request->getPost('params', []);

            // 验证必需参数
            if (empty($prompt)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '提示词不能为空',
                    'error_code' => 'MISSING_PROMPT'
                ], 400);
            }

            // 验证服务参数
            $validationErrors = $this->aiService->validateServiceParams([
                'model_code' => $modelCode,
                'scenario_code' => $scenarioCode,
                'locale' => $locale
            ]);

            if (!empty($validationErrors)) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '参数验证失败',
                    'errors' => $validationErrors,
                    'error_code' => 'VALIDATION_ERROR'
                ], 400);
            }

            // 设置流式响应头
            $this->response->setHeader('Content-Type', 'text/event-stream');
            $this->response->setHeader('Cache-Control', 'no-cache');
            $this->response->setHeader('Connection', 'keep-alive');

            // 发送初始事件
            $this->sendStreamEvent('start', [
                'model_code' => $modelCode,
                'scenario_code' => $scenarioCode,
                'locale' => $locale
            ]);

            // 调用流式AI服务
            $this->aiService->generateStream(
                $prompt,
                [$this, 'streamCallback'],
                $modelCode,
                $scenarioCode,
                $locale,
                $params
            );

            // 发送结束事件
            $this->sendStreamEvent('end', ['message' => '生成完成']);

            return '';

        } catch (\Exception $e) {
            $this->sendStreamEvent('error', [
                'message' => $e->getMessage(),
                'error_code' => 'GENERATION_ERROR'
            ]);
            return '';
        }
    }

    /**
     * 流式回调函数
     * 
     * @param string $chunk
     * @return void
     */
    public function streamCallback(string $chunk): void
    {
        $this->sendStreamEvent('data', ['chunk' => $chunk]);
    }

    /**
     * 发送流式事件
     * 
     * @param string $event
     * @param array $data
     * @return void
     */
    private function sendStreamEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";
        flush();
    }

    /**
     * 获取可用适配器列表
     * 
     * @return string
     */
    public function getAdapters(): string
    {
        try {
            $adapters = $this->aiService->getAvailableAdapters();
            $adapterList = [];

            foreach ($adapters as $code => $adapter) {
                $adapterList[] = [
                    'code' => $adapter->getCode(),
                    'name' => $adapter->getName(),
                    'description' => $adapter->getDescription(),
                    'version' => $adapter->getVersion(),
                    'supported_models' => $adapter->getSupportedModelTypes()
                ];
            }

            return $this->jsonResponse([
                'success' => true,
                'data' => $adapterList
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'ADAPTER_ERROR'
            ], 500);
        }
    }

    /**
     * 获取适配器详细信息
     * 
     * @return string
     */
    public function getAdapterInfo(): string
    {
        $scenarioCode = $this->request->getGet('scenario_code');

        if (empty($scenarioCode)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => '场景代码不能为空',
                'error_code' => 'MISSING_SCENARIO_CODE'
            ], 400);
        }

        try {
            $adapterInfo = $this->aiService->getAdapterInfo($scenarioCode);

            if ($adapterInfo) {
                return $this->jsonResponse([
                    'success' => true,
                    'data' => $adapterInfo
                ]);
            } else {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => '适配器不存在或未激活',
                    'error_code' => 'ADAPTER_NOT_FOUND'
                ], 404);
            }

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'ADAPTER_ERROR'
            ], 500);
        }
    }

    /**
     * 获取支持的语言列表
     * 
     * @return string
     */
    public function getLocales(): string
    {
        try {
            $locales = $this->aiService->getSupportedLocales();
            $defaultLocale = $this->aiService->getDefaultLocale();

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'locales' => $locales,
                    'default' => $defaultLocale
                ]
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'LOCALE_ERROR'
            ], 500);
        }
    }

    /**
     * 获取服务统计信息
     * 
     * @return string
     */
    public function getStats(): string
    {
        try {
            $stats = $this->aiService->getServiceStats();

            return $this->jsonResponse([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'error_code' => 'STATS_ERROR'
            ], 500);
        }
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @param int $statusCode
     * @return string
     */
    private function jsonResponse(array $data, int $statusCode = 200): string
    {
        $this->response->setHeader('Content-Type', 'application/json');
        $this->response->setStatusCode($statusCode);
        return json_encode($data);
    }
}
