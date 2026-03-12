<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/7/16 08:23:43
 */

namespace Weline\Taglib\Taglib;

use PHPUnit\Event\Runtime\PHP;
use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;
use Weline\Framework\Database\Model;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

class JsPart implements \Weline\Taglib\TaglibInterface
{
    public static Request|null $request = null;

    /**
     * @inheritDoc
     */
    public static function name(): string
    {
        return 'js:part';
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
        return ['name' => 1];
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
            $name = trim((string)($attributes['name'] ?? ''));
            $innerContent = trim((string)($tag_data[2] ?? ''));

            // 成对标签：name 为空但含内联内容时，直接输出 <script> 包裹的 JS
            if ($name === '' && $innerContent !== '') {
                return '<script>' . $innerContent . '</script>';
            }
            if ($name === '') {
                throw new Exception(htmlentities('<js:part name="">') . __('所指定的name找不到对应的js模板文件！name 不能为空；若为内联 JS，请使用成对标签 <js:part>代码</js:part>'));
            }
            $cache = w_cache('taglib');
            $cache_key = 'js:part::' . $name;
            $content = $cache->get($cache_key);
            if (PROD and !empty($content)) {
                return $content;
            }
            /**@var Template $template */
            $template = ObjectManager::getInstance(Template::class);
            $content_file = __DIR__ . "/../view/taglib/js/{$name}.phtml";
            if (is_file($content_file)) {
                $content = $template->fetchTagHtml('', "Weline_Taglib::taglib/js/{$name}.phtml");
                $cache->set($cache_key, $content);
                return $content;
            }
            $modules = Env::getInstance()->getActiveModules();
            foreach ($modules as $module) {
                $taglib_file = $module['base_path'] . "view/taglib/js/{$name}.phtml";
                if (is_file($taglib_file)) {
                    $content = $template->fetchTagHtml('', "{$module['name']}::taglib/js/{$name}.phtml");
                    $cache->set($cache_key, $content);
                    return $content;
                }
            }
            if (!file_exists($content_file)) {
                throw new Exception(htmlentities('<js:part name="' . $name . '">') . __('所指定的name找不到对应的js模板文件！'));
            }
            return '';
        };
    }

    public static function breadcrumb(Model $parent, string &$html, &$action_field, &$name_field)
    {
        $sub_parent = $parent->getData('parents')[0] ?? [];
        if ($sub_parent) {
            self::breadcrumb($sub_parent, $html, $action_field, $name_field);
        }
        $html = self::getHtml($parent, $name_field, $action_field, $html);
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    /**
     * 指定父标签，用于依赖管理
     * @return string|null 父标签名称
     */
    public static function parent(): ?string
    {
        return null; // JsPart标签没有依赖
    }

    public static function document(): string
    {
        return htmlentities(
            <<<DOC
自动生成面包屑导航列表。
<js:part name="debounce"/>
name: js部分的名称。目前仅支持系统自带。
DOC
        );
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/16 14:04
     * 参数区：
     *
     * @param \Weline\Framework\Database\Model $parent
     * @param                                  $name_field
     * @param                                  $action_field
     * @param string $html
     *
     * @return string
     */
    protected static function getHtml(Model $parent, &$name_field, &$action_field, string &$html): string
    {
        $name = __($parent[$name_field]);
        $request = self::getRequest();
        if (!empty($parent[$action_field])) {
            if ($request->isBackend()) {
                $action = $request->getUrlBuilder()->getBackendUrl($parent[$action_field]);
            } else {
                $action = $request->getUrlBuilder()->getUrl($parent[$action_field]);
            }
        } else {
            $action = 'javascript: void(0);';
        }
        $html .= "<li class='breadcrumb-item'><a href='{$action}'>{$name}</a></li>";
        return $html;
    }

    /**
     * @DESC          # 方法描述
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2023/7/16 14:12
     * 参数区：
     * @return \Weline\Framework\Http\Request
     */
    public static function getRequest(): Request
    {
        if (!isset(self::$request)) {
            self::$request = ObjectManager::getInstance(Request::class);
        }
        return self::$request;
    }
}
