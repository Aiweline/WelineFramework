<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/15
 * 时间：16:00
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database\DbManager;

interface ConfigProviderInterface
{
    /**
     * @DESC         |设置连接名
     *
     * 参数区：
     *
     * @return string
     */
    public function getConnectionName(): string;

    /**
     * @DESC         |获取连接名
     *
     * 参数区：
     *
     * @param string $connection_name
     *
     * @return ConfigProviderInterface
     */
    public function setConnectionName(string $connection_name): ConfigProviderInterface;

    /**
     * @DESC         |设置数据库类型
     *
     * 参数区：
     *
     * @param string $type
     *
     * @return ConfigProviderInterface
     */
    public function setDbType(string $type): ConfigProviderInterface;

    /**
     * @DESC         |数据库类型
     *
     * 参数区：
     *
     * @return string
     */
    public function getDbType(): string|null;

    /**
     * @DESC         |设置数据库主机
     *
     * 参数区：
     *
     * @param string $hostname
     *
     * @return ConfigProviderInterface
     */
    public function setHostName(string $hostname): ConfigProviderInterface;

    /**
     * @DESC         |数据库主机
     *
     * 参数区：
     *
     * @return string
     */
    public function getHostName(): string;

    /**
     * @DESC         |设置数据库名
     *
     * 参数区：
     *
     * @param string $database_name
     *
     * @return ConfigProviderInterface
     */
    public function setDatabase(string $database_name): ConfigProviderInterface;

    /**
     * @DESC         |数据库名
     *
     * 参数区：
     *
     * @return string
     */
    public function getDatabase(): string;

    /**
     * @DESC         |设置用户名
     *
     * 参数区：
     *
     * @param string $username
     *
     * @return ConfigProviderInterface
     */
    public function setUsername(string $username): ConfigProviderInterface;

    /**
     * @DESC         |用户名
     *
     * 参数区：
     *
     * @return string
     */
    public function getUsername(): string;

    /**
     * @DESC         |设置密码
     *
     * 参数区：
     *
     * @param string $password
     *
     * @return ConfigProviderInterface
     */
    public function setPassword(string $password): ConfigProviderInterface;

    /**
     * @DESC         |密码
     *
     * 参数区：
     *
     * @return string
     */
    public function getPassword(): string;

    /**
     * @DESC         |设置主机端口
     *
     * 参数区：
     *
     * @param string $host_port
     *
     * @return ConfigProviderInterface
     */
    public function setHostPort(string $host_port): ConfigProviderInterface;

    /**
     * @DESC         |主机端口
     *
     * 参数区：
     *
     * @return string
     */
    public function getHostPort(): string;

    /**
     * @DESC         | 设置表前缀
     *
     * 参数区：
     *
     * @param string $prefix
     *
     * @return ConfigProviderInterface
     */
    public function setPrefix(string $prefix): ConfigProviderInterface;

    /**
     * @DESC         |表前缀
     *
     * 参数区：
     *
     * @return string
     */
    public function getPrefix(): string;

    /**
     * @DESC         | 设置默认连接字符集
     *
     * 参数区：
     *
     * @param string $charset
     *
     * @return ConfigProviderInterface
     */
    public function setCharset(string $charset = 'utf8mb4'): ConfigProviderInterface;

    public function getCollate(): string;

    public function setCollate(string $collate = 'utf8mb4_general_ci'): ConfigProviderInterface;

    /**
     * @DESC         |连接字符集
     *
     * 参数区：
     *
     * @return string
     */
    public function getCharset(): string;


    /**
     * @DESC         | 设置连接项
     *
     * 参数区：
     *
     * @param array $pdo_options
     *
     * @return ConfigProviderInterface
     */
    public function setOptions(array $pdo_options = []): ConfigProviderInterface;

    /**
     * @DESC         |连接项
     *
     * 参数区：
     *
     * @return array
     */
    public function getOptions(): array;
}
