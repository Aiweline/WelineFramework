<?php
declare(strict_types=1);

/**
 * Weline Framework - 运行时接口
 * 
 * 定义框架运行时的统一抽象，支持 FPM 和 WLS 双模式
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

/**
 * 运行时接口
 * 
 * 抽象框架的运行时生命周期：
 * - bootstrap(): 启动运行时（进程级，只执行一次）
 * - handle(): 处理单个请求
 * - reset(): 请求结束后重置状态
 * - terminate(): 关闭运行时
 */
interface RuntimeInterface
{
    /**
     * 运行时模式常量
     */
    public const MODE_FPM = 'fpm';
    public const MODE_WLS = 'wls';
    public const MODE_CLI = 'cli';
    
    /**
     * 启动运行时
     * 
     * 在 FPM 模式下，每个请求都会调用
     * 在 WLS 模式下，Worker 启动时只调用一次
     * 
     * @return void
     */
    public function bootstrap(): void;
    
    /**
     * 处理请求
     * 
     * @param Request|null $request 请求对象（WLS 模式传入，FPM 模式自动从超全局变量获取）
     * @return string 响应内容
     */
    public function handle(?Request $request = null): string;
    
    /**
     * 请求结束后重置状态
     * 
     * 在 WLS 模式下，每个请求结束后调用，清理请求级状态
     * 在 FPM 模式下可选调用
     * 
     * @return void
     */
    public function reset(): void;
    
    /**
     * 关闭运行时
     * 
     * 在 FPM 模式下，请求结束时自动调用
     * 在 WLS 模式下，Worker 停止时调用
     * 
     * @return void
     */
    public function terminate(): void;
    
    /**
     * 获取当前运行模式
     * 
     * @return string MODE_FPM | MODE_WLS | MODE_CLI
     */
    public function getMode(): string;
    
    /**
     * 判断是否为常驻内存模式
     * 
     * @return bool
     */
    public function isPersistent(): bool;
    
    /**
     * 判断是否已初始化
     * 
     * @return bool
     */
    public function isBootstrapped(): bool;
}
