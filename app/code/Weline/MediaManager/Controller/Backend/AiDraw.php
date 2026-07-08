<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\MessageManager;
use Weline\MediaManager\Service\AiDrawService;

class AiDraw extends BackendController
{
    public function __construct(
        private readonly AiDrawService $aiDrawService,
    ) {
    }

    /**
     * SSE：文生图 / 图生图 / 多轮修图 / 批量生成
     */
    public function postStream(): void
    {
        $sse = new SseWriter();
        try {
            $adminId = (int)($this->getLoginUserId() ?? 0);
            if ($adminId <= 0) {
                $sse->start();
                $sse->sendEvent('error', ['code' => 'UNAUTHORIZED', 'message' => (string)__('未登录')]);
                $sse->close();
                return;
            }
            $input = $this->collectInput();
            $this->aiDrawService->streamGenerate($sse, $adminId, $input);
        } catch (\Throwable $throwable) {
            if (!$sse->isStarted()) {
                $sse->start();
            }
            $sse->sendEvent('error', [
                'code' => 'STREAM_FAILED',
                'message' => $throwable->getMessage(),
            ]);
            $sse->close();
        }
    }

    /**
     * 保存：覆盖原图 / 另存为新文件
     */
    public function postSave()
    {
        try {
            $adminId = (int)($this->getLoginUserId() ?? 0);
            if ($adminId <= 0) {
                return $this->fetchJson($this->error((string)__('未登录')));
            }
            $result = $this->aiDrawService->save($adminId, $this->collectInput());
            MessageManager::success(__('图片保存成功'));

            return $this->fetchJson($this->success(__('保存成功'), $result));
        } catch (\Throwable $throwable) {
            MessageManager::error(__('保存失败：%{1}', $throwable->getMessage()));

            return $this->fetchJson($this->error($throwable->getMessage()));
        }
    }

    /**
     * 适配器/模型就绪状态
     */
    public function getConfig()
    {
        return $this->fetchJson($this->success('', $this->aiDrawService->getConfigStatus()));
    }

    /**
     * 生成结果预览（二进制图片，供前端 img src 加载）
     */
    public function getPreview(): void
    {
        $this->layoutType = null;
        $sessionId = \trim((string)$this->request->getParam('session_id'));
        $generationId = \trim((string)$this->request->getParam('generation_id'));
        $previewToken = \trim((string)$this->request->getParam('preview_token'));
        if ($sessionId === '' || $generationId === '') {
            $this->redirect(404);
            return;
        }
        $adminId = (int)($this->getLoginUserId() ?? 0);
        $loaded = $this->aiDrawService->loadPreview($adminId, $sessionId, $generationId, $previewToken);
        if ($loaded === null) {
            $this->redirect(404);
            return;
        }
        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', $loaded['mime_type']);
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Content-Length', (string)\strlen($loaded['bytes']));
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setBody($loaded['bytes']);
        $response->send();
    }

    /**
     * @return array<string,mixed>
     */
    private function collectInput(): array
    {
        $input = \array_merge(
            $this->request->getParams(),
            $this->request->getPost() ?? [],
            $this->request->getQuery() ?? []
        );
        $raw = (string)$this->request->getContent();
        if ($raw !== '' && \str_starts_with(\trim($raw), '{')) {
            $decoded = \json_decode($raw, true);
            if (\is_array($decoded)) {
                $input = \array_merge($input, $decoded);
            }
        }

        return \is_array($input) ? $input : [];
    }
}
