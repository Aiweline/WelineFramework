<?php

namespace WelineTools\FontSubLetter\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;
use WelineTools\FontSubLetter\Service\FontProcessor;

class Upload extends FrontendController
{
    private const UPLOAD_TICKETS_SESSION_KEY = 'font_sub_letter_upload_tickets';

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
            $this->consumeUploadTicket();
            $this->configureUploadSettings();
            
            // 检查是否有文件上传
            if (isset($_FILES['font_file']) && $_FILES['font_file']['error'] === UPLOAD_ERR_OK) {
                // 使用传统的$_FILES方式
                $processor = ObjectManager::getInstance(FontProcessor::class);
                $record = $processor->processUpload($_FILES['font_file']);
            } else {
                // 处理上传错误，尝试继续上传
                if (isset($_FILES['font_file'])) {
                    $this->handleUploadErrors($_FILES['font_file']);
                }
                
                // 尝试读取原始POST数据
                $input = file_get_contents('php://input');
                if (empty($input)) {
                    throw new \Exception(__('没有接收到文件数据'));
                }
                
                // 解析multipart/form-data
                $boundary = $this->getBoundary();
                if (!$boundary) {
                    throw new \Exception(__('无法解析文件数据'));
                }
                
                $fileData = $this->parseMultipartData($input, $boundary);
                if (!$fileData) {
                    throw new \Exception(__('文件数据解析失败'));
                }
                
                $processor = ObjectManager::getInstance(FontProcessor::class);
                $record = $processor->processUploadFromData($fileData);
            }
            
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
            // 记录错误日志
            w_log_error('字体上传失败: ' . $e->getMessage());
            w_log_info('请求方法: ' . $this->request->getMethod());
            w_log_info('$_FILES: ' . json_encode($_FILES));
            
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
        w_log_info('前端上传配置已设置:');
        w_log_info('- upload_max_filesize: ' . ini_get('upload_max_filesize'));
        w_log_info('- post_max_size: ' . ini_get('post_max_size'));
        w_log_info('- max_execution_time: ' . ini_get('max_execution_time'));
        w_log_info('- memory_limit: ' . ini_get('memory_limit'));
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
                w_log_error('前端检测到文件大小限制错误，尝试忽略: ' . $errorMsg);
                
                // 检查文件是否仍然可用
                if (file_exists($file['tmp_name']) && is_readable($file['tmp_name'])) {
                    w_log_info('文件仍然可用，继续处理');
                    return; // 继续处理
                }
            }
            
            // 对于其他错误，抛出异常
            throw new \Exception(__('文件上传失败: %1', $errorMsg));
        }
    }
    
    /**
     * 获取multipart边界
     */
    private function getBoundary(): ?string
    {
        $contentType = \Weline\Framework\Env\WelineEnv::server('CONTENT_TYPE', '');
        if (preg_match('/boundary=(.*)$/i', $contentType, $matches)) {
            return trim($matches[1], '"');
        }
        return null;
    }
    
    /**
     * 解析multipart数据
     */
    private function consumeUploadTicket(): void
    {
        $token = \trim((string)WelineEnv::server('HTTP_X_WELINE_UPLOAD_TICKET', ''));
        if ($token === '') {
            throw new \Exception('Missing Weline upload ticket.');
        }

        /** @var Session $session */
        $session = ObjectManager::getInstance(Session::class);
        $stored = $session->getData(self::UPLOAD_TICKETS_SESSION_KEY);
        $tickets = \is_array($stored) ? $stored : [];
        $hash = \hash('sha256', $token);
        $expiresAt = (int)($tickets[$hash] ?? 0);
        unset($tickets[$hash]);
        $session->setData(self::UPLOAD_TICKETS_SESSION_KEY, \array_filter(
            $tickets,
            static fn(mixed $expires): bool => \is_int($expires) && $expires > \time()
        ));

        if ($expiresAt <= \time()) {
            throw new \Exception('Invalid or expired Weline upload ticket.');
        }
    }

    private function parseMultipartData(string $input, string $boundary): ?array
    {
        $parts = explode('--' . $boundary, $input);
        foreach ($parts as $part) {
            if (empty($part) || $part === '--') {
                continue;
            }
            
            // 查找文件数据
            if (strpos($part, 'name="font_file"') !== false) {
                // 提取文件名
                if (preg_match('/filename="([^"]+)"/', $part, $matches)) {
                    $filename = $matches[1];
                } else {
                    continue;
                }
                
                // 提取文件内容
                $fileStart = strpos($part, "\r\n\r\n");
                if ($fileStart === false) {
                    continue;
                }
                
                $fileData = substr($part, $fileStart + 4);
                $fileData = rtrim($fileData, "\r\n");
                
                return [
                    'name' => $filename,
                    'size' => strlen($fileData),
                    'tmp_name' => null,
                    'error' => 0,
                    'type' => 'application/octet-stream',
                    'data' => $fileData
                ];
            }
        }
        
        return null;
    }
}
