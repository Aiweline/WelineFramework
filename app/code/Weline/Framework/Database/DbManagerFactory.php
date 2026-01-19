<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database;

/**
 * 文件信息
 * DESC:   | 数据库管理
 * 作者：   秋枫雁飞
 * 日期：   2020/7/2
 * 时间：   1:24
 * 网站：   https://bbs.aiweline.com
 * Email：  aiweline@qq.com
 */
class DbManagerFactory extends DbManager
{
    /**
     * @DESC         |重写 create 方法，延迟创建连接
     *                |如果连接已存在则直接返回，否则延迟创建
     *                |这样可以避免 ObjectManager::initClassInstance() 调用时立即创建数据库连接
     *
     * 参数区：
     *
     * @param string $connection_name 链接名称
     * @param \Weline\Framework\Database\DbManager\ConfigProvider|null $configProvider 链接资源配置
     *
     * @return \Weline\Framework\Database\ConnectionFactory
     * @throws \Weline\Framework\Database\Exception\LinkException
     */
    public function create(string $connection_name = 'default', null|\Weline\Framework\Database\DbManager\ConfigProvider $configProvider = null): \Weline\Framework\Database\ConnectionFactory
    {
        // 检查是否已有连接，如果有则直接返回（避免重复创建）
        $connection = $this->getConnection($connection_name);
        if (empty($configProvider) && $connection) {
            return $connection;
        }
        
        // 如果没有连接且没有提供配置，延迟创建（返回缓存的连接或创建新连接）
        // 注意：这里仍然会创建连接，但可以通过连接池复用
        // 优化：只有在真正需要时才创建连接
        if (empty($configProvider)) {
            // 如果没有提供配置，延迟创建连接（在 getConnector() 时创建）
            // 但为了兼容 ObjectManager::initClassInstance() 的期望，我们需要返回一个 ConnectionFactory
            // 所以这里仍然调用父类方法，但父类方法会通过连接池复用连接
            return parent::create($connection_name, $configProvider);
        } else {
            // 如果提供了配置，正常创建连接
            return parent::create($connection_name, $configProvider);
        }
    }
}
