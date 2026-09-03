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
        $meta = clone ObjectManager::getInstance(MetaModel::class);
        $meta->clear();

        // 搜索过滤
        $namespace = $this->request->getGet('namespace');
        $type = $this->request->getGet('type');
        $search = $this->request->getGet('search');

        if ($namespace) {
            $meta->where(MetaModel::schema_fields_NAMESPACE, $namespace);
        }
        if ($type) {
            $meta->where(MetaModel::schema_fields_META_TYPE, $type);
        }
        if ($search) {
            $meta->where(MetaModel::schema_fields_FILE_PATH, '%' . $search . '%', 'LIKE');
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
        $metaId = (int)($this->request->getParam('id') ?: $this->request->getGet('id') ?: 0);
        /** @var MetaModel $meta */
        $meta = clone ObjectManager::getInstance(MetaModel::class);
        $meta->clear();

        if ($metaId > 0) {
            $meta->load($metaId);
        }

        // 避免与主题布局的 meta（数组）键冲突
        $this->assign('meta_record', $meta);
        return $this->fetch();
    }

    /**
     * 保存元数据
     */
    public function save()
    {
        $data = $this->request->getPost();
        /** @var MetaModel $meta */
        $meta = clone ObjectManager::getInstance(MetaModel::class);
        $meta->clear();

        $metaId = (int)($data[MetaModel::schema_fields_ID] ?? 0);
        if ($metaId > 0) {
            $meta->load($metaId);
        }

        $meta->setData($data);
        $metaData = json_decode((string)$meta->getData(MetaModel::schema_fields_META_DATA), true) ?? [];
        $meta->saveMeta($metaData);

        $this->getMessageManager()->addSuccess(__('保存成功'));
        $this->redirect('*/index');
    }
}

