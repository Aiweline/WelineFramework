<?php

declare(strict_types=1);

namespace Weline\Ops\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Http\Sse\SseWriter;
use Weline\Ops\Service\OpsDeskService;
use Weline\Ops\Service\OpsDispatchService;
use Weline\Ops\Service\OpsPersonaService;
use Weline\Ops\Service\OpsProgramService;
use Weline\Ops\Service\OpsTaskService;

#[Acl(
    'Weline_Ops::commerce:ops:desk',
    'AI运营台',
    'mdi mdi-robot-outline',
    '网站 AI 运营中枢',
    'Weline_Backend::ai_ops_group',
)]
class Desk extends BackendController
{
    public function __construct(
        private readonly OpsDeskService $deskService,
        private readonly OpsProgramService $programService,
        private readonly OpsPersonaService $personaService,
        private readonly OpsTaskService $taskService,
        private readonly OpsDispatchService $dispatchService,
    ) {
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_index',
        '查看 AI运营台',
        'mdi mdi-robot-outline',
        '查看网站 AI 运营台',
    )]
    public function index(): string
    {
        $websiteId = (int)$this->request->getGet('website_id', 0);
        $view = $this->deskService->buildDeskView($websiteId);
        foreach ($view as $key => $value) {
            $this->assign($key, $value);
        }

        return $this->fetch('Weline_Ops::templates/backend/Desk/index.phtml');
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_enable',
        '启用站点 AI运营',
        'mdi mdi-robot-outline',
        '为网站启用 AI 运营程序',
    )]
    public function enable()
    {
        $websiteId = (int)$this->request->getPost('website_id', $this->request->getGet('website_id', 0));
        $this->programService->setEnabled($websiteId, true);
        $this->getMessageManager()->addSuccess(__('已为该站开启 AI 运营'));

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_disable',
        '关闭站点 AI运营',
        'mdi mdi-robot-outline',
        '关闭网站 AI 运营程序',
    )]
    public function disable()
    {
        $websiteId = (int)$this->request->getPost('website_id', $this->request->getGet('website_id', 0));
        $this->programService->setEnabled($websiteId, false);
        $this->getMessageManager()->addSuccess(__('已关闭该站 AI 运营'));

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_refresh_persona',
        '刷新站点画像',
        'mdi mdi-robot-outline',
        '根据网站证据包刷新运营画像',
    )]
    public function refreshPersona()
    {
        $websiteId = (int)$this->request->getPost('website_id', $this->request->getGet('website_id', 0));
        $result = $this->personaService->generateFromContext($websiteId);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('画像已写入结构化记忆'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('画像生成失败')));
        }

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_refresh_persona_stream',
        'SSE 刷新站点画像',
        'mdi mdi-robot-outline',
        '流式推送画像提取进度（证据整合 + AI 生成）',
        'Weline_Ops::commerce:ops:desk_refresh_persona',
    )]
    public function refreshPersonaStream(): string
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $websiteId = (int)$this->request->getGet('website_id', $this->request->getPost('website_id', 0));
        $sse = new SseWriter();
        $sse->setHeartbeatInterval(15)->start();

        try {
            $sse->sendEvent('step_info', [
                'step' => 'site',
                'message' => (string)__('已连接画像提取通道 · website_id=%{1}', [(string)$websiteId]),
            ]);

            $emit = static function (string $event, array $data) use ($sse): void {
                if (!$sse->isAlive()) {
                    return;
                }
                $sse->sendEvent($event, $data);
            };

            $result = $this->personaService->generateFromContextStreaming($websiteId, $emit);
            if ($result['ok'] ?? false) {
                $sse->sendEvent('done', [
                    'success' => true,
                    'message' => (string)__('画像提取完成，已写入结构化记忆'),
                    'website_id' => $websiteId,
                    'persona' => $result['persona'] ?? [],
                ]);
            } else {
                $sse->sendEvent('failed', [
                    'success' => false,
                    'message' => (string)($result['message'] ?? __('画像生成失败')),
                    'website_id' => $websiteId,
                ]);
            }
        } catch (\Throwable $e) {
            if ($sse->isAlive()) {
                $sse->sendEvent('failed', [
                    'success' => false,
                    'message' => $e->getMessage() !== '' ? $e->getMessage() : (string)__('画像生成失败'),
                ]);
            }
        }

        $sse->close();

        return '';
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_submit_config',
        '提交运营配置',
        'mdi mdi-robot-outline',
        '受理运营配置并异步派发任务',
    )]
    public function submitConfig()
    {
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $lanesRaw = (string)$this->request->getPost('lanes', 'content,social');
        $lanes = array_values(array_filter(array_map('trim', explode(',', $lanesRaw))));
        $result = $this->dispatchService->acceptConfig([
            'website_id' => $websiteId,
            'lanes' => $lanes !== [] ? $lanes : ['content', 'social'],
            'topic' => (string)$this->request->getPost('topic', ''),
            'note' => (string)$this->request->getPost('note', ''),
        ]);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(
                __('已受理：会话 %{1}，任务 %{2} 条', [
                    (string)($result['session_id'] ?? ''),
                    (string)count($result['tasks'] ?? []),
                ])
            );
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('受理失败')));
        }

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_approve_task',
        '批准运营任务外发',
        'mdi mdi-robot-outline',
        '批准待审任务并触发域外发',
    )]
    public function approveTask()
    {
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $taskId = (int)$this->request->getPost('task_id', 0);
        $result = $this->dispatchService->approveTask($taskId);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('已批准并外发'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('批准失败')));
        }

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_create_task',
        '创建运营任务',
        'mdi mdi-robot-outline',
        '在 AI 运营台登记任务',
    )]
    public function createTask()
    {
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $result = $this->taskService->create([
            'website_id' => $websiteId,
            'scope' => (string)$this->request->getPost('scope', 'site'),
            'task_type' => (string)$this->request->getPost('task_type', ''),
            'owner_module' => (string)$this->request->getPost('owner_module', 'Weline_Ops'),
            'title' => (string)$this->request->getPost('title', ''),
            'note' => (string)$this->request->getPost('note', ''),
        ]);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('任务已登记'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('任务创建失败')));
        }

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }

    #[Acl(
        'Weline_Ops::commerce:ops:desk_close_task',
        '关闭运营任务',
        'mdi mdi-robot-outline',
        '将 AI 运营任务标为完成或取消',
    )]
    public function closeTask()
    {
        $websiteId = (int)$this->request->getPost('website_id', 0);
        $taskId = (int)$this->request->getPost('task_id', 0);
        $status = (string)$this->request->getPost('status', 'done');
        $result = $this->taskService->setStatus($taskId, $status);
        if ($result['ok'] ?? false) {
            $this->getMessageManager()->addSuccess(__('任务状态已更新'));
        } else {
            $this->getMessageManager()->addError((string)($result['message'] ?? __('更新失败')));
        }

        return $this->redirect('*/backend/desk/index', ['website_id' => $websiteId]);
    }
}
