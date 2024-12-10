function checkEnv(type, ele, errorFlag = '✖') {
    // 插入console
    let formData = $('#' + ele).serialize()
    $.ajax({
        url: '/setup/installer.php?action=' + type,
        type: 'POST',
        data: formData,
        dataType: 'json',
        success: function (json) {
            console.log("收到：" + JSON.stringify(json));
            let consoleContainer = $('#' + ele + ' .console');
            consoleContainer.append('Env: ' + json.msg + '<br>')
            consoleContainer.css('transform', 'skew(16deg, -2deg)')
            consoleContainer.css('box-shadow', '1px 45px 45px #252729');
            for (let i in json.data) {
                let value = json.data[i];
                let val = $.trim(value.value);
                if (val.indexOf(errorFlag) !== -1) {
                    consoleContainer.append('Env: ' + value.name + '=><b style="color: red">' + value.value + '</b><br>')
                } else {
                    consoleContainer.append('Env:' + value.name + '=><b style="color: darkgreen">' + value.value + '</b><br>')
                }
                consoleContainer[0].scrollTop = consoleContainer[0].scrollHeight
            }
            let next_btn = $('#next');
            console.log(json)
            if (json.hasErr) {
                next_btn.hide()
            } else {
                next_btn.addClass('next-button-active')
                next_btn.css({display: 'block'})
            }
        },

        error: function (XMLHttpRequest, textStatus, errorThrown) {
            console.log('提交请求的错误信息：' + JSON.stringify(errorThrown) + JSON.stringify(XMLHttpRequest));
        }
    });
}

