<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;

class Record extends BackendController
{
    /**
     * 字体记录列表
     */
    public function index()
    {
        $record = ObjectManager::getInstance(FontRecord::class);
        
        // 获取分页参数
        $page = (int)$this->request->getParam('page', 1);
        $limit = (int)$this->request->getParam('limit', 20);
        
        // 获取搜索参数
        $search = $this->request->getParam('search', '');
        $status = $this->request->getParam('status', '');
        
        // 构建查询条件
        if ($search) {
            $record->where('original_filename', 'like', '%' . $search . '%');
        }
        
        if ($status) {
            $record->where('status', $status);
        }
        
        // 按创建时间倒序排列
        $record->order('created_at', 'DESC');
        
        // 获取分页数据
        $records = $record->pagination()->select()->fetch();
        
        $this->assign('records', $records->getItems());
        $this->assign('pagination', $records->getPagination());
        $this->assign('search', $search);
        $this->assign('status', $status);
        $this->assign('statusOptions', $record->getStatusOptions());
        
        return $this->fetch();
    }
    
    /**
     * 删除字体记录
     */
    public function delete()
    {
        try {
            $id = (int)$this->request->getParam('id');
            if (!$id) {
                throw new \Exception(__('记录ID不能为空'));
            }
            
            $record = ObjectManager::getInstance(FontRecord::class)->load($id);
            if (!$record->getId()) {
                throw new \Exception(__('记录不存在'));
            }
            
            // 删除文件
            $originalPath = BP . '/pub/' . $record->getData('original_path');
            if (file_exists($originalPath)) {
                unlink($originalPath);
            }
            
            $processedPath = BP . '/pub/' . $record->getData('processed_path');
            if (file_exists($processedPath)) {
                unlink($processedPath);
            }
            
            // 删除记录
            $record->delete();
            
            return $this->fetchJson([
                'code' => 200,
                'msg' => __('删除成功')
            ]);
            
        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * 查看字体记录详情
     */
    public function view()
    {
        $id = (int)$this->request->getParam('id');
        if (!$id) {
            $this->redirect('fontsubletter/backend/record/index');
        }
        
        $record = ObjectManager::getInstance(FontRecord::class)->load($id);
        if (!$record->getId()) {
            $this->redirect('fontsubletter/backend/record/index');
        }
        
        $this->assign('record', $record);
        $this->assign('statusOptions', $record->getStatusOptions());
        return $this->fetch();
    }

    /**
     * 格式化文件大小（用于模板）
     */
    public function formatFileSize(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }
}
