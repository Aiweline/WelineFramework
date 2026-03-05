<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归WeShop所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/15
 * 描述：库存源管理控制器
 */

namespace WeShop\Inventory\Controller\Backend\Inventory;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Inventory\Model\Source as SourceModel;

class Source extends BackendController
{
    private SourceModel $source;

    public function __construct(SourceModel $source)
    {
        $this->source = $source;
    }

    /**
     * 库存源列表
     */
    public function index()
    {
        $sources = $this->source->reset()
            ->pagination()
            ->order(SourceModel::schema_fields_PRIORITY, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $this->assign('sources', $sources);
        $this->assign('pagination', $this->source->getPagination());
        return $this->fetch();
    }

    /**
     * 添加库存源
     */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->request->getPost();
                $this->source->reset()
                    ->clearData()
                    ->setModelData($data)
                    ->save();

                $this->getMessageManager()->addSuccess(__('库存源添加成功！'));
                $this->redirect('*/backend/inventory/source/edit', ['id' => $this->source->getId()]);
            } catch (\Exception $e) {
                $this->getMessageManager()->addError(__('库存源添加失败！') . (DEV ? $e->getMessage() : ''));
                $this->assign('source', $this->request->getPost());
            }
        }

        $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
        return $this->fetch('form');
    }

    /**
     * 编辑库存源
     */
    public function edit()
    {
        $id = (int)$this->request->getGet('id');

        if ($this->request->isPost()) {
            try {
                $data = $this->request->getPost();
                $this->source->load($id);
                if (!$this->source->getId()) {
                    throw new \Exception(__('库存源不存在！'));
                }
                $this->source->setModelData($data)->save();
                $this->getMessageManager()->addSuccess(__('库存源保存成功！'));
            } catch (\Exception $e) {
                $this->getMessageManager()->addError(__('库存源保存失败！') . (DEV ? $e->getMessage() : ''));
            }
            $this->redirect('*/backend/inventory/source/edit', ['id' => $id]);
        }

        $source = $this->source->load($id);
        if (!$source->getId()) {
            $this->getMessageManager()->addError(__('库存源不存在！'));
            $this->redirect('*/backend/inventory/source');
        }

        $this->assign('source', $source);
        $this->assign('action', $this->request->getUrlBuilder()->getCurrentUrl());
        return $this->fetch('form');
    }

    /**
     * 删除库存源
     */
    public function getDelete()
    {
        $id = (int)$this->request->getGet('id');
        $source = $this->source->load($id);

        if (!$source->getId()) {
            $this->getMessageManager()->addError(__('库存源不存在！'));
            $this->redirect('*/backend/inventory/source');
        }

        // 不允许删除默认库存源
        if ($source->getCode() === 'default') {
            $this->getMessageManager()->addError(__('默认库存源不能删除！'));
            $this->redirect('*/backend/inventory/source');
        }

        try {
            $source->delete();
            $this->getMessageManager()->addSuccess(__('库存源删除成功！'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError(__('库存源删除失败！') . (DEV ? $e->getMessage() : ''));
        }

        $this->redirect('*/backend/inventory/source');
    }
}

