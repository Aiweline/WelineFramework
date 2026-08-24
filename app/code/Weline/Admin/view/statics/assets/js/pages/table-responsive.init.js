/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Table responsive Init Js File
*/

$(function () {
    // 检查 responsiveTable 函数是否存在，以及是否有 .table-responsive 元素
    if (typeof $.fn.responsiveTable !== 'undefined' && $('.table-responsive').length > 0) {
        $('.table-responsive').responsiveTable({
            addDisplayAllBtn: 'btn btn-secondary',
            i18n: {focus: '聚焦', display: '展示', displayAll: '全部展示'}
        });
    }
    // 修复 Bootstrap 5 的 dropdown 属性
    if ($('.btn-toolbar [data-toggle=dropdown]').length > 0) {
        $('.btn-toolbar [data-toggle=dropdown]').attr('data-bs-toggle', "dropdown");
    }
});