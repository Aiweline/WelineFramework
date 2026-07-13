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
        //恢复警告
        $('.word-restore').click(function (e) {
            Swal.fire({
                title: __('确定恢复该词典么？'),
                text: __('确认后，翻译部分将恢复至翻译前！'),
                icon: "warning",
                showCancelButton: true,
                confirmButtonColor: "#1cbb8c",
                cancelButtonColor: "#ff3d60",
                cancelButtonText: __('取消'),
                confirmButtonText: __('确定'),
            }).then(function (result) {
                if (result.value) {
                    let md5 = $(e.target).attr('data-md5')
                    let code = $(e.target).attr('data-code')
                    let country_code = $(e.target).attr('data-country-code')
                    if (code && md5 && country_code) {
                        Promise.resolve(window.Weline.Api.resource('i18n_admin'))
                            .then(function (api) {
                                return api.action({
                                    action: 'word-restore',
                                    payload: {
                                        md5: md5,
                                        code: code,
                                        country_code: country_code,
                                    }
                                }, {silent: true});
                            })
                            .then(function (res) {
                                if (!res || res.success !== true) {
                                    throw new Error((res && (res.message || res.msg)) || __('恢复失败'));
                                }
                                return Swal.fire({
                                    title: __("恢复结果通知!"),
                                    text: res.message || res.msg || __("恢复成功！"),
                                    icon: "success",
                                    confirmButtonColor: "#1cbb8c",
                                    confirmButtonText: __("知道了")
                                }).then(function () {
                                    $('#words-table').find('td[data-md5="' + md5 + '"]').text(res.data || '');
                                });
                            })
                            .catch(function (error) {
                                Swal.fire({
                                    title: __("恢复结果通知!"),
                                    text: error && error.message ? error.message : __("恢复失败"),
                                    icon: "error",
                                    confirmButtonColor: "rgba(255,69,0,0.76)",
                                    confirmButtonText: __("知道了")
                                });
                            });
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
