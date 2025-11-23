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

            // 如果翻译键不是以@meta::开头，自动添加
            if (!str_starts_with($translationKey, '@meta::')) {
                $translationKey = '@meta::' . $translationKey;
            }

            // 验证翻译键格式：@meta::namespace.type.identify.group.field
            if (!preg_match('/^@meta::(.+)$/', $translationKey, $matches)) {
                return $defaultValue;
            }

            $keyParts = explode('.', $matches[1]);
            if (count($keyParts) < 5) {
                return $defaultValue;
            }

            // 获取当前语言
            $locale = \Weline\Framework\Http\Cookie::getLangLocal() ?? 'zh_Hans_CN';

            // 从I18n Dictionary获取翻译
            /** @var LocaleDictionary $localeDict */
            $localeDict = ObjectManager::getInstance()->get(LocaleDictionary::class);
            $md5 = LocaleDictionary::generateMd5($translationKey, $locale);
            $localeDict->load($md5, LocaleDictionary::fields_MD5);
            
            if ($localeDict->getId()) {
                $translation = $localeDict->getData(LocaleDictionary::fields_TRANSLATE);
                if (!empty($translation)) {
                    return $translation;
                }
            }
            
            // 优先级：翻译值 > 默认值
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
        return 'Meta标签，用于在模板中读取元数据的翻译值。' . 
               '格式：@meta{@meta::theme.layout.account_auth.info.name|默认名称}' .
               '或：@meta{theme.layout.account_auth.info.name|默认名称}' .
               '注意：默认值不需要引号包裹，直接使用|后面的内容' .
               '翻译键格式：@meta::{namespace}.{type}.{identify}.{group}.{field}';
    }
}

