<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace WelineTools\Icon\Controller;

use WelineTools\Icon\Service\IconProcessor;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;
use Exception;

/**
 * 图标工具控制器
 * 提供图标上传、转换、压缩等功能
 */
class Icon extends FrontendController
{
    /**
     * 显示图标工具页面
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 上传并处理图标
     * POST /icon/icon/upload
     */
    public function postUpload()
    {
        try {
            // 检查文件上传
            if (empty($_FILES) || !isset($_FILES['file'])) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '请选择要上传的文件'
                ]);
            }

            $file = $_FILES['file'];

            // 验证上传错误
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => $this->getUploadErrorMessage($file['error'])
                ]);
            }

            // 验证文件类型
            $allowedMime = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowedMime, true)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '不支持的文件类型，仅支持 PNG、JPG、GIF、WebP、SVG、BMP、ICO 格式'
                ]);
            }

            // 验证文件大小（最大10MB）
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $maxSize) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '文件大小超过限制（最大10MB）'
                ]);
            }

            // 保存上传的文件
            $uploadDir = BP . 'pub/media/icon/uploads/' . date('Y/m/d/');
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0775, true);
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = uniqid('icon_', true) . '.' . $ext;
            $uploadPath = $uploadDir . $filename;

            if (!@move_uploaded_file($file['tmp_name'], $uploadPath)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '文件保存失败'
                ]);
            }

            // 返回文件信息
            $publicUrl = '/media/icon/uploads/' . date('Y/m/d/') . $filename;

            return $this->fetchJson([
                'success' => true,
                'message' => '文件上传成功',
                'data' => [
                    'url' => $publicUrl,
                    'path' => $uploadPath,
                    'name' => $file['name'],
                    'size' => $file['size'],
                    'type' => $mime,
                    'ext' => $ext
                ]
            ]);
        } catch (Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => '上传失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 转换图标格式
     * POST /icon/icon/convert
     */
    public function postConvert()
    {
        try {
            $sourcePath = $this->request->getPost('source_path', '');
            $targetFormat = $this->request->getPost('target_format', 'ico');
            $width = $this->request->getPost('width', null);
            $height = $this->request->getPost('height', null);
            $quality = (int)$this->request->getPost('quality', 90);

            if (empty($sourcePath)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '缺少源文件路径'
                ]);
            }

            // 转换为绝对路径
            if (strpos($sourcePath, '/media/') === 0) {
                $sourcePath = BP . 'pub' . $sourcePath;
            }

            if (!file_exists($sourcePath)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '源文件不存在'
                ]);
            }

            // 生成目标文件路径
            $pathInfo = pathinfo($sourcePath);
            $targetDir = $pathInfo['dirname'] . '/converted/';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            $targetFilename = $pathInfo['filename'] . '.' . strtolower($targetFormat);
            $targetPath = $targetDir . $targetFilename;

            // 转换图标
            /** @var IconProcessor $processor */
            $processor = ObjectManager::getInstance(IconProcessor::class);
            $result = $processor->convert(
                $sourcePath,
                $targetPath,
                $targetFormat,
                $width ? (int)$width : null,
                $height ? (int)$height : null,
                $quality
            );

            // 生成公共URL
            $relativePath = str_replace(BP . 'pub', '', $targetPath);
            $publicUrl = str_replace('\\', '/', $relativePath);

            return $this->fetchJson([
                'success' => true,
                'message' => '转换成功',
                'data' => [
                    'url' => $publicUrl,
                    'path' => $targetPath,
                    'size' => $result['size'],
                    'format' => $result['format']
                ]
            ]);
        } catch (Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => '转换失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 压缩图片
     * POST /icon/icon/compress
     */
    public function postCompress()
    {
        try {
            $sourcePath = $this->request->getPost('source_path', '');
            $quality = (int)$this->request->getPost('quality', 80);

            if (empty($sourcePath)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '缺少源文件路径'
                ]);
            }

            // 转换为绝对路径
            if (strpos($sourcePath, '/media/') === 0) {
                $sourcePath = BP . 'pub' . $sourcePath;
            }

            if (!file_exists($sourcePath)) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => '源文件不存在'
                ]);
            }

            // 生成目标文件路径
            $pathInfo = pathinfo($sourcePath);
            $targetDir = $pathInfo['dirname'] . '/compressed/';
            if (!is_dir($targetDir)) {
                @mkdir($targetDir, 0775, true);
            }

            $targetFilename = 'compressed_' . $pathInfo['filename'] . '.' . $pathInfo['extension'];
            $targetPath = $targetDir . $targetFilename;

            // 压缩图片
            /** @var IconProcessor $processor */
            $processor = ObjectManager::getInstance(IconProcessor::class);
            $result = $processor->compress($sourcePath, $targetPath, $quality);

            // 生成公共URL
            $relativePath = str_replace(BP . 'pub', '', $targetPath);
            $publicUrl = str_replace('\\', '/', $relativePath);

            $originalSize = filesize($sourcePath);
            $compressedSize = $result['size'];
            $savedPercent = round((1 - $compressedSize / $originalSize) * 100, 2);

            return $this->fetchJson([
                'success' => true,
                'message' => '压缩成功',
                'data' => [
                    'url' => $publicUrl,
                    'path' => $targetPath,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'saved_percent' => $savedPercent
                ]
            ]);
        } catch (Exception $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => '压缩失败: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * 获取上传错误信息
     */
    private function getUploadErrorMessage(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
                return '文件大小超过服务器限制';
            case UPLOAD_ERR_FORM_SIZE:
                return '文件大小超过表单限制';
            case UPLOAD_ERR_PARTIAL:
                return '文件只有部分被上传';
            case UPLOAD_ERR_NO_FILE:
                return '没有文件被上传';
            case UPLOAD_ERR_NO_TMP_DIR:
                return '找不到临时文件夹';
            case UPLOAD_ERR_CANT_WRITE:
                return '文件写入失败';
            case UPLOAD_ERR_EXTENSION:
                return '文件上传被扩展程序阻止';
            default:
                return '未知的上传错误';
        }
    }
}

