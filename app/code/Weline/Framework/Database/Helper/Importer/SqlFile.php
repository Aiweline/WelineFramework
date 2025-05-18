<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Helper\Importer;

use Weline\Framework\App\Debug;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\Exception\LinkException;

class SqlFile
{
    private \Weline\Framework\Database\ConnectionFactory $connection;
    private DbManager\ConfigProvider $configProvider;

    public function setConnection(\Weline\Framework\Database\ConnectionFactory $connection): self
    {
        $this->connection = $connection;
        $this->configProvider = $this->connection->getConfigProvider();
        return $this;
    }

    /**
     * @DESC         |导入数据
     *
     * 参数区：
     *
     * @param string $db_filepath
     * @return array
     */
    public function import_data(string $db_filepath): array
    {
        if (!file_exists($db_filepath)) {
            return ['status' => false, 'info' => __('数据库文件不存在'), 'module' => $module];
        }
        $sql = file_get_contents($db_filepath);
        try {
            $this->_sql_execute($sql);
        } catch (\ReflectionException|LinkException|Exception $e) {
            return ['status' => false, 'file' => $db_filepath, 'info' => __('导入数据库失败'), 'e' => $e->getMessage(), 'module' => $module];
        }
        return ['status' => true, 'file' => $db_filepath, 'info' => __('导入数据库成功'), 'module' => $module];
    }

    /**
     * @DESC         |sql执行
     *
     * 参数区：
     *
     * @param string $sql
     * @return bool
     * @throws Exception
     * @throws \ReflectionException
     */
    protected function _sql_execute(string $sql): bool
    {
        if (!isset($this->connection)) {
            throw new Exception(__('数据库连接不存在. 请先设置数据库连接，使用setConnection方法设置.'));
        }
        $this->connection->query($sql)->fetch();
        return true;
    }

    /**
     * @DESC         |sql文件语句拆分
     *
     * 参数区：
     *
     * @param $sql
     * @param $db_file_table_pre
     *
     * @return array
     */
    protected function _sql_split($sql, $db_file_table_pre): array
    {
        if ($this->connection->getConnector()->getVersion() > '4.1' && $db_charset = $this->configProvider->getCharset()) {
            $sql = preg_replace('/TYPE=(InnoDB|MyISAM|MEMORY)( DEFAULT CHARSET=[^; ]+)?/', 'ENGINE=\\1 DEFAULT CHARSET=' . $db_charset, $sql);
        }
        //如果有表前缀就替换现有的前缀
        if ($db_table_prefix = $this->configProvider->getPrefix()) {
            $sql = str_replace($db_file_table_pre, $db_table_prefix, $sql);
        }
        $sql = str_replace("\r", "\n", $sql);
        $ret = [];
        $num = 0;
        $queriesarray = explode(";\n", trim($sql));
        unset($sql);
        foreach ($queriesarray as $query) {
            $ret[$num] = '';
            $queries = explode("\n", trim($query));
            $queries = array_filter($queries);
            foreach ($queries as $_query) {
                $str1 = substr($_query, 0, 1);
                if ($str1 !== '#' && $str1 !== '-') {
                    $ret[$num] .= $_query;
                }
            }
            $num++;
        }

        return $ret;
    }

    /**
     * @DESC          # 获取链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/7 13:18
     * 参数区：
     * @return \Weline\Framework\Database\ConnectionFactory
     */
    public function getLink(): \Weline\Framework\Database\ConnectionFactory
    {
        return $this->connection;
    }

    /**
     * @DESC          # 设置链接
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/10/7 13:19
     * 参数区：
     *
     * @param \Weline\Framework\Database\ConnectionFactory $connectionFactory
     *
     * @return $this
     */
    public function setLink(\Weline\Framework\Database\ConnectionFactory $connectionFactory): static
    {
        $this->connection = $connectionFactory;
        return $this;
    }
}
