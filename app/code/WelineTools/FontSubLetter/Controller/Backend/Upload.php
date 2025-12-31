<?php

namespace WelineTools\FontSubLetter\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WelineTools\FontSubLetter\Service\FontProcessor;

class Upload extends BackendController
{
    /**
     * 上传字体文件
     */
    public function index()
    {
        try {
            if (!$this->request->isPost()) {
                throw new \Exception(__('请求方法错误'));
            }

            // 设置PHP环境变量以允许更大的文件上传
            $this->configureUploadSettings();

            $file = $_FILES['font_file'] ?? null;
            if (!$file) {
                throw new \Exception(__('请选择字体文件'));
            }

            // 处理上传错误，尝试继续上传
            $this->handleUploadErrors($file);

            $processor = ObjectManager::getInstance(FontProcessor::class);
            $record = $processor->processUpload($file, $this->getUserId());

            return $this->fetchJson([
                'code' => 200,
                'msg' => __('字体文件上传成功'),
                'data' => [
                    'id' => $record->getId(),
                    'filename' => $record->getData('original_filename'),
                    'size' => $record->getFileSizeFormatted(),
                    'format' => $record->getData('font_format')
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fetchJson([
                'code' => 500,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**
     * 配置上传设置
     */
    private function configureUploadSettings(): void
    {
        // 设置允许的最大文件大小 (100MB)
        ini_set('upload_max_filesize', '100M');
        ini_set('post_max_size', '100M');
        ini_set('max_execution_time', 300); // 5分钟
        ini_set('max_input_time', 300);
        ini_set('memory_limit', '256M');
        
        // 设置文件上传相关配置
        ini_set('file_uploads', '1');
        ini_set('max_file_uploads', '10');
        
        // 记录当前配置
        error_log('上传配置已设置:');
        error_log('- upload_max_filesize: ' . ini_get('upload_max_filesize'));
        error_log('- post_max_size: ' . ini_get('post_max_size'));
        error_log('- max_execution_time: ' . ini_get('max_execution_time'));
        error_log('- memory_limit: ' . ini_get('memory_limit'));
    }

    /**
     * 处理上传错误，尝试继续上传
     */
    private function handleUploadErrors(array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超过了php.ini中upload_max_filesize的限制',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过了HTML表单中MAX_FILE_SIZE的限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展程序阻止'
            ];
            
            $errorMsg = $errorMessages[$file['error']] ?? '未知上传错误 (错误代码: ' . $file['error'] . ')';
            
            // 对于某些错误，尝试忽略并继续
            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE])) {
                error_log('检测到文件大小限制错误，尝试忽略: ' . $errorMsg);
                
                // 检查文件是否仍然可用
                if (file_exists($file['tmp_name']) && is_readable($file['tmp_name'])) {
                    error_log('文件仍然可用，继续处理');
                    return; // 继续处理
                }
            }
            
            // 对于其他错误，抛出异常
            throw new \Exception(__('文件上传失败: %1', $errorMsg));
        }
    }

    /**
     * 获取用户ID
     */
    private function getUserId(): int
    {
        // 这里应该从session或token中获取用户ID
        // 暂时返回0表示匿名用户
        return 0;
    }
}
