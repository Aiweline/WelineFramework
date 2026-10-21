/**
 * Two-factor auth management page (Weline_TwoFactorAuth).
 * Moved verbatim from the inline <script> in templates/frontend/Index/index.phtml.
 * Registered as module `twofaIndex`; the page container declares data-weline-load.
 */
document.addEventListener('click', function(e) {
            const trigger = e.target.closest('[data-twofa-action]');
            if (!trigger) {
                return;
            }

            const action = trigger.getAttribute('data-twofa-action');
            if (action === 'show-disable-modal') {
                showDisableModal();
            } else if (action === 'close-disable-modal') {
                closeDisableModal();
            } else if (action === 'disable-two-factor') {
                disableTwoFactor();
            } else if (action === 'show-regenerate-modal') {
                showRegenerateModal();
            } else if (action === 'close-regenerate-modal') {
                closeRegenerateModal();
            } else if (action === 'regenerate-backup-codes') {
                regenerateBackupCodes();
            } else if (action === 'reload-page') {
                location.reload();
            } else if (action === 'show-authenticator-app-modal') {
                showAuthenticatorAppModal();
            } else if (action === 'close-authenticator-app-modal') {
                closeAuthenticatorAppModal();
            }
        });

        function showDisableModal() {
            document.getElementById('disableModal').classList.add('active');
        }

        function closeDisableModal() {
            document.getElementById('disableModal').classList.remove('active');
            document.getElementById('disableCode').value = '';
        }

        function showRegenerateModal() {
            document.getElementById('regenerateModal').classList.add('active');
        }

        function closeRegenerateModal() {
            document.getElementById('regenerateModal').classList.remove('active');
            document.getElementById('regenerateCode').value = '';
        }

        async function disableTwoFactor() {
            const code = document.getElementById('disableCode').value;
            if (!code || code.length !== 6) {
                Weline.UI.dialog.request({
                    tone: 'warning',
                    title: '<lang>提示</lang>',
                    message: '<lang>请输入6位验证码</lang>',
                    confirmLabel: '<lang>确定</lang>'
                });
                return;
            }

            try {
                const TwoFactorApi = await Weline.Api.resource('twoFactor');
                const result = await TwoFactorApi.disable({code: code}, {silent: true});
                
                if (result.success) {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: '<lang>成功</lang>',
                        message: result.message,
                        duration: 2000,
                        confirmable: false
                    }).then(() => location.reload());
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: '<lang>失败</lang>',
                        message: result.message,
                        confirmLabel: '<lang>确定</lang>'
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: '<lang>操作失败</lang>',
                    message: '<lang>请重试</lang>',
                    confirmLabel: '<lang>确定</lang>'
                });
            }
        }

        async function regenerateBackupCodes() {
            const code = document.getElementById('regenerateCode').value;
            if (!code || code.length !== 6) {
                Weline.UI.dialog.request({
                    tone: 'warning',
                    title: '<lang>提示</lang>',
                    message: '<lang>请输入6位验证码</lang>',
                    confirmLabel: '<lang>确定</lang>'
                });
                return;
            }

            try {
                const TwoFactorApi = await Weline.Api.resource('twoFactor');
                const result = await TwoFactorApi.regenerateBackupCodes({code: code}, {silent: true});
                
                if (result.success) {
                    // 显示新的备份码
                    let codesHtml = '<h3>新的备份码</h3><ul>';
                    result.backup_codes.forEach(code => {
                        codesHtml += `<li class="s-mono-pad">${code}</li>`;
                    });
                    codesHtml += '</ul><p class="s-danger">请妥善保存这些备份码！</p>';
                    
                    const modal = document.getElementById('regenerateModal');
                    modal.querySelector('.modal-content').innerHTML = codesHtml + 
                        '<button class="w-button" type="button" data-twofa-action="reload-page" data-tone="primary">完成</button>';
                } else {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: '<lang>成功</lang>',
                        message: result.message,
                        duration: 2000,
                        confirmable: false
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: '<lang>操作失败</lang>',
                    message: '<lang>请重试</lang>',
                    confirmLabel: '<lang>确定</lang>'
                });
            }
        }

        // 显示验证器APP提示模态框
        function showAuthenticatorAppModal() {
            const modal = document.createElement('div');
            modal.id = 'authenticatorAppModal';
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;';
            
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
            const isAndroid = /Android/.test(navigator.userAgent);
            
            let installInstructions = '';
            if (isIOS) {
                installInstructions = `
                    <div class="s-info-box">
                        <strong>📱 <lang>iOS设备安装步骤：</lang></strong>
                        <ol class="s-list">
                            <li><lang>点击下方"打开验证器"按钮</lang></li>
                            <li><lang>在Safari浏览器中，点击底部"分享"按钮</lang></li>
                            <li><lang>选择"添加到主屏幕"</lang></li>
                            <li><lang>点击"添加"完成安装</lang></li>
                        </ol>
                    </div>
                `;
            } else if (isAndroid) {
                installInstructions = `
                    <div class="s-info-box">
                        <strong>📱 <lang>Android设备安装步骤：</lang></strong>
                        <ol class="s-list">
                            <li><lang>点击下方"打开验证器"按钮</lang></li>
                            <li><lang>在Chrome浏览器中，点击右上角"菜单"（三个点）</lang></li>
                            <li><lang>选择"添加到主屏幕"或"安装应用"</lang></li>
                            <li><lang>点击"添加"或"安装"完成</lang></li>
                        </ol>
                    </div>
                `;
            } else {
                installInstructions = `
                    <div class="s-info-box">
                        <strong>💻 <lang>桌面设备安装步骤：</lang></strong>
                        <ol class="s-list">
                            <li><lang>点击下方"打开验证器"按钮</lang></li>
                            <li><lang>在浏览器地址栏右侧，点击"安装"图标</lang></li>
                            <li><lang>或使用浏览器的"添加到主屏幕"功能</lang></li>
                            <li><lang>安装后即可像应用一样使用</lang></li>
                        </ol>
                    </div>
                `;
            }
            
            modal.innerHTML = `
                <div class="s-modal">
                    <h2 class="s-head">
                        📱 打开验证器APP
                    </h2>
                    <p class="s-desc">
                        我们的验证器是一个PWA（渐进式Web应用），可以像原生应用一样安装到您的设备上。
                    </p>
                    <div class="s-warn-box">
                        <strong>💡 提示：</strong>
                        <p class="s-warn-text">
                            建议将验证器保存到桌面，方便随时使用。安装后可以离线使用，无需每次打开浏览器。
                        </p>
                    </div>
                    ${installInstructions}
                    <div class="s-actions">
                        <button type="button" data-twofa-action="close-authenticator-app-modal" class="s-btn-2nd">
                            取消
                        </button>
                        <a href="@url{'twofa-app/'}" target="_blank" data-twofa-action="close-authenticator-app-modal" class="s-btn-1st">
                            打开验证器
                        </a>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // 点击外部关闭
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeAuthenticatorAppModal();
                }
            });
        }

        // 关闭验证器APP提示模态框
        function closeAuthenticatorAppModal() {
            const modal = document.getElementById('authenticatorAppModal');
            if (modal) {
                modal.remove();
            }
        }
