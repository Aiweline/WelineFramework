<?php

declare(strict_types=1);

namespace Weline\Catalog\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Catalog\Service\CatalogHubService;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Ui\FormKey;

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
        $selectedId = max(0, (int)$this->request->getParam('id', (int)$this->request->getGet('category_id', 0)));
        $isNew = (int)$this->request->getGet('new', 0) === 1;
        $parentId = $isNew
            ? max(0, (int)$this->request->getGet('pid', (int)$this->request->getGet('parent_id', 0)))
            : 0;
        $categories = [];
        $category = null;
        $error = '';
        try {
            $categories = $this->hub->execute('tree', $params);
            if (!is_array($categories)) {
                $categories = [];
            }
            if ($selectedId > 0 && !$isNew) {
                $category = $this->hub->execute('view', $params + ['category_id' => $selectedId]);
            }
        } catch (\Throwable $exception) {
            $this->request->getResponse()->setCode(503);
            $error = (string)__('分类读取失败：%{1}', [$exception->getMessage()]);
        }

        $spaces = $this->hub->listSpaces();
        $this->assign('title', (string)__('万能分类'));
        $this->assign('space', (string)$params['space']);
        $this->assign('scope_level', (string)$params['scope_level']);
        $this->assign('website_id', $websiteId);
        $this->assign('spaces', $spaces);
        $this->assign('error', $error);
        $this->assign('categories_tree', $categories);
        $this->assign('category', $category);
        $this->assign('selected_category_id', $selectedId);
        $this->assign('create_parent_id', $parentId);
        $this->assign('is_new_category', $isNew);
        $this->assign('websiteOptionsJson', $this->json($this->loadWebsiteOptions()));
        $this->assign('productWebsiteSelectValue', (string)$websiteId);
        $this->assign('productWebsiteSelectDisplay', (string)$websiteId);

        return (string)$this->fetch('Weline_Catalog::templates/backend/category/index.phtml');
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
        $websiteId = max(0, (int)$this->request->getGet('website_id', 0));

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
        $websiteId = max(0, (int)$this->request->getPost('website_id', $fallbackWebsiteId ? 0 : -1));
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 必须是非负整数'));
        }

        if ($action === ObjectAction::UPDATE && (int)$this->request->getPost('id', 0) <= 0) {
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
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, scalar> $extra
     */
    private function indexUrl(array $params, array $extra = []): string
    {
        return 'weline_catalog/backend/category/index?' . http_build_query([
            'space' => (string)($params['space'] ?? self::DEFAULT_SPACE),
            'scope_level' => (string)($params['scope_level'] ?? self::DEFAULT_SCOPE_LEVEL),
            'website_id' => max(0, (int)($params['website_id'] ?? 0)),
            ...$extra,
        ]);
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

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );
    }
}
