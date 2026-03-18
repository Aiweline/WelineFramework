<?php
declare(strict_types=1);

/**
 * 建站智能体控制器
 *
 * 提供一站式建站页面与 SSE 流式执行端点
 * 集成：自动购买域名、DNS 解析、HTTPS 申请、站点创建
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\DomainRegistrarAccount;
use Weline\Websites\Service\WebsiteAgentService;

#[Acl('Weline_Websites::site_builder_agent', '建站智能体', 'mdi mdi-robot', '根据描述自动购买域名、DNS解析、HTTPS、建站', 'Weline_Backend::website_service')]
class SiteBuilderAgent extends BackendController
{
    /**
     * 建站智能体首页
     */
    #[Acl('Weline_Websites::site_builder_agent_index', '建站智能体', 'mdi mdi-robot', '建站智能体首页')]
    public function index(): string
    {
        $accounts = $this->getActiveAccounts();
        $this->assign('accounts', $accounts);
        $this->assign('page_title', __('建站智能体'));
        $this->assign('breadcrumb_parent', __('网站服务'));
        $this->assign('breadcrumb_current', __('建站智能体'));
        return $this->fetch();
    }

    /**
     * SSE 流式执行（GET，供 EventSource 使用）
     * 参数：description, domain, account_id, use_ai=1 时优先调用 AI 智能体（智能体识别）
     */
    #[Acl('Weline_Websites::site_builder_agent_trigger', '触发建站', 'mdi mdi-play', '触发建站流程')]
    public function getTriggerSse(): void
    {
        @\set_time_limit(0);
        @\ignore_user_abort(true);

        $sse = new \Weline\Framework\Http\Sse\SseWriter();
        $sse->start();
        $sse->sendEvent('start', ['message' => __('正在初始化...')]);

        try {
            $description = \trim((string) $this->request->getGet('description', ''));
            $domain = \trim((string) $this->request->getGet('domain', ''));
            $accountId = (int) $this->request->getGet('account_id', 0);
            $useAi = ($this->request->getGet('use_ai', '1') === '1');

            if ($domain === '' && !$useAi) {
                $sse->sendEvent('error', ['message' => __('请填写要购买的域名，或启用 AI 模式由智能体推荐')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($accountId <= 0 && !$useAi) {
                $sse->sendEvent('error', ['message' => __('请选择域名商账号')]);
                $sse->complete(['success' => false]);
                return;
            }

            if ($useAi && \class_exists(\Weline\Ai\Service\AiService::class)) {
                $this->runAiAgent($sse, $description, $domain, $accountId);
                return;
            }

            if ($domain === '' || $accountId <= 0) {
                $sse->sendEvent('error', ['message' => __('非 AI 模式下需填写域名并选择账号')]);
                $sse->complete(['success' => false]);
                return;
            }
            if ($description === '') {
                $description = $domain;
            }

            $itemExtras = [];
            $cip = \trim((string) $this->request->getClientIp());
            if ($cip !== '' && \filter_var($cip, FILTER_VALIDATE_IP)) {
                $itemExtras['user_client_ip'] = $cip;
            }
            /** @var WebsiteAgentService $agentService */
            $agentService = ObjectManager::getInstance(WebsiteAgentService::class);
            $result = $agentService->buildFromDescription(
                $description,
                $domain,
                $accountId,
                function (string $event, array $data) use ($sse): void {
                    $sse->sendEvent($event, $data);
                },
                $itemExtras
            );

            if ($result['success']) {
                $sse->complete([
                    'success' => true,
                    'message' => $result['message'],
                    'domain' => $result['domain'] ?? '',
                    'website_id' => $result['website_id'] ?? 0,
                ]);
            } else {
                $sse->sendEvent('error', ['message' => $result['message']]);
                $sse->complete(['success' => false]);
            }
        } catch (\Throwable $e) {
            $sse->sendEvent('error', [
                'message' => $e->getMessage(),
                'detail' => __('执行出错：%{message}', ['message' => $e->getMessage()]),
            ]);
            $sse->complete(['success' => false]);
        }
    }

    /**
     * 通过 AI 模块智能体执行（智能体识别、理解描述、推荐域名、建站）
     */
    private function runAiAgent(
        \Weline\Framework\Http\Sse\SseWriter $sse,
        string $description,
        string $domain,
        int $accountId
    ): void {
        $prompt = $description !== ''
            ? __('请根据以下描述完成建站：%{1}', [$description])
            : __('请帮我建一个站点');
        if ($domain !== '') {
            $prompt .= "\n" . __('用户期望域名：%{1}', [$domain]);
        }
        if ($accountId > 0) {
            $prompt .= "\n" . __('使用账号 ID：%{1}', [$accountId]);
        }

        $params = ['account_id' => $accountId];
        $mapEvent = function (string $eventType, array $data) use ($sse): void {
            $msg = $data['message'] ?? $data['content'] ?? null;
            if ($msg !== null && \is_string($msg)) {
                $sse->sendEvent('progress', ['message' => $msg]);
            }
            if ($eventType === 'tool_call' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('执行工具：%{1}', [$data['name']])]);
            }
            if ($eventType === 'tool_result' && isset($data['name'])) {
                $sse->sendEvent('info', ['message' => __('工具 %{1} 完成', [$data['name']])]);
            }
        };

        try {
            /** @var \Weline\Ai\Service\AiService $aiService */
            $aiService = ObjectManager::getInstance(\Weline\Ai\Service\AiService::class);
            $result = $aiService->executeAgent('website_builder', $prompt, null, $params, $mapEvent);

            if ($result->success && $result->content !== '') {
                $sse->sendEvent('progress', ['message' => $result->content]);
            }
            $sse->complete([
                'success' => $result->success,
                'message' => $result->success ? __('建站任务已完成') : ($result->error ?? __('执行失败')),
            ]);
        } catch (\Throwable $e) {
            $sse->sendEvent('error', ['message' => $e->getMessage()]);
            $sse->complete(['success' => false]);
        }
    }

    private function getActiveAccounts(): array
    {
        $accountModel = ObjectManager::getInstance(DomainRegistrarAccount::class);
        $all = $accountModel->getAccountsWithRegistrar();
        $active = [];
        foreach ($all as $row) {
            if (($row['status'] ?? '') === 'active') {
                $active[] = [
                    'account_id' => (int) ($row['account_id'] ?? 0),
                    'account_name' => (string) ($row['account_name'] ?? ''),
                    'registrar_name' => (string) ($row['registrar_name'] ?? ''),
                    'registrar_code' => (string) ($row['registrar_code'] ?? ''),
                ];
            }
        }
        return $active;
    }
}
