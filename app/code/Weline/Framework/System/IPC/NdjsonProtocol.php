<?php
declare(strict_types=1);

namespace Weline\Framework\System\IPC;

/**
 * NDJSON 消息编解码工具
 *
 * Newline-Delimited JSON 格式：每条消息为一行 JSON 字符串 + "\n"。
 * 负责处理粘包/半包，与具体协议内容无关。
 */
final class NdjsonProtocol
{
    private function __construct() {}

    /**
     * 编码消息为 NDJSON 行
     *
     * @param array $data 消息数据（通常包含 'type' 键）
     * @return string 以 "\n" 结尾的 JSON 字符串
     */
    public static function encode(array $data): string
    {
        return \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * 解码一行 NDJSON 消息
     *
     * @param string $line 单行 JSON 字符串（可含尾部换行）
     * @return array|null 解码后的数组，失败返回 null
     */
    public static function decode(string $line): ?array
    {
        $line = \trim($line);
        if ($line === '') {
            return null;
        }

        $data = \json_decode($line, true);
        if (!\is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * 解码带 type 字段校验的 NDJSON 消息
     *
     * @param string $line 单行 JSON 字符串
     * @return array|null 解码后的数组（必须含 type），失败返回 null
     */
    public static function decodeWithType(string $line): ?array
    {
        $data = self::decode($line);
        if ($data === null || !isset($data['type'])) {
            return null;
        }
        return $data;
    }

    /**
     * 从缓冲区提取所有完整消息（处理粘包/半包）
     *
     * 传入引用缓冲区，提取所有完整的 NDJSON 行，
     * 未完成的半包数据留在缓冲区中等待下次追加。
     *
     * @param string &$buffer 读取缓冲区（引用传递，会被修改）
     * @param bool   $requireType 是否要求消息包含 type 字段（默认 true）
     * @return array 解码后的消息数组
     */
    public static function extractMessages(string &$buffer, bool $requireType = true): array
    {
        $messages = [];

        $lastNewline = \strrpos($buffer, "\n");
        if ($lastNewline === false) {
            return $messages;
        }

        $complete = \substr($buffer, 0, $lastNewline + 1);
        $buffer = \substr($buffer, $lastNewline + 1);

        $lines = \explode("\n", $complete);
        foreach ($lines as $line) {
            $msg = $requireType ? self::decodeWithType($line) : self::decode($line);
            if ($msg !== null) {
                $messages[] = $msg;
            }
        }

        return $messages;
    }
}
