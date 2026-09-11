<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

/**
 * 客服聊天附件上传（base64 → pub/media）。
 */
final class ChatMediaUploader
{
    /**
     * @return array{url:string,name:string,mime:string,size:int,kind:string}
     */
    public function storeBase64(string $name, string $mime, string $data, string $ownerSegment): array
    {
        $mime = strtolower(trim($mime));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }
        $base64 = $data;
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, (int)strpos($base64, ',') + 1);
        }
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException((string)__('上传数据无效'));
        }

        $size = strlen($binary);
        $isImage = str_starts_with($mime, 'image/');
        $max = $isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024;
        if ($size > $max) {
            throw new \InvalidArgumentException((string)__('文件过大'));
        }

        $allowedImage = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedFile = [
            'application/pdf',
            'text/plain',
            'application/zip',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if ($isImage && !in_array($mime, $allowedImage, true)) {
            throw new \InvalidArgumentException((string)__('不支持的图片类型'));
        }
        if (!$isImage && !in_array($mime, $allowedFile, true)) {
            throw new \InvalidArgumentException((string)__('不支持的文件类型'));
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._\-一-龥]+/u', '_', trim($name)) ?: 'file';
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        if ($ext === '') {
            $ext = $isImage ? 'png' : 'bin';
            $safeName .= '.' . $ext;
        }

        $owner = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $ownerSegment) ?: 'guest';
        $dirRel = 'customerservice/' . $owner . '/' . date('Y/m/d');
        $dirAbs = rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'pub' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $dirRel);
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new \RuntimeException((string)__('无法创建上传目录'));
        }
        $stored = bin2hex(random_bytes(8)) . '_' . $safeName;
        $pathAbs = $dirAbs . DIRECTORY_SEPARATOR . $stored;
        if (file_put_contents($pathAbs, $binary) === false) {
            throw new \RuntimeException((string)__('保存文件失败'));
        }

        return [
            'url' => '/media/' . $dirRel . '/' . rawurlencode($stored),
            'name' => $safeName,
            'mime' => $mime,
            'size' => $size,
            'kind' => $isImage ? ChatAttachmentCodec::TYPE_IMAGE : ChatAttachmentCodec::TYPE_FILE,
        ];
    }
}
