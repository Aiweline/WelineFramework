<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

/**
 * Fixed storefront shell/widget label sources.
 *
 * A cold non-default locale storefront used to issue one
 * `w_i18n_locale_dictionary` query per `WidgetI18n::label()` call. The shared
 * header renders before the body, so one batch prefetch here seeds both the
 * main Fiber's request memo and the process-level base-label bag; child widget
 * Fibers (which do not share the main Fiber's request memo) reuse the bag.
 *
 * Keep only stable Chinese UI sources. Catalog/category names and other
 * dynamic values are prefetched by their own renderers.
 */
final class StorefrontLabelPrefetch
{
    /** @var list<string> */
    private const SOURCES = [
        // Shared header / navigation / account.
        '店铺通知',
        '打开菜单',
        '主导航',
        '浏览',
        '登录',
        '关闭菜单',
        '首页',
        '全部分类',
        '热门入口',
        '今日特价',
        '热销榜',
        '新品上市',
        '客户服务',
        '联系我们',
        '账户与服务',
        '我的账户',
        '我的订单',
        '购物车',
        '商品对比',
        '设置',
        '语言与货币',
        '全部',
        '全部%{1}',
        '全部商品',
        '分类导航',
        '更多分类',
        '查看全部',
        '推荐产品',
        '搜索',
        '搜索类型',
        '帮助中心',
        '订单跟踪',
        '订单留言',
        '欢迎回来',
        '退出登录',
        '注册',
        '热搜',
        '更多',
        '收起',
        '收藏',
        '我的收藏',
        '我的询价',
        '我的语言',
        '地址管理',
        '浏览全部已发布商品',
        '搜索商品...',
        '为您精选的优质商品',
        '宋制汉服',
        '明制汉服',
        '新闻中心',
        '博客',
        '商品',
        '应用',
        '发送',
        '您好',
        '您好, %{1}',
        '加载更多',
        '加载失败，请稍后重试',
        // Language / currency switchers.
        '切换语言',
        '切换货币',
        '货币选项',
        '搜索货币',
        '搜索货币、货币码、符号或翻译',
        '无匹配货币',
        '未分组国家',
        '仅显示原文',
        '仅显示译文',
        '原文+译文',
        '修改邮箱身份',
        '正在加载语言…',
        '正在加载语言目录与人机验证...',
        '没有匹配的语言',
        '申请支持其他语言',
        '申请表加载失败',
        '检测中...',
        '搜索国家、语言或代码...',
        // Coupon / promotion widgets.
        '优惠券',
        '优惠券不可用',
        '优惠券无效、不可用或已达使用上限',
        '已移除优惠券',
        '该优惠券已应用',
        '应用优惠券',
        '输入优惠券代码',
        '请输入优惠券代码',
        // Store music widget.
        '进店音乐',
        '播放中',
        '开启波形',
        '关闭波形',
        '波形不可用',
        '点击开启声音',
        '点击开启音乐',
        '当前浏览器暂不支持此音频格式',
        '音频加载失败，请换一首曲目或格式',
        '请先同意营销 Cookie 后再播放',
        // B2B / wholesale credit widget.
        '批发信用',
        '正在获取批发信用报价…',
        '暂时无法获取批发信用报价，请刷新后重试',
        '站点未开启批发信用额度',
        '请先登录后再使用批发信用',
        '暂无汇率，无法使用批发信用',
        '额度不够：暂无可用批发信用余额',
        '额度不够：本单可抵扣额度为 0',
        '暂无法估算本期定金，无法用批发信用抵扣',
        '当前是零售结账，批发信用只能在「批发车」使用。请先到购物车切换到批发车，再回来结账。',
        // Customer service / marketing widgets.
        '如有特殊要求请留言',
        '欢迎使用客服服务！',
        '营销服务暂时不可用',
        '浏览 %{1} 相关商品与配件',
    ];

    public static function prefetch(): void
    {
        WidgetI18n::prefetchLabels(self::SOURCES);
    }
}
