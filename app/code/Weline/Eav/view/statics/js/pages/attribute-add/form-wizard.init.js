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
                const formData = form.serialize();
                const url = form.attr('action') + '?isAjax=true';
                const opts1 = { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: formData };
                showLoading();
                var req = window.Weline.Api.request(url, opts1);
                req.then(function(res) {
                    var response = (res && res.data) || res;
                    if (response && response['code'] === 1) {
                        form.append('<input type="hidden" name="selected" value="1"/>');
                        if ($total === $current + 1) {
                            form.find('input[name="progress"]').val('progress-submit');
                            var formDataNew = form.serialize();
                            var opts2 = { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: formDataNew };
                            var req2 = window.Weline.Api.request(url, opts2);
                            req2.then(function(res2) {
                                var resp2 = (res2 && res2.data) || res2;
                                hideLoading();
                                if (resp2 && resp2['code'] === 1) {
                                    Swal.fire({ title: __('温馨提示！'), text: resp2['msg'], icon: 'success', dangerMode: true, confirmButtonText: __('好的') }).then(function(result) {
                                        if (result.isConfirmed) window.parent.location.reload();
                                    });
                                } else {
                                    Swal.fire({ title: __('错误！'), text: (resp2 && resp2['msg']) || __('未知错误'), icon: 'error', dangerMode: true, confirmButtonText: __('好的') });
                                }
                            }).catch(function() {
                                hideLoading();
                                Swal.fire({ title: __('未知错误！'), text: __('请求失败'), icon: 'error', dangerMode: true, confirmButtonText: __('好的') });
                            });
                        } else {
                            hideLoading();
                            $('#progress-wizard').bootstrapWizard('next');
                        }
                    } else {
                        hideLoading();
                        Swal.fire({ title: __('错误！'), text: (response && response['msg']) || __('未知错误'), icon: 'error', dangerMode: true, confirmButtonText: __('好的') });
                    }
                }).catch(function() {
                    hideLoading();
                    Swal.fire({ title: __('未知错误！'), text: __('请求失败'), icon: 'error', dangerMode: true, confirmButtonText: __('好的') });
                });
                return false;
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



