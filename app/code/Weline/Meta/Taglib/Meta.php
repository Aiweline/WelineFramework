<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Taglib;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Model\Dictionary;
use Weline\I18n\Model\Locale\Dictionary as LocaleDictionary;
use Weline\Meta\Helper\MetaData;
use Weline\Taglib\TaglibInterface;

class Meta implements TaglibInterface
{
    static public function name(): string
    {
        return 'meta';
    }

    static function tag(): bool
    {
        return false; // 不支持成对标签
    }

    static function attr(): array
    {
        return []; // 不需要属性，通过@meta{}格式解析
    }

    static function tag_start(): bool
    {
        return false;
    }

    static function tag_end(): bool
    {
        return false;
    }

    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            // 只支持 @meta{} 格式
            if ($tag_key !== '@tag{}') {
                return '';
            }

            $content = trim($tag_data[1] ?? '');
            if (empty($content)) {
                return '';
            }

            // 解析格式：@meta{key|默认值}
            // 支持：@meta{key|默认值} 或 @meta{key}
            // 注意：默认值不需要引号包裹，直接使用|后面的内容
            $translationKey = '';
            $defaultValue = '';

            // 检查是否有默认值（用|分隔）
            if (strpos($content, '|') !== false) {
                [$keyPart, $defaultPart] = explode('|', $content, 2);
                $translationKey = trim($keyPart);
                // 默认值直接使用，不需要引号（如果外部有引号会自动处理）
                $defaultValue = trim($defaultPart);
            } else {
                $translationKey = trim($content);
            }

            if (empty($translationKey)) {
                return $defaultValue;
            }

            // 统一使用 MetaData 类读取所有 Meta 信息
            // MetaData::load() 已统一处理：
            // 1. .value 后缀：直接返回配置值（字符串）
            // 2. .info 后缀：直接返回信息值（字符串）
            // 3. .lang 后缀：直接返回翻译值（字符串）
            // 4. 其他格式：返回 MetaData 对象
            try {
                // 直接使用 MetaData 类读取（MetaData 内部已处理 .value、.info 和 .lang 格式）
                $result = MetaData::load($translationKey);
                
                // 如果是 .value、.info 或 .lang 格式，直接返回字符串值
                if (is_string($result) || $result === null) {
                    return $result ?? $defaultValue;
                }
                
                // 如果是 MetaData 对象，继续处理
                $metaData = $result;
                
                // 如果不是 .value 或 .info 格式，尝试作为完整的 metaIdentify 加载
                // metaIdentify 格式：theme.backend.components（不带 @meta:: 前缀）
                $metaIdentify = $translationKey;
                
                // 如果 translationKey 以 @meta:: 开头，去掉前缀
                if (str_starts_with($metaIdentify, '@meta::')) {
                    $metaIdentify = substr($metaIdentify, 7); // 去掉 '@meta::' 前缀
                }
                
                // 验证 metaIdentify 格式：namespace.type.identify.group.field（至少5部分）
                $keyParts = explode('.', $metaIdentify);
                if (count($keyParts) >= 5) {
                    // 使用 MetaData 类加载元数据
                    $metaData = MetaData::load($metaIdentify);
                    // 如果成功加载元数据，尝试获取标签信息
                    if ($metaData->isLoaded()) {
                        // 尝试从 meta_data 中获取标签信息（支持多语言）
                        // 格式：namespace.type.identify.group.field
                        // 提取 group 和 field
                        $group = $keyParts[count($keyParts) - 2] ?? '';
                        $field = $keyParts[count($keyParts) - 1] ?? '';
                        
                        if ($group && $field) {
                            // 尝试从翻译后的 meta_data 中获取标签值
                            $labelValue = $metaData->getLabel("{$group}.{$field}");
                            if ($labelValue !== null) {
                                return $labelValue;
                            }
                        }
                    }
                }

                // 如果 MetaData 没有找到，尝试从 I18n Dictionary 获取翻译（作为最后的回退）
                if (!str_starts_with($translationKey, '@meta::')) {
                    $translationKey = '@meta::' . $translationKey;
                }

                // 验证翻译键格式
                if (preg_match('/^@meta::(.+)$/', $translationKey, $matches)) {
                    $keyParts = explode('.', $matches[1]);
                    if (count($keyParts) >= 5) {
                        // 获取当前语言
                        $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

                        // 从I18n Dictionary获取翻译
                        /** @var LocaleDictionary $localeDict */
                        $localeDict = ObjectManager::getInstance(LocaleDictionary::class);
                        $md5 = LocaleDictionary::generateMd5($translationKey, $locale);
                        $localeDict->load($md5, LocaleDictionary::fields_MD5);
                        
                        if ($localeDict->getId()) {
                            $translation = $localeDict->getData(LocaleDictionary::fields_TRANSLATE);
                            if (!empty($translation)) {
                                return $translation;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // 如果获取失败，返回默认值
            }
            // 优先级：MetaConfig配置值 > MetaData元数据标签 > I18n翻译值 > 默认值
            return $defaultValue;
        };
    }

    static function tag_self_close(): bool
    {
        return false;
    }

    static function tag_self_close_with_attrs(): bool
    {
        return false;
    }

    static function parent(): ?string
    {
        return null;
    }

    static function document(): string
    {
        return 'Meta标签，用于在模板中读取元数据、配置值或翻译值。' . 
               '格式：@meta{键名|默认值}' .
               '读取规则：' .
               '1. .value后缀：读取配置值，如 @meta{theme.frontend.partials.header.value|default}' .
               '2. .info后缀：读取元数据信息，如 @meta{theme.backend.components.info.name|组件名称}' .
               '3. .lang后缀：读取翻译值，如 @meta{theme.layout.account_auth.info.name.lang|个人中心认证页面布局}' .
               '4. 完整格式：返回MetaData对象，如 @meta{theme.layout.account_auth.info.name|默认名称}' .
               '注意：默认值不需要引号包裹，直接使用|后面的内容';
    }
}

