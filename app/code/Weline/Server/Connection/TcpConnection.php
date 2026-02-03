<?php
declare(strict_types=1);

/**
 * Weline Server - TCP 连接类
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Connection;

use Weline\Server\Worker;
use Weline\Server\Event\EventInterface;

/**
 * TcpConnection - TCP 连接管理
 * 
 * 负责管理单个 TCP 连接的读写和状态
 */
class TcpConnection implements ConnectionInterface
{
    /**
     * 连接状态：建立中
     */
    public const STATUS_CONNECTING = 1;
    
    /**
     * 连接状态：已建立
     */
    public const STATUS_ESTABLISHED = 2;
    
    /**
     * 连接状态：关闭中
     */
    public const STATUS_CLOSING = 4;
    
    /**
     * 连接状态：已关闭
     */
    public const STATUS_CLOSED = 8;
    
    /**
     * 读取缓冲区大小（64KB）
     */
    public const READ_BUFFER_SIZE = 65535;
    
    /**
     * 默认最大发送缓冲区大小（1MB）
     */
    public const DEFAULT_MAX_SEND_BUFFER_SIZE = 1048576;
    
    /**
     * 默认最大包长度（10MB）
     */
    public const DEFAULT_MAX_PACKAGE_SIZE = 10485760;
    
    /**
     * 连接 ID 计数器
     */
    protected static int $idRecorder = 0;
    
    /**
     * 连接 ID
     */
    public int $id = 0;
    
    /**
     * Socket 资源
     * @var resource|null
     */
    protected $socket;
    
    /**
     * 远程地址
     */
    public string $remoteAddress = '';
    
    /**
     * 事件循环
     */
    protected ?EventInterface $eventLoop = null;
    
    /**
     * 所属 Worker
     */
    public ?Worker $worker = null;
    
    /**
     * 协议类名
     */
    public string $protocol = '';
    
    /**
     * 传输层协议
     */
    public string $transport = 'tcp';
    
    /**
     * 当前状态
     */
    protected int $status = self::STATUS_ESTABLISHED;
    
    /**
     * 接收缓冲区
     */
    protected string $recvBuffer = '';
    
    /**
     * 发送缓冲区
     */
    protected string $sendBuffer = '';
    
    /**
     * 最大发送缓冲区大小
     */
    public int $maxSendBufferSize = self::DEFAULT_MAX_SEND_BUFFER_SIZE;
    
    /**
     * 最大包长度
     */
    public static int $maxPackageSize = self::DEFAULT_MAX_PACKAGE_SIZE;
    
    /**
     * 当前包长度
     */
    protected int $currentPackageLength = 0;
    
    /**
     * 是否暂停接收
     */
    protected bool $isPaused = false;
    
    /**
     * 回调函数
     */
    public $onMessage = null;
    public $onClose = null;
    public $onError = null;
    public $onBufferFull = null;
    public $onBufferDrain = null;
    
    /**
     * 构造函数
     * 
     * @param resource $socket Socket 资源
     * @param string $remoteAddress 远程地址
     * @param EventInterface|null $eventLoop 事件循环
     */
    public function __construct($socket, string $remoteAddress, ?EventInterface $eventLoop = null)
    {
        $this->id = ++static::$idRecorder;
        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
        $this->eventLoop = $eventLoop;
        
        // 设置非阻塞
        stream_set_blocking($this->socket, false);
        
        // 禁用 Nagle 算法（减少延迟）
        stream_set_read_buffer($this->socket, 0);
        
        // 监听读事件
        if ($this->eventLoop) {
            $this->eventLoop->add(
                $this->socket,
                EventInterface::EV_READ,
                [$this, 'baseRead']
            );
        }
    }
    
    /**
     * 读取数据（底层）
     */
    public function baseRead($socket, bool $checkEof = true): void
    {
        // 读取数据
        $buffer = @fread($socket, static::READ_BUFFER_SIZE);
        
        // 连接关闭或出错
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && (feof($socket) || !is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
            return;
        }
        
        // 追加到接收缓冲区
        $this->recvBuffer .= $buffer;
        
        // 处理数据
        $this->processRecvBuffer();
    }
    
    /**
     * 处理接收缓冲区
     */
    protected function processRecvBuffer(): void
    {
        // 没有协议，直接回调
        if ($this->protocol === '') {
            if ($this->recvBuffer !== '' && $this->onMessage) {
                $this->triggerMessage($this->recvBuffer);
                $this->recvBuffer = '';
            }
            return;
        }
        
        // 使用协议解析
        while ($this->recvBuffer !== '' && $this->status === self::STATUS_ESTABLISHED) {
            // 获取当前包长度
            if ($this->currentPackageLength === 0) {
                $this->currentPackageLength = ($this->protocol)::input(
                    $this->recvBuffer,
                    $this
                );
            }
            
            // 包不完整
            if ($this->currentPackageLength === 0) {
                return;
            }
            
            // 包长度错误
            if ($this->currentPackageLength < 0) {
                $this->close();
                return;
            }
            
            // 检查包长度限制
            if ($this->currentPackageLength > static::$maxPackageSize) {
                Worker::log(\__('包太大：%{1}', [$this->currentPackageLength]));
                $this->close();
                return;
            }
            
            // 缓冲区数据不足
            if (strlen($this->recvBuffer) < $this->currentPackageLength) {
                return;
            }
            
            // 提取完整包
            $data = substr($this->recvBuffer, 0, $this->currentPackageLength);
            $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
            $this->currentPackageLength = 0;
            
            // 解码并触发消息回调
            $decoded = ($this->protocol)::decode($data, $this);
            $this->triggerMessage($decoded);
        }
    }
    
    /**
     * 触发消息回调
     */
    protected function triggerMessage($data): void
    {
        try {
            // 优先使用连接级回调
            if ($this->onMessage) {
                ($this->onMessage)($this, $data);
            } elseif ($this->worker && $this->worker->onMessage) {
                ($this->worker->onMessage)($this, $data);
            }
        } catch (\Throwable $e) {
            Worker::log(\__('onMessage 错误：%{1}', [$e->getMessage()]));
            
            if ($this->onError) {
                ($this->onError)($this, $e->getCode(), $e->getMessage());
            } elseif ($this->worker && $this->worker->onError) {
                ($this->worker->onError)($this, $e->getCode(), $e->getMessage());
            }
        }
    }
    
    /**
     * @inheritDoc
     */
    public function send(mixed $data, bool $raw = false): bool
    {
        if ($this->status === self::STATUS_CLOSED) {
            return false;
        }
        
        // 协议编码
        if (!$raw && $this->protocol !== '') {
            $data = ($this->protocol)::encode($data, $this);
            
            if ($data === '') {
                return false;
            }
        }
        
        // 如果发送缓冲区为空，尝试直接发送
        if ($this->sendBuffer === '') {
            $len = @fwrite($this->socket, $data);
            
            // 全部发送成功
            if ($len === strlen($data)) {
                return true;
            }
            
            // 部分发送
            if ($len > 0) {
                $data = substr($data, $len);
            }
        }
        
        // 检查发送缓冲区大小
        if (strlen($this->sendBuffer) + strlen($data) >= $this->maxSendBufferSize) {
            // 触发缓冲区满回调
            if ($this->onBufferFull) {
                ($this->onBufferFull)($this);
            } elseif ($this->worker && $this->worker->onBufferFull) {
                ($this->worker->onBufferFull)($this);
            }
            return false;
        }
        
        // 追加到发送缓冲区
        $this->sendBuffer .= $data;
        
        // 注册写事件
        $this->eventLoop->add(
            $this->socket,
            EventInterface::EV_WRITE,
            [$this, 'baseWrite']
        );
        
        return true;
    }
    
    /**
     * 写入数据（底层）
     */
    public function baseWrite(): void
    {
        $len = @fwrite($this->socket, $this->sendBuffer);
        
        if ($len === strlen($this->sendBuffer)) {
            // 全部发送完成
            $this->sendBuffer = '';
            
            // 移除写事件
            $this->eventLoop->del($this->socket, EventInterface::EV_WRITE);
            
            // 触发缓冲区空回调
            if ($this->onBufferDrain) {
                ($this->onBufferDrain)($this);
            } elseif ($this->worker && $this->worker->onBufferDrain) {
                ($this->worker->onBufferDrain)($this);
            }
            
            // 如果是关闭中状态，现在关闭
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            
            return;
        }
        
        if ($len > 0) {
            $this->sendBuffer = substr($this->sendBuffer, $len);
        }
    }
    
    /**
     * @inheritDoc
     */
    public function close(mixed $data = null): void
    {
        if ($this->status === self::STATUS_CLOSED || $this->status === self::STATUS_CLOSING) {
            return;
        }
        
        // 发送关闭前的数据
        if ($data !== null) {
            $this->send($data);
        }
        
        // 如果发送缓冲区不为空，设置为关闭中状态
        if ($this->sendBuffer !== '') {
            $this->status = self::STATUS_CLOSING;
            return;
        }
        
        $this->destroy();
    }
    
    /**
     * 销毁连接
     */
    public function destroy(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        
        $this->status = self::STATUS_CLOSED;
        
        // 移除事件监听
        if ($this->eventLoop && is_resource($this->socket)) {
            $this->eventLoop->del($this->socket, EventInterface::EV_READ);
            $this->eventLoop->del($this->socket, EventInterface::EV_WRITE);
        }
        
        // 关闭 Socket
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        
        // 从 Worker 中移除
        if ($this->worker) {
            unset($this->worker->connections[$this->id]);
            $this->worker->connectionCount--;
        }
        
        // 触发关闭回调
        $this->triggerClose();
        
        // 清理引用
        $this->socket = null;
        $this->eventLoop = null;
        $this->worker = null;
        $this->recvBuffer = '';
        $this->sendBuffer = '';
    }
    
    /**
     * 触发关闭回调
     */
    protected function triggerClose(): void
    {
        try {
            if ($this->onClose) {
                ($this->onClose)($this);
            } elseif ($this->worker && $this->worker->onClose) {
                ($this->worker->onClose)($this);
            }
        } catch (\Throwable $e) {
            Worker::log(\__('onClose 错误：%{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * @inheritDoc
     */
    public function getRemoteIp(): string
    {
        $pos = strrpos($this->remoteAddress, ':');
        
        if ($pos === false) {
            return '';
        }
        
        return substr($this->remoteAddress, 0, $pos);
    }
    
    /**
     * @inheritDoc
     */
    public function getRemotePort(): int
    {
        $pos = strrpos($this->remoteAddress, ':');
        
        if ($pos === false) {
            return 0;
        }
        
        return (int) substr($this->remoteAddress, $pos + 1);
    }
    
    /**
     * @inheritDoc
     */
    public function getLocalIp(): string
    {
        if (!is_resource($this->socket)) {
            return '';
        }
        
        $localAddress = stream_socket_get_name($this->socket, false);
        
        if (!$localAddress) {
            return '';
        }
        
        $pos = strrpos($localAddress, ':');
        
        if ($pos === false) {
            return '';
        }
        
        return substr($localAddress, 0, $pos);
    }
    
    /**
     * @inheritDoc
     */
    public function getLocalPort(): int
    {
        if (!is_resource($this->socket)) {
            return 0;
        }
        
        $localAddress = stream_socket_get_name($this->socket, false);
        
        if (!$localAddress) {
            return 0;
        }
        
        $pos = strrpos($localAddress, ':');
        
        if ($pos === false) {
            return 0;
        }
        
        return (int) substr($localAddress, $pos + 1);
    }
    
    /**
     * @inheritDoc
     */
    public function pauseRecv(): void
    {
        if ($this->isPaused || $this->status !== self::STATUS_ESTABLISHED) {
            return;
        }
        
        $this->isPaused = true;
        $this->eventLoop->del($this->socket, EventInterface::EV_READ);
    }
    
    /**
     * @inheritDoc
     */
    public function resumeRecv(): void
    {
        if (!$this->isPaused || $this->status !== self::STATUS_ESTABLISHED) {
            return;
        }
        
        $this->isPaused = false;
        $this->eventLoop->add($this->socket, EventInterface::EV_READ, [$this, 'baseRead']);
    }
    
    /**
     * 获取连接状态
     */
    public function getStatus(): int
    {
        return $this->status;
    }
    
    /**
     * 检查连接是否已建立
     */
    public function isConnected(): bool
    {
        return $this->status === self::STATUS_ESTABLISHED;
    }
    
    /**
     * 获取接收缓冲区数据
     */
    public function getRecvBuffer(): string
    {
        return $this->recvBuffer;
    }
    
    /**
     * 获取发送缓冲区大小
     */
    public function getSendBufferSize(): int
    {
        return strlen($this->sendBuffer);
    }
}
