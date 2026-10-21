/**
 * 媒体管理器启动器（Weline backend theme §6.1）
 *
 * 模板只输出 <script type="application/json" id="mmf-bootstrap-config"> 配置；
 * 本文件在 data-weline-load="mediaManagerBoot" 时被 Weline.load 异步加载，
 * 读取配置后调用 WelineMediaManager.init（manager.js 以 defer 先于 DOMContentLoaded 执行）。
 */
(function () {
    'use strict';

    function readConfig() {
        var el = document.getElementById('mmf-bootstrap-config');
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent || '{}');
        } catch (error) {
            return null;
        }
    }

    function run(cfg) {
        if (!window.WelineMediaManager || typeof window.WelineMediaManager.init !== 'function') {
            return false;
        }
        window.WelineMediaManager.init(
            cfg.connectorUrl || '',
            cfg.startPath || '',
            cfg.options || {}
        );
        return true;
    }

    function init() {
        var cfg = readConfig();
        if (!cfg) {
            return;
        }
        if (run(cfg)) {
            return;
        }
        var tries = 0;
        var timer = window.setInterval(function () {
            tries += 1;
            if (run(cfg) || tries > 200) {
                window.clearInterval(timer);
            }
        }, 50);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    window.WelineMediaManagerBoot = { init: init };
})();
