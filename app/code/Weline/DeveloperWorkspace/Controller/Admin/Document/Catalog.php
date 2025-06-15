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
class Catalog extends \Weline\Admin\Controller\BaseController
{
    #[Acl('Weline_DeveloperWorkspace::dev-document-catalog-manager-list',
        'dev-document-manager',
        'fa fa-list-alt',
        '文档分类列表')]
    public function index()
    {
        $catalogModel = $this->getCatalogModel();
        $catalogs = $catalogModel->getTree();
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
        $trees = $this->getCatalogModel()->getTree(
            'pid'
        );
        $trees = $this->processTrees($trees);
        return $this->fetchJson($trees);
    }

    private function processTrees(array &$trees): array
    {
        foreach ($trees as &$tree) {
            $tree['text'] = '<a class="btn" href="' . $this->_url->getBackendUrl('/dev/tool/admin/document/catalog', ['id' => $tree['id']]) . '">' .
                $tree['name'] . '</a>
<a class="btn btn-info pull-right" href="' . $this->_url->getBackendUrl('/dev/tool/admin/document/catalog', ['id' => $tree['id']]) . '">修改</a>
<a class="btn btn-danger pull-right" href="' . $this->_url->getBackendUrl('/dev/tool/admin/document/catalog/delete', ['id' => $tree['id']]) . '">' . __('删除') . '</a>
';
            $tree['selectable'] = true;
            $tree['state'] = [
                'checked' => false,
                'disabled' => !$tree['is_active'],
                'expanded' => true,
                'selected' => false
            ];
            $tree['tags'] = ['available'];
            $tree['href'] = $this->_url->getBackendUrl('/dev/tool/document/catalog', ['id' => $tree['id']]);
            if (isset($tree['nodes']) and count($tree['nodes'])) {
                $this->processTrees($tree['nodes']);
            }
        }
        return $trees;
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
    public function delete()
    {
        $catalogModel = $this->getCatalogModel()->load($this->request->getParam('id'));
        if ($catalogModel->getId()) {
            try {
                $catalogModel->delete();
            } catch (\ReflectionException $e) {
            } catch (Exception $e) {
            } catch (Core $e) {
                $this->getMessageManager()->addException($e);
            }
        }
        $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-post',
            'dev-document-manager',
            'fa fa-save',
            '文档分类保存')]
    public function postPost()
    {
        $post = $this->request->getPost();
        $catalog = $this->getCatalogModel();
        $pid_arr = explode('-', $post['pid']);
        $pid = array_shift($pid_arr);
        $pid_level = (int)array_shift($pid_arr);
        $level = $pid_level;
        if ($pid === $post['id']) {
            $this->getMessageManager()->addError(__('不能自己选择自己作为父类！'));
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $post['id']]));
        }
        if ($level) {
            $level += 1;
        } else {
            $level = 1;
        }
        $post['name'] = trim($post['name']);
        if (empty($post['name'])) {
            $this->getMessageManager()->addError(__('目录名不能为空！'));
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
        }
        $post['pid'] = $pid;
        $post['level'] = $level;
        # 检查是新增还是修改
        if ($id = $this->request->getParam('id')) {
            $catalog = $catalog->load($id);
            if (!$catalog->getId()) {
                $this->getMessageManager()->addError(__('该记录已不存在！'));
                $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog'));
            }
            $catalog->save($post);

            $this->getMessageManager()->addSuccess(__('修改成功！'));
            $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $id]));
        }
        # 新增
        unset($post['id']);
        try {
            $catalog->save($post);
            $this->getMessageManager()->addSuccess(__('添加成功！'));
        } catch (\Exception $exception) {
            $this->getMessageManager()->addException($exception);
        }
        $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document/catalog', ['id' => $catalog->getId()]));
    }

    private function getCatalogModel(): \Weline\DeveloperWorkspace\Model\Document\Catalog
    {
        return $this->_objectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document\Catalog::class);
    }
}
