<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Framework\View\Data\DataInterface;
use Weline\Theme\Helper\ThemeData;
use Weline\Taglib\TaglibInterface;

/**
 * 主题模板标签
 * 
 * 用于加载主题配置的模板文件，支持通过 layout 属性从主题配置中获取模板路径
 * 
 * 使用示例：
 * <w:theme:template layout="partials.header">Weline_Theme::theme/frontend/partials/header/default.phtml</w:theme:template>
 */
class ThemeTemplate implements TaglibInterface
{
    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'theme:template';
    }

    /**
     * @inheritDoc
     */
    public static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function attr(): array
    {
        return [
            'enable' => false,  // 是否启用（可选，默认启用）
            'layout' => false   // 布局标识（可选，如 partials.header）
        ];
    }

    /**
     * @inheritDoc
     */
    public static function tag_start(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_end(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            /** @var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            
            // 检查是否启用
            $enable = $attributes['enable'] ?? 1;
            if (!$enable or ($enable === 'false')) {
                $template_string = $tag_data[0] ?? '';
                $target_template = $tag_data[2] ?? '';
                return "<!-- 模块被禁用：{$target_template} 原始模板：{$template_string}-->";
            }
            
            // 如果指定了 layout 属性，从主题配置中获取模板路径
            $layout = $attributes['layout'] ?? '';
            if (!empty($layout)) {
                // 使用 Theme 模块的 Helper 获取模板路径
                try {
                    /** @var \Weline\Theme\Helper\ThemeConfigHelper $themeConfigHelper */
                    $themeConfigHelper = \Weline\Theme\Helper\ThemeConfigHelper::class;
                    $filePath = $themeConfigHelper::getTemplatePath($layout);
                    
                    if ($filePath) {
                        // fetchTagSource() 已支持 Module::path 语法；保留模块前缀，
                        // 否则 dir_type_theme 会再次补 theme/ 前缀，导致 theme/theme/...。
                        $filePath = self::parseMetaTags($filePath);
                        return file_get_contents($template->fetchTagSource(DataInterface::dir_type_THEME, $filePath));
                    }
                } catch (\Exception $e) {
                    // 如果获取配置失败，继续使用默认路径
                }
            }
            
            // 如果配置不存在或 layout 为空，使用标签内容中的默认路径
            $defaultPath = match ($tag_key) {
                'tag' => trim($tag_data[2] ?? ''),
                default => trim($tag_data[1] ?? '')
            };
            
            // 先解析 defaultPath 中的 @meta{} 标签，确保 @meta{} 标签先执行
            if (!empty($defaultPath)) {
                $defaultPath = self::parseMetaTags($defaultPath);
                return file_get_contents($template->fetchTagSource(DataInterface::dir_type_THEME, $defaultPath));
            }
            
            // 如果没有 layout 属性，使用原有逻辑
            $fallbackPath = match ($tag_key) {
                'tag' => trim($tag_data[2] ?? ''),
                default => trim($tag_data[1] ?? '')
            };
            
            // 先解析 fallbackPath 中的 @meta{} 标签
            $fallbackPath = self::parseMetaTags($fallbackPath);
            return file_get_contents($template->fetchTagSource(DataInterface::dir_type_THEME, $fallbackPath));
        };
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     * 
     * 设置依赖关系，确保 meta 标签先执行
     * 这样 @meta{} 标签会在 theme:template 标签之前被解析
     */
    public static function parent(): ?string
    {
        return 'meta';
    }

    /**
     * @inheritDoc
     */
    public static function document(): string
    {
        return '主题模板标签，用于加载主题配置的模板文件。支持通过 layout 属性从主题配置中获取模板路径，如果配置不存在则使用标签内容中的默认路径。';
    }

    /**
     * 解析路径中的 @meta{} 标签
     * 确保 @meta{} 标签先于 theme:template 标签执行
     * 
     * @param string $path 包含 @meta{} 标签的路径
     * @return string 解析后的路径
     */
    protected static function parseMetaTags(string $path): string
    {
        // 匹配 @meta{key|默认值} 格式
        if (!preg_match_all('/@meta\{([^}]+)\}/', $path, $matches, PREG_SET_ORDER)) {
            return $path;
        }

        foreach ($matches as $match) {
            $fullMatch = $match[0]; // @meta{...}
            $content = trim($match[1] ?? ''); // key|默认值 或 key
            
            if (empty($content)) {
                continue;
            }

            // 解析格式：@meta{key|默认值} 或 @meta{key}
            $translationKey = '';
            $defaultValue = '';

            // 检查是否有默认值（用|分隔）
            if (strpos($content, '|') !== false) {
                [$keyPart, $defaultPart] = explode('|', $content, 2);
                $translationKey = trim($keyPart);
                $defaultValue = trim($defaultPart);
            } else {
                $translationKey = trim($content);
            }

            if (empty($translationKey)) {
                // 如果 key 为空，使用默认值或移除标签
                $path = str_replace($fullMatch, $defaultValue, $path);
                continue;
            }

            // 统一使用 ThemeData 类读取（ThemeData 内部已处理 .value、.info 和 .lang 格式）
            // ThemeData::get() 已统一处理：
            // 1. .value 后缀：直接返回配置值（字符串）
            // 2. .info 后缀：直接返回信息值（字符串）
            // 3. .lang 后缀：直接返回翻译值（字符串）
            // 4. 其他格式：返回 MetaData 对象
            $resolvedValue = null;
            try {
                // 直接使用 ThemeData 类读取
                $result = ThemeData::get($translationKey);
                
                // 如果是字符串值，直接使用
                if (is_string($result) || $result === null) {
                    $resolvedValue = $result;
                }
            } catch (\Exception $e) {
                // 如果获取失败，继续尝试从 I18n Dictionary 获取
            }

            // 如果从 ThemeData 获取到了值，使用它
            if ($resolvedValue !== null) {
                $path = str_replace($fullMatch, $resolvedValue, $path);
                continue;
            }

            // 否则，尝试从 I18n Dictionary 获取翻译（原有逻辑）
            try {
                // 如果翻译键不是以@meta::开头，自动添加
                if (!str_starts_with($translationKey, '@meta::')) {
                    $translationKey = '@meta::' . $translationKey;
                }

                // 验证翻译键格式
                if (preg_match('/^@meta::(.+)$/', $translationKey, $keyMatches)) {
                    $keyParts = explode('.', $keyMatches[1]);
                    if (count($keyParts) >= 5) {
                        // 获取当前语言
                        $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

                        // 从I18n Dictionary获取翻译
                        /** @var \Weline\I18n\Model\Locale\Dictionary $localeDict */
                        $localeDict = ObjectManager::getInstance(\Weline\I18n\Model\Locale\Dictionary::class);
                        $md5 = \Weline\I18n\Model\Locale\Dictionary::generateMd5($translationKey, $locale);
                        $localeDict->load($md5, \Weline\I18n\Model\Locale\Dictionary::schema_fields_MD5);
                        
                        if ($localeDict->getId()) {
                            $translation = $localeDict->getData(\Weline\I18n\Model\Locale\Dictionary::schema_fields_TRANSLATE);
                            if (!empty($translation)) {
                                $path = str_replace($fullMatch, $translation, $path);
                                continue;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // 如果获取翻译失败，使用默认值
            }

            // 如果都没有获取到值，使用默认值或移除标签
            $path = str_replace($fullMatch, $defaultValue ?: '', $path);
        }

        return $path;
    }
}

