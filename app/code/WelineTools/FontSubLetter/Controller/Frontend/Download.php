<?php

namespace WelineTools\FontSubLetter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Manager\Message;
use WelineTools\FontSubLetter\Model\FontRecord;

class Download extends FrontendController
{
    /**
     * 下载字体文件
     */
    public function index()
    {
        try {
            $recordId = (int)$this->request->getParam('record_id');
            $isPreview = (bool)$this->request->getParam('preview');
            
            if (!$recordId) {
                throw new \Exception(__('记录ID不能为空'));
            }

            $record = ObjectManager::getInstance(FontRecord::class)->load($recordId);
            if (!$record->getId()) {
                throw new \Exception(__('记录不存在'));
            }

            // 如果是预览模式，返回原始字体文件
            if ($isPreview) {
                $filePath = BP . '/pub/' . $record->getData('original_path');
                $filename = $record->getData('original_filename');
                $contentType = $this->getFontContentType($record->getData('font_format'));
            } else {
                // 下载处理后的字体文件
                $filePath = BP . '/pub/' . $record->getData('processed_path');
                $filename = $record->getData('processed_filename');
                $contentType = $this->getFontContentType($record->getData('font_format'));
            }

            if (!file_exists($filePath)) {
                throw new \Exception(__('文件不存在'));
            }

            // 设置响应头
            header('Content-Type: ' . $contentType);
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            
            if (!$isPreview) {
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            } else {
                header('Content-Disposition: inline; filename="' . $filename . '"');
            }
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=3600');
            
            readfile($filePath);
            exit;

        } catch (\Exception $e) {
            Message::error($e->getMessage());
            return $this->redirect('*/index');
        }
    }

    /**
     * 获取字体文件的Content-Type
     */
    private function getFontContentType(string $format): string
    {
        switch (strtolower($format)) {
            case 'ttf':
                return 'font/ttf';
            case 'otf':
                return 'font/otf';
            case 'woff':
                return 'font/woff';
            case 'woff2':
                return 'font/woff2';
            default:
                return 'application/octet-stream';
        }
    }
}
