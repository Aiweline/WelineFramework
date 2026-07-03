<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Backend;

use Weline\Ai\Model\AiStyle;
use Weline\Ai\Service\AdapterScanner;
use Weline\Ai\Service\Style\AdapterStyleRepository;
use Weline\Ai\Service\Style\AdapterStyleResolver;
use Weline\Ai\Service\Style\StyleRegistry;
use Weline\Ai\Service\Style\StyleService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

#[Acl('Weline_Ai::ai_style_list', 'AI style governance', 'mdi-palette-outline', 'AI style list and governance', 'Weline_Backend::ai_group')]
class Style extends BackendController
{
    #[Acl('Weline_Ai::ai_style_index', 'AI style list', 'mdi-palette-outline', 'View AI styles')]
    public function index(): string
    {
        if ($this->request->getGet('embed') === '1') {
            $this->layoutType = 'default.blank';
        }
        $adminId = $this->adminId();
        $this->assign('activeTab', 'style');
        $this->assign('styles', $adminId > 0 ? \array_values($this->styleService()->listStyles($adminId, true)) : []);
        $this->assign('adapters', $this->listAdapterItems());
        $this->assign('embed', ($this->request->getGet('embed') === '1' || $this->request->getGet('embed') === true));

        return $this->fetch();
    }

    #[Acl('Weline_Ai::ai_style_view', 'AI style catalog', 'mdi-palette-outline', 'View AI style catalog')]
    public function getCatalog(): string
    {
        return $this->catalogResponse(false);
    }

    #[Acl('Weline_Ai::ai_style_view', 'AI style catalog', 'mdi-palette-outline', 'View AI style catalog')]
    public function postCatalog(): string
    {
        return $this->catalogResponse(true);
    }

    #[Acl('Weline_Ai::ai_style_save', 'Save AI style', 'mdi-content-save', 'Save AI custom style')]
    public function postSave(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => __('请先登录后台管理员账号。')], 401);
        }

        try {
            $payload = $this->bodyParams();
            $data = [
                'code' => $this->bodyValue('code', $payload['code'] ?? ''),
                'name' => $this->bodyValue('name', $payload['name'] ?? ''),
                'description' => $this->bodyValue('description', $payload['description'] ?? ''),
                'status' => $this->bodyValue('status', $payload['status'] ?? AiStyle::STATUS_ACTIVE),
                'cta_style' => $this->bodyValue('cta_style', $payload['cta_style'] ?? ''),
                'supplemental_prompt' => $this->bodyValue('supplemental_prompt', $payload['supplemental_prompt'] ?? ''),
            ];
            foreach ([
                'industry_tags',
                'match_keywords',
                'visual_keywords',
                'color_system',
                'layout_patterns',
                'image_strategy',
                'forbidden_patterns',
                'block_rules',
                'qa_rules',
                'example_refs',
            ] as $field) {
                $data[$field] = $this->bodyValue($field, $payload[$field] ?? []);
            }

            return $this->jsonResponse([
                'success' => true,
                'item' => $this->styleService()->saveCustom($data, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse([
                'success' => false,
                'code' => 'STYLE_SAVE_FAILED',
                'message' => __('风格保存失败：%{1}', $throwable->getMessage()),
            ], 400);
        }
    }

    #[Acl('Weline_Ai::ai_style_disable', 'Disable AI style', 'mdi-close-circle-outline', 'Disable AI custom style')]
    public function postDisable(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => __('请先登录后台管理员账号。')], 401);
        }

        $code = \trim((string)$this->bodyValue('code', $this->request->getParam('code', '')));
        if ($code === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_STYLE_CODE', 'message' => __('风格代码不能为空。')], 400);
        }

        try {
            return $this->jsonResponse([
                'success' => true,
                'item' => $this->styleService()->disableCustom($code, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'STYLE_DISABLE_FAILED', 'message' => __('风格禁用失败：%{1}', $throwable->getMessage())], 400);
        }
    }

    #[Acl('Weline_Ai::ai_style_delete', 'Delete AI style', 'mdi-delete-outline', 'Delete AI custom style')]
    public function postDelete(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => __('请先登录后台管理员账号。')], 401);
        }

        $code = \trim((string)$this->bodyValue('code', $this->request->getParam('code', '')));
        if ($code === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_STYLE_CODE', 'message' => __('风格代码不能为空。')], 400);
        }

        try {
            return $this->jsonResponse([
                'success' => true,
                'deleted' => $this->styleService()->deleteCustom($code, $adminId),
                'code' => $code,
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'STYLE_DELETE_FAILED', 'message' => __('风格删除失败：%{1}', $throwable->getMessage())], 400);
        }
    }

    #[Acl('Weline_Ai::ai_style_clone', 'Clone AI style', 'mdi-content-copy', 'Clone builtin/module style')]
    public function postCloneBuiltin(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => __('请先登录后台管理员账号。')], 401);
        }

        $code = \trim((string)$this->bodyValue('code', $this->request->getParam('code', '')));
        if ($code === '') {
            return $this->jsonResponse(['success' => false, 'message' => __('风格代码不能为空。')], 400);
        }

        try {
            return $this->jsonResponse([
                'success' => true,
                'item' => $this->styleService()->cloneBuiltin($code, $adminId),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'message' => __('风格克隆失败：%{1}', $throwable->getMessage())], 400);
        }
    }

    #[Acl('Weline_Ai::ai_style_match', 'Match AI style', 'mdi-target', 'Match AI style by brief')]
    public function postMatch(): string
    {
        $adminId = $this->adminId();
        if ($adminId <= 0) {
            return $this->jsonResponse(['success' => false, 'message' => __('请先登录后台管理员账号。')], 401);
        }

        try {
            $title = \trim((string)$this->bodyValue('site_title', $this->bodyValue('title', $this->request->getParam('site_title', ''))));
            $brief = \trim((string)$this->bodyValue('brief_description', $this->bodyValue('brief', $this->request->getParam('brief_description', ''))));
            $adapterCode = \trim((string)$this->bodyValue('adapter_code', $this->request->getParam('adapter_code', '')));
            $match = $this->styleService()->matchStyle($title, $brief, $adminId, $adapterCode);
            $item = \is_array($match['item'] ?? null) ? $match['item'] : null;

            return $this->jsonResponse([
                'success' => true,
                'matched' => !empty($match['matched']),
                'item' => $item,
                'direction' => $item,
                'style' => $item,
                'score' => (int)($match['score'] ?? 0),
                'reason' => (string)($match['reason'] ?? ''),
                'matched_keywords' => $match['matched_keywords'] ?? [],
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'message' => __('风格匹配失败：%{1}', $throwable->getMessage())], 500);
        }
    }

    #[Acl('Weline_Ai::ai_adapter_style_manage', 'Manage adapter styles', 'mdi-link-variant', 'Manage manual adapter style bindings')]
    public function postBindAdapterStyle(): string
    {
        $adapterCode = \trim((string)$this->bodyValue('adapter_code', ''));
        $styleCode = \trim((string)$this->bodyValue('style_code', ''));
        if ($adapterCode === '' || $styleCode === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_BINDING', 'message' => __('适配器代码和风格代码不能为空。')], 400);
        }

        try {
            $adminId = $this->adminId();
            $style = $this->registry()->getStyle($styleCode, $adminId, false);
            if (empty($style['exists']) || (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
                return $this->jsonResponse(['success' => false, 'code' => 'STYLE_NOT_ACTIVE', 'message' => __('只有启用状态的风格才能绑定到适配器。')], 400);
            }
            $binding = $this->adapterStyleRepository()->bind($adapterCode, $styleCode, $adminId);
            return $this->jsonResponse([
                'success' => true,
                'binding_id' => $binding->getId(),
                'catalog' => $this->resolver()->buildStyleCatalog($adapterCode, [], $adminId, true),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'BIND_FAILED', 'message' => __('适配器风格绑定失败：%{1}', $throwable->getMessage())], 400);
        }
    }

    #[Acl('Weline_Ai::ai_adapter_style_manage', 'Manage adapter styles', 'mdi-link-variant-off', 'Manage manual adapter style bindings')]
    public function postUnbindAdapterStyle(): string
    {
        $adapterCode = \trim((string)$this->bodyValue('adapter_code', ''));
        $styleCode = \trim((string)$this->bodyValue('style_code', ''));
        if ($adapterCode === '' || $styleCode === '') {
            return $this->jsonResponse(['success' => false, 'code' => 'INVALID_BINDING', 'message' => __('适配器代码和风格代码不能为空。')], 400);
        }

        try {
            $adminId = $this->adminId();
            return $this->jsonResponse([
                'success' => true,
                'removed' => $this->adapterStyleRepository()->unbind($adapterCode, $styleCode),
                'catalog' => $this->resolver()->buildStyleCatalog($adapterCode, [], $adminId, true),
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'code' => 'UNBIND_FAILED', 'message' => __('适配器风格解绑失败：%{1}', $throwable->getMessage())], 400);
        }
    }

    private function catalogResponse(bool $fromBody): string
    {
        $adminId = $this->adminId();
        $adapterCode = \trim((string)($fromBody ? $this->bodyValue('adapter_code', '') : $this->request->getGet('adapter_code', '')));
        $temporaryCodes = $fromBody
            ? $this->parseCodeList($this->bodyValue('temporary_style_codes', $this->bodyValue('selected_style_codes', [])))
            : $this->parseCodeList($this->request->getGet('temporary_style_codes', $this->request->getGet('selected_style_codes', [])));
        $includeInactiveRaw = $fromBody
            ? $this->bodyValue('include_inactive', $this->bodyValue('include_disabled', false))
            : $this->request->getGet('include_inactive', $this->request->getGet('include_disabled', false));
        $includeInactive = $this->truthy($includeInactiveRaw);

        try {
            if ($adapterCode !== '') {
                $catalog = $this->resolver()->buildStyleCatalog($adapterCode, $temporaryCodes, $adminId, $includeInactive);
                return $this->jsonResponse(['success' => true] + $catalog);
            }
            return $this->jsonResponse([
                'success' => true,
                'items' => \array_values($this->styleService()->listStyles($adminId, $includeInactive)),
                'default_style_codes' => [],
                'manual_style_codes' => [],
                'warnings' => [],
            ]);
        } catch (\Throwable $throwable) {
            return $this->jsonResponse(['success' => false, 'message' => __('风格目录加载失败：%{1}', $throwable->getMessage()), 'items' => []], 500);
        }
    }

    /**
     * @return list<string>
     */
    private function parseCodeList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }
        $codes = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = \trim((string)$item);
            if ($code !== '' && !\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function bodyParams(): array
    {
        $params = $this->request->getBodyParams(true);
        return \is_array($params) ? $params : [];
    }

    private function bodyValue(string $key, mixed $default = null): mixed
    {
        $value = $this->request->getPost($key, null);
        if ($value !== null) {
            return $value;
        }
        $body = $this->bodyParams();
        return \array_key_exists($key, $body) ? $body[$key] : $default;
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAdapterItems(): array
    {
        $itemsByCode = [];
        try {
            foreach ($this->adapterScanner()->scanAllAdapters() as $adapter) {
                $code = \trim((string)$adapter->getCode());
                if ($code === '') {
                    continue;
                }
                $itemsByCode[$code] = [
                    'code' => $code,
                    'name' => (string)$adapter->getName(),
                    'description' => (string)$adapter->getDescription(),
                    'version' => (string)$adapter->getVersion(),
                    'default_style_codes' => $this->resolver()->getDefaultStyleCodes($code),
                    'manual_style_codes' => $this->adapterStyleRepository()->listActiveStyleCodes($code),
                ];
            }
        } catch (\Throwable $throwable) {
            if (\function_exists('w_log_error')) {
                w_log_error('AI style adapter list unavailable: ' . $throwable->getMessage());
            }
        }

        \ksort($itemsByCode);
        return \array_values($itemsByCode);
    }

    private function adminId(): int
    {
        return \max(0, (int)$this->getLoginUserId());
    }

    private function jsonResponse(array $data, int $statusCode = 200): string
    {
        $this->request->getResponse()->setHttpResponseCode($statusCode);
        $this->request->getResponse()->setHeader('Content-Type', 'application/json; charset=utf-8');
        return \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function styleService(): StyleService
    {
        return ObjectManager::getInstance(StyleService::class);
    }

    private function registry(): StyleRegistry
    {
        return ObjectManager::getInstance(StyleRegistry::class);
    }

    private function resolver(): AdapterStyleResolver
    {
        return ObjectManager::getInstance(AdapterStyleResolver::class);
    }

    private function adapterStyleRepository(): AdapterStyleRepository
    {
        return ObjectManager::getInstance(AdapterStyleRepository::class);
    }

    private function adapterScanner(): AdapterScanner
    {
        return ObjectManager::getInstance(AdapterScanner::class);
    }
}
