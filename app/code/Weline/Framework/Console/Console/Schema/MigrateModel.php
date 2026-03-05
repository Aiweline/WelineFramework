<?php

declare(strict_types=1);

/*
 * Model 声明式 Schema 迁移命令
 * 将 install/upgrade/setup 中的 addColumn/addIndex 解析并生成 #[Col]/#[Index]/#[Table]
 */

namespace Weline\Framework\Console\Console\Schema;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * schema:migrate-model - 将 Model 从 install/upgrade/setup 迁移为 #[Col] 声明式
 *
 * 接受文件路径，解析 addColumn/addIndex 调用，输出迁移指南或半自动占位符。
 */
class MigrateModel extends CommandAbstract
{
    private const COLUMN_TYPE_MAP = [
        'integer' => 'int',
        'int' => 'int',
        'varchar' => 'varchar',
        'text' => 'text',
        'longtext' => 'text',
        'mediumtext' => 'text',
        'datetime' => 'datetime',
        'timestamp' => 'datetime',
        'date' => 'date',
        'smallint' => 'int',
        'tinyint' => 'int',
        'bigint' => 'int',
        'decimal' => 'decimal',
        'float' => 'float',
        'numeric' => 'decimal',
        'boolean' => 'int',
        'json' => 'text',
    ];

    public function execute(array $args = [], array $data = []): void
    {
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs);
        $filePath = $positionalArgs[0] ?? null;
        $guideOnly = isset($args['guide']) || isset($args['g']);
        $apply = isset($args['apply']) || isset($args['a']);

        if (!$filePath || !is_string($filePath)) {
            $this->printer->error('请提供 Model 文件路径，例如: php bin/w schema:migrate-model app/code/GuoLaiRen/Blog/Model/Post.php');
            return;
        }

        $basePath = defined('BP') ? BP : (getcwd() ?: '.');
        $fullPath = str_starts_with($filePath, '/') || preg_match('#^[A-Za-z]:#', $filePath)
            ? $filePath
            : $basePath . '/' . ltrim($filePath, '/');

        if (!is_file($fullPath)) {
            $this->printer->error("文件不存在: {$fullPath}");
            return;
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            $this->printer->error("无法读取文件: {$fullPath}");
            return;
        }

        $result = $this->parseModel($content);
        if ($result === null) {
            $this->printer->error('解析失败：未找到有效的 install() 或 upgrade() 中的 addColumn/addIndex 调用');
            $this->printer->note('将输出手动迁移指南');
            $this->outputManualGuide($fullPath, $content);
            return;
        }

        if ($guideOnly) {
            $this->outputManualGuide($fullPath, $content);
            return;
        }

        $this->printer->success('解析成功');
        $this->outputParsedResult($result);

        if ($apply) {
            $outPath = $fullPath . '.migrated.php';
            $patchContent = $this->generateMigrationPatch($result);
            if ($patchContent !== null && file_put_contents($outPath, $patchContent) !== false) {
                $this->printer->success("已生成迁移补丁: {$outPath}");
                $this->printer->note('请对比原文件后手动合并，或使用 diff 工具审查');
            } else {
                $this->printer->warning('无法生成迁移补丁，请根据上方输出手动迁移');
            }
        } else {
            $this->printer->note('使用 --apply 或 -a 生成 .migrated.php 补丁文件供参考');
        }
    }

    private function parseModel(string $content): ?array
    {
        $fieldConstMap = $this->buildFieldConstMap($content);
        $tableComment = $this->extractTableComment($content);
        $columns = $this->extractColumns($content, $fieldConstMap);
        $indexes = $this->extractIndexes($content, $fieldConstMap);
        $className = $this->extractClassName($content);
        $namespace = $this->extractNamespace($content);
        $tableConst = $this->extractTableConst($content);

        if (empty($columns) && empty($indexes)) {
            return null;
        }

        return [
            'namespace' => $namespace,
            'className' => $className,
            'table' => $tableConst,
            'tableComment' => $tableComment,
            'columns' => $columns,
            'indexes' => $indexes,
        ];
    }

    private function extractTableComment(string $content): string
    {
        if (preg_match('#createTable\s*\(\s*[\'"]([^\'"]+)[\'"]#u', $content, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractTableConst(string $content): string
    {
        if (preg_match('#const\s+table\s*=\s*[\'"]([^\'"]+)[\'"]#u', $content, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractNamespace(string $content): string
    {
        if (preg_match('#namespace\s+([\w\\\\]+)\s*;#u', $content, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function extractClassName(string $content): string
    {
        if (preg_match('#class\s+(\w+)\s+extends#u', $content, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractColumns(string $content, array $fieldConstMap = []): array
    {
        if (empty($fieldConstMap)) {
            $fieldConstMap = $this->buildFieldConstMap($content);
        }
        $columns = [];
        $pattern = '#addColumn\s*\(\s*'
            . '(self::schema_fields_\w+|[\'"]([^\'"]+)[\'"])\s*,\s*'  // field: self::schema_fields_X or "x"
            . '(?:TableInterface::column_type_(\w+)|[\'"]([^\'"]+)[\'"])\s*,\s*'  // type
            . '(\d+|[\'"][^\'"]*[\'"])\s*,\s*'  // length
            . '[\'"]([^\'"]*)[\'"]\s*,\s*'  // options
            . '[\'"]([^\'"]*)[\'"]\s*\)#us';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $fieldRaw = $m[1];
                $field = str_starts_with($fieldRaw, 'self::') ? ($fieldConstMap[$fieldRaw] ?? $this->constToFieldName($fieldRaw)) : ($m[2] ?? $fieldRaw);
                $typeConst = $m[3] ?? $m[4] ?? 'VARCHAR';
                $type = strtolower($typeConst);
                $length = is_numeric($m[5] ?? 0) ? (int)($m[5]) : 0;
                $options = $m[6] ?? '';
                $comment = $m[7] ?? '';
                $optionsLower = strtolower($options);
                $primaryKey = str_contains($optionsLower, 'primary');
                $autoIncrement = str_contains($optionsLower, 'auto_increment');
                $nullable = !str_contains($optionsLower, 'not null');
                $unique = str_contains($optionsLower, 'unique');
                $default = $this->parseDefault($options);
                $colType = self::COLUMN_TYPE_MAP[$type] ?? $type;
                if ($colType === 'int' && $length > 0 && $length <= 4) {
                    $colType = 'int';
                    $length = $length ?: null;
                }
                $columns[] = [
                    'field' => $field,
                    'type' => $colType,
                    'length' => $length > 0 ? $length : null,
                    'nullable' => $nullable,
                    'primaryKey' => $primaryKey,
                    'autoIncrement' => $autoIncrement,
                    'default' => $default,
                    'comment' => $comment,
                    'unique' => $unique,
                ];
            }
        }
        return $columns;
    }

    private function buildFieldConstMap(string $content): array
    {
        $map = [];
        if (preg_match_all('#const\s+(schema_fields_\w+)\s*=\s*[\'"]([^\'"]+)[\'"]#', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $map['self::' . $m[1]] = $m[2];
            }
        }
        return $map;
    }

    private function constToFieldName(string $constRef): string
    {
        if (preg_match('#self::schema_fields_(\w+)#', $constRef, $m)) {
            return strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst(str_replace('_', '', ucwords($m[1], '_')))));
        }
        return 'unknown';
    }

    private function parseDefault(string $options): mixed
    {
        if (preg_match('#default\s+(\d+)#i', $options, $m)) {
            return (int)$m[1];
        }
        if (preg_match('#default\s+[\'"]([^\'"]*)[\'"]#i', $options, $m)) {
            return $m[1];
        }
        if (preg_match('#default\s+current_timestamp#i', $options)) {
            return null;
        }
        return null;
    }

    private function extractIndexes(string $content): array
    {
        $indexes = [];
        $pattern = '#addIndex\s*\(\s*'
            . '(?:TableInterface::index_type_(\w+)|[\'"]([^\'"]+)[\'"])\s*,\s*'
            . '[\'"]([^\'"]+)[\'"]\s*,\s*'
            . '(\[[^\]]+\])\s*'  // columns array
            . '(?:,\s*[\'"]([^\'"]*)[\'"])?\s*\)#us';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $typeConst = $m[1] ?? $m[2] ?? 'KEY';
                $name = $m[3];
                $columnsStr = $m[4];
                $comment = $m[5] ?? '';
                $cols = [];
                if (preg_match_all('#(?:self::\w+|[\'"]([^\'"]+)[\'"])#', $columnsStr, $colMatches)) {
                    foreach ($colMatches[0] as $c) {
                        if (preg_match('#[\'"]([^\'"]+)[\'"]#', $c, $cm)) {
                            $cols[] = $cm[1];
                        } elseif (str_starts_with($c, 'self::')) {
                            $cols[] = trim($c, '[]');
                        }
                    }
                }
                if (empty($cols)) {
                    continue;
                }
                $indexType = strtoupper($typeConst);
                if ($indexType === 'UNIQUE') {
                    $indexType = 'UNIQUE';
                } elseif ($indexType === 'KEY' || $indexType === 'DEFAULT') {
                    $indexType = 'DEFAULT';
                }
                $indexes[] = [
                    'name' => $name,
                    'columns' => $cols,
                    'type' => $indexType,
                    'comment' => $comment,
                ];
            }
        }
        return $indexes;
    }

    private function outputParsedResult(array $result): void
    {
        $this->printer->printing('');
        $this->printer->printing('=== 1. 添加 use 语句 ===');
        $this->printer->printing('use Weline\\Framework\\Database\\Schema\\Attribute\\Col;');
        $this->printer->printing('use Weline\\Framework\\Database\\Schema\\Attribute\\Index;');
        $this->printer->printing('use Weline\\Framework\\Database\\Schema\\Attribute\\Table;');
        $this->printer->printing('');

        $this->printer->printing('=== 2. 在类上添加注解 ===');
        $comment = $result['tableComment'] ?: $result['table'] ?? '';
        $this->printer->printing("#[Table(comment: '" . addslashes($comment) . "')]");
        foreach ($result['indexes'] as $idx) {
            $cols = implode("', '", array_map('addslashes', $idx['columns']));
            $t = $idx['type'] !== 'DEFAULT' ? ", type: '" . $idx['type'] . "'" : '';
            $c = $idx['comment'] ? ", comment: '" . addslashes($idx['comment']) . "'" : '';
            $this->printer->printing("#[Index(name: '{$idx['name']}', columns: ['{$cols}']{$t}{$c})]");
        }
        $this->printer->printing('');

        $this->printer->printing('=== 3. 属性与 #[Col] ===');
        foreach ($result['columns'] as $col) {
            $field = $col['field'];
            $prop = str_replace(' ', '_', $field);
            $attrs = ['type: \'' . $col['type'] . '\''];
            if ($col['length'] !== null) {
                $attrs[] = 'length: ' . $col['length'];
            }
            $attrs[] = 'nullable: ' . ($col['nullable'] ? 'true' : 'false');
            if ($col['primaryKey']) {
                $attrs[] = 'primaryKey: true';
            }
            if ($col['autoIncrement']) {
                $attrs[] = 'autoIncrement: true';
            }
            if ($col['default'] !== null) {
                $attrs[] = 'default: ' . (is_int($col['default']) ? $col['default'] : "'" . addslashes((string)$col['default']) . "'");
            }
            if ($col['comment']) {
                $attrs[] = "comment: '" . addslashes($col['comment']) . "'";
            }
            if ($col['unique']) {
                $attrs[] = 'unique: true';
            }
            $this->printer->printing('#[Col(' . implode(', ', $attrs) . ')]');
            $this->printer->printing("protected mixed \${$prop} = null;");
            $this->printer->printing('');
        }

        $this->printer->printing('=== 4. 替换 install/upgrade/setup 为空实现 ===');
        $this->printer->printing("/** 表结构由 SchemaDiffStage 负责 */");
        $this->printer->printing("public function setup(ModelSetup \$setup, Context \$context): void {}");
        $this->printer->printing("public function upgrade(ModelSetup \$setup, Context \$context): void {}");
        $this->printer->printing("public function install(ModelSetup \$setup, Context \$context): void {}");
    }

    /** 生成可复制的迁移补丁内容（供人工合并参考） */
    private function generateMigrationPatch(array $result): ?string
    {
        $out = "<?php\n\n// Model 声明式迁移补丁 - 请参考下方代码块手动合并到原文件\n";
        $out .= "// 生成时间: " . date('Y-m-d H:i:s') . "\n\n";
        $out .= "// === 1. 在 use 区域添加 ===\n";
        $out .= "use Weline\\Framework\\Database\\Schema\\Attribute\\Col;\n";
        $out .= "use Weline\\Framework\\Database\\Schema\\Attribute\\Index;\n";
        $out .= "use Weline\\Framework\\Database\\Schema\\Attribute\\Table;\n\n";
        $tableComment = $result['tableComment'] ?: $result['table'] ?? '';
        $out .= "// === 2. 在 class 声明前添加 ===\n";
        $out .= "#[Table(comment: '" . addslashes($tableComment) . "')]\n";
        foreach ($result['indexes'] as $idx) {
            $cols = implode("', '", array_map('addslashes', (array)$idx['columns']));
            $t = ($idx['type'] ?? 'DEFAULT') !== 'DEFAULT' ? ", type: '" . ($idx['type'] ?? 'DEFAULT') . "'" : '';
            $c = !empty($idx['comment']) ? ", comment: '" . addslashes($idx['comment']) . "'" : '';
            $out .= "#[Index(name: '{$idx['name']}', columns: ['{$cols}']{$t}{$c})]\n";
        }
        $out .= "\n// === 3. 在 const schema_table 后添加属性 ===\n";
        foreach ($result['columns'] as $col) {
            $prop = str_replace(' ', '_', $col['field']);
            $attrs = ['type: \'' . $col['type'] . '\''];
            if ($col['length'] !== null) {
                $attrs[] = 'length: ' . $col['length'];
            }
            $attrs[] = 'nullable: ' . ($col['nullable'] ? 'true' : 'false');
            if ($col['primaryKey']) {
                $attrs[] = 'primaryKey: true';
            }
            if ($col['autoIncrement']) {
                $attrs[] = 'autoIncrement: true';
            }
            if ($col['default'] !== null) {
                $attrs[] = 'default: ' . (is_int($col['default']) ? (string)$col['default'] : "'" . addslashes((string)$col['default']) . "'");
            }
            if (!empty($col['comment'])) {
                $attrs[] = "comment: '" . addslashes($col['comment']) . "'";
            }
            if (!empty($col['unique'])) {
                $attrs[] = 'unique: true';
            }
            $out .= "#[Col(" . implode(', ', $attrs) . ")]\n";
            $out .= "protected mixed \${$prop} = null;\n\n";
        }
        $out .= "// === 4. 替换 install/upgrade/setup 为空实现 ===\n";
        $out .= "/** 表结构由 SchemaDiffStage 负责 */\n";
        $out .= "public function setup(\\Weline\\Framework\\Setup\\Db\\ModelSetup \$setup, \\Weline\\Framework\\Setup\\Data\\Context \$context): void {}\n\n";
        $out .= "public function upgrade(\\Weline\\Framework\\Setup\\Db\\ModelSetup \$setup, \\Weline\\Framework\\Setup\\Data\\Context \$context): void {}\n\n";
        $out .= "public function install(\\Weline\\Framework\\Setup\\Db\\ModelSetup \$setup, \\Weline\\Framework\\Setup\\Data\\Context \$context): void {}\n";
        return $out;
    }

    private function outputManualGuide(string $path, string $content): void
    {
        $this->printer->printing('');
        $this->printer->printing('=== 手动迁移指南 ===');
        $this->printer->printing('1. 添加 use: Col, Index, Table');
        $this->printer->printing('2. 在类上添加 #[Table(comment: \'...\')] 和 #[Index(...)]');
        $this->printer->printing('3. 为每列添加: protected mixed $column_name = null 及 #[Col(type, length?, nullable, default?, comment)]');
        $this->printer->printing('4. 将 install/upgrade/setup 替换为空实现');
        $this->printer->printing('');
        $this->printer->printing('参考已迁移示例:');
        $this->printer->printing('  - app/code/Agent/WeeklyReport/Model/WeeklyReport.php');
        $this->printer->printing('  - app/code/Aws/Domains/Model/AwsConfig.php');
        $this->printer->printing('  - app/code/Aws/Domains/Model/DomainOperation.php');
        $this->printer->printing('');
        $this->printer->printing('受影响清单: dev/ai/plans/model-declarative-schema-migration-affected-models.txt');
    }

    public function tip(): string
    {
        return '将 Model 从 install/upgrade/setup 迁移为 #[Col] 声明式';
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'schema:migrate-model',
            $this->tip(),
            [
                '-g, --guide' => '仅输出手动迁移指南',
                '-a, --apply' => '将变更写入文件（建议先备份）',
                '-h, --help' => '显示帮助',
            ],
            ['<file>'],
            [
                '解析并输出迁移代码' => 'php bin/w schema:migrate-model app/code/GuoLaiRen/Blog/Model/Post.php',
                '仅输出指南' => 'php bin/w schema:migrate-model app/code/GuoLaiRen/Blog/Model/Post.php --guide',
                '自动写入' => 'php bin/w schema:migrate-model app/code/GuoLaiRen/Blog/Model/Post.php --apply',
            ]
        );
    }
}
