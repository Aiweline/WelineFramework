/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Table editable Init Js File
*/
$(function () {
    var pickers = {};
    let table_edit = $('.table-edits tr');
    table_edit.editable({
        edit: function (values) {
            $(".edit i", this)
                .removeClass('fa-pencil-alt')
                .addClass('fa-save')
                .attr('title', __('保存'));
        },
        save: async function (values) {
            $(".edit i", this)
                .removeClass('fa-save')
                .addClass('fa-pencil-alt')
                .attr('title', __('编辑'));

            if (this in pickers) {
                pickers[this].destroy();
                delete pickers[this];
            }
            let data = {};
            let tds = $(this).find('td')
            for (let i = 0; i < tds.length; i++) {
                let i_data_field = $(tds[i]).attr('data-field')
                if (i_data_field) {
                    data[i_data_field] = $(tds[i]).text().replace(/^\s*|\s*$/g, '')
                    data['word'] = $(tds[i]).attr('data-word')
                    data['code'] = $(tds[i]).attr('data-code')
                    data['country_code'] = $(tds[i]).attr('data-country-code')
                    data['md5'] = $(tds[i]).attr('data-md5')
                }
            }
            showLoading();
            $.ajax({
                url: window.url('*/backend/countries/locale/words/translate'),
                type: 'post',
                dataType: 'json',
                data: data,
                success: async (res) => {
                    if (res.code !== 200) {
                        // 使用sweetalert2提示
                        Swal.fire({
                            title: __('提示'),
                            text: res.msg,
                            icon: 'error',
                        });
                    } else {
                        Swal.fire({
                            title: __('提示'),
                            text: res.msg,
                            icon: 'success',
                        });
                    }
                }, error: (res) => {
                    console.log(res)
                    // 使用sweetalert2提示
                    Swal.fire({
                        title: __('提示'),
                        text: __('保存失败'),
                        icon: 'error',
                    });
                }
            })
            hideLoading();
        },
        cancel: function (values) {
            $(".edit i", this)
                .removeClass('fa-save')
                .addClass('fa-pencil-alt')
                .attr('title', __('编辑'));

            if (this in pickers) {
                pickers[this].destroy();
                delete pickers[this];
            }
        }
    });
});