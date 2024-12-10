/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Sweetalert Js File
*/

!function ($) {
    "use strict";

    var SweetAlert = function () {
    };

    //examples
    SweetAlert.prototype.init = function () {
        // FIXME 暂时不优化:根据主题背景切换背景
        let theme_color = ''
        if ('dark-mode-switch' === sessionStorage.getItem('is_visited')) {
            theme_color = '#1d222e'
        }
        //菜单删除警告
        $('.menu-delete').click(function (e) {
            Swal.fire({
                title: __('确定删除该菜单吗？'),
                text: __('删除后不可恢复！'),
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#1cbb8c",
                cancelButtonColor: "#ff3d60",
                cancelButtonText: __('取消'),
                confirmButtonText: __('确定'),
            }).then(function (result) {
                if (result.value) {
                    let id = $(e.target).attr('data-id')
                    if (id) {
                        $.ajax(
                            {
                                url: window.url('/menus/delete'),
                                type: 'post',
                                data: {
                                    id: id
                                },
                                success: function (res) {
                                    res = JSON.parse(res)
                                    if (200 === res.code) {
                                        Swal.fire({
                                            title: __("删除!"),
                                            text: res.msg,
                                            icon: "success",
                                            confirmButtonColor: "#1cbb8c",
                                            confirmButtonText: __("知道了")
                                        })
                                    } else {
                                        Swal.fire({
                                            title: __("删除!"),
                                            text: res.msg,
                                            icon: "error",
                                            confirmButtonColor: "rgba(255,69,0,0.76)",
                                            confirmButtonText: __("知道了")
                                        })
                                    }
                                    window.location.reload()
                                },
                                error: function (res) {
                                    res = JSON.parse(res)
                                    Swal.fire({
                                        title: __("删除!"),
                                        text: res.msg,
                                        icon: "error",
                                        confirmButtonColor: "rgba(255,69,0,0.76)",
                                        confirmButtonText: __("知道了")
                                    })
                                }
                            }
                        )
                    }
                }
            });
        });


    },
        //init
        $.SweetAlert = new SweetAlert, $.SweetAlert.Constructor = SweetAlert
}(window.jQuery),

//initializing
    function ($) {
        "use strict";
        $.SweetAlert.init()
    }(window.jQuery);