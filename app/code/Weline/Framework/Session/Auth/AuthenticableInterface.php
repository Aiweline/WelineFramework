<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth;

/**
 * 可认证用户接口
 *
 * 所有需要通过 Session 进行认证的用户模型必须实现此接口。
 * 遵循 DIP（依赖倒置原则）：认证系统依赖抽象接口，而非具体用户模型。
 */
interface AuthenticableInterface
{
    /**
     * 获取用户唯一标识（主键）
     *
     * @return int|string 用户 ID
     */
    public function getAuthIdentifier(): int|string;

    /**
     * 获取用户名（用于显示）
     *
     * @return string 用户名
     */
    public function getAuthUsername(): string;

    /**
     * 获取用于 Session 存储的 Session ID
     * 
     * 如果用户有自定义的 Session ID（如从数据库读取），返回该 ID；
     * 否则返回空字符串，表示使用自动生成的 Session ID。
     *
     * @return string Session ID 或空字符串
     */
    public function getAuthSessionId(): string;

    /**
     * 获取用户模型类名
     *
     * 用于从 Session 恢复用户时，知道应该实例化哪个模型类。
     *
     * @return string 完整类名
     */
    public static function getAuthModelClass(): string;
}
