/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Table responsive Init Js File
*/

$(function() {
    $('.table-responsive').responsiveTable({
        addDisplayAllBtn: 'btn btn-secondary',
        i18n:{ focus : '聚焦', display : '展示', displayAll: '全部展示' }
    });
    $('.btn-toolbar [data-toggle=dropdown]').attr('data-bs-toggle', "dropdown");
});