<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller\Admin;

use Weline\Backend\Model\BackendUser;
use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\DeveloperWorkspace\Model\ModelService;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Uploader;

use function PHPUnit\Framework\matches;

class Document extends \Weline\Framework\App\Controller\BackendController
{
    private Url $url;

    public function __construct(
        Url $url
    ) {
        $this->url = $url;
    }

    public function index()
    {
        $documentModel = ModelService::getDocumentModel();
        $documents     = $documentModel->joinModel(Catalog::class, 'catalog', 'main_table.category_id=catalog.id')
                                       ->fields('main_table.*,main_table.id as doc_id,catalog.*,catalog.id as c_id,catalog.name as c_name')
                                       ->pagination(
                                           intval($this->request->getParam('page', 1)),
                                           intval($this->request->getParam('pageSize', 10)),
                                           $this->request->getParams()
                                       )->order('doc_id', 'desc')->select()->fetch();
        $this->assign('documents', $documents->getItems());
        $this->assign('pagination', $documentModel->getPagination());
        return $this->fetch();
    }

    public function postDelete()
    {
        $id = $this->request->getParam('id');
        try {
            ModelService::getDocumentModel()->load($id)->delete();
            return $this->fetchJson($this->success());
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    public function edit()
    {
        $this->redirect($this->url->getBackendUrl('dev/tool/admin/document/add', $this->request->getParams()));
    }

    public function add()
    {
        // 分类
        /**@var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $catalogs     = $catalogModel->getTree();
        $this->assign('catalogs', $catalogs);
        # 作者
        /**@var BackendUser $adminUserModel */
        $adminUserModel = ObjectManager::getInstance(BackendUser::class);
        $this->assign('users', $adminUserModel->select()->fetch()->getItems());
        # 如果是编辑,不是就返回空 文档
        $this->assign('document', ModelService::getDocumentModel()->load($this->request->getParam('id', 0)));
        return $this->fetch();
    }

    public function postPost()
    {
        # 保存
        /**@var \Weline\DeveloperWorkspace\Model\Document $documentModel */
        $documentModel = ObjectManager::getInstance(\Weline\DeveloperWorkspace\Model\Document::class);
        try {
            $pre_msg = __('添加');
            if ($this->request->getPost('id')) {
                $pre_msg = __('修改');
            }
            $data            = $this->request->getPost();
            $data['content'] = htmlspecialchars($data['content']);
            $documentModel->save($data);
            $this->getMessageManager()->addSuccess($pre_msg . '文档成功！ID:' . $documentModel->getId());
        } catch (\Exception $exception) {
            $this->exception($exception);
        }
        $this->redirect($this->_url->getBackendUrl('dev/tool/admin/document'));
    }

    public function postUpload()
    {
        $uploader = new Uploader();
        $paths    = $uploader->saveFiles('Weline_DeveloperWorkspace', 'document', 'wyswyg');
        if (!isset($paths[0])) {
            throw new Exception(__('文件上传失败！'));
        }
        return $this->fetchJson(['location' => $paths[0]]);
    }
}
