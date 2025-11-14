/*
Template Name: Weline -  Admin & Dashboard Template
Author: 秋枫雁飞(aiweline)
Version: 1.0.0
更多支持：https://www.aiweline.com
File: Main Js File
*/

(function ($) {

    'use strict';

    function initMetisMenu() {
        //metis menu
        // 在图标模式下禁用MetisMenu，依赖CSS hover效果
        var isCollapsedMode = $('body').hasClass('vertical-collpsed');

        if (!isCollapsedMode) {
            // 只在非图标模式下启用MetisMenu
            $("#sidebar-menu").metisMenu();
        } else {
            // 图标模式下不初始化MetisMenu，完全依赖CSS hover
            console.log('图标模式：已禁用MetisMenu，依赖CSS hover效果');
        }
    }

    function initLeftMenuCollapse() {
        $('#vertical-menu-btn').on('click', function (event) {
            event.preventDefault();
            $('body').toggleClass('sidebar-enable');
            if ($(window).width() >= 992) {
                $('body').toggleClass('vertical-collpsed');
                // 延迟处理，等待类切换完成
                setTimeout(function () {
                    initMetisMenu(); // 重新初始化MetisMenu
                }, 100);
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
        // focus active menu in left sidebar（使用原生 Upzet 方式）
        // 参照 BackendThemeUpzet/app.js initMenuItemScroll
        // 注意：允许 frequent-menus-section 下的菜单显示激活状态，但禁止对其进行滚动定位
        $(document).ready(function () {
            if ($("#sidebar-menu").length > 0) {
                // 查找所有激活的菜单项，排除 frequent-menus-section 和 frequent-menu-item 相关的菜单项
                var $activeLinks = $("#sidebar-menu .mm-active .active");
                var $activeLink = null;

                // 遍历所有激活的菜单项，找到第一个不在 frequent-menus-section 下且不是 frequent-menu-item 的
                $activeLinks.each(function () {
                    var $link = $(this);
                    var $li = $link.closest('li');

                    // 排除条件：
                    // 1. 在 frequent-menus-section 下的元素
                    // 2. 是 frequent-menu-item 或 menu-frequent-item 类的元素
                    // 3. 父元素是 frequent-menu-item 或 menu-frequent-item 的元素
                    var isInFrequentSection = $link.closest('#frequent-menus-section').length > 0;
                    var isFrequentMenuItem = $li.hasClass('frequent-menu-item') || $li.hasClass('menu-frequent-item');
                    var hasFrequentParent = $li.closest('.frequent-menu-item, .menu-frequent-item').length > 0;

                    // 如果不在排除范围内，则使用它
                    if (!isInFrequentSection && !isFrequentMenuItem && !hasFrequentParent) {
                        $activeLink = $link;
                        return false; // 跳出循环
                    }
                });

                // 如果找到了符合条件的激活菜单项，则进行定位
                if ($activeLink && $activeLink.length > 0) {
                    var isCollapsedMode = $('body').hasClass('vertical-collpsed');
                    var targetElement = $activeLink;

                    // 在图标菜单模式下，如果当前激活的是子菜单项，滚动到父级菜单
                    if (isCollapsedMode) {
                        var $currentLi = $activeLink.closest('li');
                        var $parentLi = $currentLi.parent('ul.sub-menu').prev('a').parent('li');

                        // 如果存在父级菜单项，则滚动到父级菜单
                        if ($parentLi.length > 0) {
                            targetElement = $parentLi.find('> a');
                        }
                    }

                    var activeMenu = targetElement.offset().top;
                    if (activeMenu > 300) {
                        activeMenu = activeMenu - 300;
                        $(".vertical-menu .simplebar-content-wrapper").animate({ scrollTop: activeMenu }, "slow");
                    }
                }
                // 如果所有激活的菜单项都在排除范围内，则不进行任何定位操作
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

    // 布局预设映射（基于BackendThemeUpzet的layouts-*.html文件）
    const layoutPresets = {
        'default': {
            layouts: {
                'data-layout': '',
                'data-topbar': 'light',
                'data-sidebar': 'dark',
                'data-sidebar-size': '',
                'data-layout-size': '',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'light-sidebar': {
            // layouts-light-sidebar.html: <body data-topbar="colored">
            layouts: {
                'data-layout': '',
                'data-topbar': 'colored',
                'data-sidebar': 'light',
                'data-sidebar-size': '',
                'data-layout-size': '',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'icon-sidebar': {
            // layouts-icon-sidebar.html: <body data-sidebar="dark" data-keep-enlarged="true" class="vertical-collpsed">
            layouts: {
                'data-layout': '',
                'data-topbar': '',
                'data-sidebar': 'dark',
                'data-sidebar-size': '',
                'data-layout-size': '',
                'data-keep-enlarged': 'true',
                'class': 'vertical-collpsed'
            }
        },
        'compact-sidebar': {
            // layouts-compact-sidebar.html: <body data-sidebar="dark" data-sidebar-size="small">
            layouts: {
                'data-layout': '',
                'data-topbar': '',
                'data-sidebar': 'dark',
                'data-sidebar-size': 'small',
                'data-layout-size': '',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'horizontal': {
            // layouts-horizontal.html: <body data-topbar="light" data-layout="horizontal">
            layouts: {
                'data-layout': 'horizontal',
                'data-topbar': 'light',
                'data-sidebar': '',
                'data-sidebar-size': '',
                'data-layout-size': '',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'horizontal-dark': {
            // layouts-hori-topbar-dark.html: <body data-topbar="dark" data-layout="horizontal">
            layouts: {
                'data-layout': 'horizontal',
                'data-topbar': 'dark',
                'data-sidebar': '',
                'data-sidebar-size': '',
                'data-layout-size': '',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'horizontal-boxed': {
            // layouts-hori-boxed-width.html: <body data-topbar="light" data-layout="horizontal" data-layout-size="boxed">
            layouts: {
                'data-layout': 'horizontal',
                'data-topbar': 'light',
                'data-sidebar': '',
                'data-sidebar-size': '',
                'data-layout-size': 'boxed',
                'data-keep-enlarged': '',
                'class': ''
            }
        },
        'boxed': {
            // layouts-boxed.html: <body data-sidebar="dark" data-keep-enlarged="true" class="vertical-collpsed" data-layout-size="boxed">
            layouts: {
                'data-layout': '',
                'data-topbar': '',
                'data-sidebar': 'dark',
                'data-sidebar-size': '',
                'data-layout-size': 'boxed',
                'data-keep-enlarged': 'true',
                'class': 'vertical-collpsed'
            }
        }
    };

    function initSettings() {
        // 主题颜色设置
        $("#light-mode-switch, #dark-mode-switch, #rtl-mode-switch,#dark-mode-radio,#light-mode-radio,#reset-theme").on("change", function (e) {
            updateThemeSetting(e.target.id);
        });

        // 布局预设选择（配置包）
        $('#layout-preset').on('change', function () {
            const preset = $(this).val();
            if (layoutPresets[preset]) {
                setThemeConfig(layoutPresets[preset]);
            }
        });
    }

    async function setThemeConfig(layout, reload = true) {
        if (typeof showLoading === 'function') {
            showLoading();
        }
        $.ajax({
            url: window.url('/backend/theme-config/set'),
            data: JSON.stringify(layout),
            dataType: 'json',
            type: 'post',
            contentType: 'application/json',
            success: async function (res) {
                if ((200 === res.code) && reload) {
                    window.location.reload();
                }
                if (typeof hideLoading === 'function') {
                    hideLoading();
                }
            },
            error: function () {
                if (typeof hideLoading === 'function') {
                    hideLoading();
                }
            }
        });
    }

    // 将 setThemeConfig 暴露到全局作用域，供 right-sidebar.phtml 使用
    window.setThemeConfig = setThemeConfig;

    function updateThemeSetting(id) {
        if (id === 'reset-theme') {
            setThemeConfig({
                layouts: {
                    'data-topbar': 'light',
                    'data-sidebar': 'light',
                },
                'light-mode-switch': true,
                'dark-mode-switch': false,
                'rtl-mode-switch': false,
            })
        } else if (id === 'light-mode-radio') {
            setThemeConfig({
                layouts: {
                    'data-topbar': 'light',
                    'data-sidebar': 'light',
                },
                'light-mode-switch': true,
                'dark-mode-switch': false,
                'rtl-mode-switch': false,
            })
        } else if (id === 'dark-mode-radio') {
            setThemeConfig({
                layouts: {
                    'data-topbar': 'dark',
                    'data-sidebar': 'dark',
                },
                'light-mode-switch': false,
                'dark-mode-switch': true,
                'rtl-mode-switch': false,
            })
        }
        // ajax请求设置主题模式
        else if (id === "light-mode-switch") {
            setThemeConfig({
                layouts: {
                    'data-topbar': 'light',
                    'data-sidebar': 'light',
                },
                'light-mode-switch': $("#light-mode-switch").prop("checked") === true,
                'dark-mode-switch': false,
                'rtl-mode-switch': false,
            })
        } else if (id === "dark-mode-switch") {
            setThemeConfig({
                layouts: {
                    'data-topbar': 'dark',
                    'data-sidebar': 'dark',
                },
                'light-mode-switch': false,
                'dark-mode-switch': $("#dark-mode-switch").prop("checked") === true,
                'rtl-mode-switch': false,
            })
        } else if (id === "rtl-mode-switch") {
            setThemeConfig({
                'light-mode-switch': false,
                'dark-mode-switch': false,
                'rtl-mode-switch': $("#rtl-mode-switch").prop("checked") === true,
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

    // 在页面加载完成后初始化
    $(document).ready(function () {
        init();
    });

})(jQuery)