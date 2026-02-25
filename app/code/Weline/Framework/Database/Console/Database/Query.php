<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Console\Database;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Database\DbManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 数据库查询CLI命令（仅开发模式可用）
 * 
 * 功能：
 * - 执行 SQL 查询并显示结果
 * - 支持 SELECT/INSERT/UPDATE/DELETE 等操作
 * - 结果以表格形式展示
 */
class Query extends CommandAbstract
{
    /**
     * 命令别名
     */
    public const ALIASES = ['db:query', 'db:q'];

    /**
     * @var DbManager
     */
    private DbManager $dbManager;

    public function __construct(
        DbManager $dbManager
    ) {
        $this->dbManager = $dbManager;
    }

    /**
     * 命令提示
     */
    public function tip(): string
    {
        return 'database:query 执行SQL查询（仅开发模式）';
    }

    /**
     * 帮助信息
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'database:query',
            $this->tip(),
            [
                'sql' => 'SQL语句（必填）',
                '-l, --limit' => '限制返回行数（默认100）',
                '-f, --format' => '输出格式：table（默认）, json, csv',
                '-c, --connection' => '指定数据库连接名',
                '-y, --yes, --no-confirm' => '跳过危险操作确认（UPDATE/DELETE/DROP等）',
            ],
            [
                'php bin/w database:query "SELECT * FROM m_w_theme LIMIT 5"' => '查询主题表',
                'php bin/w database:query "SELECT id,name FROM m_w_meta WHERE namespace=\'theme\'" -l 10' => '查询Meta表',
                'php bin/w database:query "SHOW TABLES" -f json' => '以JSON格式显示所有表',
                'php bin/w database:query "DESC m_w_theme"' => '查看表结构',
            ],
            [
                '⚠️  此命令仅在开发模式（DEBUG=true）下可用！',
                '⚠️  执行 UPDATE/DELETE/DROP 等危险操作时会要求确认。',
                '支持的数据库：MySQL、PostgreSQL、SQLite 等。',
            ]
        );
    }

    /**
     * 执行命令
     */
    public function execute(array $args = [], array $data = [])
    {
        // 检查是否为开发模式
        if (!defined('DEBUG') || !DEBUG) {
            $this->printer->error(__('此命令仅在开发模式下可用！'));
            $this->printer->note(__('请在 app/etc/env.php 中设置 debug => true 或在项目根目录创建 .dev 文件'));
            return;
        }

        // 获取 SQL 语句
        $sql = $args['sql'] ?? $args[1] ?? '';
        if (empty($sql)) {
            $this->printer->error(__('请提供SQL语句！'));
            $this->printer->note(__('使用 -h 或 --help 查看帮助信息'));
            return;
        }

        // 获取参数
        $limit = (int)($args['limit'] ?? $args['l'] ?? 100);
        $format = $args['format'] ?? $args['f'] ?? 'table';
        $connectionName = $args['connection'] ?? $args['c'] ?? null;
        $noConfirm = isset($args['no-confirm']) || isset($args['yes']) || isset($args['y']);

        // 检测危险操作
        $dangerousPatterns = [
            '/^\s*(UPDATE|DELETE|DROP|TRUNCATE|ALTER|INSERT)\s+/i'
        ];
        $isDangerous = false;
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                $isDangerous = true;
                break;
            }
        }

        // 危险操作确认
        if ($isDangerous && !$noConfirm) {
            $this->printer->warning(__('⚠️  检测到危险操作！'));
            $this->printer->note(__('SQL: %{1}', [$sql]));
            $this->printer->note(__('输入 "yes" 确认执行，其他输入取消：'));
            
            $confirmation = trim(fgets(STDIN));
            if (strtolower($confirmation) !== 'yes') {
                $this->printer->note(__('操作已取消。'));
                return;
            }
        }

        try {
            // 获取数据库连接（使用 create 确保连接已创建）
            $connection = $this->dbManager->create($connectionName ?? 'default');
            
            $this->printer->note(__('执行SQL: %{1}', [$sql]));
            $startTime = microtime(true);

            // 检查是否是 SELECT 查询
            $isSelect = preg_match('/^\s*SELECT\s+/i', $sql) || 
                        preg_match('/^\s*SHOW\s+/i', $sql) || 
                        preg_match('/^\s*DESC\s+/i', $sql) ||
                        preg_match('/^\s*DESCRIBE\s+/i', $sql) ||
                        preg_match('/^\s*EXPLAIN\s+/i', $sql);

            if ($isSelect) {
                // 使用框架的查询接口
                $query = $connection->query($sql);
                $results = $query->fetchArray();
                
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2);
                
                $rowCount = count($results);

                // 限制结果数量
                if ($rowCount > $limit) {
                    $results = array_slice($results, 0, $limit);
                    $this->printer->warning(__('结果已限制为前 %{1} 行（共 %{2} 行）', [$limit, $rowCount]));
                }

                // 输出结果
                $this->outputResults($results, $format);
                
                $this->printer->success(__('查询完成！返回 %{1} 行，耗时 %{2} ms', [count($results), $duration]));
            } else {
                // 非 SELECT 查询 - 直接使用 PDO
                $connector = $connection->getConnector();
                $pdo = $connector->getLink();
                $stmt = $pdo->exec($sql);
                
                $endTime = microtime(true);
                $duration = round(($endTime - $startTime) * 1000, 2);
                
                $affectedRows = is_int($stmt) ? $stmt : 0;
                $this->printer->success(__('执行成功！影响 %{1} 行，耗时 %{2} ms', [$affectedRows, $duration]));
            }

        } catch (\PDOException $e) {
            $this->printer->error(__('SQL执行错误: %{1}', [$e->getMessage()]));
            $this->printer->note(__('SQL: %{1}', [$sql]));
        } catch (\Exception $e) {
            $this->printer->error(__('执行失败: %{1}', [$e->getMessage()]));
        }
    }

    /**
     * 输出结果
     */
    private function outputResults(array $results, string $format): void
    {
        if (empty($results)) {
            $this->printer->note(__('查询结果为空'));
            return;
        }

        switch ($format) {
            case 'json':
                echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
                break;
                
            case 'csv':
                // 输出 CSV 头
                $headers = array_keys($results[0]);
                echo implode(',', array_map(fn($h) => '"' . str_replace('"', '""', $h) . '"', $headers)) . "\n";
                // 输出数据
                foreach ($results as $row) {
                    echo implode(',', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
                }
                break;
                
            case 'table':
            default:
                $this->printTable($results);
                break;
        }
    }

    /**
     * 以表格形式打印结果
     */
    private function printTable(array $results): void
    {
        if (empty($results)) {
            return;
        }

        $headers = array_keys($results[0]);
        
        // 计算每列的最大宽度
        $colWidths = [];
        foreach ($headers as $header) {
            $colWidths[$header] = mb_strlen($header);
        }
        
        foreach ($results as $row) {
            foreach ($row as $key => $value) {
                $strValue = $this->formatCellValue($value);
                $len = mb_strlen($strValue);
                if ($len > $colWidths[$key]) {
                    // 限制最大宽度为50
                    $colWidths[$key] = min($len, 50);
                }
            }
        }

        // 打印表头分隔线
        $this->printSeparator($colWidths);
        
        // 打印表头
        $headerLine = '|';
        foreach ($headers as $header) {
            $headerLine .= ' ' . $this->padString($header, $colWidths[$header]) . ' |';
        }
        echo $headerLine . "\n";
        
        // 打印表头分隔线
        $this->printSeparator($colWidths);
        
        // 打印数据行
        foreach ($results as $row) {
            $line = '|';
            foreach ($row as $key => $value) {
                $strValue = $this->formatCellValue($value);
                // 截断过长的值
                if (mb_strlen($strValue) > 50) {
                    $strValue = mb_substr($strValue, 0, 47) . '...';
                }
                $line .= ' ' . $this->padString($strValue, $colWidths[$key]) . ' |';
            }
            echo $line . "\n";
        }
        
        // 打印底部分隔线
        $this->printSeparator($colWidths);
    }

    /**
     * 格式化单元格值
     */
    private function formatCellValue($value): string
    {
        if (is_null($value)) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string)$value;
    }

    /**
     * 打印分隔线
     */
    private function printSeparator(array $colWidths): void
    {
        $line = '+';
        foreach ($colWidths as $width) {
            $line .= str_repeat('-', $width + 2) . '+';
        }
        echo $line . "\n";
    }

    /**
     * 字符串填充（支持中文）
     */
    private function padString(string $str, int $length): string
    {
        $strLen = mb_strlen($str);
        if ($strLen >= $length) {
            return $str;
        }
        return $str . str_repeat(' ', $length - $strLen);
    }
}


