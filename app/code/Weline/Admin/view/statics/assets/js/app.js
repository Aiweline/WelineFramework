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
        var $activeLink = null;
        $("#sidebar-menu a").each(function () {
            var pageUrl = window.location.href.split(/[?#]/)[0];
            if (this.href == pageUrl) {
                $activeLink = $(this);
                $activeLink.addClass("active");
                $activeLink.parent().addClass("mm-active"); // add active to li of the current link
                $activeLink.parent().parent().addClass("mm-show");
                $activeLink.parent().parent().prev().addClass("mm-active"); // add active class to an anchor
                $activeLink.parent().parent().parent().addClass("mm-active");
                $activeLink.parent().parent().parent().parent().addClass("mm-show"); // add active to li of the current link
                $activeLink.parent().parent().parent().parent().parent().addClass("mm-active");
            }
        });
        
        // 如果找到激活的菜单项，滚动到可视区域
        if ($activeLink && $activeLink.length > 0) {
            scrollToActiveMenuItem($activeLink);
        }
    }
    
    // 滚动到激活的菜单项
    function scrollToActiveMenuItem($activeLink) {
        if (!$activeLink || $activeLink.length === 0) {
            return;
        }
        
        var $activeMenu = $activeLink.closest('li');
        if (!$activeMenu || $activeMenu.length === 0) {
            return;
        }
        
        // 等待DOM完全渲染和菜单展开完成
        setTimeout(function() {
            try {
                // 确保激活菜单项的父级菜单都已展开
                $activeMenu.parents('li').each(function() {
                    var $parent = $(this);
                    var $subMenu = $parent.find('> .sub-menu').first();
                    if ($subMenu.length > 0 && $subMenu.attr('aria-expanded') === 'false') {
                        $parent.find('> a.has-arrow').first().trigger('click');
                    }
                });
                
                // 再次等待展开动画完成
                setTimeout(function() {
                    try {
                        var menuElement = $activeMenu[0];
                        if (!menuElement) {
                            return;
                        }
                        
                        // 获取滚动容器
                        var $simplebarContainer = $('.vertical-menu [data-simplebar]');
                        var scrollElement = null;
                        
                        if ($simplebarContainer.length > 0 && $simplebarContainer[0].simpleBar) {
                            // 使用 simplebar 的滚动元素
                            scrollElement = $simplebarContainer[0].simpleBar.getScrollElement();
                        } else {
                            // 降级方案：使用侧边栏菜单本身
                            var $sidebarMenu = $('#sidebar-menu');
                            if ($sidebarMenu.length && $sidebarMenu[0].scrollHeight > $sidebarMenu[0].clientHeight) {
                                scrollElement = $sidebarMenu[0];
                            } else {
                                // 再降级：使用 window
                                scrollElement = window;
                            }
                        }
                        
                        if (!scrollElement) {
                            return;
                        }
                        
                        // 计算菜单项相对于滚动容器的位置
                        var menuOffset = $activeMenu.offset();
                        
                        if (!menuOffset) {
                            return;
                        }
                        
                        var scrollTop = 0;
                        var menuTop = 0;
                        var containerHeight = 0;
                        var containerOffset = null;
                        
                        if (scrollElement === window) {
                            // 使用 window 滚动
                            menuTop = menuOffset.top;
                            containerHeight = $(window).height();
                            scrollTop = $(window).scrollTop();
                        } else {
                            // 使用元素滚动
                            var $scrollContainer = $(scrollElement);
                            containerOffset = $scrollContainer.offset();
                            
                            if (containerOffset) {
                                // 计算菜单项相对于滚动容器的位置
                                menuTop = menuOffset.top - containerOffset.top + $scrollContainer.scrollTop();
                            } else {
                                // 降级：使用 position()
                                menuTop = $activeMenu.position().top + $scrollContainer.scrollTop();
                            }
                            
                            containerHeight = $scrollContainer.height();
                            scrollTop = $scrollContainer.scrollTop();
                        }
                        
                        // 计算目标滚动位置：使菜单项位于容器中间
                        var menuHeight = $activeMenu.outerHeight() || 40; // 默认高度40px
                        var targetScrollTop = menuTop - (containerHeight / 2) + (menuHeight / 2);
                        
                        // 确保不超出滚动范围
                        var maxScrollTop = 0;
                        if (scrollElement === window) {
                            maxScrollTop = $(document).height() - $(window).height();
                        } else {
                            maxScrollTop = scrollElement.scrollHeight - scrollElement.clientHeight;
                        }
                        
                        targetScrollTop = Math.max(0, Math.min(targetScrollTop, maxScrollTop));
                        
                        // 执行滚动动画
                        if (scrollElement === window) {
                            $('html, body').animate({
                                scrollTop: targetScrollTop
                            }, 500);
                        } else {
                            $(scrollElement).animate({
                                scrollTop: targetScrollTop
                            }, 500);
                        }
                    } catch (e) {
                        console.warn('计算滚动位置时出错:', e);
                    }
                }, 300);
            } catch (e) {
                console.warn('滚动到激活菜单项时出错:', e);
            }
        }, 200);
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
        // 这个函数作为备用方案，如果 initActiveMenu 中的滚动没有执行，这里会再次尝试
        setTimeout(function() {
            var $activeLink = $("#sidebar-menu .mm-active .active, #sidebar-menu .mm-active > a.active, #sidebar-menu li.mm-active > a.active").first();
            if ($activeLink.length > 0) {
                // 调用滚动函数
                if (typeof scrollToActiveMenuItem === 'function') {
                    scrollToActiveMenuItem($activeLink);
                } else {
                    // 降级方案：使用简单的滚动逻辑
                    var $activeMenu = $activeLink.closest('li');
                    if ($activeMenu.length > 0) {
                        var $simplebarContainer = $('.vertical-menu [data-simplebar]');
                        if ($simplebarContainer.length > 0 && $simplebarContainer[0].simpleBar) {
                            var scrollElement = $simplebarContainer[0].simpleBar.getScrollElement();
                            var menuOffset = $activeMenu.offset();
                            var containerOffset = $(scrollElement).offset();
                            if (menuOffset && containerOffset) {
                                var menuTop = menuOffset.top - containerOffset.top + $(scrollElement).scrollTop();
                                var containerHeight = $(scrollElement).height();
                                var targetScrollTop = menuTop - (containerHeight / 2) + ($activeMenu.outerHeight() / 2);
                                $(scrollElement).animate({
                                    scrollTop: Math.max(0, targetScrollTop)
                                }, 500);
                            }
                        }
                    }
                }
            }
        }, 800); // 延迟执行，确保菜单已经激活
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
        $("#light-mode-switch, #dark-mode-switch, #rtl-mode-switch,#dark-mode-radio,#light-mode-radio,#reset-theme").on("change", function (e) {
            updateThemeSetting(e.target.id);
        });
        // 元素控制
        // 顶部栏
        let data_topbar = $('input[name="data-topbar"]')
        data_topbar.on("change", function (e) {
            let layout = {layouts: {'data-topbar': $(e.target).val()}, 'data-topbar': $(e.target).val()}
            setThemeConfig(layout)
        });
        // 侧边栏
        let data_sidebar = $('input[name="data-sidebar"]')
        data_sidebar.on("change", function (e) {
            let layout = {layouts: {'data-sidebar': $(e.target).val()}, 'data-sidebar': $(e.target).val()}
            setThemeConfig(layout)
        });
        // 侧边栏尺寸
        let data_sidebar_size = $('input[name="data-sidebar-size"]')
        data_sidebar_size.on("change", function (e) {
            let layout = {layouts: {'data-sidebar-size': $(e.target).val()}, 'data-sidebar-size': $(e.target).val()}
            setThemeConfig(layout)
        });
        // 侧边栏尺寸
        let data_layout_size = $('input[name="data-layout-size"]')
        data_layout_size.on("change", function (e) {
            let layout = {layouts: {'data-layout-size': $(e.target).val()}, 'data-layout-size': $(e.target).val()}
            setThemeConfig(layout)
        });
        // 布局
        let data_layout = $('#data-layout')
        data_layout.on("change", function (e) {
            let layout = {layouts: {'data-layout': ""}, 'data-layout': false}
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-layout': "horizontal"}, 'data-layout': true};
            }
            setThemeConfig(layout)
        });
        // 3、保持最大化
        let data_keep_enlarged = $('#data-keep-enlarged')
        data_keep_enlarged.on("change", function (e) {
            let layout = {layouts: {'data-layout': "false"}, 'data-keep-enlarged': false}
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-keep-enlarged': "true"}, 'data-keep-enlarged': true};
            }
            setThemeConfig(layout)
        });
        // 4、class
        let layout_class = $('input[name="layout-class"]')
        layout_class.on("change", function (e) {
            let layout = {layouts: {'class': $(e.target).val()}, 'layout-class': $(e.target).val()}
            setThemeConfig(layout)
        });


        // 菜单布局
        // 1、明亮
        let light_sidebar = $('#light-sidebar')
        if ('checked' === light_sidebar.attr('checked')) {
            light_sidebar.prop('checked', true);
        }
        light_sidebar.on("change", function (e) {
            let layout = {
                layouts: {'data-topbar': "dark", 'data-sidebar': 'dark'},
                'light-sidebar': false,
                'data-topbar': "dark",
                'data-sidebar': 'dark'
            }
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-topbar': "colored", 'data-sidebar': 'light'},
                    'light-sidebar': true,
                    'data-topbar': "colored",
                    'data-sidebar': 'light'
                };
            }
            setThemeConfig(layout)
        });
        // 2、图标菜单
        let icon_sidebar = $('#icon-sidebar')
        if ('checked' === icon_sidebar.attr('checked')) {
            icon_sidebar.prop('checked', true);
        }
        icon_sidebar.on("change", function (e) {
            let layout = {
                layouts: {'data-keep-enlarged': "false", class: ""},
                'icon-sidebar': false,
                'layout-class': "",
                'data-keep-enlarged': "false"
            }
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-keep-enlarged': "true", class: "vertical-collpsed"},
                    'icon-sidebar': true,
                    'data-keep-enlarged': "true",
                    'layout-class': "vertical-collpsed"
                };
            }
            setThemeConfig(layout)
        });
        // 3、图文菜单
        let layouts_compact_sidebar = $('#layouts-compact-sidebar')
        if ('checked' === layouts_compact_sidebar.attr('checked')) {
            layouts_compact_sidebar.prop('checked', true);
        }
        layouts_compact_sidebar.on("change", function (e) {
            let layout = {
                layouts: {'data-sidebar-size': ""},
                'layouts-compact-sidebar': false,
                'data-sidebar-size': ""
            };
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-sidebar-size': "small"},
                    'layouts-compact-sidebar': true,
                    'data-sidebar-size': "small"
                }
            }
            setThemeConfig(layout)
        });
        // 4、页顶菜单
        let topnav = $('#topnav')
        if ('checked' === topnav.attr('checked')) {
            topnav.prop('checked', true);
        }
        topnav.on("change", function (e) {
            let layout = {layouts: {}, 'topnav': false};
            if ($(e.target).prop('checked')) {
                layout = {layouts: {}, 'topnav': true}
            }
            setThemeConfig(layout)
        });

        // 布局
        // 1、水平布局
        let horizontal = $('#horizontal')
        if ('checked' === horizontal.attr('checked')) {
            horizontal.prop('checked', true);
        }
        horizontal.on("change", function (e) {
            let layout = {layouts: {'data-layout': ""}, 'horizontal': false, 'data-layout': ""};
            if ($(e.target).prop('checked')) {
                layout = {layouts: {'data-layout': "horizontal"}, 'horizontal': true, 'data-layout': "horizontal"}
            }
            setThemeConfig(layout)
        });
        // 2、水平顶黑
        let layouts_hori_topbar_dark = $('#layouts-hori-topbar-dark')
        if ('checked' === layouts_hori_topbar_dark.attr('checked')) {
            layouts_hori_topbar_dark.prop('checked', true);
        }
        layouts_hori_topbar_dark.on("change", function (e) {
            let layout = {layouts: {'data-layout': ""}, 'layouts-hori-topbar-dark': false, 'data-layout': ""};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-layout': "horizontal", 'data-topbar': 'dark'},
                    'layouts-hori-topbar-dark': true,
                    'data-topbar': 'dark',
                    'data-layout': "horizontal",
                }
            }
            setThemeConfig(layout)
        });
        // 3、水平盒子
        let layouts_hori_boxed_width = $('#layouts-hori-boxed-width')
        if ('checked' === layouts_hori_boxed_width.attr('checked')) {
            layouts_hori_boxed_width.prop('checked', true);
        }
        layouts_hori_boxed_width.on("change", function (e) {
            let layout = {
                layouts: {'data-layout': "", 'data-layout-size': ""},
                'data-layout': "",
                'layouts-hori-boxed-width': false,
                'data-layout-size': "",
            };
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {'data-layout': "horizontal", 'data-layout-size': "boxed"},
                    'layouts-hori-boxed-width': true,
                    'data-layout-size': "boxed",
                    'data-layout': "horizontal"
                }
            }
            setThemeConfig(layout)
        });
        // 4、水平盒子顶黑
        let vertical_collpsed_boxed = $('#vertical-collpsed-boxed')
        if ('checked' === vertical_collpsed_boxed.attr('checked')) {
            vertical_collpsed_boxed.prop('checked', true);
        }
        vertical_collpsed_boxed.on("change", function (e) {
            let layout = {
                layouts: {'data-layout': "", 'data-layout-size': ""},
                'vertical-collpsed-boxed': false,
                'topnav': false
            };
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {
                        'data-layout': "",
                        'data-layout-size': "boxed",
                        'data-keep-enlarged': "true",
                        class: "vertical-collpsed",
                    },
                    'vertical-collpsed-boxed': true,
                    'topnav': true,
                    'data-layout-size': "boxed",
                    'data-keep-enlarged': true,
                    'layout-class': "vertical-collpsed",
                    'data-layout': "",
                }
            }
            setThemeConfig(layout)
        });
        // 5、水平盒子菜单置顶
        let horizontal_menu_top = $('#horizontal_menu_top')
        if ('checked' === horizontal_menu_top.attr('checked')) {
            horizontal_menu_top.prop('checked', true);
        }
        horizontal_menu_top.on("change", function (e) {
            let layout = {layouts: {'data-layout': "", 'data-layout-size': ""}, 'horizontal_menu_top': false};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {
                        'data-topbar': "dark",
                        'data-layout': "horizontal"
                    },
                    'horizontal_menu_top': true,
                    'data-layout': "horizontal",
                    'data-topbar': "dark",
                }
            }
            setThemeConfig(layout)
        });
        // 6、紧凑布局
        let boxed = $('#boxed')
        if ('checked' === boxed.attr('checked')) {
            boxed.prop('checked', true);
        }
        boxed.on("change", function (e) {
            let layout = {layouts: {}, 'boxed': false};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {
                        'data-topbar': "dark",
                        'data-sidebar': "dark",
                        'data-keep-enlarged': "true",
                        class: "vertical-collpsed",
                        'data-layout-size': "boxed"
                    },
                    'boxed': true,
                    'data-layout-size': "boxed",
                    'layout-class': "vertical-collpsed",
                    'data-keep-enlarged': true,
                    'data-sidebar': "dark",
                    'data-topbar': "dark",
                }
            }
            setThemeConfig(layout)
        });
        // 7、水平紧凑布局
        let horizontal_boxed = $('#horizontal-boxed')
        if ('checked' === horizontal_boxed.attr('checked')) {
            horizontal_boxed.prop('checked', true);
        }
        horizontal_boxed.on("change", function (e) {
            let layout = {layouts: {}, 'horizontal-boxed': false};
            if ($(e.target).prop('checked')) {
                layout = {
                    layouts: {
                        'data-layout': "horizontal", 'data-layout-size': "boxed"
                    },
                    'horizontal-boxed': true,
                    'data-layout-size': "boxed",
                }
            }
            setThemeConfig(layout)
        });
    }

    async function setThemeConfig(layout, reload = true) {
        showLoading()
        $.ajax({
            url: window.url('/backend/theme-config/set'),
            data: JSON.stringify(layout),
            dataType: 'json',
            type: 'post',
            success: async res => {
                if ((200 === res.code) && reload) window.location.reload()
                hideLoading()
            }
        })
    }

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

    init();

})(jQuery)