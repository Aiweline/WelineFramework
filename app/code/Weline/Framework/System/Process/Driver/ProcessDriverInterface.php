<?php
declare(strict_types=1);

namespace Weline\Framework\System\Process\Driver;

/**
 * 进程驱动接口
 * 
 * 定义 OS 特定的进程操作契约。
 * 遵循里氏替换原则（LSP）和开闭原则（OCP）：
 * - 所有实现类可以相互替换
 * - 新增系统支持只需添加新驱动，无需修改现有代码
 */
interface ProcessDriverInterface
{
    public const PROCESS_STATE_RUNNING = 'running';
    public const PROCESS_STATE_EXITED = 'exited';
    public const PROCESS_STATE_UNKNOWN = 'unknown';

    /**
     * 获取驱动支持的操作系统名称
     * 
     * @return string 如 'Windows', 'Linux', 'Darwin' 等
     */
    public function getOsName(): string;
    
    /**
     * 检查当前驱动是否支持当前系统
     * 
     * @return bool
     */
    public function supports(): bool;
    
    /**
     * 通过进程名查找 PHP 进程 PID
     * 
     * @param string $pname 进程名（可能包含 --name= 参数）
     * @return int PID，未找到返回 0
     */
    public function findPhpProcessPid(string $pname): int;
    
    /**
     * 启动 PHP 内置服务器
     * 
     * @param string $docRoot 文档根目录
     * @param int $port 端口
     * @param string $logFile 日志文件
     * @return int 返回 PID，失败返回 0
     */
    public function startBuiltInServer(string $docRoot, int $port, string $logFile): int;
    
    /**
     * 启动后台进程
     * 
     * @param string $command 完整命令
     * @param string $logFile 日志文件路径
     * @param bool $block 是否阻塞等待
     * @return int PID，失败返回 0
     */
    public function startBackgroundProcess(string $command, string $logFile, bool $block = false): int;
    
    /**
     * 杀死进程（通过 PID）
     * 
     * @param int $pid 进程 ID
     * @return bool 是否成功
     */
    public function killProcess(int $pid): bool;
    
    /**
     * 强制杀死进程树（进程及其子进程）
     * 
     * @param int $pid 进程 ID
     * @return bool 是否成功
     */
    public function killProcessTree(int $pid): bool;

    /**
     * 对 PID（可选包含当前进程树快照）执行一次强制终止，不等待、不重试。
     *
     * 该原语用于上层身份围栏：每次重试前都必须重新验证 PID 身份，驱动内部
     * 不能在等待期间再次向可能已复用的 PID 发信号。
     *
     * @param int $pid 进程 ID
     * @param bool $tree 是否同时终止当前子进程快照
     */
    public function killProcessOnce(int $pid, bool $tree = false): bool;
    
    /**
     * 向进程发送指定信号
     *
     * @param int $pid 进程 ID
     * @param int $signal 信号值（如 SIGTERM/SIGUSR1/SIGHUP）
     * @return bool 是否发送成功
     */
    public function sendSignal(int $pid, int $signal): bool;
    
    /**
     * 检查端口是否被占用（是否有进程在监听）
     * 
     * @param int $port 端口号
     * @return bool true=被占用
     */
    public function isPortInUse(int $port): bool;
    
    /**
     * 获取监听指定端口的进程 ID
     * 
     * @param int $port 端口号
     * @return int PID，未找到返回 0
     */
    public function getProcessIdByPort(int $port): int;
    
    /**
     * 检查进程是否运行中（通过 PID）
     * 
     * @param int $pid 进程 ID
     * @return bool
     */
    public function isRunningByPid(int $pid): bool;

    /**
     * 三态探测进程状态。
     *
     * 与 {@see isRunningByPid()} 不同，系统调用失败、权限异常或输出不可解析时
     * 必须返回 unknown，不能把“无法确认”误判为“已经退出”。
     *
     * @param int $pid 进程 ID
     * @param bool $fresh 是否绕过驱动进程内缓存执行新探测
     * @return string self::PROCESS_STATE_RUNNING、EXITED 或 UNKNOWN
     */
    public function probeProcessState(int $pid, bool $fresh = false): string;
    
    /**
     * 获取进程详细信息
     * 
     * @param int $pid 进程 ID
     * @return array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}
     */
    public function getProcessInfo(int $pid): array;
    
    /**
     * 获取进程命令行
     * 
     * @param int $pid 进程 ID
     * @return string 命令行，失败返回空字符串
     */
    public function getProcessCommandLine(int $pid): string;
    
    /**
     * 通过进程名模式查找所有匹配的 PID
     * 
     * @param string $processName 进程名（用于命令行匹配）
     * @return int[] PID 数组
     */
    public function findProcessesByName(string $processName): array;
    
    /**
     * 强制释放端口
     * 
     * @param int $port 端口号
     * @return bool 是否成功
     */
    public function forceReleasePort(int $port): bool;
    
    /**
     * 清除端口检测缓存
     * 
     * @param int|null $port 指定端口，null 清除全部
     */
    public function clearPortCache(?int $port = null): void;
    
    /**
     * 批量获取多个进程的详细信息
     * 
     * 单次系统调用获取多个进程信息，避免逐个查询的性能开销
     * 
     * @param int[] $pids 进程 ID 数组
     * @return array<int, array{pid: int, exists: bool, name: string, command: string, memory: string, cpu: string, start_time: string}>
     *               键为 PID，值为进程信息数组
     */
    public function batchGetProcessInfo(array $pids): array;
}
