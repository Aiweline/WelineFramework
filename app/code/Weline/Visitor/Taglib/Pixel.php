<?php

namespace Weline\Visitor\Taglib;

use http\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\View\Template;
use Weline\Taglib\TaglibInterface;

class Pixel implements TaglibInterface
{

    /**
     * @inheritDoc
     */
    static public function name(): string
    {
        return 'pixel';
    }

    /**
     * @inheritDoc
     */
    static function tag(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function attr(): array
    {
        return ['name' => 1];
    }

    /**
     * @inheritDoc
     */
    static function tag_start(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_end(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function callback(): callable
    {
        return function ($tag_key, $config, $tag_data, $attributes) {
            $name = $attributes['name'];
            /**@var Template $tp */
            $tp = w_obj(Template::class);
            $data = new DataObject(['pixel_code' => '', 'name' => $name, 'enable' => 1]);
            /**@var EventsManager $event */
            $event = w_obj(EventsManager::class);
            $event->dispatch('Weline_Visitor::taglib_pixel', $data);
            if (empty($data->getData('enable'))) {
                return '';
            }
            $tp->assign('pixel_code', $data->getData('pixel_code'));
            $js = $tp->fetch('Weline_Visitor::taglib/js/pixel.phtml');
            return str_replace('{:name}', $name, $js);
        };
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    static function tag_self_close_with_attrs(): bool
    {
        return true;
    }

    static function document(): string
    {
        return "统计网站流量。使用方法:在想要统计的页面引入<pixel name=\"default\"/>。 name为自定义统计名称，用于区分统计来源，默认使用sys。<br>
    可在后台自定义像素以及查看访问概览时，作为区分。
    自定义事件：在你想要统计的标签上设置类名开头为weline-pixel::name类，冒号后面的名字将作为事件名。
    只有设置了weline-pixel::name类的标签才会被统计，例如：weline-pixel::place-order,weline-pixel::add-to-cart等。
    place-order,add-to-cart将自动解析为事件名字。
    想要给像素自定义事件，请监听Weline_Visitor::taglib_pixel事件。并在返回pixel_code值中包含js代码
    （例如:pixel_code=\"WelinePixel.initData.elementInfo.eventType = 'click';\"）
    所有点击事件都包含在WelinePixel.initData.elementInfo中，自定义事件时判断WelinePixel.initData.elementInfo中的数据
    然后修改WelinePixel.initData数据，最后调用WelinePixel.send()即可
    ";
    }
}