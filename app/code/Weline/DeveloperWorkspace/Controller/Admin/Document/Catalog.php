<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller\Admin\Document;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\Core;

#[Acl('Weline_DeveloperWorkspace::dev-document-manager',
    'dev-document-catalog-manager',
    'fa fa-book-o',
    '文档分类管理器')]
class Catalog extends \Weline\Framework\App\Controller\BackendController
{
    #[Acl('Weline_DeveloperWorkspace::dev-document-catalog-manager-list',
        'dev-document-manager',
        'fa fa-list-alt',
        '文档分类列表')]
    public function index()
    {
        $catalogModel = $this->getCatalogModel();
        $catalogs = $catalogModel->getTree('pid');
        $this->assign('catalogs', $catalogs);
        # 清理模型
        $catalogModel->clearData();
        if ($id = $this->request->getParam('id')) {
            $catalog = $catalogModel->load($id);
        } else {
            $catalog = $catalogModel;
        }
        $this->assign('catalog', $catalog);
        return $this->fetch();
    }


    #[Acl('Weline_DeveloperWorkspace::dev-document-catalog-manager-tree',
        'dev-document-manager',
        'fa fa-list-alt',
        '文档分类树')]
    public function tree()
    {
        try {
            $trees = $this->getCatalogModel()->getTree(
                'pid'
            );
            if (empty($trees)) {
                return $this->fetchJson([]);
            }
            // 返回简洁的数据格式，不需要HTML
            return $this->fetchJson($trees);
        } catch (\Exception $e) {
            return $this->fetchJson(['error' => $e->getMessage()]);
        }
    }

    #[Acl(
        'Weline_DeveloperWorkspace::dev-document-catalog-manager-delete',
        'dev-document-manager',
        'fa fa-delete',
        '文档分类删除')]
    /**
     * @throws \ReflectionException
     * @throws Exception
     * @throws Core
     */
    public function postDelete()
    {
        try {
            // 使用 getParams() 同时支持 JSON 和表单数据
            $params = $this->request->getParams();
            $id = (int)($params['id'] ?? 0);
            
            if (!$id) {
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'message' => __('分类ID不能为空'), 'msg' => __('分类ID不能为空')]);
                }
                $this->getMessageManager()->addError(__('分类ID不能为空'));
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                return;
            }
            
            $catalogModel = $this->getCatalogModel()->load($id);
            if (!$catalogModel->getId()) {
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'message' => __('分类不存在'), 'msg' => __('分类不存在')]);
                }
                $this->getMessageManager()->addError(__('分类不存在'));
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                return;
            }
            
            // 检查是否是系统分类
            $isSystem = (int)($catalogModel->getData('is_system') ?? 0) === 1;
            if ($isSystem) {
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'message' => __('系统分类不允许删除'), 'msg' => __('系统分类不允许删除')]);
                }
                $this->getMessageManager()->addError(__('系统分类不允许删除'));
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                return;
            }
            
            // 检查是否有系统子分类
            if ($this->hasSystemChildCatalogs($id)) {
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'message' => __('下层有系统分类，不允许删除'), 'msg' => __('下层有系统分类，不允许删除')]);
                }
                $this->getMessageManager()->addError(__('下层有系统分类，不允许删除'));
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                return;
            }
            
            // 递归删除所有子分类
            $this->deleteChildCatalogs($id);
            
            // 删除当前分类
            $catalogModel->clear()->load($id)->delete()->fetch();
            
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => true, 'message' => __('删除成功'), 'msg' => __('删除成功')]);
            }
            
            $this->getMessageManager()->addSuccess(__('删除成功！'));
        } catch (\ReflectionException $e) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'message' => $e->getMessage(), 'msg' => $e->getMessage()]);
            }
            $this->getMessageManager()->addException($e);
        } catch (Exception $e) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'message' => $e->getMessage(), 'msg' => $e->getMessage()]);
            }
            $this->getMessageManager()->addException($e);
        } catch (Core $e) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'message' => $e->getMessage(), 'msg' => $e->getMessage()]);
            }
            $this->getMessageManager()->addException($e);
        }
        
        if (!$this->request->isAjax()) {
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
        }
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-post',
            'dev-document-manager',
            'fa fa-save',
            '文档分类保存')]
    public function postPost()
    {
        try {
            $post = $this->request->getPost();
            $catalog = $this->getCatalogModel();
            
            // 处理pid（兼容旧格式和新格式）
            $pid = $post['pid'] ?? '0';
            if (strpos($pid ?? '', '-') !== false) {
                $pid_arr = explode('-', $pid);
                $pid = array_shift($pid_arr) ?? '0';
                $pid_level = (int)(array_shift($pid_arr) ?? 0);
            } else {
                $pid_level = 0;
                if ($pid && $pid !== '0' && $pid !== null) {
                    // 获取父分类的level
                    $pidInt = (int)$pid;
                    if ($pidInt > 0) {
                        $parentCatalog = $this->getCatalogModel()->clear()->load($pidInt);
                        $pid_level = $parentCatalog->getId() ? (int)($parentCatalog->getData('level') ?? 0) : 0;
                    }
                }
            }
            
            $level = $pid_level;
            if ($pid !== "0" && $pid === ($post['id'] ?? '')) {
                $errorMsg = __('不能自己选择自己作为父类！');
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'msg' => $errorMsg]);
                }
                $this->getMessageManager()->addError($errorMsg);
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $post['id']]));
                return;
            }
            
            if ($level) {
                $level += 1;
            } else {
                $level = 1;
            }
            
            $post['name'] = trim($post['name'] ?? '');
            if (empty($post['name'])) {
                $errorMsg = __('目录名不能为空！');
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'msg' => $errorMsg]);
                }
                $this->getMessageManager()->addError($errorMsg);
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                return;
            }
            
            // 确保必需字段有值
            $post['pid'] = (int)$pid;
            $post['level'] = (int)$level;
            $post['description'] = $post['description'] ?? '';
            $post['is_active'] = isset($post['is_active']) ? (int)$post['is_active'] : 1;
            
            // 确保新增时pid为整数
            if ($post['pid'] === 0) {
                $post['pid'] = 0;
            }
            
            # 检查是新增还是修改（优先从POST数据中获取id）
            $id = 0;
            if (isset($post['id']) && $post['id'] !== '' && $post['id'] !== null) {
                $id = (int)$post['id'];
            } elseif ($this->request->getParam('id')) {
                $id = (int)$this->request->getParam('id');
            }
            
            // 检查名称是否重复（同一父分类下不能有重名）
            $nameCheckCatalog = $this->getCatalogModel()->clear()
                ->where('name', $post['name'])
                ->where('pid', $pid);
            
            if ($id > 0) {
                // 更新时，排除当前记录
                $nameCheckCatalog->where('id', $id, '!=');
            }
            
                $existingCatalog = $nameCheckCatalog->find()->fetch();
            if ($existingCatalog && $existingCatalog->getId()) {
                $errorMsg = __('分类名称"' . $post['name'] . '"在同一父分类下已存在！');
                if ($this->request->isAjax()) {
                    return $this->fetchJson(['success' => false, 'msg' => $errorMsg]);
                }
                $this->getMessageManager()->addError($errorMsg);
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $id]));
                return;
            }
            
            if ($id > 0) {
                // 更新 - 使用clear()确保是干净的实例
                $catalogModel = $this->getCatalogModel()->clear();
                $catalog = $catalogModel->load($id);
                if (!$catalog || !$catalog->getId()) {
                    $errorMsg = __('该记录已不存在！ID: ' . $id);
                    if ($this->request->isAjax()) {
                        return $this->fetchJson(['success' => false, 'msg' => $errorMsg]);
                    }
                    $this->getMessageManager()->addError($errorMsg);
                    $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
                    return;
                }
                
                // 检查是否是系统分类
                $isSystem = (int)($catalog->getData('is_system') ?? 0) === 1;
                
                // 直接设置字段值，不使用setModelFieldData（避免过滤问题）
                // 系统分类不允许修改名称、pid和level
                if (!$isSystem) {
                    $catalog->setName($post['name']);
                    $catalog->setPid($post['pid']);
                    $catalog->setData('level', $post['level']);
                }
                // 描述和启用状态可以修改
                $description = trim($post['description'] ?? '');
                $catalog->setDescription($description !== '' ? $description : '');
                $catalog->setData('is_active', $post['is_active']);
                
                $catalog->save();
                $successMsg = __('修改成功！');
            } else {
                # 新增
                // 创建新实例（确保是全新的实例）
                $catalog = $this->getCatalogModel()->clear();
                
                // 直接设置字段值
                $catalog->setName($post['name']);
                $description = trim($post['description'] ?? '');
                $catalog->setDescription($description !== '' ? $description : '');
                $catalog->setPid($post['pid']);
                $catalog->setData('level', $post['level']);
                $catalog->setData('is_active', $post['is_active']);
                
                $catalog->save();
                $successMsg = __('添加成功！');
            }
            
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => true, 'msg' => $successMsg, 'data' => ['id' => $catalog->getId()]]);
            }
            
            $this->getMessageManager()->addSuccess($successMsg);
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $catalog->getId()]));
        } catch (\Exception $exception) {
            if ($this->request->isAjax()) {
                return $this->fetchJson(['success' => false, 'msg' => $exception->getMessage()]);
            }
            $this->getMessageManager()->addException($exception);
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
        }
    }
    
    /**
     * 更新分类顺序（拖拽排序）
     */
    #[Acl('Weline_DeveloperWorkspace::dev-document-catalog-manager-update-order',
        'dev-document-manager',
        'fa fa-sort',
        '文档分类排序')]
    public function postUpdateOrder()
    {
        try {
            $id = (int)($this->request->getPost('id') ?? 0);
            $pid = (int)($this->request->getPost('pid') ?? 0);
            $level = (int)($this->request->getPost('level') ?? 1);
            $position = (int)($this->request->getPost('position') ?? 0);
            
            if (!$id) {
                return $this->fetchJson(['success' => false, 'msg' => __('分类ID不能为空')]);
            }
            
            // 加载分类，验证是否存在
            $catalog = $this->getCatalogModel()->load($id);
            if (!$catalog->getId()) {
                return $this->fetchJson(['success' => false, 'msg' => __('分类不存在')]);
            }
            
            // 检查不能将自己作为自己的父分类
            if ($pid == $id) {
                return $this->fetchJson(['success' => false, 'msg' => __('不能将自己作为父分类')]);
            }
            
            // 检查系统分类不允许拖拽
            $isSystem = (int)($catalog->getData('is_system') ?? 0);
            if ($isSystem == 1) {
                return $this->fetchJson(['success' => false, 'msg' => __('系统分类不允许拖动排序')]);
            }
            
            // 获取旧的位置信息
            $oldPid = (int)($catalog->getPid() ?? 0);
            
            // 获取数据库连接和表名
            $connection = $this->getCatalogModel()->getConnection();
            $table = $this->getCatalogModel()->getTable();
            
            try {
                // 如果父分类改变了，需要更新 pid 和 level
                if ($oldPid != $pid) {
                    // 先更新旧父分类下的所有节点的position（移除当前节点后重新排序）
                    $oldSiblings = $this->getCatalogModel()->clear()
                        ->where('pid', $oldPid)
                        ->where('id', $id, '!=')
                        ->order('position', 'ASC')
                        ->select()
                        ->fetchArray();
                    
                    // 批量更新旧父分类下所有节点的position
                    if (!empty($oldSiblings)) {
                        $updateData = [];
                        foreach ($oldSiblings as $index => $sibling) {
                            $updateData[(int)$sibling['id']] = $index + 1;
                        }
                        $this->batchUpdatePosition($connection, $table, $updateData);
                    }
                    
                    // 获取新父分类下的所有节点（排除当前节点）
                    $newSiblings = $this->getCatalogModel()->clear()
                        ->where('pid', $pid)
                        ->where('id', $id, '!=')
                        ->order('position', 'ASC')
                        ->select()
                        ->fetchArray();
                    
                    // 计算目标位置
                    $targetPosition = max(0, min($position - 1, count($newSiblings)));
                    
                    // 批量更新新父分类下所有节点的position（为当前节点腾出位置）
                    if (!empty($newSiblings)) {
                        $updateData = [];
                        foreach ($newSiblings as $index => $sibling) {
                            $siblingId = (int)$sibling['id'];
                            $siblingPosition = $index >= $targetPosition ? $index + 2 : $index + 1;
                            $updateData[$siblingId] = $siblingPosition;
                        }
                        $this->batchUpdatePosition($connection, $table, $updateData);
                    }
                    
                    // 更新当前节点的 pid、level 和 position（使用ORM方法）
                    $finalPosition = $targetPosition + 1;
                    $this->getCatalogModel()->clear()
                        ->where('id', $id)
                        ->update([
                            'pid' => $pid,
                            'level' => $level,
                            'position' => $finalPosition
                        ])
                        ->fetch();
                } else {
                    // 父分类没变，只调整同级节点的顺序
                    $siblings = $this->getCatalogModel()->clear()
                        ->where('pid', $pid)
                        ->where('id', $id, '!=')
                        ->order('position', 'ASC')
                        ->select()
                        ->fetchArray();
                    
                    // 计算目标位置
                    $targetPosition = max(0, min($position - 1, count($siblings)));
                    
                    // 批量更新同级节点的position
                    if (!empty($siblings)) {
                        $updateData = [];
                        foreach ($siblings as $index => $sibling) {
                            $siblingId = (int)$sibling['id'];
                            $siblingPosition = $index >= $targetPosition ? $index + 2 : $index + 1;
                            $updateData[$siblingId] = $siblingPosition;
                        }
                        $this->batchUpdatePosition($connection, $table, $updateData);
                    }
                    
                    // 更新当前节点的position（使用ORM方法）
                    $finalPosition = $targetPosition + 1;
                    $this->getCatalogModel()->clear()
                        ->where('id', $id)
                        ->update(['position' => $finalPosition])
                        ->fetch();
                }
                
                // 返回成功结果
                return $this->fetchJson([
                    'success' => true, 
                    'msg' => __('排序已保存'),
                    'data' => [
                        'id' => $id,
                        'pid' => $pid,
                        'level' => $level,
                        'position' => $finalPosition
                    ]
                ]);
            } catch (\Exception $e) {
                throw $e;
            }
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'msg' => __('保存失败：') . $e->getMessage()]);
        }
    }

    /**
     * 递归删除子分类
     */
    private function deleteChildCatalogs(int $parentId): void
    {
        $childCatalogs = $this->getCatalogModel()->clear()
            ->where('pid', $parentId)
            ->select()
            ->fetchArray();
        
        foreach ($childCatalogs as $child) {
            $childId = (int)($child['id'] ?? 0);
            if ($childId > 0) {
                // 递归删除子分类的子分类
                $this->deleteChildCatalogs($childId);
                // 删除子分类
                $this->getCatalogModel()->clear()->load($childId)->delete()->fetch();
            }
        }
    }

    /**
     * 检查分类及其子分类中是否有系统分类
     */
    private function hasSystemChildCatalogs(int $parentId): bool
    {
        $childCatalogs = $this->getCatalogModel()->clear()
            ->where('pid', $parentId)
            ->select()
            ->fetchArray();
        
        foreach ($childCatalogs as $child) {
            $childId = (int)($child['id'] ?? 0);
            $isSystem = (int)($child['is_system'] ?? 0) === 1;
            
            // 如果当前子分类是系统分类，返回true
            if ($isSystem) {
                return true;
            }
            
            // 递归检查子分类的子分类
            if ($this->hasSystemChildCatalogs($childId)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 获取单个分类详情
     */
    #[Acl('Weline_DeveloperWorkspace::dev-document-catalog-manager-view',
        'dev-document-manager',
        'fa fa-eye',
        '文档分类查看')]
    public function getView()
    {
        try {
            $id = (int)($this->request->getParam('id') ?? 0);
            
            if (!$id) {
                return $this->fetchJson($this->error(__('分类ID不能为空')));
            }
            
            $catalog = $this->getCatalogModel()->load($id);
            if (!$catalog->getId()) {
                return $this->fetchJson($this->error(__('分类不存在')));
            }
            
            // 获取父分类信息
            $parentName = '';
            $pid = $catalog->getPid();
            if ($pid !== null && (int)$pid > 0) {
                $parent = $this->getCatalogModel()->clear()->where('id', (int)$pid)->find()->fetch();
                if ($parent && $parent->getId()) {
                    $parentName = $parent->getName();
                }
            }
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('获取成功'),
                'data' => [
                    'id' => $catalog->getId(),
                    'name' => $catalog->getName(),
                    'description' => $catalog->getData('description') ?? '',
                    'pid' => $catalog->getPid(),
                    'parent_name' => $parentName,
                    'level' => $catalog->getData('level') ?? 1,
                    'is_active' => $catalog->getData('is_active') ?? 1,
                    'is_system' => $catalog->getData('is_system') ?? 0,
                    'create_time' => $catalog->getData('create_time') ?? '',
                    'update_time' => $catalog->getData('update_time') ?? '',
                ]
            ]);
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    /**
     * 批量更新节点的position
     * 
     * @param mixed $connection 数据库连接对象
     * @param string $table 表名
     * @param array $updateData 更新数据数组，格式：['id' => position, ...]
     * @return void
     */
    private function batchUpdatePosition($connection, string $table, array $updateData): void
    {
        if (empty($updateData)) {
            return;
        }
        
        $ids = [];
        $cases = [];
        foreach ($updateData as $nodeId => $position) {
            $nodeId = (int)$nodeId;
            $position = (int)$position;
            $ids[] = $nodeId;
            $cases[] = "WHEN {$nodeId} THEN {$position}";
        }
        
        $idsStr = implode(',', $ids);
        $casesStr = implode(' ', $cases);
        $connection->query("UPDATE `{$table}` SET `position` = CASE `id` {$casesStr} END WHERE `id` IN ({$idsStr})")->fetch();
    }

    private function getCatalogModel(): \Weline\DeveloperWorkspace\Model\Document\Catalog
    {
        return $this->_objectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document\Catalog::class);
    }
}
