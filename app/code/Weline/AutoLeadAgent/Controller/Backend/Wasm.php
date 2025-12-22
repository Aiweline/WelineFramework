<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\AutoLeadAgent\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
use Weline\AutoLeadAgent\Model\WasmHash;
use Weline\Framework\Manager\ObjectManager;

/**
 * WASM管理控制器
 */
#[Acl(
    'Weline_AutoLeadAgent::wasm',
    'WASM管理',
    'mdi-code-braces',
    '管理自动寻客Agent的WASM文件',
    'Weline_AutoLeadAgent::auto_lead_agent'
)]
class Wasm extends BackendController
{
    /**
     * WASM列表
     */
    #[Acl(
        'Weline_AutoLeadAgent::wasm_index',
        '查看WASM',
        'mdi-format-list-bulleted',
        '查看WASM文件列表'
    )]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $pageSize = intval($this->request->getParam('pageSize') ?? 20);

        /** @var WasmHash $wasmModel */
        $wasmModel = ObjectManager::getInstance(WasmHash::class);

        // 使用 ORM 内置分页
        $wasmModel->clear()
            ->order(WasmHash::fields_CREATED_AT, 'DESC')
            ->pagination($page, $pageSize)
            ->select()
            ->fetch();

        $wasmFiles = $wasmModel->getItems();
        $paginationHtml = $wasmModel->getPagination();

        $this->assign('wasm_files', $wasmFiles);
        $this->assign('pagination_html', $paginationHtml);

        return $this->fetch();
    }

    /**
     * 查看WASM详情
     */
    #[Acl(
        'Weline_AutoLeadAgent::wasm_view',
        '查看WASM详情',
        'mdi-eye',
        '查看WASM文件详细信息'
    )]
    public function view()
    {
        $hashId = (int)$this->request->getParam('hashId');
        
        if (!$hashId) {
            $this->getMessageManager()->addError(__('缺少哈希ID参数'));
            return $this->redirect($this->_url->getBackendUrl('auto-lead-agent/backend/wasm/index'));
        }

        /** @var WasmHash $wasmModel */
        $wasmModel = ObjectManager::getInstance(WasmHash::class);
        $wasmModel->load($hashId);

        if (!$wasmModel->getId()) {
            $this->getMessageManager()->addError(__('WASM记录不存在'));
            return $this->redirect($this->_url->getBackendUrl('auto-lead-agent/backend/wasm/index'));
        }

        // 检查文件是否存在
        $filePath = $wasmModel->getData(WasmHash::fields_WASM_PATH);
        $fileExists = file_exists($filePath);
        $fileSize = $fileExists ? filesize($filePath) : 0;

        $this->assign('wasm', $wasmModel);
        $this->assign('file_exists', $fileExists);
        $this->assign('file_size', $this->formatFileSize($fileSize));

        return $this->fetch();
    }

    /**
     * 格式化文件大小
     */
    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}

