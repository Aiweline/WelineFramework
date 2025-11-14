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
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Exception;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Uploader;

use function PHPUnit\Framework\matches;

#[Acl(
    'Weline_DeveloperWorkspace::dev-document',
     '开发文档管理', 
     'fa fa-list-alt',
      '管理开发文档',
      'Weline_DeveloperWorkspace::dev-document-manager')]
class Document extends \Weline\Framework\App\Controller\BackendController
{
    private Url $url;

    public function __construct(
        Url $url
    ) {
        $this->url = $url;
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager', '文档列表', 'fa fa-list-alt')]
    public function index()
    {
        // 获取分类树（只获取激活的分类）
        /**@var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $catalogs = $catalogModel->getTree('pid');
        
        // 确保返回数组格式
        if (!is_array($catalogs)) {
            $catalogs = [];
        }
        
        $this->assign('catalogs', $catalogs);
        
        // 获取选中的分类ID
        $categoryId = $this->request->getParam('category_id');
        
        $documentModel = ModelService::getDocumentModel();
        $query = $documentModel->joinModel(Catalog::class, 'catalog', 'main_table.category_id=catalog.id')
                               ->fields('main_table.*,main_table.id as doc_id,catalog.*,catalog.id as c_id,catalog.name as c_name');
        
        // 如果指定了分类，则过滤
        if ($categoryId) {
            $query->where('main_table.category_id', $categoryId);
        }
        
        $documents = $query->pagination(
                           intval($this->request->getParam('page', 1)),
                           intval($this->request->getParam('pageSize', 10)),
                           $this->request->getParams()
                       )->order('doc_id', 'desc')->select()->fetch();
        $this->assign('documents', $documents->getItems());
        $this->assign('pagination', $documentModel->getPagination());
        $this->assign('selectedCategoryId', $categoryId);
        return $this->fetch();
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-delete', '文档删除', 'fa fa-delete')]
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

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-edit', '文档编辑', 'fa fa-edit')]
    public function edit()
    {
        $this->redirect($this->url->getBackendUrl('dev/tool/admin/document/add', $this->request->getParams()));
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-add', '文档添加', 'fa fa-plus')]
    public function add()
    {
        // 分类（只获取激活的分类）
        /**@var Catalog $catalogModel */
        $catalogModel = ObjectManager::getInstance(Catalog::class);
        $catalogs     = $catalogModel->getTree('pid');
        $this->assign('catalogs', $catalogs);
        # 作者
        /**@var BackendUser $adminUserModel */
        $adminUserModel = ObjectManager::getInstance(BackendUser::class);
        $this->assign('users', $adminUserModel->select()->fetch()->getItems());
        # 如果是编辑,不是就返回空 文档
        $this->assign('document', ModelService::getDocumentModel()->load($this->request->getParam('id', 0)));
        return $this->fetch();
    }

    #[
        Acl('Weline_DeveloperWorkspace::dev-document-manager-save', '文档保存', 'fa fa-save'),
    ]
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

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-upload', '文档上传', 'fa fa-upload')]
    public function postUpload()
    {
        $uploader = new Uploader();
        $paths    = $uploader->saveFiles('Weline_DeveloperWorkspace', 'document', 'wyswyg');
        if (!isset($paths[0])) {
            throw new Exception(__('文件上传失败！'));
        }
        return $this->fetchJson(['location' => $paths[0]]);
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-view', '文档查看', 'fa fa-eye')]
    public function getView()
    {
        $id = $this->request->getParam('id');
        if (!$id) {
            return $this->fetchJson($this->error(__('文档ID不能为空')));
        }
        try {
            $document = ModelService::getDocumentModel()->load($id);
            if (!$document->getId()) {
                return $this->fetchJson($this->error(__('文档不存在')));
            }
            // 获取分类信息
            $catalog = ObjectManager::getInstance(Catalog::class)->load($document->getCategoryId());
            // 获取作者信息
            // 如果是自动导入的文档，优先使用模块名作为作者
            $authorName = __('未知');
            if ($document->isAutoImported() && $document->getModuleName()) {
                $authorName = $document->getModuleName();
            } else {
                $author = null;
                if ($document->getAuthorId()) {
                    $author = ObjectManager::getInstance(BackendUser::class)->load($document->getAuthorId());
                    if ($author && $author->getId()) {
                        $authorName = $author->getData('username');
                    }
                }
            }
            // 获取文档内容并清理HTML注释
            $content = $document->getDecodeContent() ?? '';
            $content = $this->cleanHtmlComments($content);
            
            return $this->fetchJson($this->success(__('获取成功'), [
                'id' => $document->getId(),
                'title' => $document->getTitle(),
                'summary' => $document->getData('summary'),
                'content' => $content,
                'category' => $catalog->getName(),
                'author' => $authorName,
                'create_time' => $document->getData('create_time'),
                'update_time' => $document->getData('update_time'),
                'module_name' => $document->getModuleName(),
                'file_path' => $document->getFilePath(),
                'file_name' => $document->getFileName(),
                'is_auto_imported' => $document->isAutoImported(),
            ]));
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }

    #[Acl('Weline_DeveloperWorkspace::dev-document-manager-list', '文档列表API', 'fa fa-list')]
    public function getList()
    {
        $categoryId = $this->request->getParam('category_id');
        $page = intval($this->request->getParam('page', 1));
        $pageSize = intval($this->request->getParam('pageSize', 10));
        
        try {
            $documentModel = ModelService::getDocumentModel();
            $query = $documentModel->joinModel(Catalog::class, 'catalog', 'main_table.category_id=catalog.id')
                                   ->fields('main_table.*,main_table.id as doc_id,catalog.*,catalog.id as c_id,catalog.name as c_name');
            
            // 如果指定了分类，则过滤
            if ($categoryId) {
                $query->where('main_table.category_id', $categoryId);
            }
            
            $documents = $query->pagination($page, $pageSize, $this->request->getParams())
                               ->order('doc_id', 'desc')
                               ->select()
                               ->fetch();
            
            $items = [];
            foreach ($documents->getItems() as $doc) {
                // 如果是自动导入的文档，使用模块名作为作者
                $authorName = '';
                $isAutoImported = (bool)$doc->getData('is_auto_imported');
                if ($isAutoImported && $doc->getData('module_name')) {
                    $authorName = $doc->getData('module_name');
                } else {
                    $authorId = $doc->getData('author_id');
                    if ($authorId) {
                        $author = ObjectManager::getInstance(BackendUser::class)->load($authorId);
                        if ($author && $author->getId()) {
                            $authorName = $author->getData('username');
                        }
                    }
                }
                
                $items[] = [
                    'doc_id' => $doc->getData('doc_id'),
                    'title' => $doc->getData('title'),
                    'summary' => $doc->getData('summary'),
                    'c_name' => $doc->getData('c_name'),
                    'c_id' => $doc->getData('c_id'),
                    'author_id' => $doc->getData('author_id'),
                    'author' => $authorName ?: __('未知'),
                    'create_time' => $doc->getData('create_time'),
                    'update_time' => $doc->getData('update_time'),
                    'module_name' => $doc->getData('module_name'),
                    'is_auto_imported' => $isAutoImported,
                ];
            }
            
            return $this->fetchJson($this->success(__('获取成功'), [
                'items' => $items,
                'pagination' => $documentModel->getPagination(),
            ]));
        } catch (\Exception $exception) {
            return $this->fetchJson($this->exception($exception));
        }
    }
    
    /**
     * 清理HTML注释
     * 
     * @param string|null $content 原始内容
     * @return string 清理后的内容
     */
    private function cleanHtmlComments(?string $content): string
    {
        if (empty($content)) {
            return '';
        }
        
        // 清理HTML注释（包括单行和多行注释）
        // 匹配格式：<!-- ... --> 或 <!-- ... \n ... -->
        $cleanedContent = preg_replace('/<!--[\s\S]*?-->/', '', $content ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = $content ?? '';
        }
        
        // 清理后可能产生多余的空行，移除连续的空行（保留单个空行）
        $cleanedContent = preg_replace('/\n{3,}/', "\n\n", $cleanedContent ?? '');
        
        // preg_replace 可能返回 null，需要处理
        if ($cleanedContent === null) {
            $cleanedContent = '';
        }
        
        // 清理首尾空白
        $cleanedContent = trim($cleanedContent ?? '');
        
        return $cleanedContent;
    }
}
