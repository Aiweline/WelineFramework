<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\Meta as MetaModel;

class Meta extends BackendController
{
    /**
     * 元数据列表
     */
    public function index()
    {
        /** @var MetaModel $meta */
        $meta = ObjectManager::getInstance()->get(MetaModel::class);
        
        // 搜索过滤
        $namespace = $this->request->getGet('namespace');
        $type = $this->request->getGet('type');
        $search = $this->request->getGet('search');
        
        if ($namespace) {
            $meta->where(MetaModel::fields_NAMESPACE, $namespace);
        }
        if ($type) {
            $meta->where(MetaModel::fields_META_TYPE, $type);
        }
        if ($search) {
            $meta->where('file_path', '%' . $search . '%', 'LIKE');
        }
        
        $metas = $meta->pagination()->select()->fetch();
        
        $this->assign('metas', $metas->getItems());
        $this->assign('pagination', $metas->getPagination());
        return $this->fetch();
    }

    /**
     * 编辑元数据
     */
    public function edit()
    {
        $metaId = $this->request->getParam('id');
        /** @var MetaModel $meta */
        $meta = ObjectManager::getInstance()->get(MetaModel::class);
        
        if ($metaId) {
            $meta->load($metaId);
        }
        
        $this->assign('meta', $meta);
        return $this->fetch();
    }

    /**
     * 保存元数据
     */
    public function save()
    {
        $data = $this->request->getPost();
        /** @var MetaModel $meta */
        $meta = ObjectManager::getInstance()->get(MetaModel::class);
        
        if (!empty($data['meta_id'])) {
            $meta->load($data['meta_id']);
        }
        
        $meta->setData($data);
        $metaData = json_decode($meta->getData(MetaModel::fields_META_DATA), true) ?? [];
        $meta->saveMeta($metaData);
        
        $this->getMessageManager()->addSuccess(__('保存成功'));
        $this->redirect('*/index');
    }
}

