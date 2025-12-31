<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;
use WelineTools\FontSubLetter\Service\FontProcessor;

class Extract extends BackendController
{
    /**
     * 提取字符
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

            $processor = ObjectManager::getInstance(FontProcessor::class);
            $characters = $processor->extractCharacters($record);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('字符提取成功'),
                'data' => [
                    'characters' => $characters,
                    'count' => count($characters)
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $e->getMessage()
            ]);
        }
    }
}
