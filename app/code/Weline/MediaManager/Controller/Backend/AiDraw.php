<?php

declare(strict_types=1);

namespace Weline\MediaManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Ui\FormKey;
use Weline\MediaManager\Service\AiDrawService;
use Weline\MediaManager\Service\MediaFileAccessContextFactory;

/**
 * AI 作图 HTTP 入口；QueryProvider `media_manager.generate/config/save` 的
 * backend_acl source_id 必须与这里收集进 ACL 表的精确 source 一致，
 * 否则即使超管（role_id=1）也会因资源不存在而 403。
 */
#[Acl(
    'Weline_MediaManager::query:ai_draw',
    'AI 作图',
    'image',
    '媒体管理 AI 作图（文生图/图生图/批量）',
    'Weline_MediaManager::file_manager'
)]
class AiDraw extends BackendController
{
    public function __construct(
        private readonly AiDrawService $aiDrawService,
        private readonly MediaFileAccessContextFactory $fileAccessContexts,
    ) {
    }

    protected function csrf(): string
    {
        return FormKey::key_name;
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
            $input = $this->fileAccessContexts->freeze($this->collectInput(), $adminId);
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
    #[Acl(
        'Weline_MediaManager::query:ai_draw_save',
        'AI 作图保存',
        'save',
        '保存 AI 作图结果到媒体库',
        'Weline_MediaManager::query:ai_draw'
    )]
    public function postSave(): string
    {
        try {
            $adminId = (int)($this->getLoginUserId() ?? 0);
            if ($adminId <= 0) {
                return $this->encodeJsonResponse($this->error((string)__('未登录'), '', 401));
            }
            $result = $this->aiDrawService->save(
                $adminId,
                $this->fileAccessContexts->freeze($this->collectInput(), $adminId),
            );
            MessageManager::success(__('图片保存成功'));

            return $this->encodeJsonResponse($this->success(__('保存成功'), $result));
        } catch (\Throwable $throwable) {
            MessageManager::error(__('保存失败：%{1}', $throwable->getMessage()));

            return $this->encodeJsonResponse($this->error($throwable->getMessage()));
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encodeJsonResponse(array $payload): string
    {
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');

        return \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
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
