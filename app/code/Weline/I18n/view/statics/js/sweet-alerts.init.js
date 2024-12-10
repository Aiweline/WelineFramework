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
                        $.ajax(
                            {
                                url: window.url('*/backend/countries/locale/words/restore'),
                                type: 'post',
                                data: {
                                    md5: md5,
                                    code: code,
                                    country_code: country_code,
                                },
                                success: function (res) {
                                    if (200 === res.code) {
                                        Swal.fire({
                                            title: __("恢复结果通知!"),
                                            text: res.msg,
                                            icon: "success",
                                            confirmButtonColor: "#1cbb8c",
                                            confirmButtonText: __("知道了")
                                        })
                                        console.log($(e.target).parent().parent())
                                        $('#words-table').find('td[data-md5="' + md5 + '"]').text(res.data)
                                    } else {
                                        Swal.fire({
                                            title: __("恢复结果通知!"),
                                            text: res.msg,
                                            icon: "error",
                                            confirmButtonColor: "rgba(255,69,0,0.76)",
                                            confirmButtonText: __("知道了")
                                        })
                                    }
                                },
                                error: function (res) {
                                    res = JSON.parse(res)
                                    Swal.fire({
                                        title: __("恢复结果通知!"),
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