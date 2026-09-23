window.WelineWidgetAssets.register('theme-social-social-share-default-0', function (widgetScript) {
(function () {
    var root = document.querySelector(('[data-uid="' + widgetScript.dataset.v0 + '"]'));
    if (!root) return;

    var shareMessages = {
        wechatQr: JSON.parse(widgetScript.dataset.v1 || 'null')
    };

    var pageUrl = encodeURIComponent(window.location.href);
    var pageTitle = encodeURIComponent(document.title);

    function showShareNotice(message, type) {
        if (window.Weline && window.Weline.UI.toast && typeof window.Weline.UI.toast.show === 'function') {
            window.Weline.UI.toast.show(message, {tone: type || 'info'});
            return;
        }
        var status = root.querySelector('.share-status');
        if (status) {
            status.textContent = message;
            return;
        }
        console.info(message);
    }

    root.querySelectorAll('.share-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = this.dataset.action;
            var url = this.dataset.url;

            if (action === 'copy') {
                navigator.clipboard.writeText(window.location.href).then(function () {
                    btn.dataset.state = 'copied';
                    setTimeout(function () { btn.dataset.state = 'idle'; }, 2000);
                });
                return;
            }

            if (action === 'qrcode') {
                showShareNotice(shareMessages.wechatQr, 'info');
                return;
            }

            url = url.replace('{url}', pageUrl).replace('{title}', pageTitle);
            window.open(url, '_blank', 'width=600,height=400');
        });
    });
})();
});
