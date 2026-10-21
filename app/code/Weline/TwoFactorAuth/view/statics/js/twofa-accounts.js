/**
 * Two-factor auth account manager (Weline_TwoFactorAuth).
 * Moved from the inline <script> in templates/frontend/Accounts/index.phtml.
 * UI copy comes from the non-executable JSON block #twofa-accounts-config via t().
 * Registered as module `twofaAccounts`; the page container declares data-weline-load.
 */
(function () {
    'use strict';
    var CONFIG = (function () {
        var el = document.getElementById('twofa-accounts-config');
        if (!el) { return {}; }
        try { return JSON.parse(el.textContent || '{}') || {}; } catch (e) { return {}; }
    }());

    function t(key) {
        return (CONFIG.ui && CONFIG.ui[key]) || key;
    }

        const TwoFactorApiClient = (function () {
            let apiPromise = null;
            function get() {
                if (!apiPromise) {
                    apiPromise = Promise.resolve().then(function () {
                        if (!window.Weline || !window.Weline.Api || typeof window.Weline.Api.resource !== 'function') {
                            throw new Error('Weline.Api is not ready.');
                        }
                        return window.Weline.Api.resource('twoFactor');
                    });
                }
                return apiPromise;
            }
            async function call(method, params, options) {
                const api = await get();
                if (!api || typeof api[method] !== 'function') {
                    throw new Error('TwoFactor API method is unavailable: ' + method);
                }
                return api[method](params || {}, options || { silent: true });
            }
            return { call };
        })();

        // Worker管理器 - 使用加密混淆的Worker处理TOTP计算
        const TotpWorkerManager = (function() {
            let worker = null;
            let workerReady = false;
            const callbacks = new Map();
            let callbackId = 0;
            let globalCountdownTimer = null;

            async function fetchCode(accountId) {
                try {
                    const result = await TwoFactorApiClient.call('getCode', { account_id: Number(accountId) });
                    postMessage('code_data', { accountId: String(accountId), data: result });
                } catch (error) {
                    console.error('Failed to load TOTP code data:', error);
                    postMessage('code_data', {
                        accountId: String(accountId),
                        data: { success: false, message: error && error.message ? error.message : 'Request failed' }
                    });
                }
            }

            async function handleImportAccounts(accounts) {
                const summary = { success: 0, fail: 0, errors: [] };
                for (const account of accounts || []) {
                    try {
                        const uri = account && (account.uri || account.url || account);
                        const result = await TwoFactorApiClient.call('importAccount', { uri: String(uri || '') });
                        if (result && result.success !== false) {
                            summary.success += 1;
                        } else {
                            summary.fail += 1;
                            summary.errors.push((result && (result.message || result.msg)) || 'Import failed');
                        }
                    } catch (error) {
                        summary.fail += 1;
                        summary.errors.push(error && error.message ? error.message : 'Import failed');
                    }
                }
                showImportResult(summary);
            }

            function showImportResult(result) {
                let message = `Import complete. Success: ${result.success}`;
                if (result.fail > 0) {
                    message += `, failed: ${result.fail}`;
                }
                if (result.errors && result.errors.length > 0) {
                    message += "\n" + result.errors.slice(0, 5).join("\n");
                }
                Weline.UI.dialog.request({
                    tone: result.fail > 0 ? 'warning' : 'success',
                    title: t('提示'),
                    message: message,
                    confirmLabel: t('确定'),
                    size: 'lg'
                });
                if (result.success > 0) {
                    closeModal('addModal');
                    location.reload();
                }
            }
            
            // 初始化Worker
            function initWorker() {
                if (worker) return Promise.resolve();
                
                return new Promise((resolve, reject) => {
                    try {
                        // 使用Worker处理所有TOTP计算和API调用
                        worker = new Worker('/two-factor-auth/frontend/accounts/worker');
                        
                        worker.onmessage = function(e) {
                            const data = e.data;
                            
                            switch(data.type) {
                                case 'init_complete':
                                    workerReady = true;
                                    if (data.accounts && data.accounts.length > 0) {
                                        data.accounts.forEach(acc => {
                                            updateAccountDisplay(acc.accountId, acc.code, acc.data);
                                        });
                                        // 立即触发一次倒计时更新，确保所有账户显示正确
                                        postMessage('update_countdowns', {});
                                    }
                                    resolve();
                                    break;
                                    
                                case 'code_update':
                                    updateAccountDisplay(data.accountId, data.code, data.data);
                                    break;

                                case 'fetch_code':
                                    fetchCode(data.accountId);
                                    break;

                                case 'import_request':
                                    handleImportAccounts(data.accounts || []);
                                    break;
                                    
                        case 'countdown_update':
                            if (data.countdowns) {
                                data.countdowns.forEach(cd => {
                                    // 更新倒计时圆圈
                                    updateCountdownCircle(cd.accountId, cd.period, cd.remaining);
                                    // 同时更新验证码显示（如果提供了code和data）
                                    if (cd.code && cd.data) {
                                        updateAccountDisplay(cd.accountId, cd.code, cd.data);
                                    }
                                });
                            }
                            if (data.toRefresh && data.toRefresh.length > 0) {
                                data.toRefresh.forEach(accountId => {
                                    refreshAccountCode(accountId);
                                });
                            }
                            break;
                            
                        case 'parse_result':
                            // 文件解析结果
                            const previewEl = document.getElementById('importFileText');
                            const previewSection = document.getElementById('importFilePreview');
                            if (previewEl && previewSection) {
                                if (data.count === 0) {
                                    previewEl.innerHTML = 
                                        `<span class="tfa-status-warn">⚠ 未找到有效的账户数据。请检查文件格式是否正确。</span><br>
                                        <small class="tfa-faint">支持格式：JSON(Google/Microsoft/Authy/2FAS/双因素身份验证)、CSV、TXT</small>`;
                                } else {
                                    previewEl.innerHTML = 
                                        `<span class="tfa-status-ok">✓ 解析成功，找到 ${data.count} 个账户</span>`;
                                    // 存储解析结果，用于后续导入
                                    window._importAccounts = data.accounts;
                                    // 自动开始导入
                                    if (data.accounts && data.accounts.length > 0) {
                                        TotpWorkerManager.importAccounts(data.accounts);
                                    }
                                }
                                previewSection.style.display = 'block';
                            }
                            break;
                            
                        case 'parse_error':
                            // 文件解析错误
                            const errorEl = document.getElementById('importFileText');
                            const errorSection = document.getElementById('importFilePreview');
                            if (errorEl && errorSection) {
                                errorEl.innerHTML = 
                                    `<span class="tfa-status-error">✗ 解析失败：${data.error}</span><br>
                                    <small class="tfa-faint">请检查文件格式是否正确</small>`;
                                errorSection.style.display = 'block';
                            }
                            break;
                            
                        case 'import_result':
                            showImportResult(data.result || { success: 0, fail: 0, errors: [] });
                            break;
                            }
                        };
                        
                        worker.onerror = function(error) {
                            console.error('Worker错误:', error);
                            reject(error);
                        };
                        
                        // Worker创建后立即认为就绪（实际初始化在init消息中）
                        workerReady = true;
                        resolve();
                    } catch (error) {
                        console.error('无法创建Worker:', error);
                        reject(error);
                    }
                });
            }
            
            // 发送消息到Worker
            function postMessage(type, data) {
                if (!worker || !workerReady) {
                    console.warn('Worker未就绪');
                    return;
                }
                worker.postMessage({ type, ...data });
            }
            
            // 初始化所有账户
            function initAccounts(accountIds) {
                // 立即启动全局倒计时定时器（不等待初始化完成）
                if (globalCountdownTimer) {
                    clearInterval(globalCountdownTimer);
                }
                globalCountdownTimer = setInterval(() => {
                    postMessage('update_countdowns', {});
                }, 1000);
                
                // 然后发送初始化请求（并行获取所有账户数据）
                postMessage('init', { accountIds });
            }
            
            // 刷新账户验证码
            function refreshAccountCode(accountId) {
                postMessage('refresh', { accountId });
            }
            
            // 移除账户
            function removeAccount(accountId) {
                postMessage('remove', { accountId });
            }
            
            // 解析导入文件
            function parseFile(fileContent, fileName) {
                postMessage('parse_file', { fileContent, fileName });
            }
            
            // 批量导入账户
            function importAccounts(accounts) {
                postMessage('import_accounts', { accounts });
            }
            
            return {
                init: initWorker,
                initAccounts: initAccounts,
                refreshAccountCode: refreshAccountCode,
                removeAccount: removeAccount,
                parseFile: parseFile,
                importAccounts: importAccounts
            };
        })();
        
        // 更新账户显示
        function updateAccountDisplay(accountId, code, data) {
            const codeElement = document.getElementById(`code-${accountId}`);
            if (codeElement && code) {
                codeElement.textContent = code;
            }
            
            if (data) {
                const now = Math.floor(Date.now() / 1000);
                const remaining = Math.max(0, data.expires_at - now);
                updateCountdownCircle(accountId, data.period, remaining);
            }
        }
        
        // 更新倒计时圆圈和数字
        function updateCountdownCircle(accountId, period, remaining) {
            const progressBar = document.getElementById(`progress-${accountId}`);
            const countdownNumber = document.getElementById(`countdown-${accountId}`);
            const circumference = 94.24; // 2 * π * 15
            
            if (progressBar && countdownNumber) {
                countdownNumber.textContent = Math.max(0, Math.ceil(remaining));
                const offset = circumference - (remaining / period) * circumference;
                progressBar.style.strokeDashoffset = offset;
            }
        }
        
        // 刷新账户验证码（公开接口）
        function refreshAccountCode(accountId) {
            TotpWorkerManager.refreshAccountCode(accountId);
        }
        
        // 初始化所有账户
        async function initializeAccounts() {
            try {
                // 初始化Worker
                await TotpWorkerManager.init();
                
                // 获取所有账户ID
                const codeElements = document.querySelectorAll('[id^="code-"]');
                const accountIds = [];
                codeElements.forEach(element => {
                    const accountId = element.id.replace('code-', '');
                    if (accountId) {
                        accountIds.push(accountId);
                    }
                });
                
                // 初始化账户
                if (accountIds.length > 0) {
                    TotpWorkerManager.initAccounts(accountIds);
                }
            } catch (error) {
                console.error('初始化失败:', error);
            }
        }
        
        // 页面加载完成后初始化
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAccounts);
        } else {
            initializeAccounts();
        }

        document.addEventListener('click', function(event) {
            const trigger = event.target.closest('[data-twofa-action]');
            if (!trigger) {
                return;
            }

            if (trigger.getAttribute('data-stop-propagation') === 'true') {
                event.stopPropagation();
            }

            const action = trigger.getAttribute('data-twofa-action');
            const accountId = trigger.getAttribute('data-account-id');

            if (action === 'show-add-modal') {
                showAddModal();
            } else if (action === 'show-import-modal') {
                showImportModal();
            } else if (action === 'export-accounts') {
                exportAccounts();
            } else if (action === 'toggle-dropdown') {
                toggleDropdown(accountId);
            } else if (action === 'confirm-delete') {
                confirmDelete(accountId);
            } else if (action === 'copy-account-code') {
                copyAccountCode(accountId);
            } else if (action === 'close-modal') {
                closeModal(trigger.getAttribute('data-modal-id'));
            } else if (action === 'switch-tab') {
                switchTabWithEvent(trigger.getAttribute('data-tab'));
            } else if (action === 'trigger-file-input') {
                if (event.target.matches('input[type="file"]')) {
                    return;
                }
                const input = document.getElementById(trigger.getAttribute('data-target-input'));
                if (input) {
                    input.click();
                }
            } else if (action === 'start-camera') {
                startCamera();
            } else if (action === 'switch-import-tab') {
                switchImportTab(trigger.getAttribute('data-import-tab'));
            }
        });

        document.addEventListener('keydown', function(event) {
            const trigger = event.target.closest('[data-twofa-action]');
            if (!trigger || (event.key !== 'Enter' && event.key !== ' ')) {
                return;
            }
            event.preventDefault();
            trigger.click();
        });

        document.addEventListener('change', function(event) {
            const action = event.target.getAttribute('data-twofa-change');
            if (action === 'handle-file-upload') {
                handleFileUpload(event);
            } else if (action === 'handle-import-file') {
                handleImportFile(event);
            }
        });

        document.addEventListener('submit', function(event) {
            const action = event.target.getAttribute('data-twofa-submit');
            if (action === 'add-account') {
                addAccount(event);
            } else if (action === 'import-account') {
                importAccount(event);
            }
        });

        // 显示添加模态框
        function showAddModal() {
            document.getElementById('addModal').classList.add('active');
        }

        // 关闭模态框
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.getElementById('addForm').reset();
            document.getElementById('importForm').reset();
            stopCamera();
            switchTab('qr');
        }

        // 切换标签页
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if (tab === 'qr') {
                document.querySelectorAll('.tab')[0].classList.add('active');
                document.getElementById('qrTab').classList.add('active');
            } else if (tab === 'manual') {
                document.querySelectorAll('.tab')[1].classList.add('active');
                document.getElementById('manualTab').classList.add('active');
            } else {
                document.querySelectorAll('.tab')[2].classList.add('active');
                document.getElementById('uriTab').classList.add('active');
            }
        }
        
        // 切换标签页（事件处理）
        function switchTabWithEvent(tab) {
            switchTab(tab);
        }
        
        // 处理文件上传
        function handleFileUpload(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    document.getElementById('previewImage').src = e.target.result;
                    document.getElementById('previewSection').style.display = 'block';
                    parseQRCode(img);
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }
        
        // 解析二维码
        function parseQRCode(img) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            canvas.width = img.width;
            canvas.height = img.height;
            ctx.drawImage(img, 0, 0);
            
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height);
            
            if (code) {
                document.getElementById('previewText').innerHTML = '<span class="tfa-status-ok">✓ 二维码解析成功！</span>';
                setTimeout(() => {
                    handleQRCodeURI(code.data);
                }, 500);
            } else {
                document.getElementById('previewText').innerHTML = '<span class="tfa-status-error">✗ 无法识别二维码，请重试</span>';
            }
        }
        
        // 启动摄像头
        let cameraStream = null;
        let scanningInterval = null;
        
        function startCamera() {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(stream => {
                    cameraStream = stream;
                    const video = document.getElementById('cameraVideo');
                    video.srcObject = stream;
                    video.style.display = 'block';
                    document.getElementById('startCameraBtn').style.display = 'none';
                    
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    scanningInterval = setInterval(() => {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        ctx.drawImage(video, 0, 0);
                        
                        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(imageData.data, imageData.width, imageData.height);
                        
                        if (code) {
                            stopCamera();
                            handleQRCodeURI(code.data);
                        }
                    }, 100);
                })
                .catch(err => {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('无法访问摄像头'),
                        message: err.message,
                        confirmLabel: t('确定')
                    });
                });
        }
        
        // 停止摄像头
        function stopCamera() {
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            if (scanningInterval) {
                clearInterval(scanningInterval);
                scanningInterval = null;
            }
            document.getElementById('cameraVideo').style.display = 'none';
            document.getElementById('startCameraBtn').style.display = 'block';
        }
        
        // 处理解析的URI
        async function handleQRCodeURI(uri) {
            if (!uri.startsWith('otpauth://')) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('错误'),
                    message: t('无效的二维码格式'),
                    confirmLabel: t('确定')
                });
                return;
            }
            
            // 解析URI
            const url = new URL(uri);
            const params = new URLSearchParams(url.search);
            
            const data = {
                name: url.pathname.split(':').pop() || '',
                issuer: params.get('issuer') || '',
                secret: params.get('secret') || '',
                algorithm: params.get('algorithm') || 'SHA1',
                digits: params.get('digits') || '6',
                period: params.get('period') || '30'
            };
            
            try {
                const result = await TwoFactorApiClient.call('importAccount', { uri });
                if (result.success) {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: t('账户添加成功'),
                        duration: 2000,
                        confirmable: false
                    }).then(() => {
                        closeModal('addModal');
                        location.reload();
                    });
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('添加失败'),
                        message: result.message || t('请重试'),
                        confirmLabel: t('确定')
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('添加失败'),
                    message: t('请重试'),
                    confirmLabel: t('确定')
                });
            }
        }

        // 添加账户
        async function addAccount(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            
            try {
                const result = await TwoFactorApiClient.call('saveAccount', {
                    name: data.name || '',
                    secret: data.secret || '',
                    issuer: data.issuer || '',
                    algorithm: data.algorithm || 'SHA1',
                    digits: Number(data.digits || 6),
                    period: Number(data.period || 30)
                });
                
                if (result.success) {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: t('账户添加成功'),
                        duration: 2000,
                        confirmable: false
                    }).then(() => {
                        closeModal('addModal');
                        location.reload();
                    });
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('添加失败'),
                        message: result.message,
                        confirmLabel: t('确定')
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('添加失败'),
                    message: t('请重试'),
                    confirmLabel: t('确定')
                });
            }
        }

        // 导入账户
        async function importAccount(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            try {
                const result = await TwoFactorApiClient.call('importAccount', {
                    uri: String(formData.get('uri') || '')
                });
                
                if (result.success) {
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: t('账户导入成功'),
                        duration: 2000,
                        confirmable: false
                    }).then(() => {
                        closeModal('addModal');
                        location.reload();
                    });
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('导入失败'),
                        message: result.message,
                        confirmLabel: t('确定')
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('导入失败'),
                    message: t('请重试'),
                    confirmLabel: t('确定')
                });
            }
        }

        // 切换下拉菜单
        function toggleDropdown(accountId) {
            // 关闭所有下拉菜单
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.classList.remove('show');
            });
            
            // 切换当前菜单
            const dropdown = document.getElementById(`dropdown-${accountId}`);
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }
        
        // 点击页面其他地方时关闭下拉菜单
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.account-actions')) {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.classList.remove('show');
                });
            }
        });
        
        // 确认删除账户（二次确认）
        async function confirmDelete(accountId) {
            const result = await Weline.UI.dialog.request({
                tone: 'warning',
                title: t('删除验证器账户'),
                message: t('此操作不可撤销，确定要删除这个验证器账户吗？'),
                cancelable: true,
                confirmLabel: t('删除'),
                cancelLabel: t('取消'),
                confirmTone: 'danger'
            });

            if (result.confirmed) {
                deleteAccount(accountId);
            }
        }
        
        // 删除账户（实际执行）
        async function deleteAccount(accountId) {
            try {
                const result = await TwoFactorApiClient.call('deleteAccount', {
                    account_id: Number(accountId)
                });
                
                if (result.success) {
                    // 从Worker中移除账户
                    TotpWorkerManager.removeAccount(accountId);
                    Weline.UI.dialog.request({
                        tone: 'success',
                        title: t('成功'),
                        message: t('账户已删除'),
                        duration: 2000,
                        confirmable: false
                    }).then(() => location.reload());
                } else {
                    Weline.UI.dialog.request({
                        tone: 'danger',
                        title: t('删除失败'),
                        message: result.message,
                        confirmLabel: t('确定')
                    });
                }
            } catch (error) {
                Weline.UI.dialog.request({
                    tone: 'danger',
                    title: t('删除失败'),
                    message: t('请重试'),
                    confirmLabel: t('确定')
                });
            }
        }

        // 显示导入模态框
        function showImportModal() {
            showAddModal();
            switchTab('uri');
            // 默认显示文件导入
            switchImportTab('file');
        }
        
        // 触发文件上传
        function triggerFileUpload() {
            const input = document.getElementById('importFileInput');
            if (input) {
                input.click();
            }
        }
        
        // 切换导入方式标签页
        function switchImportTab(mode) {
            document.querySelectorAll('#uriTab .tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('#uriTab .import-tab-content').forEach(c => c.classList.remove('active'));
            
            if (mode === 'file') {
                document.querySelectorAll('#uriTab .tab')[0].classList.add('active');
                document.getElementById('fileImportTab').classList.add('active');
            } else {
                document.querySelectorAll('#uriTab .tab')[1].classList.add('active');
                document.getElementById('uriImportTab').classList.add('active');
            }
        }
        
        // 处理文件导入
        function handleImportFile(event) {
            const file = event.target.files[0];
            if (!file) return;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const text = e.target.result;
                const fileName = file.name.toLowerCase();
                
                try {
                    let accounts = [];
                    console.log('开始解析文件:', file.name, '大小:', file.size, '字节');
                    
                    // 根据文件扩展名判断格式
                    if (fileName.endsWith('.json')) {
                        // 使用Worker解析，不再直接调用未定义的函数
                        console.warn('parseJsonImport未定义，应使用TotpWorkerManager.parseFile');
                        throw new Error('请使用Worker解析文件');
                    } else if (fileName.endsWith('.csv')) {
                        // 使用Worker解析，不再直接调用未定义的函数
                        console.warn('parseCsvImport未定义，应使用TotpWorkerManager.parseFile');
                        throw new Error('请使用Worker解析文件');
                    } else if (fileName.endsWith('.txt')) {
                        // 使用Worker解析，不再直接调用未定义的函数
                        console.warn('parseTxtImport未定义，应使用TotpWorkerManager.parseFile');
                        throw new Error('请使用Worker解析文件');
                    } else {
                        throw new Error('不支持的文件格式');
                    }
                    
                    console.log('解析结果:', accounts);
                    
                    if (accounts.length === 0) {
                        document.getElementById('importFileText').innerHTML = 
                            `<span class="tfa-status-warn">⚠ 未找到有效的账户数据。请检查文件格式是否正确。</span><br>
                            <small class="tfa-faint">支持格式：JSON(Google/Microsoft/Authy/2FAS/双因素身份验证)、CSV、TXT</small>`;
                        document.getElementById('importFilePreview').style.display = 'block';
                        return;
                    }
                    
                    document.getElementById('importFileText').innerHTML = 
                        `<span class="tfa-status-ok">✓ 解析成功，找到 ${accounts.length} 个账户</span>`;
                    document.getElementById('importFilePreview').style.display = 'block';
                    
                    // 批量导入账户
                    importAccounts(accounts);
                    
                } catch (error) {
                    console.error('解析错误:', error);
                    document.getElementById('importFileText').innerHTML = 
                        `<span class="tfa-status-error">✗ 解析失败：${error.message}</span><br>
                        <small class="tfa-faint">请检查文件格式是否正确</small>`;
                    document.getElementById('importFilePreview').style.display = 'block';
                }
            };
            reader.readAsText(file);
        }
        
        // 导出账户
        async function exportAccounts() {
            const result = await Weline.UI.dialog.request({
                tone: 'info',
                title: t('选择导出格式'),
                message: t('请选择要导出的验证器账户文件格式。'),
                cancelable: true,
                confirmLabel: t('导出'),
                cancelLabel: t('取消'),
                field: {
                    type: 'select',
                    choices: {json: 'JSON', csv: 'CSV'},
                    value: 'json',
                    required: true
                }
            });
            if (!result.confirmed) {
                return;
            }
            const format = result.value === 'csv' ? 'csv' : 'json';
            window.location.href = `/two-factor-auth/frontend/accounts/export?format=${format}`;
        }
        
        // 复制账户验证码
        async function copyAccountCode(accountId) {
            const codeElement = document.getElementById(`code-${accountId}`);
            const copyBtn = document.getElementById(`copy-btn-${accountId}`);
            
            if (!codeElement) {
                return;
            }
            
            // 获取验证码文本（去掉空格）
            let code = codeElement.textContent.trim();
            
            // 如果验证码还是占位符，不执行复制
            if (code === '---- --' || code === '' || code.length < 6) {
                Weline.UI.dialog.request({
                    tone: 'info',
                    title: t('提示'),
                    message: t('验证码尚未生成，请稍候...'),
                    confirmLabel: t('确定')
                });
                return;
            }
            
            // 去掉验证码中的空格
            code = code.replace(/\s+/g, '');
            
            try {
                // 使用 Clipboard API 复制
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(code);
                    
                    // 更新按钮状态
                    if (copyBtn) {
                        const originalText = copyBtn.innerHTML;
                        copyBtn.classList.add('copied');
                        copyBtn.innerHTML = '✓ 已复制';
                        
                        // 2秒后恢复原状
                        setTimeout(() => {
                            copyBtn.classList.remove('copied');
                            copyBtn.innerHTML = originalText;
                        }, 2000);
                    }
                } else {
                    // 降级方案：使用传统方法
                    const textArea = document.createElement('textarea');
                    textArea.value = code;
                    textArea.style.position = 'fixed';
                    textArea.style.left = '-999999px';
                    textArea.style.top = '-999999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    
                    try {
                        document.execCommand('copy');
                        if (copyBtn) {
                            const originalText = copyBtn.innerHTML;
                            copyBtn.classList.add('copied');
                            copyBtn.innerHTML = '✓ 已复制';
                            setTimeout(() => {
                                copyBtn.classList.remove('copied');
                                copyBtn.innerHTML = originalText;
                            }, 2000);
                        }
                    } catch (err) {
                        Weline.UI.dialog.request({
                            tone: 'warning',
                            title: t('复制失败'),
                            message: t('请手动复制：') + code,
                            confirmLabel: t('确定')
                        });
                    }
                    
                    document.body.removeChild(textArea);
                }
            } catch (error) {
                console.error('复制失败:', error);
                Weline.UI.dialog.request({
                    tone: 'warning',
                    title: t('复制失败'),
                    message: t('请手动复制：') + code,
                    confirmLabel: t('确定')
                });
            }
        }
}());
