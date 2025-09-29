<?php
/**
 * 数据库迁移接口
 * 
 * @author WelineFramework
 * @package Weline\Database\Interface
 */

namespace Weline\Database\Interface;

interface MigrationInterface
{
    /**
     * 执行迁移安装
     * 
     * @return bool 安装是否成功
     */
    public function install(): bool;
    
    /**
     * 执行迁移卸载/回滚
     * 
     * @return bool 卸载是否成功
     */
    public function uninstall(): bool;
    
    /**
     * 获取迁移信息
     * 
     * @return array 迁移信息
     */
    public function getInfo(): array;
    
    /**
     * 验证迁移前置条件
     * 
     * @return bool 是否满足前置条件
     */
    public function validate(): bool;
    
    /**
     * 获取迁移依赖
     * 
     * @return array 依赖的迁移列表
     */
    public function getDependencies(): array;
    
    /**
     * 获取迁移描述
     * 
     * @return string 迁移描述
     */
    public function getDescription(): string;
    
    /**
     * 获取迁移版本
     * 
     * @return string 迁移版本
     */
    public function getVersion(): string;
    
    /**
     * 获取迁移日期
     * 
     * @return string 迁移日期
     */
    public function getDate(): string;
}
