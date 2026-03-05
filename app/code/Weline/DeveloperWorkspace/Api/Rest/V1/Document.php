<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Model\Document as DocumentModel;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\App\Controller\BackendRestController;
use Weline\Framework\Manager\ObjectManager;

/**
 * 文档API控制器 - 提供开发工具面板的文档搜索和浏览
 * 需要后台登录权限
 */
class Document extends BackendRestController
{
    private DocumentModel $documentModel;
    private Catalog $catalogModel;
    
    public function __construct(
        DocumentModel $documentModel,
        Catalog $catalogModel
    ) {
        $this->documentModel = $documentModel;
        $this->catalogModel = $catalogModel;
    }
    
    /**
     * 获取所有模块列表
     * GET /api/dev/document/modules
     */
    public function getModules()
    {
        try {
            // 获取所有有文档的模块（去重）
            $modules = $this->documentModel->clear()
                ->fields(DocumentModel::schema_fields_MODULE_NAME)
                ->where(DocumentModel::schema_fields_MODULE_NAME, '', '!=')
                ->group(DocumentModel::schema_fields_MODULE_NAME)
                ->select()
                ->fetch()
                ->getItems();
            
            $moduleList = [];
            foreach ($modules as $module) {
                $moduleName = $module->getData(DocumentModel::schema_fields_MODULE_NAME) ?? '';
                if ($moduleName) {
                    // 统计该模块的文档数量
                    $count = $this->documentModel->clear()
                        ->where(DocumentModel::schema_fields_MODULE_NAME, $moduleName)
                        ->count();
                    
                    $moduleList[] = [
                        'name' => $moduleName,
                        'display_name' => $this->formatModuleName($moduleName),
                        'doc_count' => $count
                    ];
                }
            }
            
            return $this->success('success', $moduleList);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 搜索文档
     * GET /api/dev/document/search?keyword=xxx&module=xxx&page=1&size=20
     */
    public function getSearch()
    {
        try {
            $keyword = $this->request->getGet('keyword', '');
            $module = $this->request->getGet('module', '');
            $page = (int)$this->request->getGet('page', 1);
            $pageSize = (int)$this->request->getGet('size', 20);
            
            if ($page < 1) $page = 1;
            if ($pageSize < 1 || $pageSize > 100) $pageSize = 20;
            
            // 构建查询
            $query = $this->documentModel->clear();
            
            // 关键词搜索（标题、摘要、内容）
            // 注意：由于框架限制，使用 OR 条件需要分别执行查询后合并，或使用 SQL 原生查询
            // 这里简化处理：仅搜索标题字段
            if ($keyword) {
                $query->where(DocumentModel::schema_fields_TITLE, '%' . $keyword . '%', 'like');
            }
            
            // 模块过滤
            if ($module && $module !== 'all') {
                $query->where(DocumentModel::schema_fields_MODULE_NAME, $module);
            }
            
            // 排序
            $query->order(DocumentModel::schema_fields_SORT_ORDER, 'ASC')
                  ->order(DocumentModel::schema_fields_ID, 'DESC');
            
            // 先获取总数（不带分页限制）
            $total = $query->count();
            
            // 重新构建查询并添加分页
            $query = $this->documentModel->clear();
            if ($keyword) {
                $query->where(DocumentModel::schema_fields_TITLE, '%' . $keyword . '%', 'like');
            }
            if ($module && $module !== 'all') {
                $query->where(DocumentModel::schema_fields_MODULE_NAME, $module);
            }
            $query->order(DocumentModel::schema_fields_SORT_ORDER, 'ASC')
                  ->order(DocumentModel::schema_fields_ID, 'DESC');
            
            // 添加分页
            $offset = ($page - 1) * $pageSize;
            $query->limit($offset, $pageSize);
            
            // 获取数据
            $documents = $query->select()->fetch()->getItems();
            
            // 格式化返回数据
            $items = [];
            foreach ($documents as $doc) {
                $items[] = [
                    'id' => $doc->getId(),
                    'title' => $doc->getTitle(),
                    'summary' => $doc->getData(DocumentModel::schema_fields_summary),
                    'module_name' => $doc->getModuleName(),
                    'module_display' => $this->formatModuleName($doc->getModuleName()),
                    'file_name' => $doc->getFileName(),
                    'file_path' => $doc->getFilePath(),
                    'category_id' => $doc->getCategoryId(),
                    'is_auto_imported' => $doc->isAutoImported(),
                    'url' => $doc->getUrl()
                ];
            }
            
            return $this->success('success', [
                'items' => $items,
                'pagination' => [
                    'total' => $total,
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 获取文档详情
     * GET /api/dev/document/detail?id=xxx
     */
    public function getDetail()
    {
        try {
            $id = (int)$this->request->getGet('id', 0);
            
            if (!$id) {
                return $this->error('文档ID不能为空', 400);
            }
            
            $doc = $this->documentModel->clear()->load($id);
            
            if (!$doc->getId()) {
                return $this->error('文档不存在', 404);
            }
            
            // 获取分类信息
            $catalog = null;
            if ($doc->getCategoryId()) {
                $catalogData = $this->catalogModel->clear()->load((int)$doc->getCategoryId());
                if ($catalogData->getId()) {
                    $catalog = [
                        'id' => $catalogData->getId(),
                        'name' => $catalogData->getName(),
                        'description' => $catalogData->getDescription()
                    ];
                }
            }
            
            return $this->success('success', [
                'id' => $doc->getId(),
                'title' => $doc->getTitle(),
                'summary' => $doc->getData(DocumentModel::schema_fields_summary),
                'content' => $doc->getDecodeContent(),
                'module_name' => $doc->getModuleName(),
                'module_display' => $this->formatModuleName($doc->getModuleName()),
                'file_name' => $doc->getFileName(),
                'file_path' => $doc->getFilePath(),
                'category' => $catalog,
                'is_auto_imported' => $doc->isAutoImported()
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 获取目录树
     * GET /api/dev/document/catalogs
     */
    public function getCatalogs()
    {
        try {
            $catalogs = $this->catalogModel->clear()
                ->where(Catalog::schema_fields_is_active, 1)
                ->order(Catalog::schema_fields_position, 'ASC')
                ->order(Catalog::schema_fields_ID, 'ASC')
                ->select()
                ->fetch()
                ->getItems();
            
            // 构建树形结构
            $tree = $this->buildCatalogTree($catalogs);
            
            return $this->success('success', $tree);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 500);
        }
    }
    
    /**
     * 构建目录树
     */
    private function buildCatalogTree(array $catalogs, int $parentId = 0): array
    {
        $tree = [];
        
        foreach ($catalogs as $catalog) {
            if ((int)$catalog->getPid() === $parentId) {
                $node = [
                    'id' => $catalog->getId(),
                    'name' => $catalog->getName(),
                    'description' => $catalog->getDescription(),
                    'level' => $catalog->getData(Catalog::schema_fields_level),
                    'is_system' => $catalog->getData(Catalog::schema_fields_is_system),
                    'children' => $this->buildCatalogTree($catalogs, (int)$catalog->getId())
                ];
                $tree[] = $node;
            }
        }
        
        return $tree;
    }
    
    /**
     * 格式化模块名称
     */
    private function formatModuleName(string $moduleName): string
    {
        // 将 Weline_Framework 格式化为 Weline / Framework
        return str_replace('_', ' / ', $moduleName);
    }
    
    /**
     * 返回 JSON 响应
     */
    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

