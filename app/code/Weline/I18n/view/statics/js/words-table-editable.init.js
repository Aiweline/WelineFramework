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
            try {
                if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                    throw new Error(__('Weline.Api 不可用'));
                }
                const api = await Promise.resolve(window.Weline.Api.resource('i18n_admin'));
                const res = await api.action({
                    action: 'word-translate',
                    payload: data
                }, {silent: true});
                if (!res || res.success !== true) {
                    throw new Error((res && (res.message || res.msg)) || __('保存失败'));
                }
                await Swal.fire({
                    title: __('提示'),
                    text: res.message || __('保存成功'),
                    icon: 'success',
                });
            } catch (error) {
                Swal.fire({
                    title: __('提示'),
                    text: error && error.message ? error.message : __('保存失败'),
                    icon: 'error',
                });
            } finally {
                hideLoading();
            }
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
