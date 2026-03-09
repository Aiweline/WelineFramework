<?php

declare(strict_types=1);

/**
 * WLS Session Server NDJSON 协议编解码
 *
 * 定义 Session Server 与 Client 之间的通信协议。
 * 使用 NDJSON（Newline-Delimited JSON）格式，每条消息以 \n 结尾。
 *
 * @author Aiweline
 */

namespace Weline\Server\Session\Server;

final class SessionProtocol
{
    // ==================== 序列化器 ====================
    
    public const SERIALIZER_JSON = 'json';
    public const SERIALIZER_MSGPACK = 'msgpack';
    public const SERIALIZER_IGBINARY = 'igbinary';
    
    /** 当前序列化器 */
    private static string $serializer = self::SERIALIZER_JSON;
    
    /**
     * 设置序列化器
     */
    public static function setSerializer(string $serializer): void
    {
        if ($serializer === self::SERIALIZER_MSGPACK && !\extension_loaded('msgpack')) {
            $serializer = self::SERIALIZER_JSON;
        }
        if ($serializer === self::SERIALIZER_IGBINARY && !\extension_loaded('igbinary')) {
            $serializer = self::SERIALIZER_JSON;
        }
        self::$serializer = $serializer;
    }
    
    /**
     * 获取当前序列化器
     */
    public static function getSerializer(): string
    {
        return self::$serializer;
    }
    
    /**
     * 检查序列化器是否可用
     */
    public static function isSerializerAvailable(string $serializer): bool
    {
        return match ($serializer) {
            self::SERIALIZER_JSON => true,
            self::SERIALIZER_MSGPACK => \extension_loaded('msgpack'),
            self::SERIALIZER_IGBINARY => \extension_loaded('igbinary'),
            default => false,
        };
    }

    // ==================== 命令类型 ====================

    /** 获取单个 Session 键值 */
    public const CMD_GET = 'get';

    /** 获取整个 Session */
    public const CMD_GET_ALL = 'get_all';

    /** 设置单个 Session 键值 */
    public const CMD_SET = 'set';

    /** 批量设置整个 Session */
    public const CMD_SET_ALL = 'set_all';

    /** 删除单个 Session 键 */
    public const CMD_DELETE = 'delete';

    /** 销毁整个 Session */
    public const CMD_DESTROY = 'destroy';

    /** 检查 Session 是否存在 */
    public const CMD_EXISTS = 'exists';

    /** 刷新 Session 过期时间 */
    public const CMD_TOUCH = 'touch';

    /** 批量获取多个键 */
    public const CMD_MGET = 'mget';

    /** 批量设置多个键 */
    public const CMD_MSET = 'mset';

    /** 垃圾回收 */
    public const CMD_GC = 'gc';

    /** 强制持久化 */
    public const CMD_PERSIST = 'persist';

    /** 获取统计信息 */
    public const CMD_STATS = 'stats';

    /** 心跳/存活检测 */
    public const CMD_PING = 'ping';
    
    /** 认证命令 */
    public const CMD_AUTH = 'auth';
    
    /** 原子递增 */
    public const CMD_INCREMENT = 'incr';
    
    /** 原子递减 */
    public const CMD_DECREMENT = 'decr';
    
    /** 原子追加（数组） */
    public const CMD_APPEND = 'append';
    
    /** 比较并设置（CAS） */
    public const CMD_COMPARE_SET = 'cas';
    
    /** Prometheus 格式指标 */
    public const CMD_METRICS = 'metrics';
    
    /** 列出 Session（支持过滤） */
    public const CMD_LIST = 'list';

    // ==================== 编码方法 ====================

    /**
     * 编码请求消息
     *
     * @param string $cmd 命令类型
     * @param array $params 参数
     * @return string NDJSON 格式消息
     */
    public static function encodeRequest(string $cmd, array $params = []): string
    {
        $message = ['cmd' => $cmd] + $params;
        return self::serialize($message) . "\n";
    }
    
    /**
     * 序列化消息
     */
    private static function serialize(array $message): string
    {
        return match (self::$serializer) {
            self::SERIALIZER_MSGPACK => \msgpack_pack($message),
            self::SERIALIZER_IGBINARY => \igbinary_serialize($message),
            default => \json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
    
    /**
     * 反序列化消息
     */
    private static function unserialize(string $data): ?array
    {
        $result = match (self::$serializer) {
            self::SERIALIZER_MSGPACK => @\msgpack_unpack($data),
            self::SERIALIZER_IGBINARY => @\igbinary_unserialize($data),
            default => @\json_decode($data, true),
        };
        
        return \is_array($result) ? $result : null;
    }

    /**
     * 编码成功响应
     *
     * @param mixed $data 响应数据
     * @return string NDJSON 格式消息
     */
    public static function encodeSuccess(mixed $data = null): string
    {
        $message = ['ok' => true];
        if ($data !== null) {
            $message['data'] = $data;
        }
        return self::serialize($message) . "\n";
    }

    /**
     * 编码错误响应
     *
     * @param string $error 错误信息
     * @param string|null $code 错误代码
     * @return string NDJSON 格式消息
     */
    public static function encodeError(string $error, ?string $code = null): string
    {
        $message = ['ok' => false, 'err' => $error];
        if ($code !== null) {
            $message['code'] = $code;
        }
        return self::serialize($message) . "\n";
    }

    /**
     * 解码消息
     *
     * @param string $message 原始消息（可能包含多行）
     * @return array|null 解码后的消息，失败返回 null
     */
    public static function decode(string $message): ?array
    {
        $message = \trim($message);
        if ($message === '') {
            return null;
        }

        return self::unserialize($message);
    }

    /**
     * 从缓冲区提取完整消息
     *
     * @param string $buffer 读缓冲区（引用，会移除已提取的消息）
     * @return array 提取的消息数组
     */
    public static function extractMessages(string &$buffer): array
    {
        $messages = [];
        
        while (($pos = \strpos($buffer, "\n")) !== false) {
            $line = \substr($buffer, 0, $pos);
            $buffer = \substr($buffer, $pos + 1);
            
            $decoded = self::decode($line);
            if ($decoded !== null) {
                $messages[] = $decoded;
            }
        }
        
        return $messages;
    }

    // ==================== 请求构建器 ====================

    /**
     * 构建 GET 请求
     */
    public static function buildGet(string $sessionId, ?string $key = null): string
    {
        $params = ['sid' => $sessionId];
        if ($key !== null) {
            $params['key'] = $key;
        }
        return self::encodeRequest(self::CMD_GET, $params);
    }

    /**
     * 构建 GET_ALL 请求
     */
    public static function buildGetAll(string $sessionId): string
    {
        return self::encodeRequest(self::CMD_GET_ALL, ['sid' => $sessionId]);
    }

    /**
     * 构建 SET 请求
     */
    public static function buildSet(string $sessionId, string $key, mixed $value, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_SET, [
            'sid' => $sessionId,
            'key' => $key,
            'val' => $value,
            'ttl' => $ttl,
        ]);
    }

    /**
     * 构建 SET_ALL 请求
     */
    public static function buildSetAll(string $sessionId, array $data, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_SET_ALL, [
            'sid' => $sessionId,
            'data' => $data,
            'ttl' => $ttl,
        ]);
    }

    /**
     * 构建 DELETE 请求
     */
    public static function buildDelete(string $sessionId, string $key): string
    {
        return self::encodeRequest(self::CMD_DELETE, [
            'sid' => $sessionId,
            'key' => $key,
        ]);
    }

    /**
     * 构建 DESTROY 请求
     */
    public static function buildDestroy(string $sessionId): string
    {
        return self::encodeRequest(self::CMD_DESTROY, ['sid' => $sessionId]);
    }

    /**
     * 构建 EXISTS 请求
     */
    public static function buildExists(string $sessionId): string
    {
        return self::encodeRequest(self::CMD_EXISTS, ['sid' => $sessionId]);
    }

    /**
     * 构建 TOUCH 请求
     */
    public static function buildTouch(string $sessionId, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_TOUCH, [
            'sid' => $sessionId,
            'ttl' => $ttl,
        ]);
    }

    /**
     * 构建 MGET 请求
     *
     * @param string[] $keys
     */
    public static function buildMget(string $sessionId, array $keys): string
    {
        return self::encodeRequest(self::CMD_MGET, [
            'sid' => $sessionId,
            'keys' => $keys,
        ]);
    }

    /**
     * 构建 MSET 请求
     *
     * @param array<string, mixed> $data
     */
    public static function buildMset(string $sessionId, array $data, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_MSET, [
            'sid' => $sessionId,
            'data' => $data,
            'ttl' => $ttl,
        ]);
    }

    /**
     * 构建 GC 请求
     */
    public static function buildGc(int $maxLifetime): string
    {
        return self::encodeRequest(self::CMD_GC, ['max_lifetime' => $maxLifetime]);
    }

    /**
     * 构建 PERSIST 请求
     */
    public static function buildPersist(): string
    {
        return self::encodeRequest(self::CMD_PERSIST);
    }

    /**
     * 构建 STATS 请求
     */
    public static function buildStats(): string
    {
        return self::encodeRequest(self::CMD_STATS);
    }

    /**
     * 构建 PING 请求
     */
    public static function buildPing(): string
    {
        return self::encodeRequest(self::CMD_PING);
    }
    
    /**
     * 构建 AUTH 请求
     */
    public static function buildAuth(string $token): string
    {
        return self::encodeRequest(self::CMD_AUTH, ['token' => $token]);
    }
    
    /**
     * 构建 INCREMENT 请求
     */
    public static function buildIncrement(string $sessionId, string $key, int $delta = 1, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_INCREMENT, [
            'sid' => $sessionId,
            'key' => $key,
            'delta' => $delta,
            'ttl' => $ttl,
        ]);
    }
    
    /**
     * 构建 DECREMENT 请求
     */
    public static function buildDecrement(string $sessionId, string $key, int $delta = 1, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_DECREMENT, [
            'sid' => $sessionId,
            'key' => $key,
            'delta' => $delta,
            'ttl' => $ttl,
        ]);
    }
    
    /**
     * 构建 APPEND 请求
     */
    public static function buildAppend(string $sessionId, string $key, mixed $value, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_APPEND, [
            'sid' => $sessionId,
            'key' => $key,
            'val' => $value,
            'ttl' => $ttl,
        ]);
    }
    
    /**
     * 构建 COMPARE_SET 请求（CAS）
     */
    public static function buildCompareSet(string $sessionId, string $key, mixed $expected, mixed $newValue, int $ttl = 3600): string
    {
        return self::encodeRequest(self::CMD_COMPARE_SET, [
            'sid' => $sessionId,
            'key' => $key,
            'expected' => $expected,
            'val' => $newValue,
            'ttl' => $ttl,
        ]);
    }
    
    /**
     * 构建 METRICS 请求
     */
    public static function buildMetrics(): string
    {
        return self::encodeRequest(self::CMD_METRICS);
    }
    
    /**
     * 构建 LIST 请求
     *
     * @param array $filter 过滤条件，如 ['type' => 'backend']
     * @param int $limit 最大返回数量
     */
    public static function buildList(array $filter = [], int $limit = 50): string
    {
        return self::encodeRequest(self::CMD_LIST, [
            'filter' => $filter,
            'limit' => $limit,
        ]);
    }

    // ==================== 响应解析器 ====================

    /**
     * 检查响应是否成功
     */
    public static function isSuccess(array $response): bool
    {
        return ($response['ok'] ?? false) === true;
    }

    /**
     * 获取响应数据
     */
    public static function getData(array $response): mixed
    {
        return $response['data'] ?? null;
    }

    /**
     * 获取错误信息
     */
    public static function getError(array $response): ?string
    {
        return $response['err'] ?? null;
    }
}
