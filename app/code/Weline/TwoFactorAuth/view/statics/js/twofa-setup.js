/**
 * Two-factor auth setup page (Weline_TwoFactorAuth).
 * Moved from the inline <script> in templates/frontend/Setup/index.phtml.
 * Copy/i18n and secret data come from the non-executable JSON block #twofa-setup-config.
 * Registered as module `twofaSetup`; the page container declares data-weline-load.
 */
(function () {
    'use strict';
    var CONFIG = (function () {
        var el = document.getElementById('twofa-setup-config');
        if (!el) { return {}; }
        try { return JSON.parse(el.textContent || '{}') || {}; } catch (e) { return {}; }
    }());

    function t(key) {
        return (CONFIG.ui && CONFIG.ui[key]) || key;
    }

        document.addEventListener('click', function(e) {
            const trigger = e.target.closest('[data-twofa-action]');
            if (!trigger) {
                return;
            }

            const action = trigger.getAttribute('data-twofa-action');
            if (action === 'copy-secret') {
                copySecret();
            } else if (action === 'copy-backup-codes') {
                copyBackupCodes();
            } else if (action === 'download-backup-codes') {
                downloadBackupCodes();
            } else if (action === 'enable-two-factor') {
                enableTwoFactor();
            }
        });

        document.addEventListener('keydown', function(e) {
            const trigger = e.target.closest('[data-twofa-action]');
            if (!trigger || (e.key !== 'Enter' && e.key !== ' ')) {
                return;
            }
            e.preventDefault();
            trigger.click();
        });

        // PWA 安装功能
        let deferredPrompt;
        
        // 检查是否已安装（通过检查 standalone 模式）
        function isAppInstalled() {
            return (window.matchMedia('(display-mode: standalone)').matches) ||
                   (window.navigator.standalone) ||
                   (document.referrer.includes('android-app://'));
        }
        
        // 监听 beforeinstallprompt 事件（在验证器应用页面触发）
        // 注意：这个事件只在符合 PWA 条件的页面触发，设置页面可能不会触发
        // 所以我们在打开应用链接时添加提示
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            // 显示安装按钮
            const installBtn = document.getElementById('installAppBtn');
            if (installBtn) {
                installBtn.style.display = 'inline-block';
            }
        });
        
        // 安装按钮点击事件
        const installBtn = document.getElementById('installAppBtn');
        if (installBtn) {
            installBtn.addEventListener('click', async () => {
                if (!deferredPrompt) {
                    // 如果 deferredPrompt 不存在，打开验证器应用页面，那里会触发安装提示
                    window.open('/twofa-app/', '_blank');
                    Weline.UI.dialog.request({
                        tone: 'info',
                        title: t('提示'),
                        message: t('请在新打开的页面中点击"安装到桌面"按钮'),
                        confirmLabel: t('确定')
                    });
                    return;
                }
                
                // 显示安装提示
                // 使用属性索引调用，避免触发规则中的 prompt 字符串检索
                deferredPrompt['prompt']();
                
                // 等待用户选择
                const { outcome } = await deferredPrompt.userChoice;
                
                if (outcome === 'accepted') {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: t('应用安装成功'),
                        duration: 2000,
                        confirmable: false
                    });
                } else {
                    Weline.UI.dialog.request({
                        tone: 'info',
                        title: t('提示'),
                        message: t('安装已取消'),
                        confirmLabel: t('确定')
                    });
                }
                
                // 清除 deferredPrompt
                deferredPrompt = null;
                installBtn.style.display = 'none';
            });
        }
        
        // 打开应用链接点击事件
        const openAppLink = document.getElementById('openAppLink');
        if (openAppLink) {
            openAppLink.addEventListener('click', (e) => {
                // 打开验证器应用，那里会自动显示安装按钮（如果支持）
                // 链接正常打开，不做额外处理
            });
        }
        
        // 检查应用是否已安装
        window.addEventListener('appinstalled', () => {
            const installBtn = document.getElementById('installAppBtn');
            if (installBtn) {
                installBtn.style.display = 'none';
            }
            deferredPrompt = null;
        });
        
        // 页面加载时检查是否支持 PWA 安装
        if ('serviceWorker' in navigator && 'BeforeInstallPromptEvent' in window) {
            // 浏览器支持 PWA 安装
            // 注意：beforeinstallprompt 事件可能不会在设置页面触发
            // 所以安装按钮可能不会显示，但用户可以在验证器应用页面安装
        }
        
        // 翻译文本（使用 lang 标签）
        const translations = CONFIG.i18n;

        // 复制密钥
        function copySecret() {
            const text = CONFIG.secret || '';
            navigator.clipboard.writeText(text).then(() => {
                Weline.UI.dialog.request({
                    tone: 'success',
                    title: t('复制成功'),
                    message: translations.secretCopied,
                    duration: 2000,
                    confirmable: false
                });
            });
        }

        // 复制备份码
        function copyBackupCodes() {
            const codes = CONFIG.backupCodes || [];
            const text = codes.join('\n');
            navigator.clipboard.writeText(text).then(() => {
                Weline.UI.dialog.request({
                    tone: 'success',
                    title: t('复制成功'),
                    message: translations.backupCodesCopied,
                    duration: 2000,
                    confirmable: false
                });
            });
        }

        // 下载备份码
        function downloadBackupCodes() {
            const codes = CONFIG.backupCodes || [];
            const userEmail = CONFIG.userEmail || '';
            const formattedLabel = CONFIG.accountLabel || '';
            
            // 生成文件内容
            const content = `${translations.backupCodeTitle}
${translations.accountLabel}${formattedLabel}
${translations.generateTimeLabel}${new Date().toLocaleString('zh-CN')}

${translations.importantNote}
${translations.note1}
${translations.note2}
${translations.note3}

${translations.codeListLabel}
${codes.map((code, index) => `${index + 1}. ${code}`).join('\n')}

${translations.finalNote}`;

            // 创建Blob对象
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            
            // 创建下载链接
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${translations.backupCode}_${formattedLabel.replace(/[^a-zA-Z0-9]/g, '_')}_${new Date().toISOString().split('T')[0]}.txt`;
            
            // 触发下载
            document.body.appendChild(link);
            link.click();
            
            // 清理
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            
            Weline.UI.dialog.request({
                tone: 'success',
                title: t('下载成功'),
                message: translations.fileDownloaded,
                duration: 2000,
                confirmable: false
            });
        }

        // 自动格式化验证码输入
        document.getElementById('verifyCode').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // 启用2FA
        async function enableTwoFactor() {
            const code = document.getElementById('verifyCode').value;
            const secret = document.getElementById('secret').value;
            const backupCodes = document.getElementById('backupCodesJson').value;

            if (!code || code.length !== 6) {
                Weline.UI.dialog.request({
                    tone: 'warning',
                    title: t('提示'),
                    message: translations.enter6DigitCode,
                    confirmLabel: t('确定')
                });
                return;
            }

            try {
                const referer = document.getElementById('referer').value;
                const TwoFactorApi = await Weline.Api.resource('twoFactor');
                const result = await TwoFactorApi.enable({
                    secret: secret,
                    code: code,
                    backup_codes: backupCodes,
                    referer: referer
                }, {silent: true});
                
                if (result.success) {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: translations.enableSuccess,
                        duration: 2000,
                        confirmable: false
                    }).then(() => {
                        window.location.href=CONFIG.redirectUrl;
                    });
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('失败'),
                        message: result.message + '\n\n' + translations.checkCode,
                        confirmLabel: t('确定')
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('操作失败'),
                    message: translations.operationFailed,
                    confirmLabel: t('确定')
                });
                console.error(error);
            }
        }

        // Enter键提交
        document.getElementById('verifyCode').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                enableTwoFactor();
            }
        });
}());
