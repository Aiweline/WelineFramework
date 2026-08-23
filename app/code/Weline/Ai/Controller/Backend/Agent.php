<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Api\AgentCatalogInterface;
use Weline\Ai\Service\AgentScanner;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Ai::ai_agent_list', 'AI 智能体治理', 'circle', 'AI 智能体目录与工具治理', 'Weline_Backend::ai_group')]
class Agent extends BackendController
{
    #[Acl('Weline_Ai::ai_agent_index', '查看 AI 智能体', 'list', '查看 AI 智能体目录')]
    public function index(): string
    {
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $this->assign('activeTab', 'agent');
        $this->assign('agents', $this->catalog()->listCatalog(true));
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Ai::ai_agent_index', '查看 AI 智能体目录', 'tag', '查看 AI 智能体目录')]
    public function getCatalog(): string
    {
        return $this->catalogResponse(false);
    }

    #[Acl('Weline_Ai::ai_agent_index', '查看 AI 智能体目录', 'tag', '查看 AI 智能体目录')]
    public function postCatalog(): string
    {
        return $this->catalogResponse(true);
    }

    #[Acl('Weline_Ai::ai_agent_save', '保存智能体覆盖', 'save', '保存智能体名称与描述覆盖')]
    public function postSave(): string
    {
        $code = trim((string)$this->bodyValue('code', ''));
        if ($code === '') {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'INVALID_AGENT_CODE',
                'message' => (string)__('智能体代码不能为空。'),
            ], 400);
        }

        try {
            $item = $this->catalog()->saveOverrides(
                $code,
                $this->nullableBodyString('name_override'),
                $this->nullableBodyString('description_override'),
            );
            return $this->jsonResponse(['success' => true, 'item' => $item]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'AGENT_SAVE_FAILED',
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    #[Acl('Weline_Ai::ai_agent_toggle', '切换智能体状态', 'switch', '启用或禁用 AI 智能体')]
    public function postSetActive(): string
    {
        $code = trim((string)$this->bodyValue('code', ''));
        if ($code === '') {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'INVALID_AGENT_CODE',
                'message' => (string)__('智能体代码不能为空。'),
            ], 400);
        }

        try {
            $item = $this->catalog()->setActive($code, $this->truthy($this->bodyValue('is_active', true)));
            return $this->jsonResponse(['success' => true, 'item' => $item]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'AGENT_TOGGLE_FAILED',
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    #[Acl('Weline_Ai::ai_agent_save', '保存工具描述覆盖', 'save', '保存智能体工具描述覆盖')]
    public function postSaveTool(): string
    {
        $agentCode = trim((string)$this->bodyValue('agent_code', $this->bodyValue('code', '')));
        $toolName = trim((string)$this->bodyValue('tool_name', ''));
        if ($agentCode === '' || $toolName === '') {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'INVALID_TOOL',
                'message' => (string)__('智能体代码和工具名称不能为空。'),
            ], 400);
        }

        try {
            $item = $this->catalog()->saveToolOverride(
                $agentCode,
                $toolName,
                $this->nullableBodyString('description_override'),
            );
            return $this->jsonResponse(['success' => true, 'item' => $item]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'TOOL_SAVE_FAILED',
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    #[Acl('Weline_Ai::ai_agent_toggle', '切换工具状态', 'switch', '启用或禁用智能体工具')]
    public function postSetToolStatus(): string
    {
        $agentCode = trim((string)$this->bodyValue('agent_code', $this->bodyValue('code', '')));
        $toolName = trim((string)$this->bodyValue('tool_name', ''));
        if ($agentCode === '' || $toolName === '') {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'INVALID_TOOL',
                'message' => (string)__('智能体代码和工具名称不能为空。'),
            ], 400);
        }

        try {
            $item = $this->catalog()->setToolEnabled(
                $agentCode,
                $toolName,
                $this->truthy($this->bodyValue('is_enabled', true)),
            );
            return $this->jsonResponse(['success' => true, 'item' => $item]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'TOOL_TOGGLE_FAILED',
                'message' => $throwable->getMessage(),
            ], 400);
        }
    }

    #[Acl('Weline_Ai::ai_agent_scan', '扫描 AI 智能体', 'search', '扫描并注册 AI 智能体')]
    public function scan(): string
    {
        return $this->postScan();
    }

    #[Acl('Weline_Ai::ai_agent_scan', '扫描 AI 智能体', 'search', '扫描并注册 AI 智能体')]
    public function postScan(): string
    {
        try {
            $scanned = $this->scanner()->scanAllAgents();
            return $this->jsonResponse([
                'success' => true,
                'count' => count($scanned),
                'message' => (string)__('成功扫描 %{count} 个智能体', ['count' => count($scanned)]),
                'items' => $this->catalog()->listCatalog(true),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'AGENT_SCAN_FAILED',
                'message' => (string)__('扫描智能体失败') . '：' . $throwable->getMessage(),
            ], 400);
        }
    }

    private function catalogResponse(bool $fromBody): string
    {
        $includeInactive = $this->truthy(
            $fromBody
                ? $this->bodyValue('include_inactive', true)
                : $this->request->getGet('include_inactive', true)
        );
        $code = trim((string)($fromBody
            ? $this->bodyValue('code', '')
            : $this->request->getGet('code', '')));

        try {
            if ($code !== '') {
                $item = $this->catalog()->findByCode($code);
                return $this->jsonResponse([
                    'success' => true,
                    'item' => $item,
                    'items' => $item ? [$item] : [],
                ]);
            }
            $items = $this->catalog()->listCatalog($includeInactive);
            return $this->jsonResponse(['success' => true, 'items' => $items]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'message' => $throwable->getMessage(),
                'items' => [],
            ], 400);
        }
    }

    private function nullableBodyString(string $key): ?string
    {
        $value = $this->bodyValue($key, null);
        if ($value === null) {
            return null;
        }

        return (string)$value;
    }

    private function bodyValue(string $key, mixed $default = null): mixed
    {
        return $this->request->getBodyParam($key, $this->request->getPost($key, $default));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function jsonResponse(array $data, int $statusCode = 200): string
    {
        $this->request->getResponse()->setHttpResponseCode($statusCode);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function catalog(): AgentCatalogInterface
    {
        return ObjectManager::getInstance(AgentCatalogInterface::class);
    }

    private function scanner(): AgentScanner
    {
        return ObjectManager::getInstance(AgentScanner::class);
    }
}
