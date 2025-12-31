/**
 * AutoLeadAgent 配置页 - 实用工具函数
 */

var ConfigUtils = (function () {
    'use strict';

    /**
     * 安全的 Toast 提示
     */
    function safeToast(type, message, duration) {
        try {
            duration = duration || 3000;
            if (typeof window.showToast === 'function') {
                window.showToast(type, message, duration);
                return;
            }
            if (typeof Toastify !== 'undefined') {
                Toastify({
                    text: message,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: type === 'success' ? '#28a745' : (type === 'warning' ? '#ffc107' : '#dc3545')
                }).showToast();
                return;
            }
            if (typeof toastr !== 'undefined' && typeof toastr[type] === 'function') {
                toastr[type](message);
                return;
            }
        } catch (e) {
            // ignore
        }
        console.log('[Toast]', type, ':', message);
    }

    /**
     * 格式化数字
     */
    function formatNumber(num) {
        if (num === null || num === undefined || isNaN(num)) return '0';
        num = Number(num);
        if (num >= 1000000) return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
        return String(num);
    }

    /**
     * 格式化文件大小
     */
    function formatFileSize(bytes) {
        if (!bytes || bytes === 0 || isNaN(bytes)) return '-';
        bytes = Number(bytes);
        var mb = bytes / 1024 / 1024;
        if (mb >= 1024) return (mb / 1024).toFixed(2).replace(/\.0+$/, '') + ' GB';
        return mb.toFixed(1).replace(/\.0+$/, '') + ' MB';
    }

    /**
     * 从 MB 格式化文件大小
     */
    function formatFileSizeFromMB(mb) {
        if (!mb || mb === 0 || isNaN(mb)) return '-';
        mb = Number(mb);
        if (mb >= 1024) return (mb / 1024).toFixed(2).replace(/\.0+$/, '') + ' GB';
        return mb.toFixed(1).replace(/\.0+$/, '') + ' MB';
    }

    /**
     * 获取基础配置 URL
     */
    function getBaseConfigUrl() {
        var path = window.location.pathname || '';
        return path.replace(/\/index[^\/]*$/, '');
    }

    /**
     * 构建带有查询参数的 URL
     */
    function buildUrl(action, params) {
        var url = getBaseConfigUrl() + '/' + action;
        if (params && typeof params === 'object') {
            var qs = new URLSearchParams(params).toString();
            if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
        }
        return url;
    }

    return {
        safeToast: safeToast,
        formatNumber: formatNumber,
        formatFileSize: formatFileSize,
        formatFileSizeFromMB: formatFileSizeFromMB,
        getBaseConfigUrl: getBaseConfigUrl,
        buildUrl: buildUrl
    };
})();

// 导出到全局
if (typeof window !== 'undefined') {
    window.ConfigUtils = ConfigUtils;
    window.safeToast = ConfigUtils.safeToast;
}
