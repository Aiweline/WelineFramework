<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Hook;

/**
 * Hook 规约接口
 * 
 * 定义框架和主题的所有标准 hook 常量
 * Hook 命名格式：{ModuleName}::{area}::{type}::{component}::{position}
 * 
 * @DESC    :    此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 * @since   1.0.0
 */
interface HookInterface
{
    // ==================== Theme Frontend Partials - Footer ====================
    const THEME_FRONTEND_PARTIALS_FOOTER_BEFORE = 'Weline_Theme::frontend::partials::footer::before';
    const THEME_FRONTEND_PARTIALS_FOOTER_CONTENT_BEFORE = 'Weline_Theme::frontend::partials::footer::content-before';
    const THEME_FRONTEND_PARTIALS_FOOTER_SECTION_BEFORE = 'Weline_Theme::frontend::partials::footer::section-before';
    const THEME_FRONTEND_PARTIALS_FOOTER_SECTION_AFTER = 'Weline_Theme::frontend::partials::footer::section-after';
    const THEME_FRONTEND_PARTIALS_FOOTER_CONTENT_AFTER = 'Weline_Theme::frontend::partials::footer::content-after';
    const THEME_FRONTEND_PARTIALS_FOOTER_SOCIAL_MEDIA_BEFORE = 'Weline_Theme::frontend::partials::footer::social-media-before';
    const THEME_FRONTEND_PARTIALS_FOOTER_SOCIAL_MEDIA_LINKS_BEFORE = 'Weline_Theme::frontend::partials::footer::social-media-links-before';
    const THEME_FRONTEND_PARTIALS_FOOTER_SOCIAL_MEDIA_LINKS_AFTER = 'Weline_Theme::frontend::partials::footer::social-media-links-after';
    const THEME_FRONTEND_PARTIALS_FOOTER_SOCIAL_MEDIA_AFTER = 'Weline_Theme::frontend::partials::footer::social-media-after';
    const THEME_FRONTEND_PARTIALS_FOOTER_COPYRIGHT_BEFORE = 'Weline_Theme::frontend::partials::footer::copyright-before';
    const THEME_FRONTEND_PARTIALS_FOOTER_COPYRIGHT_AFTER = 'Weline_Theme::frontend::partials::footer::copyright-after';
    const THEME_FRONTEND_PARTIALS_FOOTER_AFTER = 'Weline_Theme::frontend::partials::footer::after';
    
    // ==================== Theme Frontend Partials - Header ====================
    const THEME_FRONTEND_PARTIALS_HEADER_BEFORE = 'Weline_Theme::frontend::partials::header::before';
    const THEME_FRONTEND_PARTIALS_HEADER_LOGO_BEFORE = 'Weline_Theme::frontend::partials::header::logo-before';
    const THEME_FRONTEND_PARTIALS_HEADER_LOGO_AFTER = 'Weline_Theme::frontend::partials::header::logo-after';
    const THEME_FRONTEND_PARTIALS_HEADER_CATEGORIES_BEFORE = 'Weline_Theme::frontend::partials::header::categories-before';
    const THEME_FRONTEND_PARTIALS_HEADER_CATEGORIES_AFTER = 'Weline_Theme::frontend::partials::header::categories-after';
    const THEME_FRONTEND_PARTIALS_HEADER_NAV_BEFORE = 'Weline_Theme::frontend::partials::header::nav-before';
    const THEME_FRONTEND_PARTIALS_HEADER_NAV_AFTER = 'Weline_Theme::frontend::partials::header::nav-after';
    const THEME_FRONTEND_PARTIALS_HEADER_SEARCH_BEFORE = 'Weline_Theme::frontend::partials::header::search-before';
    const THEME_FRONTEND_PARTIALS_HEADER_SEARCH_AFTER = 'Weline_Theme::frontend::partials::header::search-after';
    const THEME_FRONTEND_PARTIALS_HEADER_ACTIONS_BEFORE = 'Weline_Theme::frontend::partials::header::actions-before';
    const THEME_FRONTEND_PARTIALS_HEADER_ACTIONS_AFTER = 'Weline_Theme::frontend::partials::header::actions-after';
    const THEME_FRONTEND_PARTIALS_HEADER_AFTER = 'Weline_Theme::frontend::partials::header::after';
    
    // ==================== Theme Frontend Layouts - Base (通用) ====================
    const THEME_FRONTEND_LAYOUTS_BASE_HEAD_BEFORE = 'Weline_Theme::frontend::layouts::base::head-before';
    const THEME_FRONTEND_LAYOUTS_BASE_HEAD_AFTER = 'Weline_Theme::frontend::layouts::base::head-after';
    
    // ==================== Theme Frontend Layouts - Homepage ====================
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_HEAD_BEFORE = 'Weline_Theme::frontend::layouts::homepage::head-before';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_HEAD_AFTER = 'Weline_Theme::frontend::layouts::homepage::head-after';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_BODY_START = 'Weline_Theme::frontend::layouts::homepage::body-start';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_HEADER_BEFORE = 'Weline_Theme::frontend::layouts::homepage::header-before';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_HEADER_AFTER = 'Weline_Theme::frontend::layouts::homepage::header-after';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_CONTENT_BEFORE = 'Weline_Theme::frontend::layouts::homepage::content-before';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_CONTENT_AFTER = 'Weline_Theme::frontend::layouts::homepage::content-after';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_FOOTER_BEFORE = 'Weline_Theme::frontend::layouts::homepage::footer-before';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_FOOTER_AFTER = 'Weline_Theme::frontend::layouts::homepage::footer-after';
    const THEME_FRONTEND_LAYOUTS_HOMEPAGE_BODY_END = 'Weline_Theme::frontend::layouts::homepage::body-end';
    
    // ==================== Theme Frontend Layouts - Default ====================
    const THEME_FRONTEND_LAYOUTS_DEFAULT_HEAD_BEFORE = 'Weline_Theme::frontend::layouts::default::head-before';
    const THEME_FRONTEND_LAYOUTS_DEFAULT_HEAD_AFTER = 'Weline_Theme::frontend::layouts::default::head-after';
    const THEME_FRONTEND_LAYOUTS_DEFAULT_BODY_START = 'Weline_Theme::frontend::layouts::default::body-start';
    const THEME_FRONTEND_LAYOUTS_DEFAULT_CONTENT_BEFORE = 'Weline_Theme::frontend::layouts::default::content-before';
    const THEME_FRONTEND_LAYOUTS_DEFAULT_CONTENT_AFTER = 'Weline_Theme::frontend::layouts::default::content-after';
    const THEME_FRONTEND_LAYOUTS_DEFAULT_BODY_END = 'Weline_Theme::frontend::layouts::default::body-end';
    
    // ==================== Theme Frontend Layouts - Account ====================
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_HEAD_BEFORE = 'Weline_Theme::frontend::layouts::account::head-before';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_HEAD_AFTER = 'Weline_Theme::frontend::layouts::account::head-after';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_BODY_START = 'Weline_Theme::frontend::layouts::account::body-start';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_SIDEBAR_BEFORE = 'Weline_Theme::frontend::layouts::account::sidebar-before';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_SIDEBAR_AFTER = 'Weline_Theme::frontend::layouts::account::sidebar-after';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_CONTENT_BEFORE = 'Weline_Theme::frontend::layouts::account::content-before';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_CONTENT_AFTER = 'Weline_Theme::frontend::layouts::account::content-after';
    const THEME_FRONTEND_LAYOUTS_ACCOUNT_BODY_END = 'Weline_Theme::frontend::layouts::account::body-end';
    
    // ==================== Checkout Frontend Layouts ====================
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_HEAD_BEFORE = 'Weline_Checkout::frontend::layouts::checkout::head-before';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_HEAD_AFTER = 'Weline_Checkout::frontend::layouts::checkout::head-after';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_CONTENT_BEFORE = 'Weline_Checkout::frontend::layouts::checkout::content-before';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_CONTENT_AFTER = 'Weline_Checkout::frontend::layouts::checkout::content-after';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_FORM_BEFORE = 'Weline_Checkout::frontend::layouts::checkout::form-before';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_FORM_AFTER = 'Weline_Checkout::frontend::layouts::checkout::form-after';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_PAYMENT_METHODS_BEFORE = 'Weline_Checkout::frontend::layouts::checkout::payment-methods-before';
    const CHECKOUT_FRONTEND_LAYOUTS_CHECKOUT_PAYMENT_METHODS_AFTER = 'Weline_Checkout::frontend::layouts::checkout::payment-methods-after';
    
    // ==================== Checkout Frontend Order Layouts ====================
    const CHECKOUT_FRONTEND_LAYOUTS_ORDER_LIST_CONTENT_BEFORE = 'Weline_Checkout::frontend::layouts::order::list::content-before';
    const CHECKOUT_FRONTEND_LAYOUTS_ORDER_LIST_CONTENT_AFTER = 'Weline_Checkout::frontend::layouts::order::list::content-after';
    const CHECKOUT_FRONTEND_LAYOUTS_ORDER_VIEW_CONTENT_BEFORE = 'Weline_Checkout::frontend::layouts::order::view::content-before';
    const CHECKOUT_FRONTEND_LAYOUTS_ORDER_VIEW_CONTENT_AFTER = 'Weline_Checkout::frontend::layouts::order::view::content-after';
    
    // ==================== Checkout Backend Order Layouts ====================
    const CHECKOUT_BACKEND_ORDER_LIST_FILTERS = 'Weline_Checkout::backend::order::list::filters';
    const CHECKOUT_BACKEND_ORDER_VIEW_BEFORE = 'Weline_Checkout::backend::order::view::before';
    const CHECKOUT_BACKEND_ORDER_VIEW_AFTER = 'Weline_Checkout::backend::order::view::after';
}

