/*
Template Name: Weline -  Admin & WelineFramework
Author: 秋枫雁飞(aiweline)
Contact: 秋枫雁飞(aiweline) 1714255949@qq.com
File: Form wizard Js File
*/

$(document).ready(function () {
    $('#basic-pills-wizard').bootstrapWizard({
        'tabClass': 'nav nav-pills nav-justified'
    });

    $('#progress-wizard').bootstrapWizard({
        onInit: function (tab, navigation, index) {
            // var triggerTabList = [].slice.call(document.querySelectorAll('.twitter-bs-wizard-nav .nav-link'))
            // triggerTabList.forEach(function (triggerEl) {
            //     var tabTrigger = new bootstrap.Tab(triggerEl)
            //     triggerEl.addEventListener('click', function (event) {
            //         event.preventDefault()
            //         tabTrigger.show()
            //     })
            // })
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
                next.find('a').text(__('提交'));
            }else{
                next.find('a').text(__('下一步'));
            }
        },
        onNext: function (tab, navigation, index) {
            var $total = navigation.find('li').length;
            const $current = index;
            let tab_id = $(tab.find('a').get(0)).attr('href')
            let form = $(tab_id).find('form')
            let validate_status = true;
            if(form.length > 0){
                validate_status = form.get(0).reportValidity()
            }
            if (validate_status) {
                // ajax提交
                // 获取表单数据并使用AJAX提交
                // 获取表单数据
                const formData = form.serialize();
                showLoading()
                let ajaxResult = false
                // 发送AJAX请求
                $.ajax({
                    type: 'POST',
                    url: form.attr('action') + '?isAjax=true',
                    data: formData,
                    async: false,
                    success: function (response) {
                        // 成功时的处理
                        if (response['code'] === 1) {
                            if ($total === $current) {
                                // 继续修改提交 保存
                                form.find('input[name="progress"]').val('progress-submit')
                                // 获取表单数据
                                const formDataNew = form.serialize();
                                $.ajax({
                                    type: 'POST',
                                    url: form.attr('action') + '?isAjax=true',
                                    data: formDataNew,
                                    async: false,
                                    success: function (response) {
                                        // 成功时的处理
                                        if (response['code'] === 1) {
                                            Swal.fire(
                                                {
                                                    title: __('温馨提示！'),
                                                    text: response['msg'],
                                                    icon: 'success',
                                                    dangerMode: true,
                                                    confirmButtonText: __('好的')
                                                }
                                            ).then(function(result){
                                                if(result.isConfirmed){
                                                    window.parent.location.reload()
                                                }
                                            })
                                        } else {
                                            Swal.fire(
                                                {
                                                    title: __('错误！'),
                                                    text: response['msg'],
                                                    icon: 'error',
                                                    dangerMode: true,
                                                    confirmButtonText: __('好的')
                                                }
                                            )
                                        }
                                        hideLoading()
                                    },
                                    error: function () {
                                        // 失败时的处理
                                        Swal.fire(
                                            {
                                                title: __('未知错误！'),
                                                text: response['msg'],
                                                icon: 'error',
                                                dangerMode: true,
                                                confirmButtonText: __('好的')
                                            }
                                        )
                                        hideLoading()
                                    }
                                });
                                // // 提示
                                // Swal.fire(
                                //     {
                                //         title: __('温馨提示！'),
                                //         text: response['msg'],
                                //         icon: 'success',
                                //         dangerMode: true,
                                //         confirmButtonText: __('好的')
                                //     }
                                // )
                            }
                            // 选中
                            form.append('<input type="hidden" name="selected" value="1"/>')
                            ajaxResult = true;
                        } else {
                            Swal.fire(
                                {
                                    title: __('错误！'),
                                    text: response['msg'],
                                    icon: 'error',
                                    dangerMode: true,
                                    confirmButtonText: __('好的')
                                }
                            )
                            ajaxResult = false;
                        }
                        hideLoading()
                    },
                    error: function () {
                        // 失败时的处理
                        Swal.fire(
                            {
                                title: __('未知错误！'),
                                text: response['msg'],
                                icon: 'error',
                                dangerMode: true,
                                confirmButtonText: __('好的')
                            }
                        )
                        hideLoading()
                    }
                });
                return ajaxResult;
            } else {
                return false;
            }
        },
        onTabClick: function (activeTab, navigation, currentIndex, nextIndex) {
        }
    });

});

// Active tab pane on nav link

var triggerTabList = [].slice.call(document.querySelectorAll('.twitter-bs-wizard-nav .nav-link'))
triggerTabList.forEach(function (triggerEl) {
    var tabTrigger = new bootstrap.Tab(triggerEl)
    triggerEl.addEventListener('click', function (event) {
        event.preventDefault()
        tabTrigger.show()
    })
})



