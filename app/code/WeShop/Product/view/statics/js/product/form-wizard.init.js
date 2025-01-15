/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Form wizard Js File
*/
$(document).ready(async function () {
    $('#basic-pills-wizard').bootstrapWizard({
        'tabClass': 'nav nav-pills nav-justified'
    });
    const activeStep = $('.twitter-bs-wizard-nav .nav-link.active')
    $('#progress-wizard').bootstrapWizard({
        onInit: function (tab, navigation, index) {
            var triggerTabList = [].slice.call(document.querySelectorAll('.twitter-bs-wizard-nav .nav-link'))
            triggerTabList.forEach(function (triggerEl) {
                var tabTrigger = new bootstrap.Tab(triggerEl)
                triggerEl.addEventListener('click', function (event) {
                    event.preventDefault()
                    tabTrigger.show()
                })
            })
        },
        onTabShow: function (tab, navigation, index) {
            var $total = navigation.find('li').length;
            var $current = index + 1;
            var $percent = ($current / $total) * 100;
            $('#progress-wizard').find('.progress-bar').css({width: $percent + '%'});
            // 如果是最后一页
            let next = $('.next')
            if ($total === $current) {
                next.removeClass('disabled');
                // 检测属性
                next.find('a').text(__('提交'));
            } else {
                next.find('a').text(__('下一步'));
            }
        },
        onNext: function (tab, navigation, index) {
            let total = navigation.find('li').length;
            let current = index;
            // let tab_id = $(tab.find('a').get(0)).attr('href')
            if (total === current) {
                // 读取configurableProducts表格中的产品信息
                let configurableProducts = [];
                let rows = $('#configurableProducts').find('tbody').find('tr');
                for (let i = 0; i < rows.length; i++) {
                    let product_id = $(rows[i]).find('td[data-field="product_id"]').text();
                    let image = $(rows[i]).find('td[data-field="image"]').text();
                    let name = $(rows[i]).find('td[data-field="name"]').text();
                    let price = $(rows[i]).find('td[data-field="price"]').text();
                    let sku = $(rows[i]).find('td[data-field="sku"]').text();
                    let stock = $(rows[i]).find('td[data-field="stock"]').text();
                    configurableProducts.push({
                        id: product_id,
                        image: image,
                        name: name,
                        sku: sku,
                        price: price,
                        stock: stock
                    })
                }
                $('#configurableProductItems').val(JSON.stringify(configurableProducts));
                $('#productForm').submit()
            }
        },
        onTabClick: function (activeTab, navigation, currentIndex, nextIndex) {
        }
    });
    $('#progress-wizard').bootstrapWizard('show', activeStep.data('index'));
});

// Active tab pane on nav link

// var triggerTabList = [].slice.call(document.querySelectorAll('.twitter-bs-wizard-nav .nav-link'))
// triggerTabList.forEach(function (triggerEl) {
//     var tabTrigger = new bootstrap.Tab(triggerEl)
//     triggerEl.addEventListener('click', function (event) {
//         event.preventDefault()
//         tabTrigger.show()
//     })
// })



