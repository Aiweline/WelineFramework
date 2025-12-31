<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Model\FontRecord;
use WelineTools\FontSubLetter\Service\FontProcessor;

class Generate extends BackendController
{
    /**
     * 生成子集字体
     */
    public function index()
    {
        try {
            $recordId = (int)$this->request->getPost('record_id');
            $selectedCharsParam = $this->request->getPost('selected_chars', '[]');
            
            // 解析JSON字符串
            $selectedChars = json_decode($selectedCharsParam, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception(__('字符数据格式错误'));
            }

            if (!$recordId) {
                throw new \Exception(__('记录ID不能为空'));
            }

            if (empty($selectedChars)) {
                throw new \Exception(__('请选择要包含的字符'));
            }

            $record = ObjectManager::getInstance(FontRecord::class)->load($recordId);
            if (!$record->getId()) {
                throw new \Exception(__('记录不存在'));
            }

            $processor = ObjectManager::getInstance(FontProcessor::class);
            $outputPath = $processor->generateSubsetFont($record, $selectedChars);

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('子集字体生成成功'),
                'data' => [
                    'download_url' => $outputPath,
                    'filename' => $record->getData('processed_filename')
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
