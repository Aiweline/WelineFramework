<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Session\Session;

/**
 * 虚拟主题上下文服务
 *
 * 负责管理 PageBuilder AI 站点生成过程中的虚拟主题会话状态。
 * 参考 Weline\Theme\Service\PreviewContextService 设计模式。
 */
final class VirtualThemeContextService
{
    public const SESSION_KEY = 'pagebuilder_virtual_theme_context';

    public const DEFAULT_VIRTUAL_THEME_ID = 0;
    public const DEFAULT_AI_SESSION_ID = '';
    public const DEFAULT_PREVIEW_TOKEN = '';

    public function __construct(
        private readonly Request $request,
        private readonly Session $session,
    ) {
    }

    /**
     * 获取默认上下文
     */
    public function getDefaultContext(): array
    {
        return [
            'virtual_theme_id' => self::DEFAULT_VIRTUAL_THEME_ID,
            'ai_session_id' => self::DEFAULT_AI_SESSION_ID,
            'preview_token' => self::DEFAULT_PREVIEW_TOKEN,
        ];
    }

    /**
     * 获取当前虚拟主题上下文
     *
     * @param bool $mergeRequest 是否合并 Request 参数（优先级：Request > Session > 默认值）
     * @return array 规范化的上下文数组
     */
    public function getCurrentContext(bool $mergeRequest = true): array
    {
        $context = $this->getDefaultContext();

        // 从 Session 读取
        $storedContext = $this->session->getData(self::SESSION_KEY);
        if (\is_array($storedContext)) {
            $context = \array_replace($context, $storedContext);
        }

        // 从 Request 参数读取（最高优先级）
        if ($mergeRequest) {
            $context = \array_replace($context, $this->extractContextFromRequest());
        }

        return $this->normalizeContext($context);
    }

    /**
     * 获取当前虚拟主题 ID
     *
     * @return int 虚拟主题 ID，0 表示未设置
     */
    public function getCurrentVirtualThemeId(): int
    {
        $context = $this->getCurrentContext();
        return (int)($context['virtual_theme_id'] ?? 0);
    }

    /**
     * 获取当前 AI 会话 ID
     *
     * @return string AI 会话 ID，空字符串表示未设置
     */
    public function getAiSessionId(): string
    {
        $context = $this->getCurrentContext();
        return (string)($context['ai_session_id'] ?? '');
    }

    /**
     * 获取当前预览 Token
     *
     * @return string 预览 Token，空字符串表示未设置
     */
    public function getPreviewToken(): string
    {
        $context = $this->getCurrentContext();
        return (string)($context['preview_token'] ?? '');
    }

    /**
     * 判断当前请求是否为虚拟主题请求
     *
     * @return bool true 表示当前请求包含虚拟主题上下文
     */
    public function isVirtualThemeRequest(): bool
    {
        return $this->getCurrentVirtualThemeId() > 0;
    }

    /**
     * 持久化上下文到 Session
     *
     * @param array $context 要持久化的上下文数组
     * @param bool $syncRequest 是否同步到 Request 参数
     * @return array 规范化后的上下文
     */
    public function persistContext(array $context, bool $syncRequest = true): array
    {
        $normalized = $this->normalizeContext($context);

        $this->session->setData(self::SESSION_KEY, $normalized);

        if ($syncRequest) {
            $this->syncRequest($normalized);
        }

        return $normalized;
    }

    /**
     * 持久化当前请求的上下文
     *
     * @param array $overrides 要覆盖的上下文字段
     * @return array 规范化后的上下文
     */
    public function persistCurrentRequestContext(array $overrides = []): array
    {
        $context = $this->getCurrentContext();
        $context = \array_replace($context, $overrides);
        return $this->persistContext($context);
    }

    /**
     * 清除虚拟主题上下文
     */
    public function clearContext(): void
    {
        $this->session->delete(self::SESSION_KEY);
    }

    /**
     * 构建上下文（合并当前上下文或默认上下文）
     *
     * @param array $context 要合并的上下文字段
     * @param bool $mergeCurrent 是否合并当前上下文（false 则合并默认上下文）
     * @return array 规范化后的上下文
     */
    public function buildContext(array $context, bool $mergeCurrent = true): array
    {
        $base = $mergeCurrent ? $this->getCurrentContext() : $this->getDefaultContext();
        return $this->normalizeContext(\array_replace($base, $context));
    }

    /**
     * 规范化上下文数据
     *
     * @param array $context 原始上下文数组
     * @return array 规范化后的上下文
     */
    public function normalizeContext(array $context): array
    {
        $normalized = \array_replace($this->getDefaultContext(), $context);

        // 规范化 virtual_theme_id：必须为非负整数
        $normalized['virtual_theme_id'] = \max(0, (int)($normalized['virtual_theme_id'] ?? 0));

        // 规范化 ai_session_id：必须为字符串
        $aiSessionId = $normalized['ai_session_id'] ?? '';
        $normalized['ai_session_id'] = \is_scalar($aiSessionId) ? \trim((string)$aiSessionId) : '';

        // 规范化 preview_token：必须为字符串
        $previewToken = $normalized['preview_token'] ?? '';
        $normalized['preview_token'] = \is_scalar($previewToken) ? \trim((string)$previewToken) : '';

        return $normalized;
    }

    /**
     * 从 Request 提取上下文参数
     *
     * @return array 提取的上下文字段
     */
    private function extractContextFromRequest(): array
    {
        $context = [];

        // 读取 virtual_theme_id
        $virtualThemeId = $this->request->getParam('virtual_theme_id');
        if ($virtualThemeId !== null && $virtualThemeId !== '') {
            $context['virtual_theme_id'] = (int)$virtualThemeId;
        }

        // 读取 ai_session_id
        $aiSessionId = $this->request->getParam('ai_session_id');
        if ($aiSessionId !== null && $aiSessionId !== '') {
            $context['ai_session_id'] = (string)$aiSessionId;
        }

        // 读取 preview_token
        $previewToken = $this->request->getParam('preview_token');
        if ($previewToken !== null && $previewToken !== '') {
            $context['preview_token'] = (string)$previewToken;
        }

        return $context;
    }

    /**
     * 同步上下文到 Request 参数
     *
     * @param array $context 规范化的上下文
     */
    private function syncRequest(array $context): void
    {
        foreach ($context as $key => $value) {
            if ($value === null || $value === '' || ($key === 'virtual_theme_id' && (int)$value === 0)) {
                continue;
            }
            $this->request->setGet($key, $value);
        }
    }
}
