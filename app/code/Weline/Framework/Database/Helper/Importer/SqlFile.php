<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Helper\Importer;

use Weline\Framework\App\Exception;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Database\Exception\LinkException;

class SqlFile
{
    private \Weline\Framework\Database\ConnectionFactory $connection;
    private DbManager\ConfigProvider $configProvider;

    /**
     * SqlFile constructor.
     *
     * @param DbManager $dbManager
     *
     * @throws \ReflectionException
     * @throws \Weline\Framework\App\Exception
     * @throws \Weline\Framework\Database\Exception\LinkException
     */
    public function __construct(DbManager $dbManager)
    {
        $this->connection     = $dbManager->create();
        $this->configProvider = $this->connection->getConfigProvider();
    }

    /**
     * @DESC         |导入数据
     *
     * 参数区：
     *
     * @param string $db_filepath
     * @param string $dbfile_table_pre
     *
     * @return array
     */
    public function import_data(string $db_filepath, string $db_file_table_pre = 'w_'): array
    {
        if (!file_exists($db_filepath)) {
            return ['status' => false, 'info' => '数据库文件不存在'];
        }
        $sql = file_get_contents($db_filepath);
        try {
            $this->_sql_execute($sql, $db_file_table_pre);
        } catch (\ReflectionException|LinkException|Exception $e) {
            return ['status' => false, 'file' => $db_filepath, 'info' => '导入数据库失败', 'e' => $e->getMessage()];
        }
        return ['status' => true, 'file' => $db_filepath, 'info' => '导入数据库成功'];
    }

    /**
     * @DESC         |sql执行
     *
     * 参数区：
     *
     * @param        $sql
     * @param string $dbfile_table_pre
     *
     * @return bool
     * @throws LinkException
     * @throws \ReflectionException
     * @throws Exception
     */
    protected function _sql_execute($sql, $db_file_table_pre = 'w_'): bool
    {
        $sqls = $this->_sql_split($sql, $db_file_table_pre);
        if (is_array($sqls)) {
            foreach ($sqls as $sql) {
                if (trim($sql) !== '') {
                    $this->connection->query($sql);
                }
            }
        } else {
            $this->connection->query((string)$sqls);
        }

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
     * @throws LinkException
     */
    protected function _sql_split($sql, $db_file_table_pre): array
    {
        if ($this->connection->getLink()->query('select version()')->fetchColumn() > '4.1' && $db_charset = $this->configProvider->getCharset()) {
            $sql = preg_replace('/TYPE=(InnoDB|MyISAM|MEMORY)( DEFAULT CHARSET=[^; ]+)?/', 'ENGINE=\\1 DEFAULT CHARSET=' . $db_charset, $sql);
        }
        //如果有表前缀就替换现有的前缀
        if ($db_table_prefix = $this->configProvider->getPrefix()) {
            $sql = str_replace($db_file_table_pre, $db_table_prefix, $sql);
        }
        $sql          = str_replace("\r", "\n", $sql);
        $ret          = [];
        $num          = 0;
        $queriesarray = explode(";\n", trim($sql));
        unset($sql);
        foreach ($queriesarray as $query) {
            $ret[$num] = '';
            $queries   = explode("\n", trim($query));
            $queries   = array_filter($queries);
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
