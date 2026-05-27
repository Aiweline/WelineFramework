/*
Template Name: Weline - Admin & WelineFramework
File: Sweetalert Js File
*/

!function ($) {
    "use strict";

    var SweetAlert = function () {
    };

    SweetAlert.prototype.init = function () {
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
                if (!result.value) {
                    return;
                }

                var id = $(e.target).attr('data-id');
                if (!id) {
                    return;
                }

                var body = new URLSearchParams();
                body.append('id', id);

                window.Weline.Api.request(window.url('/menus/delete'), {
                    method: 'POST',
                    body: body
                }).then(function (res) {
                    if (typeof res === 'string') {
                        res = JSON.parse(res);
                    }
                    var success = 200 === res.code || res.success === true;
                    Swal.fire({
                        title: __("删除!"),
                        text: res.msg || res.message || (success ? __('删除成功') : __('删除失败')),
                        icon: success ? "success" : "error",
                        confirmButtonColor: success ? "#1cbb8c" : "rgba(255,69,0,0.76)",
                        confirmButtonText: __("知道了")
                    });
                    window.location.reload();
                }).catch(function (error) {
                    Swal.fire({
                        title: __("删除!"),
                        text: error && error.message ? error.message : __("操作失败"),
                        icon: "error",
                        confirmButtonColor: "rgba(255,69,0,0.76)",
                        confirmButtonText: __("知道了")
                    });
                });
            });
        });
    };

    $.SweetAlert = new SweetAlert;
    $.SweetAlert.Constructor = SweetAlert;
}(window.jQuery);

!function ($) {
    "use strict";
    $.SweetAlert.init();
}(window.jQuery);
