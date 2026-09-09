<?php

declare(strict_types=1);

namespace Weline\CustomerService\Service;

/**
 * 结构化聊天附件协议（写入 content 字段，兼容旧纯文本）。
 */
final class ChatAttachmentCodec
{
    public const PREFIX = '__CSJSON__';

    public const TYPE_IMAGE = 'image';
    public const TYPE_FILE = 'file';

    /**
     * @param array{type:string,url:string,name?:string,size?:int,mime?:string} $payload
     */
    public static function encode(array $payload): string
    {
        $type = (string)($payload['type'] ?? '');
        $url = trim((string)($payload['url'] ?? ''));
        if (($type !== self::TYPE_IMAGE && $type !== self::TYPE_FILE) || $url === '') {
            throw new \InvalidArgumentException((string)__('无效的附件消息'));
        }

        $data = [
            'type' => $type,
            'url' => $url,
            'name' => (string)($payload['name'] ?? ''),
            'size' => (int)($payload['size'] ?? 0),
            'mime' => (string)($payload['mime'] ?? ''),
        ];

        return self::PREFIX . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{type:string,url:string,name:string,size:int,mime:string}|null
     */
    public static function decode(string $content): ?array
    {
        $content = trim($content);
        if (!str_starts_with($content, self::PREFIX)) {
            return null;
        }
        $json = substr($content, strlen(self::PREFIX));
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $type = (string)($data['type'] ?? '');
        $url = trim((string)($data['url'] ?? ''));
        if (($type !== self::TYPE_IMAGE && $type !== self::TYPE_FILE) || $url === '') {
            return null;
        }

        return [
            'type' => $type,
            'url' => $url,
            'name' => (string)($data['name'] ?? ''),
            'size' => (int)($data['size'] ?? 0),
            'mime' => (string)($data['mime'] ?? ''),
        ];
    }

    public static function isStructured(string $content): bool
    {
        return self::decode($content) !== null;
    }

    public static function displayFallback(string $content): string
    {
        $decoded = self::decode($content);
        if ($decoded === null) {
            return $content;
        }
        if ($decoded['type'] === self::TYPE_IMAGE) {
            return $decoded['name'] !== '' ? (string)__('图片：%{1}', [$decoded['name']]) : (string)__('图片');
        }

        return $decoded['name'] !== '' ? (string)__('文件：%{1}', [$decoded['name']]) : (string)__('文件');
    }
}
