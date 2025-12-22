<?php

declare(strict_types=1);

/*
 * Weline Cms Module
 * CMS内容管理系统样式模型 - 用于管理页面样式模板
 */

namespace Weline\Cms\Model;

use Weline\Framework\Database\Api\Db\TableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

class Style extends Model
{
    public const table = 'weline_cms_style';
    
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
        
        $baseStylePath = BP . 'app/code/Weline/Cms/view/templates/style/';
        
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
        $basePath = BP . 'app/code/Weline/Cms/view/templates/';
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
        $basePath = BP . 'app/code/Weline/Cms/view/templates/';
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
        $basePath = BP . 'app/code/Weline/Cms/view/templates/';
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
        
        // 自动检测 colors 目录，如果存在则创建独立的色系配置分组
        $colorSchemes = null;
        try {
            $colorSchemes = $this->scanColorSchemes();
        } catch (\Exception $e) {
            // 扫描失败不影响整体流程
        }
        
        // 如果检测到色系配置，创建独立的色系配置分组（放在最顶部）
        if (!empty($colorSchemes)) {
            $fileGroups['color_scheme'] = [
                'key' => 'color_scheme',
                'label' => __('色系配置'),
                'icon' => 'mdi-palette',
                'groups' => [
                    'color_scheme' => [
                        'key' => 'color_scheme',
                        'label' => __('色系选择'),
                        'tag' => '',
                        'help_title' => '',
                        'help_content' => __('选择不同的色系可以快速改变整个模板的颜色方案'),
                        'icon' => 'mdi-palette',
                        'configs' => [
                            'color_scheme' => [
                                'key' => 'color_scheme',
                                'label' => __('色系选择'),
                                'type' => 'color_scheme',
                                'default' => 'default',
                                'color_schemes' => $colorSchemes,
                                'options' => [],
                                'file' => 'color_scheme',
                                'group' => 'color_scheme',
                            ],
                        ],
                    ],
                ],
            ];
        }
        
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
        // 跳过 color_scheme 配置（已经自动创建）
        foreach ($allConfigs as $configKey => $config) {
            // 跳过 color_scheme 配置，因为已经自动创建了
            if ($configKey === 'color_scheme' || strpos($configKey, 'color_scheme') !== false) {
                continue;
            }
            
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
        
        // 移除空的文件分组（除了色系配置分组）
        foreach ($fileGroups as $fileKey => $fileGroup) {
            if ($fileKey === 'color_scheme') {
                continue; // 色系配置分组始终保留
            }
            if (empty($fileGroup['groups'])) {
                unset($fileGroups[$fileKey]);
            }
        }
        
        // 重新排序，确保色系配置在最顶部
        $result = [];
        if (isset($fileGroups['color_scheme'])) {
            $result['color_scheme'] = $fileGroups['color_scheme'];
            unset($fileGroups['color_scheme']);
        }
        // 添加其他配置分组
        foreach ($fileGroups as $fileKey => $fileGroup) {
            $result[$fileKey] = $fileGroup;
        }
        
        return $result;
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
     * 扫描模板的 colors 目录，获取所有可用的色系
     * 
     * @return array 色系列表，格式：
     * [
     *   'default' => [
     *     'name' => 'default',
     *     'display_name' => '默认暗色主题',
     *     'description' => '深色背景配以蓝色强调色...',
     *     'preview_image' => 'style/jion-landing/colors/default.jpg' // 或 null
     *   ],
     *   ...
     * ]
     */
    private function scanColorSchemes(): array
    {
        $styleCode = $this->getData(self::fields_CODE);
        if (empty($styleCode)) {
            return [];
        }
        
        $basePath = BP . 'app/code/Weline/Cms/view/templates/';
        $stylePath = (string)$this->getData(self::fields_PATH);
        // 规范化分隔符，避免 Windows 下混合斜杠导致 glob 失效
        $stylePath = str_replace('\\', '/', $stylePath);
        $colorsDir = rtrim($basePath, '/\\') . '/' . trim($stylePath, '/\\') . '/colors';
        // 如果按存储的路径不存在，回退到按 code 推导的标准路径
        if (!is_dir($colorsDir)) {
            $fallback = rtrim($basePath, '/\\') . '/style/' . $styleCode . '/colors';
            $colorsDir = $fallback;
        }
        
        if (!is_dir($colorsDir)) {
            return [];
        }
        
        $schemes = [];
        
        // 扫描所有 .phtml 文件
        // 再次规范化目录，确保 glob 能正确匹配
        $globDir = str_replace('\\', '/', $colorsDir);
        $files = glob($globDir . '/*.phtml');
        
        foreach ($files as $file) {
            // 以文件名作为唯一色系代码，避免被元信息覆盖导致 key 冲突
            $schemeCode = basename($file, '.phtml');
            
            // 读取文件内容，提取色系元信息
            $content = file_get_contents($file);
            $displayName = $schemeCode;
            $description = '';
            
            // 解析色系元信息（约定格式）
            // 仅用于展示名称与描述，不改变色系代码
            if (preg_match('/SCHEME_DISPLAY_NAME:\s*(.+)/i', $content, $displayMatch)) {
                $displayName = trim($displayMatch[1]);
            }
            if (preg_match('/SCHEME_DESCRIPTION:\s*(.+)/i', $content, $descMatch)) {
                $description = trim($descMatch[1]);
            }
            
            // 查找预览图（支持 jpg, png, webp）
            $actualSchemeName = $schemeCode;
            $previewImage = null;
            $previewExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            foreach ($previewExtensions as $ext) {
                // 先尝试与文件名同名的预览图
                $previewPath = $colorsDir . '/' . $actualSchemeName . '.' . $ext;
                if (file_exists($previewPath)) {
                    // 返回相对于模板目录的路径
                    $previewImage = 'style/' . $styleCode . '/colors/' . $actualSchemeName . '.' . $ext;
                    break;
                }
                // 兜底：如果未找到，再尝试用展示名（不推荐，但兼容历史约定）
                $fallbackByDisplay = $colorsDir . '/' . $displayName . '.' . $ext;
                if ($displayName !== $actualSchemeName && file_exists($fallbackByDisplay)) {
                    $previewImage = 'style/' . $styleCode . '/colors/' . $displayName . '.' . $ext;
                    break;
                }
            }
            
            // 如果没有预览图，生成渐变色背景
            $gradientColors = null;
            if (!$previewImage) {
                try {
                    $gradientColors = $this->extractGradientColors($file);
                } catch (\Exception $e) {
                    // 如果提取渐变色失败，记录错误但不影响整体流程
                    // 可以在这里添加日志记录
                    $gradientColors = null;
                }
            }
            
            // 用文件名作为唯一 key，确保与 colors 目录下 phtml 文件一一对应
            $schemes[$schemeCode] = [
                'name' => $schemeCode,
                'display_name' => $displayName,
                'description' => $description,
                'preview_image' => $previewImage,
                'gradient_colors' => $gradientColors, // 渐变色数组，用于无预览图时显示
            ];
        }
        
        return $schemes;
    }
    
    /**
     * 从色系文件中提取渐变色
     * 选择主色生成渐变色背景
     * 
     * @param string $colorSchemeFile 色系文件路径
     * @return array|null 渐变色数组，格式：['#color1', '#color2', '#color3']，如果没有有效颜色则返回null
     */
    private function extractGradientColors(string $colorSchemeFile): ?array
    {
        if (!file_exists($colorSchemeFile)) {
            return null;
        }
        
        // 限制文件大小，避免内存问题（色系文件应该很小，限制在100KB以内）
        $fileSize = filesize($colorSchemeFile);
        if ($fileSize > 100 * 1024) {
            return null;
        }
        
        // 读取文件内容并解析颜色数组
        $content = file_get_contents($colorSchemeFile);
        
        // 限制内容长度，避免处理过大的字符串
        if (strlen($content) > 100 * 1024) {
            return null;
        }
        
        // 提取 $colors 数组定义
        // 使用更精确的匹配，找到数组开始和结束位置
        $arrayStart = strpos($content, '$colors');
        if ($arrayStart === false) {
            return null;
        }
        
        // 找到数组开始标记 [
        $bracketStart = strpos($content, '[', $arrayStart);
        if ($bracketStart === false) {
            return null;
        }
        
        // 找到对应的结束标记 ]
        $bracketCount = 0;
        $bracketEnd = $bracketStart;
        $maxLength = 50000; // 限制最大扫描长度
        $scanLength = 0;
        
        for ($i = $bracketStart; $i < strlen($content) && $scanLength < $maxLength; $i++) {
            $char = $content[$i];
            if ($char === '[') {
                $bracketCount++;
            } elseif ($char === ']') {
                $bracketCount--;
                if ($bracketCount === 0) {
                    $bracketEnd = $i;
                    break;
                }
            }
            $scanLength++;
        }
        
        if ($bracketCount !== 0) {
            // 没有找到匹配的结束括号
            return null;
        }
        
        // 提取数组内容（不包含括号）
        $colorsArrayContent = substr($content, $bracketStart + 1, $bracketEnd - $bracketStart - 1);
        
        // 限制数组内容长度
        if (strlen($colorsArrayContent) > 50000) {
            return null;
        }
        
        // 提取颜色值（支持多种格式：'#RRGGBB', 'rgb(...)', 'rgba(...)', 'transparent'等）
        $colors = [];
        
        // 优先选择的颜色键（按优先级排序）
        $priorityKeys = [
            'primary_bg',
            'accent_blue',
            'section_bg_gradient_start',
            'section_bg_gradient_end',
            'accent_blue_light',
            'button_primary_bg',
            'card_bg',
            'body_bg',
        ];
        
        // 提取所有颜色值
        foreach ($priorityKeys as $key) {
            // 匹配 'key' => 'value' 或 'key' => "value"，支持跨行
            // 匹配模式：'key' => 'value' 或 "key" => "value" 或 'key' => "value"
            $pattern = '/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/';
            if (preg_match($pattern, $colorsArrayContent, $keyMatch)) {
                $colorValue = trim($keyMatch[1]);
                // 跳过透明和无效值
                if ($colorValue !== 'transparent' && !empty($colorValue)) {
                    $hexColor = $this->colorToHex($colorValue);
                    if ($hexColor && !in_array($hexColor, $colors)) {
                        $colors[] = $hexColor;
                        // 如果已经有足够的颜色（3-4个），可以提前结束
                        if (count($colors) >= 4) {
                            break;
                        }
                    }
                }
            }
        }
        
        // 如果提取到的颜色少于2个，尝试从其他颜色键提取
        if (count($colors) < 2) {
            // 匹配所有颜色值，限制匹配次数和长度
            // 限制rgb/rgba括号内的内容长度（最多100字符）
            $pattern = '/[\'"]\w+[\'"]\s*=>\s*[\'"](#[0-9A-Fa-f]{6}|#[0-9A-Fa-f]{3}|rgb\([^)]{0,100}\)|rgba\([^)]{0,100}\))[\'"]/';
            $matchCount = preg_match_all($pattern, $colorsArrayContent, $allMatches, PREG_SET_ORDER);
            
            // 限制最多处理20个匹配结果
            $maxMatches = min($matchCount, 20);
            for ($i = 0; $i < $maxMatches && count($colors) < 4; $i++) {
                if (isset($allMatches[$i][1])) {
                    $colorValue = trim($allMatches[$i][1]);
                    $hexColor = $this->colorToHex($colorValue);
                    if ($hexColor && !in_array($hexColor, $colors)) {
                        $colors[] = $hexColor;
                    }
                }
            }
        }
        
        // 至少需要2个颜色才能生成渐变
        if (count($colors) < 2) {
            return null;
        }
        
        // 如果颜色少于3个，尝试生成中间色
        if (count($colors) === 2) {
            // 在两个颜色之间插入一个中间色
            $color1 = $colors[0];
            $color2 = $colors[1];
            $middleColor = $this->blendColors($color1, $color2);
            $colors = [$color1, $middleColor, $color2];
        }
        
        // 限制最多4个颜色
        return array_slice($colors, 0, 4);
    }
    
    /**
     * 将各种颜色格式转换为 HEX 格式
     * 
     * @param string $color 颜色值（支持 hex, rgb, rgba）
     * @return string|null HEX格式颜色（如 #RRGGBB），转换失败返回null
     */
    private function colorToHex(string $color): ?string
    {
        $color = trim($color);
        
        // 已经是 HEX 格式
        if (preg_match('/^#([0-9A-Fa-f]{6}|[0-9A-Fa-f]{3})$/', $color)) {
            // 如果是3位hex，转换为6位
            if (strlen($color) === 4) {
                return '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
            }
            return strtoupper($color);
        }
        
        // RGB 格式：rgb(255, 255, 255) 或 rgb(255 255 255) 或 rgb(16 30 56)
        if (preg_match('/rgb\(([^)]+)\)/', $color, $matches)) {
            // 处理空格或逗号分隔的值
            $values = preg_split('/[,\s]+/', trim($matches[1]));
            // 过滤空值
            $values = array_filter($values, function($v) { return trim($v) !== ''; });
            $values = array_values($values); // 重新索引数组
            
            if (count($values) >= 3) {
                $r = intval(trim($values[0]));
                $g = intval(trim($values[1]));
                $b = intval(trim($values[2]));
                // 确保值在有效范围内
                $r = max(0, min(255, $r));
                $g = max(0, min(255, $g));
                $b = max(0, min(255, $b));
                return sprintf('#%02X%02X%02X', $r, $g, $b);
            }
        }
        
        // RGBA 格式：rgba(255, 255, 255, 0.5)
        if (preg_match('/rgba\(([^)]+)\)/', $color, $matches)) {
            $values = preg_split('/[,\s]+/', trim($matches[1]));
            if (count($values) >= 3) {
                $r = intval($values[0]);
                $g = intval($values[1]);
                $b = intval($values[2]);
                return sprintf('#%02X%02X%02X', $r, $g, $b);
            }
        }
        
        return null;
    }
    
    /**
     * 混合两个颜色，生成中间色
     * 
     * @param string $color1 HEX格式颜色1
     * @param string $color2 HEX格式颜色2
     * @return string HEX格式中间色
     */
    private function blendColors(string $color1, string $color2): string
    {
        // 提取RGB值
        $r1 = hexdec(substr($color1, 1, 2));
        $g1 = hexdec(substr($color1, 3, 2));
        $b1 = hexdec(substr($color1, 5, 2));
        
        $r2 = hexdec(substr($color2, 1, 2));
        $g2 = hexdec(substr($color2, 3, 2));
        $b2 = hexdec(substr($color2, 5, 2));
        
        // 计算中间值
        $r = intval(($r1 + $r2) / 2);
        $g = intval(($g1 + $g2) / 2);
        $b = intval(($b1 + $b2) / 2);
        
        return sprintf('#%02X%02X%02X', $r, $g, $b);
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
        
        $setup->createTable('CMS内容管理系统-样式表')
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
        $baseStylePath = BP . 'app/code/Weline/Cms/view/templates/style/';
        
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

