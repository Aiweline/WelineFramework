<?php

declare(strict_types=1);

/*
 * GuoLaiRen PageBuilder Module
 * 样式模型 - 用于管理页面样式模板
 */

namespace GuoLaiRen\PageBuilder\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Style extends Model
{
    public const table = 'guolairen_page_builder_style';
    
    // 字段定义
    public const fields_ID = 'style_id';
    public const fields_CODE = 'code';
    public const fields_NAME = 'name';
    public const fields_DESCRIPTION = 'description';
    public const fields_PATH = 'path';
    public const fields_PREVIEW_IMAGE = 'preview_image';
    public const fields_IS_ACTIVE = 'is_active';
    public const fields_IS_PUBLISHED = 'is_published';
    public const fields_SORT_ORDER = 'sort_order';
    public const fields_CREATE_TIME = 'create_time';
    public const fields_UPDATE_TIME = 'update_time';
    
    /**
     * 自动扫描开关 - 是否在获取样式列表时自动扫描
     */
    private static bool $autoScanEnabled = true;
    
    /**
     * 上次扫描时间 - 用于避免频繁扫描
     */
    private static ?int $lastScanTime = null;
    
    /**
     * 自动扫描样式模板
     * 在查询样式列表时自动调用，保持数据库与文件系统同步
     * 
     * @param int $interval 扫描间隔（秒），默认60秒扫描一次
     * @return void
     */
    public static function autoScan(int $interval = 60): void
    {
        if (!self::$autoScanEnabled) {
            return;
        }
        
        // 检查是否需要扫描（避免频繁扫描）
        if (self::$lastScanTime !== null && (time() - self::$lastScanTime) < $interval) {
            return;
        }
        
        self::$lastScanTime = time();
        
        $baseStylePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($baseStylePath)) {
            return;
        }
        
        $styleDirs = glob($baseStylePath . '*', GLOB_ONLYDIR);
        
        if (empty($styleDirs)) {
            return;
        }
        
        $styleModel = \Weline\Framework\Manager\ObjectManager::getInstance(self::class);
        
        foreach ($styleDirs as $styleDir) {
            $styleName = basename($styleDir);
            
            // 检查必需文件
            $headerFile = $styleDir . '/header.phtml';
            $footerFile = $styleDir . '/footer.phtml';
            $readmeFile = $styleDir . '/readme.md';
            
            if (!file_exists($headerFile) || !file_exists($footerFile) || !file_exists($readmeFile)) {
                continue;
            }
            
            // 检查数据库中是否已存在
            $existing = clone $styleModel;
            $existing->clear()
                ->where(self::fields_CODE, $styleName)
                ->find()
                ->fetch();
            
            // 读取README内容
            $readmeContent = file_get_contents($readmeFile);
            $description = self::extractDescriptionFromReadmeStatic($readmeContent);
            
            // 检测预览图（优先webp，其次png）
            $previewImage = '';
            if (file_exists($styleDir . '/preview.webp')) {
                $previewImage = 'style/' . $styleName . '/preview.webp';
            } elseif (file_exists($styleDir . '/preview.png')) {
                $previewImage = 'style/' . $styleName . '/preview.png';
            }
            
            if ($existing->getId()) {
                // 检查文件是否有更新（比较修改时间）
                $dbUpdateTime = strtotime($existing->getData(self::fields_UPDATE_TIME));
                $fileModTime = max(
                    filemtime($headerFile),
                    filemtime($footerFile),
                    filemtime($readmeFile)
                );
                
                // 如果文件有更新，同步到数据库
                if ($fileModTime > $dbUpdateTime) {
                    $existing->setData(self::fields_NAME, self::formatStyleNameStatic($styleName))
                        ->setData(self::fields_DESCRIPTION, $description)
                        ->setData(self::fields_PATH, 'style/' . $styleName)
                        ->setData(self::fields_PREVIEW_IMAGE, $previewImage)
                        ->save();
                }
            } else {
                // 创建新样式
                $newStyle = clone $styleModel;
                $newStyle->clearData()
                    ->setData(self::fields_CODE, $styleName)
                    ->setData(self::fields_NAME, self::formatStyleNameStatic($styleName))
                    ->setData(self::fields_DESCRIPTION, $description)
                    ->setData(self::fields_PATH, 'style/' . $styleName)
                    ->setData(self::fields_PREVIEW_IMAGE, $previewImage)
                    ->setData(self::fields_IS_ACTIVE, 1)
                    ->setData(self::fields_SORT_ORDER, 10)
                    ->save(true);
            }
        }
        
        // 清理数据库中不存在的样式（文件已删除）
        self::cleanupDeletedStyles($styleDirs, $styleModel);
    }
    
    /**
     * 清理已删除的样式
     */
    private static function cleanupDeletedStyles(array $styleDirs, self $styleModel): void
    {
        // 获取所有文件系统中的样式代码
        $fileStyleCodes = [];
        foreach ($styleDirs as $styleDir) {
            $fileStyleCodes[] = basename($styleDir);
        }
        
        // 获取数据库中所有样式
        $allStyles = clone $styleModel;
        $dbStyles = $allStyles->clear()
            ->select()
            ->fetch()
            ->getItems();
        
        // 检查并删除不存在的样式
        foreach ($dbStyles as $dbStyle) {
            $code = $dbStyle->getData(self::fields_CODE);
            if (!in_array($code, $fileStyleCodes)) {
                // 文件已删除，禁用样式而不是删除（保留历史数据）
                $dbStyle->setData(self::fields_IS_ACTIVE, 0)->save();
            }
        }
    }
    
    /**
     * 启用自动扫描
     */
    public static function enableAutoScan(): void
    {
        self::$autoScanEnabled = true;
    }
    
    /**
     * 禁用自动扫描
     */
    public static function disableAutoScan(): void
    {
        self::$autoScanEnabled = false;
    }
    
    /**
     * 强制立即扫描（忽略时间间隔）
     */
    public static function forceScan(): void
    {
        self::$lastScanTime = null;
        self::autoScan(0);
    }
    
    /**
     * 静态方法：从README中提取描述
     */
    private static function extractDescriptionFromReadmeStatic(string $content): string
    {
        // 移除markdown标题
        $content = preg_replace('/^#.*$/m', '', $content);
        
        // 获取第一段非空内容
        $lines = explode("\n", trim($content));
        $description = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $description = $line;
                break;
            }
        }
        
        return $description ?: '无描述';
    }
    
    /**
     * 静态方法：格式化样式名称
     */
    private static function formatStyleNameStatic(string $code): string
    {
        // 将下划线或连字符转换为空格，并首字母大写
        $name = str_replace(['_', '-'], ' ', $code);
        return ucwords($name);
    }
    
    /**
     * 获取样式的header模板路径
     */
    public function getHeaderPath(): string
    {
        $path = $this->getData(self::fields_PATH);
        return $path . '/header.phtml';
    }
    
    /**
     * 获取样式的footer模板路径
     */
    public function getFooterPath(): string
    {
        $path = $this->getData(self::fields_PATH);
        return $path . '/footer.phtml';
    }
    
    /**
     * 获取content.phtml路径
     */
    public function getContentPath(): string
    {
        $path = $this->getData(self::fields_PATH);
        return $path . '/content.phtml';
    }
    
    /**
     * 获取样式的content配置文件路径
     */
    public function getContentConfigPath(): string
    {
        $path = $this->getData(self::fields_PATH);
        return $path . '/content.phtml';
    }
    
    /**
     * 获取样式的README路径
     */
    public function getReadmePath(): string
    {
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $path = $this->getData(self::fields_PATH);
        return $basePath . $path . '/readme.md';
    }
    
    /**
     * 读取README内容
     */
    public function getReadmeContent(): string
    {
        $readmePath = $this->getReadmePath();
        if (file_exists($readmePath)) {
            return file_get_contents($readmePath);
        }
        return '';
    }
    
    /**
     * 检查样式文件是否完整
     */
    public function validateStyleFiles(): array
    {
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $path = $this->getData(self::fields_PATH);
        $errors = [];
        
        // 检查header.phtml
        if (!file_exists($basePath . $path . '/header.phtml')) {
            $errors[] = 'header.phtml文件不存在';
        }
        
        // 检查footer.phtml
        if (!file_exists($basePath . $path . '/footer.phtml')) {
            $errors[] = 'footer.phtml文件不存在';
        }
        
        // 检查readme.md
        if (!file_exists($basePath . $path . '/readme.md')) {
            $errors[] = 'readme.md文件不存在';
        }
        
        // 检查content.phtml（配置文件，可选）
        if (!file_exists($basePath . $path . '/content.phtml')) {
            $errors[] = 'content.phtml配置文件不存在（可选）';
        }
        
        return $errors;
    }
    
    /**
     * 扫描并解析样式配置（支持group分组语法）
     * 从 header.phtml, footer.phtml, content.phtml 三个文件中提取配置定义
     * 
     * 支持两种格式：
     * 1. 直接定义: key => label:type:default|options
     * 2. 分组定义: group:group_key => 分组名称
     *              group_key.field_key => label:type:default|options
     * 
     * @return array 配置信息数组，包含分组信息
     * [
     *   'groups' => [
     *     'position' => ['key' => 'position', 'label' => '位置', 'file' => 'header'],
     *     ...
     *   ],
     *   'configs' => [
     *     'position.logo_position' => [
     *       'key' => 'position.logo_position',
     *       'group' => 'position',
     *       'label' => 'Logo位置',
     *       'type' => 'select',
     *       'default' => 'left',
     *       'options' => ['left', 'center', 'right'],
     *       'unit' => '',
     *       'file' => 'header'
     *     ],
     *     ...
     *   ]
     * ]
     */
    public function parseStyleConfig(): array
    {
        $basePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/';
        $path = $this->getData(self::fields_PATH);
        $groups = [];
        $configs = [];
        
        // 定义需要扫描的文件
        $files = [
            'header' => $basePath . $path . '/header.phtml',
            'footer' => $basePath . $path . '/footer.phtml',
            'content' => $basePath . $path . '/content.phtml',
        ];
        
        // 遍历每个文件
        foreach ($files as $fileKey => $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }
            
            $content = file_get_contents($filePath);
            
            // 查找配置块 @fields_start ... @fields_end
            preg_match('/@fields_start(.*?)@fields_end/s', $content, $matches);
            
            if (!isset($matches[1])) {
                continue;
            }
            
            $configBlock = $matches[1];
            $lines = explode("\n", $configBlock);
            
            $currentGroup = null; // 当前分组
            
            foreach ($lines as $line) {
                $line = trim($line);
                // 移除注释标记
                $line = preg_replace('/^\*\s*/', '', $line);
                $line = trim($line);
                
                if (empty($line)) {
                    continue;
                }
                
                // 检查是否是分组定义行
                // 格式: group:group_key => 分组名称[标记]:说明标题:说明内容
                if (preg_match('/^group:([a-zA-Z0-9_-]+)\s*=>\s*(.+)$/', $line, $groupMatch)) {
                    $groupKey = trim($groupMatch[1]);
                    $groupValue = trim($groupMatch[2]);
                    
                    // 分解分组值：分组名称[标记]:说明标题:说明内容
                    $groupParts = explode(':', $groupValue, 3);
                    
                    // 第一部分：分组名称（可能包含方括号标记）
                    $groupLabelWithTag = trim($groupParts[0]);
                    $groupLabel = $groupLabelWithTag;
                    $groupTag = '';
                    
                    // 提取方括号中的标记
                    if (preg_match('/^(.+?)\[(.+?)\]$/', $groupLabelWithTag, $tagMatch)) {
                        $groupLabel = trim($tagMatch[1]);
                        $groupTag = trim($tagMatch[2]);
                    }
                    
                    // 第二部分：说明标题（可选）
                    $helpTitle = isset($groupParts[1]) ? trim($groupParts[1]) : '';
                    
                    // 第三部分：说明内容（可选）
                    $helpContent = isset($groupParts[2]) ? trim($groupParts[2]) : '';
                    
                    // 记录分组
                    $groups[$groupKey] = [
                        'key' => $groupKey,
                        'label' => $groupLabel,
                        'tag' => $groupTag,
                        'help_title' => $helpTitle,
                        'help_content' => $helpContent,
                        'file' => $fileKey,
                    ];
                    
                    // 设置当前分组
                    $currentGroup = $groupKey;
                    continue;
                }
                
                // 解析配置项
                // 格式: key => label:type:default|options
                if (preg_match('/^([a-zA-Z0-9._-]+)\s*=>\s*(.+)$/', $line, $itemMatch)) {
                    $key = trim($itemMatch[1]);
                    $configStr = trim($itemMatch[2]);
                    
                    // 解析配置字符串: label:type:default|options
                    // 支持转义冒号 \: 和转义逗号 \, 保持原样不分割
                    $colonPlaceholder = '__ESCAPED_COLON__';
                    $commaPlaceholder = '__ESCAPED_COMMA__';
                    
                    $configStrEscaped = str_replace(['\\:', '\\,'], [$colonPlaceholder, $commaPlaceholder], $configStr);
                    $parts = explode(':', $configStrEscaped);
                    
                    if (count($parts) < 2) {
                        continue;
                    }
                    
                    // 还原转义的冒号和逗号
                    $label = trim(str_replace([$colonPlaceholder, $commaPlaceholder], [':', ','], $parts[0]));
                    $type = trim(str_replace([$colonPlaceholder, $commaPlaceholder], [':', ','], $parts[1]));
                    $defaultValue = isset($parts[2]) ? trim(str_replace([$colonPlaceholder, $commaPlaceholder], [':', ','], $parts[2])) : '';
                    
                    // 解析 default 和 options
                    $default = '';
                    $options = [];
                    $unit = '';
                    $description = '';
                    $responsive = false;
                    
                    if (!empty($defaultValue)) {
                        // 保护转义的斜杠和逗号（如URL中的 \/ 和文本中的 \, ）避免被误判
                        $slashPlaceholder = '__ESCAPED_SLASH__';
                        $commaPlaceholder2 = '__ESCAPED_COMMA2__';
                        $defaultValue = str_replace(['\\/', '\\,'], [$slashPlaceholder, $commaPlaceholder2], $defaultValue);
                        
                        // 提取描述信息（在方括号中，如 [MD] [MTD]）
                        if (preg_match('/^(.+?)\[(.+?)\]$/', $defaultValue, $matches)) {
                            $defaultValue = trim($matches[1]);
                            $descriptionPart = trim($matches[2]);
                            
                            // 检查是否包含响应式标记（MTD字母组合）
                            // M = Mobile, T = Tablet, D = Desktop
                            if (preg_match('/^[MTD]+$/', $descriptionPart)) {
                                $responsive = true;
                                $devices = [];
                                if (strpos($descriptionPart, 'M') !== false) $devices[] = '移动端';
                                if (strpos($descriptionPart, 'T') !== false) $devices[] = '平板';
                                if (strpos($descriptionPart, 'D') !== false) $devices[] = 'PC端';
                                $description = '支持响应式配置(' . implode('、', $devices) . ')';
                            } else {
                                $description = $descriptionPart;
                            }
                        }
                        
                        // 检查是否有单位或选项列表（如 |px, |option1,option2）
                        if (strpos($defaultValue, '|') !== false) {
                            $valueParts = explode('|', $defaultValue, 2);
                            
                            // 第一部分是默认值
                            $default = trim($valueParts[0]);
                            
                            // 第二部分可能是单位或选项列表
                            $secondPart = isset($valueParts[1]) ? $valueParts[1] : '';
                            
                            // 检查第二部分是否包含逗号（选项列表）
                            if (!empty($secondPart) && strpos($secondPart, ',') !== false) {
                                // 这是选项列表
                                $optionParts = explode(',', $secondPart);
                                foreach ($optionParts as $opt) {
                                    // 还原选项中的转义逗号和斜杠
                                    $opt = str_replace([$slashPlaceholder, $commaPlaceholder2], ['/', ','], trim($opt));
                                    $options[] = $opt;
                                }
                                // 单位为空
                                $unit = '';
                            } else {
                                // 这是单位（如 px, %, em）
                                $unit = str_replace([$slashPlaceholder, $commaPlaceholder2], ['/', ','], $secondPart);
                            }
                        } else {
                            $default = $defaultValue;
                        }
                        
                        // 检查默认值中是否包含斜杠（响应式格式）
                        // 注意：此时转义的斜杠已被替换为占位符，不会误判URL
                        if (!$responsive && !empty($default) && strpos($default, '/') !== false) {
                            $responsive = true;
                            if (empty($description)) {
                                $description = '支持响应式配置';
                            }
                        }
                        
                        // 还原转义的斜杠和逗号（在检测响应式之后）
                        $default = str_replace([$slashPlaceholder, $commaPlaceholder2], ['/', ','], $default);
                    }
                    
                    // 确定字段所属的分组
                    $fieldGroup = null;
                    if ($currentGroup && strpos($key, $currentGroup . '.') === 0) {
                        // 如果 key 以当前分组开头，则属于当前分组
                        $fieldGroup = $currentGroup;
                    } elseif (strpos($key, '.') !== false) {
                        // 如果 key 包含点号，提取前缀作为分组
                        $parts = explode('.', $key);
                        $fieldGroup = $parts[0];
                    } else {
                        // 否则使用文件名作为分组
                        $fieldGroup = $fileKey;
                        $key = $fileKey . '.' . $key;
                    }
                    
                    $configs[$key] = [
                        'key' => $key,
                        'group' => $fieldGroup,
                        'label' => $label,
                        'type' => $type,
                        'default' => $default,
                        'options' => $options,
                        'unit' => $unit,
                        'description' => $description,
                        'responsive' => $responsive,
                        'file' => $fileKey,
                    ];
                }
            }
        }
        
        return [
            'groups' => $groups,
            'configs' => $configs,
        ];
    }
    
    /**
     * 获取配置分组（两级结构：文件 -> 分组）
     * 
     * 返回格式：
     * [
     *   'header' => [
     *     'key' => 'header',
     *     'label' => '头部配置',
     *     'icon' => 'mdi-page-layout-header',
     *     'groups' => [
     *       'layout' => [
     *         'key' => 'layout',
     *         'label' => '布局',
     *         'icon' => 'mdi-view-dashboard',
     *         'configs' => [
     *           'layout.logo_position' => [...]
     *         ]
     *       ],
     *       'style' => [...],
     *       'size' => [...],
     *     ]
     *   ],
     *   'content' => [...],
     *   'footer' => [...],
     * ]
     * 
     * @return array
     */
    public function getConfigGroups(): array
    {
        $parsed = $this->parseStyleConfig();
        $definedGroups = $parsed['groups'] ?? [];
        $allConfigs = $parsed['configs'] ?? [];
        
        // 按文件组织配置（两级结构）
        $fileGroups = [
            'header' => [
                'key' => 'header',
                'label' => __('头部配置'),
                'icon' => 'mdi-page-layout-header',
                'groups' => [],
            ],
            'content' => [
                'key' => 'content',
                'label' => __('内容配置'),
                'icon' => 'mdi-file-document-edit',
                'groups' => [],
            ],
            'footer' => [
                'key' => 'footer',
                'label' => __('页脚配置'),
                'icon' => 'mdi-page-layout-footer',
                'groups' => [],
            ],
        ];
        
        // 为每个文件创建分组
        foreach ($definedGroups as $groupKey => $groupInfo) {
            $fileKey = $groupInfo['file'];
            
            if (!isset($fileGroups[$fileKey])) {
                continue;
            }
            
            $fileGroups[$fileKey]['groups'][$groupKey] = [
                'key' => $groupKey,
                'label' => $groupInfo['label'],
                'tag' => $groupInfo['tag'] ?? '',
                'help_title' => $groupInfo['help_title'] ?? '',
                'help_content' => $groupInfo['help_content'] ?? '',
                'icon' => $this->getGroupIcon($groupKey),
                'configs' => [],
            ];
        }
        
        // 将配置分配到对应的文件和分组
        foreach ($allConfigs as $configKey => $config) {
            $fileKey = $config['file'];
            $groupKey = $config['group'];
            
            if (!isset($fileGroups[$fileKey])) {
                continue;
            }
            
            // 如果分组不存在，自动创建
            if (!isset($fileGroups[$fileKey]['groups'][$groupKey])) {
                $fileGroups[$fileKey]['groups'][$groupKey] = [
                    'key' => $groupKey,
                    'label' => $this->getGroupLabel($groupKey),
                    'tag' => '',
                    'help_title' => '',
                    'help_content' => '',
                    'icon' => $this->getGroupIcon($groupKey),
                    'configs' => [],
                ];
            }
            
            // 添加配置到分组
            $fileGroups[$fileKey]['groups'][$groupKey]['configs'][$configKey] = $config;
        }
        
        // 移除空的文件分组
        foreach ($fileGroups as $fileKey => $fileGroup) {
            if (empty($fileGroup['groups'])) {
                unset($fileGroups[$fileKey]);
            }
        }
        
        return $fileGroups;
    }
    
    /**
     * 获取分组标签
     */
    private function getGroupLabel(string $groupName): string
    {
        $labels = [
            // 文件级别分组
            'header' => __('头部配置'),
            'content' => __('内容配置'),
            'footer' => __('页脚配置'),
            
            // 通用分组
            'position' => __('位置'),
            'color' => __('颜色'),
            'size' => __('尺寸'),
            'style' => __('样式'),
            'layout' => __('布局'),
            'typography' => __('排版'),
            'spacing' => __('间距'),
            'border' => __('边框'),
            'effect' => __('效果'),
            'seo' => __('SEO设置'),
            'tracking' => __('统计代码'),
        ];
        
        return $labels[$groupName] ?? ucfirst($groupName);
    }
    
    /**
     * 获取分组图标
     */
    private function getGroupIcon(string $groupName): string
    {
        $icons = [
            // 文件级别分组
            'header' => 'mdi-page-layout-header',
            'content' => 'mdi-file-document-edit',
            'footer' => 'mdi-page-layout-footer',
            
            // 通用分组
            'position' => 'mdi-crosshairs',
            'color' => 'mdi-palette',
            'size' => 'mdi-resize',
            'style' => 'mdi-format-paint',
            'layout' => 'mdi-view-dashboard',
            'typography' => 'mdi-format-text',
            'spacing' => 'mdi-arrow-expand-all',
            'border' => 'mdi-border-all',
            'effect' => 'mdi-auto-fix',
            'seo' => 'mdi-search-web',
            'tracking' => 'mdi-google-analytics',
        ];
        
        return $icons[$groupName] ?? 'mdi-cog';
    }

    /**
     * 安装表结构
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        // 删除旧表（如果存在）- 仅在重建表结构时临时启用
        // $setup->dropTable();
        
        // 检查表是否已存在
        if ($setup->tableExist()) {
            return;
        }
        
        $setup->createTable('页面构建器-样式表')
            ->addColumn(
                self::fields_ID,
                TableInterface::column_type_INTEGER,
                0,
                'primary key auto_increment',
                '样式ID'
            )
            ->addColumn(
                self::fields_CODE,
                TableInterface::column_type_VARCHAR,
                100,
                'not null unique',
                '样式代码(唯一)'
            )
            ->addColumn(
                self::fields_NAME,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '样式名称'
            )
            ->addColumn(
                self::fields_DESCRIPTION,
                TableInterface::column_type_TEXT,
                0,
                '',
                '样式描述'
            )
            ->addColumn(
                self::fields_PATH,
                TableInterface::column_type_VARCHAR,
                255,
                'not null',
                '样式模板路径(相对于view/templates/)'
            )
            ->addColumn(
                self::fields_PREVIEW_IMAGE,
                TableInterface::column_type_VARCHAR,
                255,
                '',
                '预览图片路径'
            )
            ->addColumn(
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 1',
                '是否启用:0禁用,1启用'
            )
            ->addColumn(
                self::fields_IS_PUBLISHED,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否发布:0未发布,1已发布(只有已发布的模板才能在页面创建时选择)'
            )
            ->addColumn(
                self::fields_SORT_ORDER,
                TableInterface::column_type_INTEGER,
                0,
                'not null default 0',
                '排序'
            )
            ->addColumn(
                self::fields_CREATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP',
                '创建时间'
            )
            ->addColumn(
                self::fields_UPDATE_TIME,
                TableInterface::column_type_DATETIME,
                0,
                'not null default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP',
                '更新时间'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_code', [self::fields_CODE], '样式代码索引')
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_active', [self::fields_IS_ACTIVE], '状态索引')
            ->create();
    }

    /**
     * 升级表结构
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 添加 is_published 字段（如果不存在）
        if ($setup->tableExist() && !$setup->hasField(self::fields_IS_PUBLISHED)) {
            $setup->alterTable()->addColumn(
                self::fields_IS_PUBLISHED,
                self::fields_IS_ACTIVE,
                TableInterface::column_type_SMALLINT,
                1,
                'not null default 0',
                '是否发布:0未发布,1已发布(只有已发布的模板才能在页面创建时选择)'
            )
            ->addIndex(TableInterface::index_type_KEY, 'idx_is_published', [self::fields_IS_PUBLISHED], '发布状态索引')
            ->alter();
        }
        
        // 扫描并注册默认样式模板
        $this->scanAndRegisterStyles();
    }
    
    /**
     * 扫描并注册样式模板
     */
    private function scanAndRegisterStyles(): void
    {
        $baseStylePath = BP . 'app/code/GuoLaiRen/PageBuilder/view/templates/style/';
        
        if (!is_dir($baseStylePath)) {
            return;
        }
        
        $styleDirs = glob($baseStylePath . '*', GLOB_ONLYDIR);
        
        if (empty($styleDirs)) {
            return;
        }
        
        foreach ($styleDirs as $styleDir) {
            $styleName = basename($styleDir);
            
            // 检查必需文件
            if (!file_exists($styleDir . '/header.phtml') || 
                !file_exists($styleDir . '/footer.phtml') || 
                !file_exists($styleDir . '/readme.md')) {
                continue;
            }
            
            // 读取README内容
            $readmeFile = $styleDir . '/readme.md';
            $readmeContent = file_get_contents($readmeFile);
            $description = $this->extractDescriptionFromReadme($readmeContent);
            
            // 检查数据库中是否已存在
            $existing = clone $this;
            $existing->clear()
                ->where(self::fields_CODE, $styleName)
                ->find()
                ->fetch();
            
            if (!$existing->getId()) {
                // 创建新样式
                $newStyle = clone $this;
                $newStyle->clearData()
                    ->setData(self::fields_CODE, $styleName)
                    ->setData(self::fields_NAME, $this->formatStyleName($styleName))
                    ->setData(self::fields_DESCRIPTION, $description)
                    ->setData(self::fields_PATH, 'style/' . $styleName)
                    ->setData(self::fields_IS_ACTIVE, 1)
                    ->setData(self::fields_SORT_ORDER, 10)
                    ->save(true);
            }
        }
    }
    
    /**
     * 从README中提取描述
     */
    private function extractDescriptionFromReadme(string $content): string
    {
        // 移除markdown标题
        $content = preg_replace('/^#.*$/m', '', $content);
        
        // 获取第一段非空内容
        $lines = explode("\n", trim($content));
        $description = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $description = $line;
                break;
            }
        }
        
        return $description ?: '无描述';
    }
    
    /**
     * 格式化样式名称
     */
    private function formatStyleName(string $code): string
    {
        // 将下划线或连字符转换为空格，并首字母大写
        $name = str_replace(['_', '-'], ' ', $code);
        return ucwords($name);
    }

    /**
     * 设置表结构
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }
}

