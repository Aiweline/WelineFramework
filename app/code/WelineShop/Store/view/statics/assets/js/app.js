/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Version: 1.0.0
更多支持：https://www.aiweline.com
File: Main Js File
*/
// import fa from "../libs/moment/locale/zh-cn";

(function ($) {

    'use strict';

    function initMetisMenu() {
        //metis menu
        $("#side-menu").metisMenu();
    }

    function initLeftMenuCollapse() {
        $('#vertical-menu-btn').on('click', function (event) {
            event.preventDefault();
            $('body').toggleClass('sidebar-enable');
            if ($(window).width() >= 992) {
                $('body').toggleClass('vertical-collpsed');
            } else {
                $('body').removeClass('vertical-collpsed');
            }
        });

        $('body,html').click(function (e) {
            var container = $("#vertical-menu-btn");
            if (!container.is(e.target) && container.has(e.target).length === 0 && !(e.target).closest('div.vertical-menu')) {
                $("body").removeClass("sidebar-enable");
            }
        });
    }

    function initActiveMenu() {
        // === following js will activate the menu in left side bar based on url ====
        $("#sidebar-menu a").each(function () {
            var pageUrl = window.location.href.split(/[?#]/)[0];
            if (this.href == pageUrl) {
                $(this).addClass("active");
                $(this).parent().addClass("mm-active"); // add active to li of the current link
                $(this).parent().parent().addClass("mm-show");
                $(this).parent().parent().prev().addClass("mm-active"); // add active class to an anchor
                $(this).parent().parent().parent().addClass("mm-active");
                $(this).parent().parent().parent().parent().addClass("mm-show"); // add active to li of the current link
                $(this).parent().parent().parent().parent().parent().addClass("mm-active");
            }
        });
    }

    function initMenuItem() {
        $(".navbar-nav a").each(function () {
            var pageUrl = window.location.href.split(/[?#]/)[0];
            if (this.href == pageUrl) {
                $(this).addClass("active");
                $(this).parent().addClass("active");
                $(this).parent().parent().addClass("active");
                $(this).parent().parent().parent().addClass("active");
                $(this).parent().parent().parent().parent().addClass("active");
                $(this).parent().parent().parent().parent().parent().addClass("active");
            }
        });
    }

    function initMenuItemScroll() {
        // focus active menu in left sidebar
        $(document).ready(function () {
            if ($("#sidebar-menu").length > 0 && $("#sidebar-menu .mm-active .active").length > 0) {
                var activeMenu = $("#sidebar-menu .mm-active .active").offset().top;
                if (activeMenu > 300) {
                    activeMenu = activeMenu - 300;
                    $(".vertical-menu .simplebar-content-wrapper").animate({scrollTop: activeMenu}, "slow");
                }
            }
        });
    }

    function initFullScreen() {
        $('[data-toggle="fullscreen"]').on("click", function (e) {
            e.preventDefault();
            $('body').toggleClass('fullscreen-enable');
            if (!document.fullscreenElement && /* alternative standard method */ !document.mozFullScreenElement && !document.webkitFullscreenElement) {  // current working methods
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen();
                } else if (document.documentElement.mozRequestFullScreen) {
                    document.documentElement.mozRequestFullScreen();
                } else if (document.documentElement.webkitRequestFullscreen) {
                    document.documentElement.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
                }
            } else {
                if (document.cancelFullScreen) {
                    document.cancelFullScreen();
                } else if (document.mozCancelFullScreen) {
                    document.mozCancelFullScreen();
                } else if (document.webkitCancelFullScreen) {
                    document.webkitCancelFullScreen();
                }
            }
        });
        document.addEventListener('fullscreenchange', exitHandler);
        document.addEventListener("webkitfullscreenchange", exitHandler);
        document.addEventListener("mozfullscreenchange", exitHandler);

        function exitHandler() {
            if (!document.webkitIsFullScreen && !document.mozFullScreen && !document.msFullscreenElement) {
                console.log('pressed');
                $('body').removeClass('fullscreen-enable');
            }
        }
    }

    function initRightSidebar() {
        // right side-bar toggle
        $('.right-bar-toggle').on('click', function (e) {
            $('body').toggleClass('right-bar-enabled');
        });

        $(document).on('click', 'body', function (e) {
            if ($(e.target).closest('.right-bar-toggle, .right-bar').length > 0) {
                return;
            }

            $('body').removeClass('right-bar-enabled');
            return;
        });
    }

    function initDropdownMenu() {
        if (document.getElementById("topnav-menu-content")) {
            var elements = document.getElementById("topnav-menu-content").getElementsByTagName("a");
            for (var i = 0, len = elements.length; i < len; i++) {
                elements[i].onclick = function (elem) {
                    if (elem.target.getAttribute("href") === "#") {
                        elem.target.parentElement.classList.toggle("active");
                        elem.target.nextElementSibling.classList.toggle("show");
                    }
                }
            }
            window.addEventListener("resize", updateMenu);
        }
    }

    function updateMenu() {
        var elements = document.getElementById("topnav-menu-content").getElementsByTagName("a");
        for (var i = 0, len = elements.length; i < len; i++) {
            if (elements[i].parentElement.getAttribute("class") === "nav-item dropdown active") {
                elements[i].parentElement.classList.remove("active");
                elements[i].nextElementSibling.classList.remove("show");
            }
        }
    }

    function initComponents() {

        // Tooltip
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // Popover
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        })

    }

    function initPreloader() {
        $(window).on('load', function () {
            $('#status').fadeOut();
            $('#preloader').delay(350).fadeOut('slow');
        });
    }

    function initSettings() {
        $("#light-mode-switch, #dark-mode-switch, #rtl-mode-switch").on("change", function (e) {
            updateThemeSetting(e.target.id);
        });

        // 菜单布局
        // 1、明亮
        let light_sidebar = $('#light-sidebar')
        if ('checked' === light_sidebar.attr('checked')) {
            light_sidebar.prop('checked', true);
        }
        light_sidebar.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-topbar': "dark", 'data-sidebar': 'dark'}, 'light-sidebar': false}
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-topbar': "colored", 'data-sidebar': 'light'}, 'light-sidebar': true};
            }
            setThemeConfig(layout)
            hideLoading()
        });
        // 2、图标菜单
        let icon_sidebar = $('#icon-sidebar')
        if ('checked' === icon_sidebar.attr('checked')) {
            icon_sidebar.prop('checked', true);
        }
        icon_sidebar.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-keep-enlarged': "false", class: ""}, 'icon-sidebar': false}
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-keep-enlarged': "true", class: "vertical-collpsed"}, 'icon-sidebar': true};
            }
            setThemeConfig(layout)
            hideLoading()
        });
        // 3、图文菜单
        let layouts_compact_sidebar = $('#layouts-compact-sidebar')
        if ('checked' === layouts_compact_sidebar.attr('checked')) {
            layouts_compact_sidebar.prop('checked', true);
        }
        layouts_compact_sidebar.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-sidebar-size': ""}, 'layouts-compact-sidebar': false};
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-sidebar-size': "small"}, 'layouts-compact-sidebar': true}
            }
            setThemeConfig(layout)
            hideLoading()
        });
        // 布局
        // 1、水平布局
        let layouts_horizontal = $('#layouts-horizontal')
        if ('checked' === layouts_horizontal.attr('checked')) {
            layouts_horizontal.prop('checked', true);
        }
        layouts_horizontal.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-layout': ""}, 'layouts-horizontal': false};
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-layout': "horizontal"}, 'layouts-horizontal': true}
            }
            setThemeConfig(layout)
            hideLoading()
        });
        // 2、水平顶黑
        let layouts_hori_topbar_dark = $('#layouts-hori-topbar-dark')
        if ('checked' === layouts_hori_topbar_dark.attr('checked')) {
            layouts_hori_topbar_dark.prop('checked', true);
        }
        layouts_hori_topbar_dark.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-layout': ""}, 'layouts-hori-topbar-dark': false};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-layout': "horizontal", 'data-topbar': 'dark'},
                    'layouts-hori-topbar-dark': true
                }
            }
            setThemeConfig(layout)
            hideLoading()
        });
        // 3、水平盒子
        let layouts_hori_boxed_width = $('#layouts-hori-boxed-width')
        if ('checked' === layouts_hori_boxed_width.attr('checked')) {
            layouts_hori_boxed_width.prop('checked', true);
        }
        layouts_hori_boxed_width.on("change", function (e) {
            showLoading()
            let layout = {layouts: {'data-layout': "", 'data-layout-size': ""}, 'layouts-hori-boxed-width': false};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-layout': "horizontal", 'data-layout-size': "boxed"},
                    'layouts-hori-boxed-width': true
                }
            }
            setThemeConfig(layout)
            hideLoading()
        });
    }

    async function setThemeConfig(layout, reload = true) {
        $.ajax({
            url: window.site.buildUrl('admin/ThemeConfig/Set'),
            data: JSON.stringify(layout),
            dataType: 'json',
            type: 'post',
            success: async res => {
                if ((200 === res.code) && reload) window.location.reload()
            }
        })
    }

    function updateThemeSetting(id) {
        // ajax请求设置主题模式
        if ($("#light-mode-switch").prop("checked") === true && id === "light-mode-switch") {
            showLoading()
            setThemeConfig({
                layouts: {
                    'data-topbar': 'light',
                    'data-sidebar': 'light',
                },
                'light-mode-switch': true,
                'dark-mode-switch': false,
                'rtl-mode-switch': false,
            })
        } else if ($("#dark-mode-switch").prop("checked") === true && id === "dark-mode-switch") {
            showLoading()
            setThemeConfig({
                layouts: {
                    'data-topbar': 'dark',
                    'data-sidebar': 'dark',
                },
                'light-mode-switch': false,
                'dark-mode-switch': true,
                'rtl-mode-switch': false,
            })
        } else if ($("#rtl-mode-switch").prop("checked") === true && id === "rtl-mode-switch") {
            showLoading()
            setThemeConfig({
                'light-mode-switch': false,
                'dark-mode-switch': false,
                'rtl-mode-switch': true,
            })
        }
    }

    function init() {
        initSettings();
        initMetisMenu();
        initLeftMenuCollapse();
        initActiveMenu();
        initMenuItem();
        initMenuItemScroll();
        initFullScreen();
        initRightSidebar();
        initDropdownMenu();
        initComponents();
        initPreloader()

        Waves.init();
    }

    init();

})(jQuery)