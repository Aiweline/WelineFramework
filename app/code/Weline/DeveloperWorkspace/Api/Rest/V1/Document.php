<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\CacheManager\Service\RuntimeCachePolicy;
use Weline\DeveloperWorkspace\Model\Document as DocumentModel;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;

class Document extends DevToolRestController
{
    private const DOCS_TTL_SECONDS = 60;

    private DocumentModel $documentModel;
    private Catalog $catalogModel;
    private ?DevToolPayloadStore $payloadStore = null;

    public function __construct(
        DocumentModel $documentModel,
        Catalog $catalogModel
    ) {
        parent::__construct();
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }

    public function getModules()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool document is not allowed', [], 403);
            }
            $data = $this->payloadStore()->remember('docs', 'docs:modules:v1', $this->docsTtl(), function (): array {
                return $this->buildModules();
            });

            return $this->success('success', $data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function getSearch()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool document is not allowed', [], 403);
            }
            $keyword = \trim((string)$this->request->getGet('keyword', ''));
            $module = \trim((string)$this->request->getGet('module', ''));
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = (int)$this->request->getGet('size', 20);
            $page = \max(1, $page);
            $pageSize = ($pageSize < 1 || $pageSize > 100) ? 20 : $pageSize;

            $key = 'docs:search:v2:' . DevToolPayloadStore::hashQuery([
                'keyword' => $keyword,
                'module' => $module,
                'page' => $page,
                'size' => $pageSize,
            ]);

            $data = $this->payloadStore()->remember('docs', $key, $this->docsTtl(), function () use ($keyword, $module, $page, $pageSize): array {
                return $this->buildSearch($keyword, $module, $page, $pageSize);
            });

            return $this->success('success', $data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function getDetail()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool document is not allowed', [], 403);
            }
            $id = (int)$this->request->getGet('id', 0);
            if ($id <= 0) {
                return $this->error('文档ID不能为空', [], 400);
            }

            $data = $this->payloadStore()->remember('docs', 'docs:detail:' . $id, $this->docsTtl(), function () use ($id): array {
                return $this->buildDetail($id);
            });

            if (empty($data)) {
                return $this->error('文档不存在', [], 404);
            }

            return $this->success('success', $data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function getCatalogs()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool document is not allowed', [], 403);
            }
            $data = $this->payloadStore()->remember('docs', 'docs:catalogs:v1', $this->docsTtl(), function (): array {
                $catalogs = $this->catalogModel->clear()
                    ->where(Catalog::schema_fields_is_active, 1)
                    ->order(Catalog::schema_fields_position, 'ASC')
                    ->order(Catalog::schema_fields_ID, 'ASC')
                    ->select()
                    ->fetch()
                    ->getItems();

                return $this->buildCatalogTree($catalogs);
            });

            return $this->success('success', $data);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    private function buildModules(): array
    {
        $modules = $this->documentModel->clear()
            ->fields(DocumentModel::schema_fields_MODULE_NAME)
            ->where(DocumentModel::schema_fields_MODULE_NAME, '', '!=')
            ->group(DocumentModel::schema_fields_MODULE_NAME)
            ->select()
            ->fetch()
            ->getItems();

        $moduleList = [];
        foreach ($modules as $module) {
            $moduleName = (string)($module->getData(DocumentModel::schema_fields_MODULE_NAME) ?? '');
            if ($moduleName === '') {
                continue;
            }
            $count = $this->documentModel->clear()
                ->where(DocumentModel::schema_fields_MODULE_NAME, $moduleName)
                ->count();

            $moduleList[] = [
                'name' => $moduleName,
                'display_name' => $this->formatModuleName($moduleName),
                'doc_count' => $count,
            ];
        }

        return $moduleList;
    }

    private function buildSearch(string $keyword, string $module, int $page, int $pageSize): array
    {
        $query = $this->documentModel->clear();
        if ($keyword !== '') {
            $query->where(DocumentModel::schema_fields_TITLE, '%' . $keyword . '%', 'like');
        }
        if ($module !== '' && $module !== 'all') {
            $query->where(DocumentModel::schema_fields_MODULE_NAME, $module);
        }
        $query->order(DocumentModel::schema_fields_SORT_ORDER, 'ASC')
            ->order(DocumentModel::schema_fields_ID, 'DESC');

        $total = $query->count();

        $query = $this->documentModel->clear();
        if ($keyword !== '') {
            $query->where(DocumentModel::schema_fields_TITLE, '%' . $keyword . '%', 'like');
        }
        if ($module !== '' && $module !== 'all') {
            $query->where(DocumentModel::schema_fields_MODULE_NAME, $module);
        }
        $query->order(DocumentModel::schema_fields_SORT_ORDER, 'ASC')
            ->order(DocumentModel::schema_fields_ID, 'DESC')
            ->limit($pageSize, ($page - 1) * $pageSize);

        $items = [];
        foreach ($query->select()->fetch()->getItems() as $doc) {
            $items[] = [
                'id' => $doc->getId(),
                'title' => $doc->getTitle(),
                'summary' => $doc->getData(DocumentModel::schema_fields_summary),
                'module_name' => $doc->getModuleName(),
                'module_display' => $this->formatModuleName((string)$doc->getModuleName()),
                'file_name' => $doc->getFileName(),
                'file_path' => $doc->getFilePath(),
                'category_id' => $doc->getCategoryId(),
                'is_auto_imported' => $doc->isAutoImported(),
                'url' => $doc->getUrl(),
            ];
        }

        return [
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
                'totalPages' => (int)\ceil($total / $pageSize),
            ],
        ];
    }

    private function buildDetail(int $id): array
    {
        $doc = $this->documentModel->clear()->load($id);
        if (!$doc->getId()) {
            return [];
        }

        $catalog = null;
        if ($doc->getCategoryId()) {
            $catalogData = $this->catalogModel->clear()->load((int)$doc->getCategoryId());
            if ($catalogData->getId()) {
                $catalog = [
                    'id' => $catalogData->getId(),
                    'name' => $catalogData->getName(),
                    'description' => $catalogData->getDescription(),
                ];
            }
        }

        return [
            'id' => $doc->getId(),
            'title' => $doc->getTitle(),
            'summary' => $doc->getData(DocumentModel::schema_fields_summary),
            'content' => $doc->getDecodeContent(),
            'module_name' => $doc->getModuleName(),
            'module_display' => $this->formatModuleName((string)$doc->getModuleName()),
            'file_name' => $doc->getFileName(),
            'file_path' => $doc->getFilePath(),
            'category' => $catalog,
            'is_auto_imported' => $doc->isAutoImported(),
        ];
    }

    private function buildCatalogTree(array $catalogs, int $parentId = 0): array
    {
        $tree = [];
        foreach ($catalogs as $catalog) {
            if ((int)$catalog->getPid() !== $parentId) {
                continue;
            }
            $tree[] = [
                'id' => $catalog->getId(),
                'name' => $catalog->getName(),
                'description' => $catalog->getDescription(),
                'level' => $catalog->getData(Catalog::schema_fields_level),
                'is_system' => $catalog->getData(Catalog::schema_fields_is_system),
                'children' => $this->buildCatalogTree($catalogs, (int)$catalog->getId()),
            ];
        }

        return $tree;
    }

    private function formatModuleName(string $moduleName): string
    {
        return \str_replace('_', ' / ', $moduleName);
    }

    private function payloadStore(): DevToolPayloadStore
    {
        if ($this->payloadStore === null) {
            $this->payloadStore = ObjectManager::getInstance(DevToolPayloadStore::class);
        }

        return $this->payloadStore;
    }

    private function docsTtl(): int
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class)->ttl('dev.docs_ttl', self::DOCS_TTL_SECONDS);
    }

    private function isAllowed(): bool
    {
        if ((\defined('DEV') && DEV) || (\defined('DEBUG') && DEBUG)) {
            return true;
        }
        $cookieName = (string)Env::get('dev_tool.cookie_name', 'w_dev_tool');

        return Cookie::get($cookieName) === '1';
    }
}
