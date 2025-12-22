/**
 * 自动寻客 Agent 核心逻辑
 * 
 * 此文件通过API动态加载，包含WASM加载和业务逻辑
 */

(function() {
    'use strict';

    /**
     * Agent Core 主类
     */
    class AgentCore {
        constructor() {
            this.wasmModule = null;
            this.wasmInstance = null;
            this.token = null;
        }

        /**
         * 初始化
         */
        async init(token) {
            try {
                this.token = token || window.AgentShell?.token;

                // 加载WASM
                await this.loadWASM();

                console.log('AgentCore initialized successfully');
                return true;

            } catch (error) {
                console.error('AgentCore init error:', error);
                throw error;
            }
        }

        /**
         * 加载WASM模块
         */
        async loadWASM() {
            try {
                // 获取WASM哈希
                const hashResponse = await fetch('/api/v1/auto-lead-agent/wasm/hash', {
                    headers: {
                        'X-Agent-Token': this.token,
                    }
                });
                const hashResult = await hashResponse.json();
                const expectedHash = hashResult.data?.hash;

                if (!expectedHash) {
                    throw new Error('Failed to get WASM hash');
                }

                // 下载WASM文件
                const wasmResponse = await fetch('/api/v1/auto-lead-agent/wasm/download', {
                    headers: {
                        'X-Agent-Token': this.token,
                    }
                });

                if (!wasmResponse.ok) {
                    throw new Error('Failed to download WASM file');
                }

                const wasmBytes = await wasmResponse.arrayBuffer();

                // 验证WASM哈希
                const actualHash = await this.calculateSHA256(wasmBytes);
                if (actualHash !== expectedHash) {
                    throw new Error('WASM hash mismatch');
                }

                // 实例化WASM模块
                const wasmModule = await WebAssembly.compile(wasmBytes);
                this.wasmInstance = await WebAssembly.instantiate(wasmModule, {
                    env: {
                        memory: new WebAssembly.Memory({ initial: 256, maximum: 512 })
                    }
                });

                this.wasmModule = wasmModule;
                console.log('WASM module loaded and verified');

            } catch (error) {
                console.error('Load WASM error:', error);
                throw error;
            }
        }

        /**
         * 计算SHA-256哈希
         */
        async calculateSHA256(buffer) {
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        }

        /**
         * 调用WASM函数：计算客户评分
         */
        calculateCustomerScore(profileData) {
            if (!this.wasmInstance) {
                throw new Error('WASM not loaded');
            }

            const profileStr = JSON.stringify(profileData);
            const profileLength = profileStr.length;

            // 调用WASM函数（需要根据实际绑定调整）
            // 这里假设WASM导出了 calculateCustomerScore 函数
            if (this.wasmInstance.exports.calculateCustomerScore) {
                return this.wasmInstance.exports.calculateCustomerScore(profileStr, profileLength);
            }

            // 如果WASM未正确绑定，使用JS实现作为fallback
            return this.calculateCustomerScoreJS(profileData);
        }

        /**
         * JS实现的客户评分（fallback）
         */
        calculateCustomerScoreJS(profileData) {
            // 简化版评分算法
            let score = 0;
            const keywords = ['客户', '产品', '服务', '质量', '价格', '品牌'];
            const text = JSON.stringify(profileData);
            
            keywords.forEach(keyword => {
                if (text.includes(keyword)) {
                    score += 15;
                }
            });

            return Math.min(100, score);
        }
    }

    // 导出到全局
    window.AgentCore = AgentCore;

    // 如果AgentShell存在，自动初始化
    if (window.AgentShell && window.AgentShell.token) {
        const core = new AgentCore();
        core.init(window.AgentShell.token).then(() => {
            window.agentCore = core;
        });
    }

})();

