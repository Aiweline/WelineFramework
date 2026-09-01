<?php

declare(strict_types=1);

namespace Weline\Catalog\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\State;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Ui\FormKey;
use Weline\Websites\Model\Website;

final class Category extends BackendController
{
    private const DEFAULT_SPACE = 'product';
    private const DEFAULT_SCOPE_LEVEL = 'website';

    public function __construct(
        private readonly CatalogHubService $hub,
        private readonly BackendObjectAuthorizationGuardInterface $objectAuthorization,
    ) {
    }

    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '万能分类',
        'tree',
        '维护多空间分类树',
        'Weline_Backend::commerce:catalog:group',
    )]
    public function index(): string
    {
        $context = $this->readPageContext();
        if ($context['error'] !== '') {
            $this->request->getResponse()->setCode(403);

            return $context['error'];
        }

        $params = $context['params'];
        $websiteId = (int)$params['website_id'];
        $scopeLevel = (string)$params['scope_level'];
        $isStructureMode = $scopeLevel === 'website';
        $selectedId = max(0, (int)$this->request->getParam('id', (int)$this->request->getGet('category_id', 0)));
        $isNew = $isStructureMode && (int)$this->request->getGet('new', 0) === 1;
        $parentId = $isNew
            ? max(0, (int)$this->request->getGet('pid', (int)$this->request->getGet('parent_id', 0)))
            : 0;
        $categories = [];
        $category = null;
        $displaySelection = [];
        $error = '';
        try {
            $categories = $this->hub->execute('tree', $params);
            if (!is_array($categories)) {
                $categories = [];
            }
            if ($selectedId > 0 && !$isNew && $isStructureMode) {
                $category = $this->hub->execute('view', $params + ['category_id' => $selectedId]);
            }
            if (!$isStructureMode) {
                $displaySelection = $this->hub->execute('readDisplaySelection', $params);
                if (!is_array($displaySelection)) {
                    $displaySelection = [];
                }
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('分类读取失败：%{1}', [$exception->getMessage()]);
        }

        $spaces = $this->hub->listSpaces();
        $this->assign('title', (string)__('万能分类'));
        $this->assign('space', (string)$params['space']);
        $this->assign('scope_level', $scopeLevel);
        $this->assign('website_id', $websiteId);
        $this->assign('store_id', (int)$params['store_id']);
        $this->assign('channel_id', (int)$params['channel_id']);
        $this->assign('is_structure_mode', $isStructureMode);
        $this->assign('spaces', $spaces);
        $this->assign('error', $error);
        $this->assign('categories_tree', $categories);
        $this->assign('category', $category);
        $this->assign('display_selection', $displaySelection);
        $this->assign('selected_category_id', $selectedId);
        $this->assign('create_parent_id', $parentId);
        $this->assign('is_new_category', $isNew);
        $this->assign('websiteOptionsJson', $this->json($this->loadWebsiteOptions()));
        $this->assign('productWebsiteSelectValue', (string)$websiteId);
        $this->assign('productWebsiteSelectDisplay', (string)$websiteId);
        $storeOptions = $this->loadStoreOptions($websiteId);
        $channelOptions = $this->loadChannelOptions($websiteId, (int)$params['store_id']);
        $this->assign('storeOptionsJson', $this->json($storeOptions));
        $this->assign('channelOptionsJson', $this->json($channelOptions));
        $this->assign('catalogStoreSelectValue', (string)((int)$params['store_id']));
        $this->assign('catalogStoreSelectDisplay', (string)((int)$params['store_id']));
        $this->assign('catalogChannelSelectValue', (string)((int)$params['channel_id']));
        $this->assign('catalogChannelSelectDisplay', (string)((int)$params['channel_id']));

        return (string)$this->fetch('Weline_Catalog::templates/backend/category/index.phtml');
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '保存展示选择',
        'save',
        '保存 Store/Channel 分类展示选择',
    )]
    public function postDisplaySave(): string
    {
        try {
            $params = $this->readMutationParams(ObjectAction::UPDATE);
            $rowsRaw = $this->request->getPost('rows', []);
            if (!is_array($rowsRaw)) {
                $decoded = json_decode((string)$rowsRaw, true);
                $rowsRaw = is_array($decoded) ? $decoded : [];
            }
            $result = $this->hub->execute('saveDisplaySelection', $params + ['rows' => $rowsRaw]);
            if (is_array($result) && ($result['success'] ?? true) === false) {
                throw new \RuntimeException((string)($result['message'] ?? __('展示选择保存失败')));
            }
            if ($this->request->isAjax()) {
                return $this->fetchJson([
                    'success' => true,
                    'msg' => (string)__('展示选择已保存'),
                    'data' => is_array($result) ? $result : [],
                ]);
            }
            $this->getMessageManager()->addSuccess(__('展示选择已保存'));

            return (string)$this->redirect($this->indexUrl($params));
        } catch (\Throwable $exception) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
            }
            $this->getMessageManager()->addError($exception->getMessage());

            return (string)$this->redirect($this->indexUrl($this->readMutationParams(ObjectAction::UPDATE, true)));
        }
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '保存分类',
        'save',
        '保存分类结构',
    )]
    public function postCategoryPost(): string
    {
        try {
            $params = $this->readMutationParams(ObjectAction::UPDATE);
            $result = $this->hub->execute('save', $params + [
                'category_id' => max(0, (int)$this->request->getPost('id', 0)),
                'parent_id' => max(0, (int)$this->request->getPost('pid', 0)),
                'name' => trim((string)$this->request->getPost('name', '')),
                'code' => trim((string)$this->request->getPost('code', '')),
                'google_taxonomy_id' => trim((string)$this->request->getPost('google_taxonomy_id', '')),
                'image' => trim((string)$this->request->getPost('image', '')),
                'banner' => trim((string)$this->request->getPost('banner', '')),
                'summary' => trim((string)$this->request->getPost('summary', '')),
                'description' => trim((string)$this->request->getPost('description', '')),
                'is_active' => !empty($this->request->getPost('is_active')) ? 1 : 0,
            ]);
            if (is_array($result) && ($result['success'] ?? true) === false) {
                throw new \RuntimeException((string)($result['message'] ?? __('分类保存失败')));
            }
            $categoryId = max(0, (int)($result['category_id'] ?? $result['id'] ?? 0));
            if ($this->request->isAjax()) {
                return $this->fetchJson([
                    'success' => true,
                    'msg' => (string)__('分类已保存'),
                    'data' => [
                        'id' => $categoryId,
                        'category_id' => $categoryId,
                        'code' => (string)($result['code'] ?? ''),
                        'path' => (string)($result['path'] ?? ''),
                    ],
                ]);
            }
            $this->getMessageManager()->addSuccess(__('分类已保存'));

            return (string)$this->redirect($this->indexUrl($params, ['id' => $categoryId]));
        } catch (\Throwable $exception) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
            }
            $this->getMessageManager()->addError($exception->getMessage());

            return (string)$this->redirect($this->indexUrl($this->readMutationParams(ObjectAction::UPDATE, true)));
        }
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '删除分类',
        'trash',
        '删除分类结构',
    )]
    public function postCategoryDelete(): string
    {
        try {
            $params = $this->readMutationParams(ObjectAction::DELETE);
            $this->hub->execute('delete', $params + [
                'category_id' => max(0, (int)$this->request->getPost('id', 0)),
            ]);
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => true, 'msg' => (string)__('分类已删除')]);
            }
            $this->getMessageManager()->addSuccess(__('分类已删除'));
        } catch (\Throwable $exception) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
            }
            $this->getMessageManager()->addError($exception->getMessage());
        }

        return (string)$this->redirect($this->indexUrl($this->readMutationParams(ObjectAction::DELETE, true)));
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '分类排序',
        'tree',
        '拖拽排序分类',
    )]
    public function postCategoryUpdateOrder(): string
    {
        try {
            $params = $this->readMutationParams(ObjectAction::UPDATE);
            $data = $this->hub->execute('reorder', $params + [
                'category_id' => max(0, (int)$this->request->getPost('id', 0)),
                'parent_id' => max(0, (int)$this->request->getPost('pid', 0)),
                'level' => max(1, (int)$this->request->getPost('level', 1)),
                'position' => max(1, (int)$this->request->getPost('position', 1)),
            ]);

            return $this->fetchJson([
                'success' => true,
                'msg' => (string)__('分类顺序已保存'),
                'data' => is_array($data) ? $data : [],
            ]);
        } catch (\Throwable $exception) {
            return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
        }
    }

    #[Acl(
        'Weline_Catalog::commerce:universal-catalog:categories',
        '查看分类',
        'tree',
        '查看分类详情',
    )]
    public function getCategoryView(): string
    {
        try {
            $params = $this->readPageContext()['params'];
            $category = $this->hub->execute('view', $params + [
                'category_id' => max(0, (int)$this->request->getParam('id', 0)),
            ]);
            if ($category === null) {
                return $this->fetchJson(['success' => false, 'msg' => (string)__('分类不存在')]);
            }

            return $this->fetchJson(['success' => true, 'data' => $category]);
        } catch (\Throwable $exception) {
            return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
        }
    }

    /**
     * @return array{params: array<string, mixed>, error: string}
     */
    private function readPageContext(): array
    {
        $space = trim((string)$this->request->getParam('space', self::DEFAULT_SPACE));
        $scopeLevel = strtolower(trim((string)$this->request->getParam('scope_level', self::DEFAULT_SCOPE_LEVEL)));
        if (!in_array($scopeLevel, ['website', 'store', 'channel'], true)) {
            $scopeLevel = self::DEFAULT_SCOPE_LEVEL;
        }
        $websiteId = $this->resolvePageWebsiteId();
        $storeId = max(0, (int)$this->request->getGet('store_id', 0));
        $channelId = max(0, (int)$this->request->getGet('channel_id', 0));
        if ($scopeLevel === 'website') {
            $storeId = 0;
            $channelId = 0;
        } elseif ($scopeLevel === 'store') {
            $channelId = 0;
        }

        try {
            $this->objectAuthorization->requireForQuery(
                ObjectAction::VIEW,
                $this->websiteIdentity($websiteId),
            );
        } catch (FrontendQueryException $exception) {
            return ['params' => [], 'error' => $exception->getMessage()];
        }

        return [
            'params' => [
                'space' => $space !== '' ? $space : self::DEFAULT_SPACE,
                'scope_level' => $scopeLevel,
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
                'locale' => (string)State::getLangLocal(),
            ],
            'error' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readMutationParams(string $action, bool $fallbackWebsiteId = false): array
    {
        $space = trim((string)$this->request->getPost('space', self::DEFAULT_SPACE));
        $scopeLevel = strtolower(trim((string)$this->request->getPost('scope_level', self::DEFAULT_SCOPE_LEVEL)));
        if (!in_array($scopeLevel, ['website', 'store', 'channel'], true)) {
            $scopeLevel = self::DEFAULT_SCOPE_LEVEL;
        }
        $websiteId = $this->resolveMutationWebsiteId($fallbackWebsiteId);
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 必须是非负整数'));
        }
        $storeId = max(0, (int)$this->request->getPost('store_id', 0));
        $channelId = max(0, (int)$this->request->getPost('channel_id', 0));
        if ($scopeLevel === 'website') {
            $storeId = 0;
            $channelId = 0;
        } elseif ($scopeLevel === 'store') {
            $channelId = 0;
        }

        if ($action === ObjectAction::UPDATE && (int)$this->request->getPost('id', 0) <= 0
            && $scopeLevel === 'website') {
            $action = ObjectAction::CREATE;
        }

        $this->objectAuthorization->requireSubmitForQuery(
            $action,
            $this->websiteIdentity($websiteId),
            0,
        );

        return [
            'space' => $space !== '' ? $space : self::DEFAULT_SPACE,
            'scope_level' => $scopeLevel,
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'locale' => (string)State::getLangLocal(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, scalar> $extra
     */
    private function indexUrl(array $params, array $extra = []): string
    {
        return 'weline_catalog/backend/category/index?' . http_build_query(array_filter([
            'space' => (string)($params['space'] ?? self::DEFAULT_SPACE),
            'scope_level' => (string)($params['scope_level'] ?? self::DEFAULT_SCOPE_LEVEL),
            'website_id' => max(0, (int)($params['website_id'] ?? 0)),
            'store_id' => max(0, (int)($params['store_id'] ?? 0)),
            'channel_id' => max(0, (int)($params['channel_id'] ?? 0)),
            ...$extra,
        ], static fn(mixed $value): bool => $value !== '' && $value !== null));
    }

    private function websiteIdentity(int $websiteId): ScopeIdentity
    {
        $code = 'default';
        foreach ($this->loadWebsiteOptions() as $option) {
            if ((int)($option['website_id'] ?? 0) === $websiteId) {
                $code = trim((string)($option['code'] ?? 'default'));
                break;
            }
        }

        return ScopeIdentity::website($websiteId, $code !== '' ? $code : 'default');
    }

    private function resolvePageWebsiteId(): int
    {
        $raw = $this->request->getGet('website_id');
        if ($raw !== null && $raw !== '') {
            return max(Website::ID_DEFAULT, (int)$raw);
        }

        // Align with Product catalog admin: default Website scope is ID 0 when the page is opened without a selector submit.
        return Website::ID_DEFAULT;
    }

    private function resolveMutationWebsiteId(bool $fallbackWebsiteId): int
    {
        $posted = $this->request->getPost('website_id');
        if ($posted !== null && $posted !== '') {
            return max(Website::ID_DEFAULT, (int)$posted);
        }
        if ($fallbackWebsiteId) {
            return $this->resolvePageWebsiteId();
        }

        return Website::ID_DEFAULT;
    }

    /** @return list<array<string, mixed>> */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteList', []);
        } catch (\Throwable) {
            $rows = [];
        }
        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $websiteId = (int)($row['website_id'] ?? $row['id'] ?? 0);
            $code = trim((string)($row['code'] ?? $row['website_code'] ?? ''));
            $name = trim((string)($row['name'] ?? $code));
            if ($websiteId < 0 || ($websiteId === 0 && $code === '')) {
                continue;
            }
            $options[] = [
                'website_id' => $websiteId,
                'code' => $code !== '' ? $code : 'default',
                'name' => $name !== '' ? $name : ($code !== '' ? $code : 'default'),
                'url' => trim((string)($row['url'] ?? '')),
                'label' => ($name !== '' ? $name : ($code !== '' ? $code : 'default'))
                    . ' / ' . ($code !== '' ? $code : 'default'),
            ];
        }
        $hasDefault = false;
        foreach ($options as $option) {
            $hasDefault = $hasDefault || (int)$option['website_id'] === 0;
        }
        if (!$hasDefault) {
            array_unshift($options, [
                'website_id' => 0,
                'code' => 'default',
                'name' => 'default',
                'url' => '',
                'label' => 'default / default',
            ]);
        }

        return $options;
    }

    /** @return list<array<string, mixed>> */
    private function loadStoreOptions(int $websiteId): array
    {
        try {
            $rows = w_query('websites', 'getStoreList', ['website_id' => $websiteId]);
        } catch (\Throwable) {
            try {
                $rows = w_query('store', 'getStoreList', ['website_id' => $websiteId]);
            } catch (\Throwable) {
                $rows = [];
            }
        }
        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $storeId = (int)($row['store_id'] ?? $row['id'] ?? 0);
            if ($storeId < 0) {
                continue;
            }
            $code = trim((string)($row['code'] ?? $row['store_code'] ?? ''));
            $name = trim((string)($row['name'] ?? $code));
            $options[] = [
                'store_id' => $storeId,
                'code' => $code,
                'name' => $name !== '' ? $name : ('Store #' . $storeId),
                'label' => ($name !== '' ? $name : ('Store #' . $storeId))
                    . ($code !== '' ? ' / ' . $code : ''),
            ];
        }

        return $options;
    }

    /** @return list<array<string, mixed>> */
    private function loadChannelOptions(int $websiteId, int $storeId): array
    {
        try {
            $rows = w_query('websites', 'getChannelList', [
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ]);
        } catch (\Throwable) {
            $rows = [];
        }
        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $channelId = (int)($row['channel_id'] ?? $row['id'] ?? 0);
            if ($channelId < 0) {
                continue;
            }
            $code = trim((string)($row['code'] ?? $row['channel_code'] ?? ''));
            $name = trim((string)($row['name'] ?? $code));
            $options[] = [
                'channel_id' => $channelId,
                'code' => $code,
                'name' => $name !== '' ? $name : ('Channel #' . $channelId),
                'label' => ($name !== '' ? $name : ('Channel #' . $channelId))
                    . ($code !== '' ? ' / ' . $code : ''),
            ];
        }

        return $options;
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }
}
