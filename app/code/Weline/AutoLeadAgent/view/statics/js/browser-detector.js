/**
 * 浏览器检测工具
 * 用于检测浏览器类型和内核，判断是否为 Chrome 浏览器
 */

var BrowserDetector = (function () {
    'use strict';

    /**
     * 检测浏览器类型和内核
     * @returns {Object} 浏览器信息对象
     */
    function detectBrowser() {
        const userAgent = navigator.userAgent;
        const browserInfo = {
            name: 'Unknown',
            version: 'Unknown',
            engine: 'Unknown',
            isChrome: false,
            isChromeCompatible: false,
        };

        // 检测 Chrome
        if (userAgent.includes('Chrome') && 
            !userAgent.includes('Edg') && 
            !userAgent.includes('OPR') && 
            !(userAgent.includes('Safari') && !userAgent.includes('Chrome'))) {
            browserInfo.name = 'Chrome';
            browserInfo.isChrome = true;
            
            // 提取 Chrome 版本
            const chromeMatch = userAgent.match(/Chrome\/(\d+)/);
            if (chromeMatch) {
                browserInfo.version = chromeMatch[1];
            }
            
            // 检测 Chrome 兼容性（Extension API 和 Built-in AI）
            browserInfo.isChromeCompatible = isChromeCompatible();
        }
        // 检测 Edge
        else if (userAgent.includes('Edg')) {
            browserInfo.name = 'Edge';
            const edgeMatch = userAgent.match(/Edg\/(\d+)/);
            if (edgeMatch) {
                browserInfo.version = edgeMatch[1];
            }
        }
        // 检测 Firefox
        else if (userAgent.includes('Firefox')) {
            browserInfo.name = 'Firefox';
            const firefoxMatch = userAgent.match(/Firefox\/(\d+)/);
            if (firefoxMatch) {
                browserInfo.version = firefoxMatch[1];
            }
        }
        // 检测 Safari
        else if (userAgent.includes('Safari') && !userAgent.includes('Chrome')) {
            browserInfo.name = 'Safari';
            const safariMatch = userAgent.match(/Version\/(\d+)/);
            if (safariMatch) {
                browserInfo.version = safariMatch[1];
            }
        }
        // 检测 Opera
        else if (userAgent.includes('OPR')) {
            browserInfo.name = 'Opera';
            const operaMatch = userAgent.match(/OPR\/(\d+)/);
            if (operaMatch) {
                browserInfo.version = operaMatch[1];
            }
        }

        // 检测浏览器内核
        if (userAgent.includes('WebKit')) {
            browserInfo.engine = 'WebKit';
        } else if (userAgent.includes('Gecko')) {
            browserInfo.engine = 'Gecko';
        } else if (userAgent.includes('Trident')) {
            browserInfo.engine = 'Trident';
        }

        return browserInfo;
    }

    /**
     * 判断是否为 Chrome 浏览器
     * @returns {boolean} 是否为 Chrome
     */
    function isChrome() {
        const userAgent = navigator.userAgent;
        // Chrome 浏览器：包含 'Chrome' 但不包含 'Edg'（Edge）、'OPR'（Opera）、'Safari'（Safari）
        return userAgent.includes('Chrome') && 
               !userAgent.includes('Edg') && 
               !userAgent.includes('OPR') && 
               !(userAgent.includes('Safari') && !userAgent.includes('Chrome'));
    }

    /**
     * 判断是否兼容 Chrome 功能
     * @returns {boolean} 是否兼容（Chrome Extension API 和 Chrome Built-in AI）
     */
    function isChromeCompatible() {
        // 检查 Chrome Extension API
        const hasChromeAPI = typeof chrome !== 'undefined' && 
                            chrome.runtime && 
                            chrome.tabs && 
                            chrome.scripting;
        
        // 检查 Chrome Built-in AI（可选，但推荐）
        const hasChromeAI = typeof window !== 'undefined' && 
                           window.ai && 
                           typeof window.ai.createTextSession === 'function';
        
        return isChrome() && hasChromeAPI;
    }

    /**
     * 获取浏览器详细信息
     * @returns {Object} 浏览器详细信息
     */
    function getBrowserInfo() {
        const browserInfo = detectBrowser();
        
        return {
            ...browserInfo,
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            language: navigator.language,
            languages: navigator.languages || [],
            cookieEnabled: navigator.cookieEnabled,
            onLine: navigator.onLine,
        };
    }

    /**
     * 显示浏览器不兼容提示
     * @param {Object} options 选项
     * @param {string} options.title 弹窗标题
     * @param {string} options.message 提示消息
     * @param {Function} options.onClose 关闭回调
     */
    function showIncompatibleDialog(options) {
        options = options || {};
        const title = options.title || '浏览器不兼容';
        const message = options.message || '自动寻客功能需要在 Chrome 浏览器中运行';
        const onClose = options.onClose || function() {};

        const browserInfo = getBrowserInfo();
        const browserName = browserInfo.name;
        const browserVersion = browserInfo.version;

        // 创建模态框
        const modal = document.createElement('div');
        modal.className = 'modal fade show';
        modal.style.display = 'block';
        modal.style.backgroundColor = 'rgba(0, 0, 0, 0.5)';
        modal.setAttribute('tabindex', '-1');
        modal.setAttribute('role', 'dialog');

        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">
                            <i class="mdi mdi-alert-circle"></i> ${title}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>${message}</p>
                        <p class="text-muted">
                            <strong>当前浏览器：</strong>${browserName} ${browserVersion}
                        </p>
                        <p class="text-muted">
                            请使用 Chrome 浏览器访问此页面以使用自动寻客功能。
                        </p>
                    </div>
                    <div class="modal-footer">
                        <a href="https://www.google.com/chrome/" target="_blank" class="btn btn-primary">
                            <i class="mdi mdi-download"></i> 下载 Chrome
                        </a>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="mdi mdi-check"></i> 我知道了
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // 绑定关闭事件
        const closeButtons = modal.querySelectorAll('[data-bs-dismiss="modal"], .btn-close, .btn-secondary');
        closeButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                modal.remove();
                if (typeof onClose === 'function') {
                    onClose();
                }
            });
        });

        // 点击背景关闭
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
                if (typeof onClose === 'function') {
                    onClose();
                }
            }
        });

        return modal;
    }

    // 导出公共 API
    return {
        detectBrowser: detectBrowser,
        isChrome: isChrome,
        isChromeCompatible: isChromeCompatible,
        getBrowserInfo: getBrowserInfo,
        showIncompatibleDialog: showIncompatibleDialog,
    };

})();

// 自动检测并导出到全局（如果不在模块环境中）
if (typeof window !== 'undefined') {
    window.BrowserDetector = BrowserDetector;
}

