/**
 * 自动寻客 Agent 安全外壳
 * 
 * 负责域名验证、Token验证和动态代码加载
 */

(function() {
    'use strict';

    /**
     * Agent Shell 主类
     */
    class AgentShell {
        constructor() {
            this.allowedDomains = []; // 从配置或API获取
            this.token = null;
            this.tokenCheckInterval = null;
            this.statusDiv = null;
            this.coreLoaded = false;
        }

        /**
         * 初始化
         */
        async init() {
            try {
                this.createStatusDiv();
                this.updateStatus(__('正在初始化...'), 'info');

                // 检查域名
                if (!this.checkDomain()) {
                    this.updateStatus(__('域名未授权'), 'error');
                    return false;
                }

                // 检查Token
                this.token = this.getTokenFromStorage();
                if (!this.token) {
                    this.updateStatus(__('Token未找到，请先获取Token'), 'warning');
                    return false;
                }

                // 验证Token
                if (!await this.validateToken()) {
                    this.updateStatus(__('Token验证失败'), 'error');
                    return false;
                }

                // 启动Token续订检查
                this.startTokenRenewalCheck();

                // 加载核心代码
                await this.loadCore();

                this.updateStatus(__('Agent初始化成功'), 'success');
                return true;

            } catch (error) {
                console.error('AgentShell init error:', error);
                this.updateStatus(__('初始化失败：%{1}', [error.message]), 'error');
                return false;
            }
        }

        /**
         * 检查域名
         */
        checkDomain() {
            const hostname = window.location.hostname;
            
            // 如果允许列表为空，从API获取
            if (this.allowedDomains.length === 0) {
                // 临时允许当前域名（实际应该从API获取）
                this.allowedDomains = [hostname];
            }

            return this.allowedDomains.includes(hostname);
        }

        /**
         * 从localStorage获取Token
         */
        getTokenFromStorage() {
            return localStorage.getItem('auto_lead_agent_token');
        }

        /**
         * 保存Token到localStorage
         */
        saveTokenToStorage(token) {
            localStorage.setItem('auto_lead_agent_token', token);
            this.token = token;
        }

        /**
         * 验证Token
         */
        async validateToken() {
            try {
                const response = await fetch('/api/v1/auto-lead-agent/token/validate?token=' + encodeURIComponent(this.token) + '&domain=' + encodeURIComponent(window.location.hostname), {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                    }
                });

                const result = await response.json();
                return result.code === 200 && result.data && result.data.valid === true;

            } catch (error) {
                console.error('Token validation error:', error);
                return false;
            }
        }

        /**
         * 加载核心代码
         */
        async loadCore() {
            if (this.coreLoaded) {
                return;
            }

            try {
                this.updateStatus(__('正在加载核心代码...'), 'info');

                // 从API获取核心代码
                const response = await fetch('/api/v1/auto-lead-agent/core', {
                    method: 'GET',
                    headers: {
                        'X-Agent-Token': this.token,
                        'Content-Type': 'application/javascript',
                    }
                });

                if (!response.ok) {
                    throw new Error(__('加载核心代码失败：HTTP %{1}', [response.status]));
                }

                const coreCode = await response.text();

                // 使用 new Function 执行代码
                const coreFunction = new Function(coreCode);
                coreFunction();

                this.coreLoaded = true;
                this.updateStatus(__('核心代码加载成功'), 'success');

            } catch (error) {
                console.error('Load core error:', error);
                this.updateStatus(__('加载核心代码失败：%{1}', [error.message]), 'error');
                throw error;
            }
        }

        /**
         * 启动Token续订检查
         */
        startTokenRenewalCheck() {
            // 每5分钟检查一次Token
            this.tokenCheckInterval = setInterval(async () => {
                if (!await this.validateToken()) {
                    this.updateStatus(__('Token已过期，请重新获取'), 'error');
                    this.stopAgent();
                }
            }, 5 * 60 * 1000);
        }

        /**
         * 停止Agent
         */
        stopAgent() {
            if (this.tokenCheckInterval) {
                clearInterval(this.tokenCheckInterval);
                this.tokenCheckInterval = null;
            }
            this.coreLoaded = false;
        }

        /**
         * 创建状态显示div
         */
        createStatusDiv() {
            this.statusDiv = document.createElement('div');
            this.statusDiv.id = 'agent-shell-status';
            this.statusDiv.style.cssText = 'position:fixed;top:10px;right:10px;padding:10px;background:#fff;border:1px solid #ccc;border-radius:4px;z-index:9999;font-size:12px;';
            document.body.appendChild(this.statusDiv);
        }

        /**
         * 更新状态
         */
        updateStatus(message, type = 'info') {
            if (!this.statusDiv) {
                return;
            }

            const colors = {
                'info': '#2196F3',
                'success': '#4CAF50',
                'warning': '#FF9800',
                'error': '#F44336'
            };

            this.statusDiv.style.borderColor = colors[type] || colors.info;
            this.statusDiv.textContent = message;
        }
    }

    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            const shell = new AgentShell();
            shell.init();
            window.AgentShell = shell;
        });
    } else {
        const shell = new AgentShell();
        shell.init();
        window.AgentShell = shell;
    }

})();

