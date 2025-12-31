<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;

class Delete extends BackendController
{
    /**
     * 删除记录
     */
    public function index()
    {
        try {
            $recordId = (int)$this->request->getParam('record_id');
            if (!$recordId) {
                throw new \Exception(__('记录ID不能为空'));
            }

            $record = ObjectManager::getInstance(FontRecord::class)->load($recordId);
            if (!$record->getId()) {
                throw new \Exception(__('记录不存在'));
            }

            $record->delete();

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('记录删除成功')
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $e->getMessage()
            ]);
        }
    }
}
