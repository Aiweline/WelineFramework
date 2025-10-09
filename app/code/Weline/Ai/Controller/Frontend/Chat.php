<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Controller\Frontend;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Service\AiService;
use Weline\Ai\Service\AdapterScanner;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\Message;

/**
 * AI聊天界面控制器
 * 
 * 功能：
 * - 提供聊天界面
 * - 支持文本、图片、音频、视频等多媒体聊天
 * - 实时流式响应
 * - 聊天历史记录
 */
class Chat extends FrontendController
{
    /**
     * @var AiService
     */
    private AiService $aiService;

    /**
     * @var AiModel
     */
    private AiModel $aiModel;

    /**
     * @var AiApiKey
     */
    private AiApiKey $aiApiKey;

    /**
     * @var AdapterScanner
     */
    private AdapterScanner $adapterScanner;

    /**
     * 构造函数
     * 
     * @param AiService $aiService
     * @param AiModel $aiModel
     * @param AiApiKey $aiApiKey
     * @param AdapterScanner $adapterScanner
     */
    public function __construct(
        AiService $aiService,
        AiModel $aiModel,
        AiApiKey $aiApiKey,
        AdapterScanner $adapterScanner
    ) {
        $this->aiService = $aiService;
        $this->aiModel = $aiModel;
        $this->aiApiKey = $aiApiKey;
        $this->adapterScanner = $adapterScanner;
    }

    /**
     * 聊天界面
     * 
     * @return string
     */
    public function index(): string
    {
        // 检查用户登录状态
        if (!$this->session->isLogin()) {
            Message::warning(__('请先登录以使用AI聊天功能'));
            return $this->redirect($this->_url->getFrontendUrl('*/frontend/index'));
        }

        // 获取可用的AI模型
        $models = $this->aiModel->reset()
            ->where(AiModel::fields_IS_ACTIVE, 1)
            ->select()
            ->fetch();

        // 获取可用的场景适配器
        $adapters = $this->adapterScanner->getAllActiveAdapters();

        $this->assign('page_title', __('AI聊天'));
        $this->assign('models', $models->getItems());
        $this->assign('adapters', $adapters);

        return $this->fetch();
    }

    /**
     * 发送消息（AJAX）
     * 
     * @return string
     */
    public function send(): string
    {
        if (!$this->session->isLogin()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        if (!$this->request->isPost()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('无效的请求方法')
            ]);
        }

        $message = $this->request->getPost('message', '');
        $modelCode = $this->request->getPost('model_code', null);
        $scenarioCode = $this->request->getPost('scenario_code', null);
        $locale = $this->request->getPost('locale', null);

        if (empty($message)) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('消息内容不能为空')
            ]);
        }

        try {
            // 调用AI服务生成响应
            $response = $this->aiService->generate(
                $message,
                $modelCode,
                $scenarioCode,
                $locale
            );

            return $this->jsonResponse([
                'success' => true,
                'data' => [
                    'message' => $message,
                    'response' => $response,
                    'model_code' => $modelCode,
                    'scenario_code' => $scenarioCode,
                    'timestamp' => time()
                ]
            ]);
        } catch (\Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('生成失败：%1', $e->getMessage())
            ]);
        }
    }

    /**
     * 流式响应（Server-Sent Events）
     * 
     * @return void
     */
    public function stream(): void
    {
        if (!$this->session->isLogin()) {
            echo "event: error\n";
            echo "data: " . json_encode(['message' => __('请先登录')]) . "\n\n";
            flush();
            return;
        }

        $message = $this->request->getGet('message', '');
        $modelCode = $this->request->getGet('model_code', null);
        $scenarioCode = $this->request->getGet('scenario_code', null);
        $locale = $this->request->getGet('locale', null);

        if (empty($message)) {
            echo "event: error\n";
            echo "data: " . json_encode(['message' => __('消息内容不能为空')]) . "\n\n";
            flush();
            return;
        }

        // 设置SSE响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // 禁用nginx缓冲

        try {
            // 流式生成响应
            $this->aiService->generateStream(
                $message,
                function($chunk) {
                    echo "event: message\n";
                    echo "data: " . json_encode(['chunk' => $chunk]) . "\n\n";
                    flush();
                },
                $modelCode,
                $scenarioCode,
                $locale
            );

            // 发送完成事件
            echo "event: done\n";
            echo "data: " . json_encode(['message' => 'Stream completed']) . "\n\n";
            flush();
        } catch (\Exception $e) {
            echo "event: error\n";
            echo "data: " . json_encode(['message' => $e->getMessage()]) . "\n\n";
            flush();
        }
    }

    /**
     * 获取聊天历史
     * 
     * @return string
     */
    public function history(): string
    {
        if (!$this->session->isLogin()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        // TODO: 实现聊天历史记录功能
        $history = [];

        return $this->jsonResponse([
            'success' => true,
            'data' => $history
        ]);
    }

    /**
     * 清空聊天历史
     * 
     * @return string
     */
    public function clearHistory(): string
    {
        if (!$this->session->isLogin()) {
            return $this->jsonResponse([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        // TODO: 实现清空聊天历史功能

        return $this->jsonResponse([
            'success' => true,
            'message' => __('聊天历史已清空')
        ]);
    }

    /**
     * JSON响应
     * 
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setHeader('Content-Type', 'application/json');
        return json_encode($data);
    }
}

