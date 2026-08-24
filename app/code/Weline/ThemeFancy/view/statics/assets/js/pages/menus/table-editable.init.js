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
        /*选择数据*/
         dropdowns: {
             is_system: ['0', '1']
           },
        edit: function (values) {
            $(".edit i", this)
                .removeClass('fa-pencil-alt')
                .addClass('fa-save')
                .attr('title', '保存');
        },
        save: async function (values) {
            $(".edit i", this)
                .removeClass('fa-save')
                .addClass('fa-pencil-alt')
                .attr('title', '编辑');

            if (this in pickers) {
                pickers[this].destroy();
                delete pickers[this];
            }
            let data = {};
            let tds = $(this).find('td')
            for (let i = 0; i < tds.length; i++) {
                let i_data_field = $(tds[i]).attr('data-field')
                if (i_data_field) {
                    data[i_data_field] = $(tds[i]).text()
                }
            }
            showLoading();
            $.ajax({
                url: window.site.buildUrl('system/menus/save'),
                type: 'post',
                dataType:'json',
                data: JSON.stringify(data),
                success: async (res) => {
                    console.log(res);
                }, error: (res) => {
                    console.log(res)
                }
            })
            hideLoading();
        },
        cancel: function (values) {
            $(".edit i", this)
                .removeClass('fa-save')
                .addClass('fa-pencil-alt')
                .attr('title', '编辑');

            if (this in pickers) {
                pickers[this].destroy();
                delete pickers[this];
            }
        }
    });
});