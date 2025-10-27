/**
 * Weline 身份验证器 - 核心JavaScript
 * 完全原生实现，不依赖任何第三方库
 */

class TwoFactorAuthApp {
    constructor() {
        this.base32Chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        this.accounts = this.loadAccounts();
        this.updateInterval = null;
    }

    /**
     * Base32解码
     */
    base32Decode(encoded) {
        encoded = encoded.toUpperCase().replace(/[=\s-]/g, '');
        if (encoded.length === 0) return new Uint8Array(0);

        let bits = '';

        for (let i = 0; i < encoded.length; i++) {
            const char = encoded[i];
            const val = this.base32Chars.indexOf(char);
            if (val === -1) continue;
            bits += val.toString(2).padStart(5, '0');
        }

        const bytes = [];
        for (let i = 0; i + 8 <= bits.length; i += 8) {
            bytes.push(parseInt(bits.substr(i, 8), 2));
        }

        return new Uint8Array(bytes);
    }

    /**
     * HMAC-SHA1算法（使用Web Crypto API）
     */
    async hmacSha1(key, message) {
        try {
            const cryptoKey = await crypto.subtle.importKey(
                'raw',
                key,
                { name: 'HMAC', hash: 'SHA-1' },
                false,
                ['sign']
            );

            const signature = await crypto.subtle.sign(
                'HMAC',
                cryptoKey,
                message
            );

            return new Uint8Array(signature);
        } catch (error) {
            console.error('HMAC-SHA1 error:', error);
            throw error;
        }
    }

    /**
     * 生成TOTP验证码
     */
    async generateCode(secret, timestamp = null, period = 30, digits = 6) {
        if (timestamp === null) {
            timestamp = Math.floor(Date.now() / 1000);
        }

        const timeStep = Math.floor(timestamp / period);
        const key = this.base32Decode(secret);

        const timeBytes = new ArrayBuffer(8);
        const dataView = new DataView(timeBytes);
        dataView.setUint32(0, 0);
        dataView.setUint32(4, timeStep);

        const hash = await this.hmacSha1(key, new Uint8Array(timeBytes));

        const offset = hash[hash.length - 1] & 0x0F;
        const truncatedHash = hash.slice(offset, offset + 4);

        let value = 0;
        for (let i = 0; i < 4; i++) {
            value = (value << 8) | truncatedHash[i];
        }
        value = value & 0x7FFFFFFF;

        const code = value % Math.pow(10, digits);

        return code.toString().padStart(digits, '0');
    }

    /**
     * 获取剩余秒数
     */
    getRemainingSeconds(period = 30) {
        const currentTime = Math.floor(Date.now() / 1000);
        return period - (currentTime % period);
    }

    /**
     * 解析otpauth URI
     */
    parseOtpAuthUri(uri) {
        const match = uri.match(/otpauth:\/\/totp\/([^?]+)\?(.+)/);
        if (!match) return null;

        const label = decodeURIComponent(match[1]);
        const params = new URLSearchParams(match[2]);

        let issuer, account;
        if (label.includes(':')) {
            [issuer, account] = label.split(':').map(s => s.trim());
        } else {
            issuer = params.get('issuer') || 'Unknown';
            account = label.trim();
        }

        return {
            type: 'totp',
            issuer,
            account,
            secret: params.get('secret'),
            algorithm: params.get('algorithm') || 'SHA1',
            digits: parseInt(params.get('digits') || '6'),
            period: parseInt(params.get('period') || '30')
        };
    }

    /**
     * 加载账户
     */
    loadAccounts() {
        try {
            const data = localStorage.getItem('weline_2fa_accounts');
            return data ? JSON.parse(data) : [];
        } catch (error) {
            console.error('Failed to load accounts:', error);
            return [];
        }
    }

    /**
     * 保存账户
     */
    saveAccounts() {
        try {
            localStorage.setItem('weline_2fa_accounts', JSON.stringify(this.accounts));
        } catch (error) {
            console.error('Failed to save accounts:', error);
            showToast('保存失败：' + error.message);
        }
    }

    /**
     * 添加账户
     */
    addAccount(issuer, account, secret, digits = 6, period = 30, note = '') {
        const newAccount = {
            id: Date.now(),
            issuer,
            account,
            secret,
            digits,
            period,
            note: note || '', // 备注字段
            createdAt: new Date().toISOString()
        };

        this.accounts.push(newAccount);
        this.saveAccounts();
        return newAccount;
    }

    /**
     * 删除账户
     */
    deleteAccount(accountId) {
        this.accounts = this.accounts.filter(acc => acc.id !== accountId);
        this.saveAccounts();
    }

    /**
     * 获取所有账户
     */
    getAccounts() {
        return this.accounts;
    }
}

// 全局实例
const app = new TwoFactorAuthApp();

/**
 * 显示账户列表
 */
async function displayAccounts() {
    const container = document.getElementById('accountsContainer');
    const accounts = app.getAccounts();
    const emptyState = document.getElementById('emptyState');

    if (accounts.length === 0) {
        emptyState.style.display = 'block';
        return;
    }

    emptyState.style.display = 'none';

    const remaining = app.getRemainingSeconds();
    const progress = (remaining / 30) * 100;
    const circumference = 2 * Math.PI * 20;
    const offset = circumference * (1 - progress / 100);

    let html = '';
    for (const account of accounts) {
        try {
            const code = await app.generateCode(account.secret, null, account.period || 30, account.digits || 6);
            html += `
                <div class="account-card">
                    <div class="account-header">
                        <div class="account-info">
                            <div class="account-issuer">${escapeHtml(account.issuer)}</div>
                            <div class="account-name">${escapeHtml(account.account)}</div>
                            ${account.note ? `<div class="account-note" style="font-size: 12px; color: #888; margin-top: 4px;">📝 ${escapeHtml(account.note)}</div>` : ''}
                        </div>
                        <button class="delete-btn" onclick="deleteAccount(${account.id})">删除</button>
                    </div>
                    <div class="code-container">
                        <div class="code-with-copy" style="display: flex; align-items: center; gap: 10px;">
                            <div class="code" onclick="copyCode('${code}')" title="${__('点击复制')}" style="cursor: pointer;">
                                ${code}
                            </div>
                            <button class="copy-icon-btn" onclick="copyCode('${code}'); event.stopPropagation();" 
                                    title="${__('复制验证码')}"
                                    style="background: transparent; border: none; cursor: pointer; font-size: 20px; color: #666; padding: 5px; transition: all 0.2s;">
                                📋
                            </button>
                        </div>
                        <div class="timer">
                            <svg class="timer-circle" viewBox="0 0 50 50">
                                <circle cx="25" cy="25" r="20" fill="none" stroke="#e0e0e0" stroke-width="4"/>
                                <circle cx="25" cy="25" r="20" fill="none" stroke="#007bff" stroke-width="4"
                                        stroke-dasharray="${circumference}"
                                        stroke-dashoffset="${offset}"
                                        stroke-linecap="round"/>
                            </svg>
                            <div class="timer-text">${remaining}</div>
                        </div>
                    </div>
                </div>
            `;
        } catch (error) {
            console.error('Error generating code for account:', account, error);
        }
    }

    // 只更新变化的部分，避免不必要的DOM重绘
    if (container.innerHTML !== html) {
        container.innerHTML = html;
    }

    // 确保倒计时每秒更新（修复刷新问题）
    updateAllTimers();
}

/**
 * 更新所有账户的倒计时显示
 */
function updateAllTimers() {
    const accounts = app.getAccounts();
    if (accounts.length === 0) return;

    const remaining = app.getRemainingSeconds();
    const progress = (remaining / 30) * 100;
    const circumference = 2 * Math.PI * 20;
    const offset = circumference * (1 - progress / 100);

    // 更新所有倒计时圆环
    document.querySelectorAll('.timer-circle circle:last-child').forEach(circle => {
        circle.style.strokeDashoffset = offset;
    });

    // 更新所有倒计时文本
    document.querySelectorAll('.timer-text').forEach(text => {
        text.textContent = remaining;
    });
}

/**
 * 复制验证码
 */
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast(__('✓ 验证码已复制'));
    }).catch(() => {
        showToast(__('复制失败，请手动复制'));
    });
}

/**
 * 显示添加模态框
 */
function showAddModal() {
    document.getElementById('addModal').classList.add('active');
}

/**
 * 关闭添加模态框
 */
function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
    document.getElementById('issuer').value = '';
    document.getElementById('accountName').value = '';
    document.getElementById('secret').value = '';
    document.getElementById('accountNote').value = '';

    // 清除字段高亮效果
    clearFieldHighlights();
}

/**
 * 清除字段高亮效果
 */
function clearFieldHighlights() {
    const fields = ['issuer', 'accountName', 'secret', 'accountNote'];
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.style.backgroundColor = '';
            field.style.border = '';
        }
    });
}

/**
 * 切换标签
 */
function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });

    event.target.classList.add('active');
    document.getElementById(tabName + 'Tab').classList.add('active');

    // 切换按钮显示
    const addBtn = document.getElementById('addBtn');
    const importBtn = document.getElementById('importBtn');

    if (tabName === 'import') {
        addBtn.style.display = 'none';
        importBtn.style.display = 'inline-block';
    } else {
        addBtn.style.display = 'inline-block';
        importBtn.style.display = 'none';
    }
}

// 存储待导入的账户
let pendingImportAccounts = [];

/**
 * 添加账户
 */
// 暂存待确认的账户
let pendingAccount = null;

function addAccount() {
    const issuer = document.getElementById('issuer').value.trim();
    const accountName = document.getElementById('accountName').value.trim();
    let secret = document.getElementById('secret').value.trim();
    const note = document.getElementById('accountNote').value.trim();

    if (!secret) {
        showToast(__('请输入密钥或链接'));
        return;
    }

    // 检查是否是otpauth URI
    if (secret.startsWith('otpauth://')) {
        const parsed = app.parseOtpAuthUri(secret);
        if (!parsed) {
            showToast(__('无效的otpauth链接'));
            return;
        }
        // 暂存账户信息，等待用户确认编辑
        pendingAccount = {
            issuer: parsed.issuer,
            account: parsed.account,
            secret: parsed.secret,
            digits: parsed.digits || 6,
            period: parsed.period || 30,
            note: note
        };
    } else {
        if (!issuer || !accountName) {
            showToast(__('请填写所有字段'));
            return;
        }

        secret = secret.replace(/[\s-]/g, '').toUpperCase();

        // 验证Base32格式
        if (!/^[A-Z2-7]+=*$/.test(secret)) {
            showToast(__('无效的密钥格式'));
            return;
        }

        // 暂存账户信息，等待用户确认编辑
        pendingAccount = {
            issuer: issuer,
            account: accountName,
            secret: secret,
            digits: 6,
            period: 30,
            note: note
        };
    }

    // 关闭添加模态框，打开编辑确认框
    closeAddModal();
    showEditConfirmModal();
}

/**
 * 显示编辑确认框
 */
async function showEditConfirmModal() {
    if (!pendingAccount) return;

    // 填充表单
    document.getElementById('editIssuer').value = pendingAccount.issuer;
    document.getElementById('editAccountName').value = pendingAccount.account;
    document.getElementById('editNote').value = pendingAccount.note || '';
    document.getElementById('editEnableRecovery').checked = false;
    document.getElementById('recoveryCodesDisplay').style.display = 'none';

    // 生成并显示验证码预览
    try {
        const code = await app.generateCode(pendingAccount.secret, null, pendingAccount.period, pendingAccount.digits);
        document.getElementById('previewCode').textContent = code;
        updatePreviewTimer();
    } catch (error) {
        console.error('Failed to generate preview code:', error);
        document.getElementById('previewCode').textContent = '------';
    }

    // 显示模态框
    document.getElementById('editConfirmModal').classList.add('active');

    // 应用i18n翻译
    applyTranslations();
}

/**
 * 更新预览验证码倒计时
 */
function updatePreviewTimer() {
    if (!pendingAccount) return;

    const remaining = app.getRemainingSeconds(pendingAccount.period || 30);
    const timerEl = document.getElementById('previewTimer');
    if (timerEl) {
        timerEl.innerHTML = `${remaining} <span data-i18n="seconds_unit">${__('秒')}</span>`;
    }
}

/**
 * 关闭编辑确认框
 */
function closeEditConfirmModal(abandon = false) {
    document.getElementById('editConfirmModal').classList.remove('active');

    if (abandon) {
        // 用户取消，删除暂存的账户
        pendingAccount = null;
    } else {
        // 正常关闭，刷新显示
        displayAccounts();
    }
}

/**
 * 保存编辑后的账户
 */
function saveEditedAccount() {
    if (!pendingAccount) return;

    // 获取编辑后的值
    const editedAccount = pendingAccount.account;
    const editedNote = document.getElementById('editNote').value.trim();
    const enableRecovery = document.getElementById('editEnableRecovery').checked;

    // 更新账户名和备注
    pendingAccount.account = editedAccount;
    pendingAccount.note = editedNote;

    // 如果启用恢复码，生成并保存
    if (enableRecovery) {
        pendingAccount.recoveryCodes = generateRecoveryCodes();
    }

    // 正式添加账户
    app.accounts.push({
        id: Date.now(),
        ...pendingAccount,
        createdAt: new Date().toISOString()
    });
    app.saveAccounts();

    // 关闭模态框
    closeEditConfirmModal(false);

    // 显示成功提示
    showToast(__('✓ 账户保存成功'));

    pendingAccount = null;
}

/**
 * 生成恢复码
 */
function generateRecoveryCodes(count = 8) {
    const codes = [];
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // 排除易混淆字符

    for (let i = 0; i < count; i++) {
        let code = '';
        for (let j = 0; j < 8; j++) {
            code += chars[Math.floor(Math.random() * chars.length)];
        }
        // 格式化为 XXXX-XXXX
        codes.push(code.substring(0, 4) + '-' + code.substring(4));
    }

    return codes;
}

/**
 * 复制恢复码
 */
function copyRecoveryCodes() {
    const codes = Array.from(document.querySelectorAll('#recoveryCodesList code'))
        .map(el => el.textContent)
        .join('\n');

    navigator.clipboard.writeText(codes).then(() => {
        showToast(__('✓ 恢复码已复制到剪贴板'));
    }).catch(() => {
        showToast(__('❌ 复制失败，请手动复制'));
    });
}

/**
 * 删除账户
 */
function deleteAccount(accountId) {
    if (confirm(__('确定要删除这个账户吗？'))) {
        app.deleteAccount(accountId);
        displayAccounts();
        showToast(__('✓ 账户已删除'));
    }
}

/**
 * 处理二维码图片上传
 */
async function handleQRImageUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    // 显示预览区
    document.getElementById('qrImagePreview').style.display = 'block';
    document.getElementById('qrScanStatus').innerHTML = '<span style="color: #007bff;">⏳ 正在解析二维码...</span>';

    try {
        // 读取并显示图片
        const reader = new FileReader();
        reader.onload = async function (e) {
            const img = document.getElementById('qrPreviewImg');
            img.src = e.target.result;

            // 等待图片加载
            img.onload = async function () {
                try {
                    // 解析二维码
                    const result = await scanQRCodeFromImage(img);

                    if (result && result.data) {
                        // 解析成功
                        document.getElementById('qrScanStatus').innerHTML =
                            '<span style="color: #28a745;">✓ 识别成功！</span>';

                        // 自动填充数据
                        await processQRCodeData(result.data);

                        // 2秒后关闭预览并切换到手动输入标签查看结果
                        setTimeout(() => {
                            document.getElementById('qrImagePreview').style.display = 'none';
                            // processQRCodeData内部已经会切换标签
                        }, 2000);

                    } else {
                        // 解析失败
                        document.getElementById('qrScanStatus').innerHTML =
                            '<span style="color: #dc3545;">✗ 未能识别二维码，请确保图片清晰</span>';
                    }
                } catch (error) {
                    console.error('QR scan error:', error);
                    document.getElementById('qrScanStatus').innerHTML =
                        '<span style="color: #dc3545;">✗ 解析失败：' + error.message + '</span>';
                }
            };

            img.onerror = function () {
                document.getElementById('qrScanStatus').innerHTML =
                    '<span style="color: #dc3545;">✗ 图片加载失败</span>';
            };
        };

        reader.readAsDataURL(file);

    } catch (error) {
        showToast('图片读取失败：' + error.message);
        document.getElementById('qrImagePreview').style.display = 'none';
    }
}

/**
 * 从图片中扫描二维码
 */
async function scanQRCodeFromImage(image) {
    return new Promise((resolve) => {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');

        canvas.width = image.width || image.naturalWidth;
        canvas.height = image.height || image.naturalHeight;

        ctx.drawImage(image, 0, 0, canvas.width, canvas.height);

        const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

        // 使用jsQR库解析
        if (typeof jsQR !== 'undefined') {
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: "dontInvert",
            });
            resolve(code);
        } else {
            showToast('二维码解析库未加载');
            resolve(null);
        }
    });
}

/**
 * 处理扫描到的二维码数据
 */
async function processQRCodeData(data) {
    console.log('QR Code Data:', data);

    // 检查是否是otpauth链接
    if (data.startsWith('otpauth://')) {
        const parsed = app.parseOtpAuthUri(data);
        if (parsed) {
            // 切换到手动输入标签
            switchTabByName('manual');

            // 自动填充到手动输入表单
            document.getElementById('issuer').value = parsed.issuer;
            document.getElementById('accountName').value = parsed.account;
            document.getElementById('secret').value = parsed.secret;

            // 高亮显示已填充的字段
            highlightFilledFields();

            // 显示提示，让用户知道可以编辑
            showToast('✓ 扫描成功！已自动填充信息，您可以修改账户名或添加备注', 5000);
        } else {
            showToast('无法解析二维码内容');
        }
    } else {
        // 不是otpauth链接，可能是其他内容
        showToast('这不是有效的2FA二维码');
    }
}

/**
 * 高亮显示已填充的字段，提示用户可以编辑
 */
function highlightFilledFields() {
    const fields = ['issuer', 'accountName', 'secret'];
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field && field.value) {
            // 添加绿色高亮效果
            field.style.backgroundColor = '#e8f5e9';
            field.style.border = '2px solid #4caf50';
            field.style.transition = 'all 0.3s ease';

            // 3秒后恢复正常
            setTimeout(() => {
                field.style.backgroundColor = '';
                field.style.border = '';
            }, 3000);
        }
    });

    // 聚焦到备注字段，提示用户可以添加备注
    const noteField = document.getElementById('accountNote');
    if (noteField) {
        setTimeout(() => {
            noteField.focus();
            noteField.placeholder = '👈 可以在这里添加备注，方便识别';
            setTimeout(() => {
                noteField.placeholder = '如：公司账号、个人邮箱等';
            }, 4000);
        }, 600);
    }
}

/**
 * 切换标签（通过名称）
 */
function switchTabByName(tabName) {
    const tabs = {
        'manual': 0,
        'scan': 1,
        'import': 2
    };

    const buttons = document.querySelectorAll('.tab-btn');
    if (buttons[tabs[tabName]]) {
        buttons[tabs[tabName]].click();
    }
}

/**
 * 启动摄像头扫描
 */
let videoStream = null;
let scanningInterval = null;

async function startCameraScanning() {
    const video = document.getElementById('qrVideo');
    const preview = document.getElementById('cameraPreview');

    try {
        // 请求摄像头权限
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' } // 优先使用后置摄像头
        });

        videoStream = stream;
        video.srcObject = stream;
        video.play();
        preview.style.display = 'block';

        // 显示扫描中提示
        showToast('📷 摄像头已启动，请将二维码对准镜头', 2000);

        // 开始扫描循环
        scanningInterval = setInterval(async () => {
            const result = await scanQRCodeFromVideo(video);
            if (result && result.data) {
                // 找到二维码，停止扫描
                stopCameraScanning();
                await processQRCodeData(result.data);
            }
        }, 500); // 每500ms扫描一次

    } catch (error) {
        if (error.name === 'NotAllowedError') {
            showToast('❌ 您拒绝了摄像头权限，无法扫描', 4000);
        } else if (error.name === 'NotFoundError') {
            showToast('❌ 未检测到摄像头设备', 4000);
        } else {
            showToast('❌ 摄像头启动失败：' + error.message, 4000);
        }
        console.error('Camera error:', error);
    }
}

/**
 * 停止摄像头扫描
 */
function stopCameraScanning() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }

    if (scanningInterval) {
        clearInterval(scanningInterval);
        scanningInterval = null;
    }

    const preview = document.getElementById('cameraPreview');
    preview.style.display = 'none';
}

/**
 * 从视频流中扫描二维码
 */
async function scanQRCodeFromVideo(video) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;

    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);

    if (typeof jsQR !== 'undefined') {
        return jsQR(imageData.data, imageData.width, imageData.height, {
            inversionAttempts: "dontInvert",
        });
    }

    return null;
}

/**
 * 扫描二维码（旧方法，保留兼容性）
 */
function scanQRCode() {
    document.getElementById('qrImageFile').click();
}

/**
 * 处理文件选择
 */
function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    document.getElementById('fileName').textContent = `已选择：${file.name}`;

    const reader = new FileReader();
    reader.onload = function (e) {
        const content = e.target.result;
        document.getElementById('importText').value = content;
        parseImportContent(content);
    };
    reader.readAsText(file);
}

/**
 * 解析导入内容
 */
function parseImportContent(content) {
    if (!content || !content.trim()) {
        document.getElementById('importPreview').style.display = 'none';
        return;
    }

    try {
        const accounts = parseBackupContent(content);

        if (accounts.length === 0) {
            showToast('未找到有效的账户数据');
            document.getElementById('importPreview').style.display = 'none';
            return;
        }

        // 显示预览
        pendingImportAccounts = accounts;
        displayImportPreview(accounts);
        document.getElementById('importPreview').style.display = 'block';

    } catch (error) {
        showToast('解析失败：' + error.message);
        console.error('Parse error:', error);
        document.getElementById('importPreview').style.display = 'none';
    }
}

/**
 * 解析备份内容（支持多种格式）
 */
function parseBackupContent(content) {
    content = content.trim();
    const accounts = [];

    // 尝试JSON格式
    if (content.startsWith('{') || content.startsWith('[')) {
        try {
            const data = JSON.parse(content);

            // Aegis格式
            if (data.db && data.db.entries) {
                data.db.entries.forEach(entry => {
                    if (entry.type === 'totp' && entry.info) {
                        accounts.push({
                            issuer: entry.issuer || 'Unknown',
                            account: entry.name || '',
                            secret: entry.info.secret,
                            digits: entry.info.digits || 6,
                            period: entry.info.period || 30
                        });
                    }
                });
            }
            // 标准JSON数组
            else if (Array.isArray(data)) {
                data.forEach(item => {
                    if (item.uri && item.uri.startsWith('otpauth://')) {
                        const parsed = app.parseOtpAuthUri(item.uri);
                        if (parsed) accounts.push(parsed);
                    } else if (item.secret) {
                        accounts.push({
                            issuer: item.issuer || item.label || 'Unknown',
                            account: item.account || item.name || '',
                            secret: item.secret,
                            digits: item.digits || 6,
                            period: item.period || 30
                        });
                    }
                });
            }
            // 单个对象
            else if (data.secret) {
                accounts.push({
                    issuer: data.issuer || 'Unknown',
                    account: data.account || data.name || '',
                    secret: data.secret,
                    digits: data.digits || 6,
                    period: data.period || 30
                });
            }

            return accounts;
        } catch (e) {
            // JSON解析失败，尝试其他格式
        }
    }

    // URI列表格式
    if (content.includes('otpauth://')) {
        const lines = content.split('\n');
        lines.forEach(line => {
            line = line.trim();
            if (line.startsWith('otpauth://')) {
                const parsed = app.parseOtpAuthUri(line);
                if (parsed) accounts.push(parsed);
            }
        });
        return accounts;
    }

    // Google Authenticator导出格式提示
    if (content.startsWith('otpauth-migration://')) {
        throw new Error('Google Authenticator导出格式需要先转换。请使用"账户转移"功能导出为标准格式，或使用其他验证器的备份功能。');
    }

    return accounts;
}

/**
 * 显示导入预览
 */
function displayImportPreview(accounts) {
    const listEl = document.getElementById('importList');
    let html = '<div style="background: #f8f9fa; padding: 10px; border-radius: 5px;">';

    accounts.forEach((account, index) => {
        html += `
            <div style="padding: 8px; margin: 5px 0; background: white; border-radius: 4px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>${escapeHtml(account.issuer)}</strong>
                    <div style="font-size: 12px; color: #666;">${escapeHtml(account.account)}</div>
                </div>
                <span style="color: #28a745;">✓</span>
            </div>
        `;
    });

    html += '</div>';
    html += `<div style="margin-top: 10px; padding: 10px; background: #d1ecf1; color: #0c5460; border-radius: 5px; font-size: 14px;">
        共找到 ${accounts.length} 个账户
    </div>`;

    listEl.innerHTML = html;
}

/**
 * 导入账户
 */
function importAccounts() {
    if (pendingImportAccounts.length === 0) {
        showToast('请先选择要导入的文件或粘贴内容');
        return;
    }

    let successCount = 0;
    let failCount = 0;

    pendingImportAccounts.forEach(account => {
        try {
            // 验证密钥
            const secret = account.secret.replace(/[\s-]/g, '').toUpperCase();
            if (!/^[A-Z2-7]+=*$/.test(secret)) {
                failCount++;
                return;
            }

            app.addAccount(
                account.issuer,
                account.account,
                secret,
                account.digits || 6,
                account.period || 30
            );
            successCount++;
        } catch (error) {
            console.error('Failed to import account:', account, error);
            failCount++;
        }
    });

    closeAddModal();
    displayAccounts();

    let message = `✓ 成功导入 ${successCount} 个账户`;
    if (failCount > 0) {
        message += `，${failCount} 个失败`;
    }
    showToast(message);

    // 清空
    pendingImportAccounts = [];
    document.getElementById('importText').value = '';
    document.getElementById('fileName').textContent = '';
    document.getElementById('importPreview').style.display = 'none';
}

/**
 * 导出当前账户（显示格式选择）
 */
function exportAccounts() {
    const accounts = app.getAccounts();
    if (accounts.length === 0) {
        showToast('没有账户可以导出');
        return;
    }

    // 显示格式选择对话框
    showExportModal(accounts);
}

/**
 * 显示导出格式选择模态框
 */
function showExportModal(accounts) {
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.innerHTML = `
        <div class="modal-overlay" onclick="this.parentElement.remove()"></div>
        <div class="modal-content">
            <div class="modal-header">
                <h2>选择导出格式</h2>
                <button class="close-btn" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <div class="modal-body">
                <p style="color: #666; margin-bottom: 20px;">
                    共 ${accounts.length} 个账户 • 选择目标验证器的格式
                </p>
                
                <div class="export-formats">
                    <div class="export-format-item" onclick="doExport('weline', ${accounts.length})">
                        <div class="format-icon">📱</div>
                        <div class="format-info">
                            <strong>Weline验证器</strong>
                            <small>本应用标准格式（JSON）</small>
                        </div>
                        <div class="format-badge">推荐</div>
                    </div>
                    
                    <div class="export-format-item" onclick="doExport('aegis', ${accounts.length})">
                        <div class="format-icon">🛡️</div>
                        <div class="format-info">
                            <strong>Aegis Authenticator</strong>
                            <small>Android最佳开源验证器</small>
                        </div>
                    </div>
                    
                    <div class="export-format-item" onclick="doExport('andotp', ${accounts.length})">
                        <div class="format-icon">🤖</div>
                        <div class="format-info">
                            <strong>andOTP</strong>
                            <small>开源Android验证器</small>
                        </div>
                    </div>
                    
                    <div class="export-format-item" onclick="doExport('2fas', ${accounts.length})">
                        <div class="format-icon">🔐</div>
                        <div class="format-info">
                            <strong>2FAS Authenticator</strong>
                            <small>跨平台验证器</small>
                        </div>
                    </div>
                    
                    <div class="export-format-item" onclick="doExport('uri_list', ${accounts.length})">
                        <div class="format-icon">📄</div>
                        <div class="format-info">
                            <strong>URI列表（通用）</strong>
                            <small>兼容所有验证器（TXT）</small>
                        </div>
                        <div class="format-badge">兼容最好</div>
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 8px; font-size: 14px; color: #856404;">
                    ⚠️ <strong>安全提示：</strong>备份文件包含所有账户的密钥，请妥善保管！
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

/**
 * 执行导出
 */
function doExport(format, accountCount) {
    const accounts = app.getAccounts();
    let content, filename, mimeType;

    const exportData = accounts.map(acc => ({
        issuer: acc.issuer,
        account: acc.account,
        secret: acc.secret,
        digits: acc.digits || 6,
        period: acc.period || 30,
        algorithm: acc.algorithm || 'SHA1',
        type: 'totp'
    }));

    switch (format) {
        case 'aegis':
            content = exportToAegis(exportData);
            filename = `aegis-backup-${Date.now()}.json`;
            mimeType = 'application/json';
            break;

        case 'andotp':
            content = exportToAndOTP(exportData);
            filename = `andotp-backup-${Date.now()}.json`;
            mimeType = 'application/json';
            break;

        case '2fas':
            content = exportTo2FAS(exportData);
            filename = `2fas-backup-${Date.now()}.json`;
            mimeType = 'application/json';
            break;

        case 'uri_list':
            content = exportToUriList(exportData);
            filename = `2fa-accounts-${Date.now()}.txt`;
            mimeType = 'text/plain';
            break;

        case 'weline':
        default:
            content = JSON.stringify(exportData, null, 2);
            filename = `weline-2fa-backup-${Date.now()}.json`;
            mimeType = 'application/json';
            break;
    }

    // 下载文件
    const blob = new Blob([content], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);

    // 关闭模态框
    document.querySelector('.modal').remove();

    showToast(`✓ 已导出 ${accountCount} 个账户（${getFormatName(format)}格式）`);
}

/**
 * 导出为Aegis格式
 */
function exportToAegis(accounts) {
    const entries = accounts.map(acc => ({
        type: 'totp',
        uuid: generateUUID(),
        name: acc.account,
        issuer: acc.issuer,
        note: '',
        icon: null,
        info: {
            secret: acc.secret,
            algo: (acc.algorithm || 'SHA1').toUpperCase(),
            digits: acc.digits || 6,
            period: acc.period || 30
        }
    }));

    return JSON.stringify({
        type: 'totp',
        version: 1,
        db: {
            version: 2,
            entries: entries
        }
    }, null, 2);
}

/**
 * 导出为andOTP格式
 */
function exportToAndOTP(accounts) {
    const entries = accounts.map(acc => ({
        secret: acc.secret,
        issuer: acc.issuer,
        label: acc.account,
        digits: acc.digits || 6,
        type: 'TOTP',
        algorithm: (acc.algorithm || 'SHA1').toUpperCase(),
        thumbnail: 'Default',
        last_used: 0,
        used_frequency: 0,
        period: acc.period || 30,
        tags: []
    }));

    return JSON.stringify(entries, null, 2);
}

/**
 * 导出为2FAS格式
 */
function exportTo2FAS(accounts) {
    const services = accounts.map((acc, index) => ({
        otp: {
            account: acc.account,
            digits: acc.digits || 6,
            period: acc.period || 30,
            algorithm: (acc.algorithm || 'SHA1').toUpperCase(),
            secret: acc.secret,
            issuer: acc.issuer
        },
        type: 'totp',
        name: acc.issuer,
        icon: null,
        order: {
            position: index
        }
    }));

    return JSON.stringify({
        version: 2,
        services: services,
        groups: []
    }, null, 2);
}

/**
 * 导出为URI列表
 */
function exportToUriList(accounts) {
    const uris = accounts.map(acc => {
        const params = new URLSearchParams({
            secret: acc.secret,
            issuer: acc.issuer,
            algorithm: acc.algorithm || 'SHA1',
            digits: acc.digits || 6,
            period: acc.period || 30
        });
        return `otpauth://totp/${encodeURIComponent(acc.issuer)}:${encodeURIComponent(acc.account)}?${params}`;
    });

    return uris.join('\n');
}

/**
 * 生成UUID
 */
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

/**
 * 获取格式显示名称
 */
function getFormatName(format) {
    const names = {
        'weline': 'Weline',
        'aegis': 'Aegis',
        'andotp': 'andOTP',
        '2fas': '2FAS',
        'uri_list': 'URI列表'
    };
    return names[format] || 'JSON';
}

/**
 * 监听导入文本框变化
 */
document.addEventListener('DOMContentLoaded', function () {
    const importText = document.getElementById('importText');
    if (importText) {
        let timeoutId;
        importText.addEventListener('input', function () {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(() => {
                parseImportContent(this.value);
            }, 500);
        });
    }

    // 恢复码勾选框监听
    const recoveryCheckbox = document.getElementById('editEnableRecovery');
    if (recoveryCheckbox) {
        recoveryCheckbox.addEventListener('change', function () {
            const display = document.getElementById('recoveryCodesDisplay');
            const codesList = document.getElementById('recoveryCodesList');

            if (this.checked) {
                // 生成并显示恢复码
                const codes = generateRecoveryCodes(8);
                codesList.innerHTML = codes.map((code, index) =>
                    `<div style="padding: 5px 0;">
                        ${index + 1}. <code style="background: #fff; padding: 4px 8px; border-radius: 4px; font-weight: bold;">${code}</code>
                    </div>`
                ).join('');
                display.style.display = 'block';
                showToast(__('✓ 已生成8个恢复码，请务必保存'), 4000);
            } else {
                display.style.display = 'none';
            }
        });
    }

    // 账户名编辑框实时保存
    const editAccountName = document.getElementById('editAccountName');
    if (editAccountName) {
        editAccountName.addEventListener('input', function () {
            if (pendingAccount) {
                pendingAccount.account = this.value.trim();
            }
        });
    }

    // 应用i18n翻译
    applyTranslations();
});

/**
 * i18n翻译函数 - 使用框架提供的翻译系统
 * 
 * Weline Framework会自动将__()函数注入到页面中
 * 翻译文件位于：app/code/Weline/TwoFactorAuth/i18n/zh_CN.csv 和 en_US.csv
 * 
 * 如果框架未注入__()函数（PWA独立应用场景），提供简单的fallback
 */
if (typeof window.__ === 'undefined') {
    console.warn('框架__()函数未注入，使用fallback模式');

    // Fallback：简单的参数替换，直接返回原文本
    window.__ = function (text, args = null) {
        let result = text;

        // 处理参数替换（支持框架的占位符格式）
        if (args !== null) {
            if (typeof args === 'string' || typeof args === 'number') {
                // 单个参数：%{}
                result = result.replace(/%\{\}/g, String(args));
            } else if (Array.isArray(args)) {
                // 数组参数：%{1}, %{2}, ...
                args.forEach((arg, index) => {
                    const pattern = new RegExp(`%\\{${index + 1}\\}`, 'g');
                    result = result.replace(pattern, String(arg));
                });
            } else if (typeof args === 'object') {
                // 对象参数：%{name}, %{count}, ...
                for (let key in args) {
                    const pattern = new RegExp(`%\\{${key}\\}`, 'g');
                    result = result.replace(pattern, String(args[key]));
                }
            }
        }

        return result;
    };
}

/**
 * 应用翻译到HTML元素（data-i18n属性）
 * 注意：这只是fallback，框架会自动处理翻译
 */
function applyTranslations() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.getAttribute('data-i18n');
        if (key) {
            el.textContent = __(key);
        }
    });
}

/**
 * 显示Toast提示
 */
function showToast(message, duration = 3000) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}

/**
 * HTML转义
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * 检查登录状态
 * 确保只有登录用户才能使用验证器
 */
async function checkLoginStatus() {
    // 检查URL参数，如果有from=backend&logged=1说明是通过后台控制器访问
    const urlParams = new URLSearchParams(window.location.search);
    const from = urlParams.get('from');
    const logged = urlParams.get('logged');

    if (from === 'backend' && logged === '1') {
        console.log('✓ 通过后台菜单访问，用户已登录');
        return true;
    }

    // 如果直接访问静态URL（没有正确的参数），显示警告提示
    if (window.location.pathname.includes('/static/')) {
        showLoginWarning();
        return false;
    }

    return true;
}

/**
 * 显示登录警告
 */
function showLoginWarning() {
    const warningHtml = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; 
                    background: rgba(0,0,0,0.9); z-index: 99999; 
                    display: flex; align-items: center; justify-content: center;">
            <div style="background: white; padding: 40px; border-radius: 16px; 
                        max-width: 500px; text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,0.3);">
                <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
                <h2 style="color: #d32f2f; margin-bottom: 16px; font-size: 24px;">
                    ${__('需要登录才能使用')}
                </h2>
                <p style="color: #666; margin-bottom: 24px; line-height: 1.6;">
                    ${__('验证器应用需要登录后才能使用<br>请通过后台菜单访问')}
                </p>
                <div style="padding: 16px; background: #f5f5f5; border-radius: 8px; 
                            margin-bottom: 24px; font-family: monospace; font-size: 14px; color: #333;">
                    ${__('后台菜单')} → ${__('工具')} → ${__('双因素认证器')}
                </div>
                <button onclick="window.location.href='/backend/admin/login'" 
                        style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                               color: white; border: none; padding: 14px 32px; 
                               border-radius: 8px; font-size: 16px; cursor: pointer; 
                               font-weight: 500; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);">
                    ${__('前往登录')} →
                </button>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', warningHtml);
}

/**
 * 初始化应用
 */
async function initApp() {
    // 首先检查登录状态
    const isLoggedIn = await checkLoginStatus();
    if (!isLoggedIn) {
        return; // 未登录，显示警告并停止初始化
    }

    // 已登录，继续初始化
    displayAccounts();

    // 每秒更新倒计时（不重新生成验证码，避免闪烁）
    setInterval(() => {
        const remaining = app.getRemainingSeconds();

        // 当倒计时归零时，重新生成所有验证码
        if (remaining === 30 || remaining === 0) {
            displayAccounts();
        } else {
            // 否则只更新倒计时显示
            updateAllTimers();
        }
    }, 1000);
}

// 页面加载完成后初始化
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initApp);
} else {
    initApp();
}

