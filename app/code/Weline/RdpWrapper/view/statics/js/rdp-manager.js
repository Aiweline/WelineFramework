/**
 * RDP Wrapper 远程桌面管理前端逻辑
 */
(function () {
    'use strict';

    const config = window.__RdpManagerConfig || {};
    const api = config.api || {};

    const RdpManager = {

        /**
         * 发送 API 请求
         */
        async request(url, data = null, method = 'POST') {
            try {
                const options = {
                    method: method,
                    headers: {'Content-Type': 'application/json'}
                };
                if (data && method !== 'GET') {
                    options.body = JSON.stringify(data);
                }
                const response = await fetch(url, options);
                return await response.json();
            } catch (error) {
                console.error('Request failed:', error);
                return {success: false, message: error.message};
            }
        },

        /**
         * 刷新系统状态
         */
        async refreshStatus() {
            AdminToast.info('正在刷新状态...');
            const result = await this.request(api.status, null, 'GET');
            if (result.success) {
                AdminToast.success('状态已刷新');
                // 重新加载页面以更新状态
                location.reload();
            } else {
                AdminToast.error('刷新失败：' + (result.message || ''));
            }
        },

        /**
         * 安装 RDP Wrapper
         */
        async install() {
            AdminConfirm.show('安装 RDP Wrapper 需要管理员权限，确定继续安装吗？', {
                title: '安装 RDP Wrapper',
                confirmText: '安装',
                cancelText: '取消'
            }).then(async (confirmed) => {
                if (!confirmed) return;

                AdminToast.info('正在安装 RDP Wrapper，请稍候...', 0);
                const result = await this.request(api.install);
                if (result.success) {
                    AdminToast.success(result.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    AdminToast.error(result.message);
                }
            });
        },

        /**
         * 启用远程桌面
         */
        async enableRdp() {
            AdminConfirm.show('确定启用远程桌面？启用后其他设备可通过 RDP 连接到本机。', {
                title: '启用远程桌面',
                confirmText: '启用',
                cancelText: '取消'
            }).then(async (confirmed) => {
                if (!confirmed) return;

                AdminToast.info('正在启用远程桌面...');
                const result = await this.request(api.enableRdp);
                if (result.success) {
                    AdminToast.success(result.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    AdminToast.error(result.message);
                }
            });
        },

        /**
         * 禁用远程桌面
         */
        async disableRdp() {
            AdminConfirm.show('确定禁用远程桌面？禁用后其他设备将无法通过 RDP 连接到本机。', {
                title: '禁用远程桌面',
                confirmText: '禁用',
                cancelText: '取消',
                type: 'danger'
            }).then(async (confirmed) => {
                if (!confirmed) return;

                const result = await this.request(api.disableRdp);
                if (result.success) {
                    AdminToast.success(result.message);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    AdminToast.error(result.message);
                }
            });
        },

        // ==================== 用户管理 ====================

        /**
         * 显示创建用户弹窗
         */
        showCreateUserModal() {
            document.getElementById('createUserForm').reset();
            document.getElementById('createUserModal').style.display = 'flex';
        },

        /**
         * 显示重置密码弹窗
         */
        showResetPasswordModal(username) {
            document.getElementById('resetUsername').value = username;
            document.getElementById('resetUsernameDisplay').value = username;
            document.getElementById('resetPasswordInput').value = '';
            document.getElementById('resetPasswordModal').style.display = 'flex';
        },

        /**
         * 关闭弹窗
         */
        closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        },

        /**
         * 创建用户
         */
        async createUser() {
            const form = document.getElementById('createUserForm');
            const formData = new FormData(form);

            const username = (formData.get('username') || '').trim();
            const password = formData.get('password') || '';

            if (!username || !password) {
                AdminToast.warning('用户名和密码不能为空');
                return;
            }

            if (!/^[a-zA-Z][a-zA-Z0-9_]{2,19}$/.test(username)) {
                AdminToast.warning('用户名格式不正确：3-20位，字母开头，仅允许字母数字下划线');
                return;
            }

            if (password.length < 8) {
                AdminToast.warning('密码长度至少为8位');
                return;
            }

            const data = {
                username: username,
                password: password,
                display_name: (formData.get('display_name') || '').trim(),
                is_admin: formData.get('is_admin') === 'on',
                remark: (formData.get('remark') || '').trim()
            };

            AdminToast.info('正在创建用户...');
            const result = await this.request(api.createUser, data);

            if (result.success) {
                AdminToast.success(result.message);
                this.closeModal('createUserModal');
                setTimeout(() => location.reload(), 1000);
            } else {
                AdminToast.error(result.message);
            }
        },

        /**
         * 删除用户
         */
        async removeUser(username) {
            AdminConfirm.show('确定要删除用户 "' + username + '" 吗？该操作将同时删除该用户的 Windows 账户，不可恢复！', {
                title: '删除用户',
                confirmText: '删除',
                cancelText: '取消',
                type: 'danger'
            }).then(async (confirmed) => {
                if (!confirmed) return;

                const result = await this.request(api.removeUser, {username: username});
                if (result.success) {
                    AdminToast.success(result.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    AdminToast.error(result.message);
                }
            });
        },

        /**
         * 启用/禁用用户
         */
        async toggleUser(username, enable) {
            const action = enable ? '启用' : '禁用';
            AdminConfirm.show('确定要' + action + '用户 "' + username + '" 吗？', {
                title: action + '用户',
                confirmText: '确定',
                cancelText: '取消'
            }).then(async (confirmed) => {
                if (!confirmed) return;

                const result = await this.request(api.toggleUser, {username: username, enable: enable});
                if (result.success) {
                    AdminToast.success(result.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    AdminToast.error(result.message);
                }
            });
        },

        /**
         * 重置密码
         */
        async resetPassword() {
            const username = document.getElementById('resetUsername').value;
            const password = document.getElementById('resetPasswordInput').value;

            if (!password || password.length < 8) {
                AdminToast.warning('密码长度至少为8位');
                return;
            }

            const result = await this.request(api.resetPassword, {username: username, password: password});
            if (result.success) {
                AdminToast.success(result.message);
                this.closeModal('resetPasswordModal');
            } else {
                AdminToast.error(result.message);
            }
        },

        /**
         * 切换密码可见性
         */
        togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'mdi mdi-eye-off';
            } else {
                input.type = 'password';
                icon.className = 'mdi mdi-eye';
            }
        },

        /**
         * 切换使用指南显示/隐藏
         */
        toggleGuide() {
            const content = document.getElementById('guideContent');
            const icon = document.getElementById('guideToggleIcon');
            if (content.style.display === 'none') {
                content.style.display = 'block';
                icon.className = 'mdi mdi-chevron-down';
            } else {
                content.style.display = 'none';
                icon.className = 'mdi mdi-chevron-right';
            }
        }
    };

    // 暴露到全局
    window.RdpManager = RdpManager;

    // 点击弹窗外部关闭
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('rdp-modal-overlay')) {
            e.target.style.display = 'none';
        }
    });

})();
