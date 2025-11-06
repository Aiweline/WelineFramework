// 混淆的TOTP Worker - 使用编码保护算法（Worker中调用后端API）
(function() {
    'use strict';
    
    // 混淆字符串数组
    const _0x4a3b=['fetch','json','success','hash_value','digits','period','remaining','expires_at','floor','max','ceil','pow','toString','padStart','POST','application/x-www-form-urlencoded','uri','parse','split','trim','startsWith','endsWith','push','forEach','length','encodeURIComponent'];
    
    // 解码函数
    const _0x5c2d = function(_0x1a2b) {
        _0x1a2b = _0x1a2b - 0x0;
        return _0x4a3b[_0x1a2b];
    };
    
    // 存储账户数据（Worker内存中）
    const _0x6d7e = new Map();
    
    // 从后端获取验证码数据（Worker中直接调用API，不依赖localStorage）
    async function _0xa1b2(_0xc3d4) {
        // 检查内存缓存
        const _0xe5f6 = _0x6d7e.get(_0xc3d4);
        if (_0xe5f6) {
            const _0xg7h8 = Math[_0x5c2d('0x8')](Date.now() / 0x3e8);
            if (_0xe5f6[_0x5c2d('0x7')] > _0xg7h8 + 0x1) {
                return {
                    hash_value: _0xe5f6[_0x5c2d('0x3')],
                    digits: _0xe5f6[_0x5c2d('0x4')],
                    period: _0xe5f6[_0x5c2d('0x5')],
                    remaining: _0xe5f6[_0x5c2d('0x7')] - _0xg7h8,
                    expires_at: _0xe5f6[_0x5c2d('0x7')]
                };
            }
        }
        
        // 从后端获取
        try {
            const _0xq7r8 = await fetch(`/two-factor-auth/frontend/accounts/getcode?account_id=${_0xc3d4}`);
            const _0xs9t0 = await _0xq7r8[_0x5c2d('0x1')]();
            
            if (_0xs9t0[_0x5c2d('0x2')]) {
                const _0xu1v2 = Math[_0x5c2d('0x8')](Date.now() / 0x3e8);
                const _0xw3x4 = {
                    hash_value: _0xs9t0[_0x5c2d('0x3')],
                    digits: _0xs9t0[_0x5c2d('0x4')],
                    period: _0xs9t0[_0x5c2d('0x5')],
                    remaining: _0xs9t0[_0x5c2d('0x6')],
                    expires_at: _0xu1v2 + _0xs9t0[_0x5c2d('0x6')],
                    obtained_at: _0xu1v2
                };
                
                // 存储到内存缓存
                _0x6d7e.set(_0xc3d4, _0xw3x4);
                
                return {
                    hash_value: _0xs9t0[_0x5c2d('0x3')],
                    digits: _0xs9t0[_0x5c2d('0x4')],
                    period: _0xs9t0[_0x5c2d('0x5')],
                    remaining: _0xs9t0[_0x5c2d('0x6')],
                    expires_at: _0xu1v2 + _0xs9t0[_0x5c2d('0x6')]
                };
            }
        } catch(_0xy5z6) {
            console.error('获取验证码失败:', _0xy5z6);
        }
        
        return null;
    }
    
    // 计算TOTP验证码（混淆算法）
    function _0xa7b8(_0xc9d0) {
        if (!_0xc9d0) return null;
        const _0xe1f2 = _0xc9d0[_0x5c2d('0x3')] % Math[_0x5c2d('0xb')](0xa, _0xc9d0[_0x5c2d('0x4')]);
        return _0xe1f2[_0x5c2d('0xc')]()[_0x5c2d('0xd')](_0xc9d0[_0x5c2d('0x4')], '0');
    }
    
    // 更新倒计时信息
    function _0xi5j6() {
        const _0xk7l8 = Math[_0x5c2d('0x8')](Date.now() / 0x3e8);
        const _0xm9n0 = [];
        
        _0x6d7e.forEach((_0xop1q2, _0xrs3t4) => {
            const _0xuv5w6 = Math[_0x5c2d('0x9')](0x0, _0xop1q2[_0x5c2d('0x7')] - _0xk7l8);
            if (_0xuv5w6 <= 0x0) {
                _0xm9n0.push(_0xrs3t4);
            }
        });
        
        return { now: _0xk7l8, toRefresh: _0xm9n0 };
    }
    
    // Worker消息处理
    self.onmessage = async function(_0xza1b2) {
        const _0xc3d4e5 = _0xza1b2.data;
        
        switch(_0xc3d4e5.type) {
            case 'init':
                // 初始化账户（并行获取所有账户数据，提高速度）
                const _0xf6g7h8 = _0xc3d4e5.accountIds || [];
                
                // 并行获取所有账户数据
                const _0xi9j0k1 = await Promise.all(_0xf6g7h8.map(async (_0xl2m3n4) => {
                    const _0xo5p6q7 = await _0xa1b2(_0xl2m3n4);
                    if (_0xo5p6q7) {
                        // 确保数据存储到Map中
                        _0x6d7e.set(_0xl2m3n4, _0xo5p6q7);
                        return {
                            accountId: _0xl2m3n4,
                            code: _0xa7b8(_0xo5p6q7),
                            data: _0xo5p6q7
                        };
                    }
                    return null;
                }));
                
                // 过滤掉null值
                const _0xya1b2 = _0xi9j0k1.filter(_0xzc3d4 => _0xzc3d4 !== null);
                
                self.postMessage({
                    type: 'init_complete',
                    accounts: _0xya1b2
                });
                break;
                
            case 'refresh':
                // 刷新单个账户
                const _0xr8s9t0 = _0xc3d4e5.accountId;
                _0x6d7e.delete(_0xr8s9t0); // 清除缓存强制刷新
                const _0xu1v2w3 = await _0xa1b2(_0xr8s9t0);
                if (_0xu1v2w3) {
                    // 确保数据存储到Map中
                    _0x6d7e.set(_0xr8s9t0, _0xu1v2w3);
                    self.postMessage({
                        type: 'code_update',
                        accountId: _0xr8s9t0,
                        code: _0xa7b8(_0xu1v2w3),
                        data: _0xu1v2w3
                    });
                }
                break;
                
            case 'update_countdowns':
                // 更新所有倒计时
                const _0xy4z5a6 = _0xi5j6();
                const _0xb7c8d9 = [];
                
                // 遍历所有存储的账户数据，同时包含验证码信息
                _0x6d7e.forEach((_0xe0f1g2, _0xh3i4j5) => {
                    if (_0xe0f1g2 && _0xe0f1g2[_0x5c2d('0x7')]) {
                        const _0xk6l7m8 = Math[_0x5c2d('0x9')](0x0, _0xe0f1g2[_0x5c2d('0x7')] - _0xy4z5a6.now);
                        _0xb7c8d9.push({
                            accountId: _0xh3i4j5,
                            remaining: _0xk6l7m8,
                            period: _0xe0f1g2[_0x5c2d('0x5')],
                            code: _0xa7b8(_0xe0f1g2), // 包含验证码，确保显示正确
                            data: _0xe0f1g2 // 包含完整数据
                        });
                    }
                });
                
                self.postMessage({
                    type: 'countdown_update',
                    countdowns: _0xb7c8d9,
                    toRefresh: _0xy4z5a6.toRefresh
                });
                break;
                
            case 'remove':
                // 移除账户
                const _0xn9o0p1 = _0xc3d4e5.accountId;
                _0x6d7e.delete(_0xn9o0p1);
                break;
                
            case 'parse_file':
                // 解析导入文件（混淆逻辑）
                try {
                    const _0xya1b2 = _0xc3d4e5.fileContent;
                    const _0xzc3d4 = _0xc3d4e5.fileName.toLowerCase();
                    let _0xde5f6 = [];
                    
                    if (_0xzc3d4[_0x5c2d('0x15')]('.json')) {
                        _0xde5f6 = _0xge7h8(_0xya1b2);
                    } else if (_0xzc3d4[_0x5c2d('0x15')]('.csv')) {
                        _0xde5f6 = _0xhi9j0(_0xya1b2);
                    } else if (_0xzc3d4[_0x5c2d('0x15')]('.txt')) {
                        _0xde5f6 = _0xjk1l2(_0xya1b2);
                    } else {
                        throw new Error('不支持的文件格式');
                    }
                    
                    self.postMessage({
                        type: 'parse_result',
                        accounts: _0xde5f6,
                        count: _0xde5f6[_0x5c2d('0x17')]
                    });
                } catch(_0xlm3n4) {
                    self.postMessage({
                        type: 'parse_error',
                        error: _0xlm3n4.message
                    });
                }
                break;
                
            case 'import_accounts':
                // 批量导入账户（Worker中调用API）
                const _0xop5q6 = _0xc3d4e5.accounts || [];
                const _0xrs7t8 = { success: 0x0, fail: 0x0, errors: [] };
                
                for (const _0xuv9w0 of _0xop5q6) {
                    try {
                        const _0xx1y2 = await fetch('/two-factor-auth/frontend/accounts/import', {
                            method: _0x5c2d('0xe'),
                            headers: {
                                'Content-Type': _0x5c2d('0xf')
                            },
                            body: `${_0x5c2d('0x10')}=${encodeURIComponent(_0xuv9w0.uri || _0xuv9w0)}`
                        });
                        const _0xz3a4 = await _0xx1y2[_0x5c2d('0x1')]();
                        
                        if (_0xz3a4[_0x5c2d('0x2')]) {
                            _0xrs7t8.success++;
                        } else {
                            _0xrs7t8.fail++;
                            _0xrs7t8.errors.push(_0xz3a4.message || '导入失败');
                        }
                    } catch(_0xb5c6) {
                        _0xrs7t8.fail++;
                        _0xrs7t8.errors.push(_0xb5c6.message);
                    }
                }
                
                self.postMessage({
                    type: 'import_result',
                    result: _0xrs7t8
                });
                break;
        }
    };
    
    // 解析JSON格式（混淆）
    function _0xge7h8(_0xd7e8) {
        const _0xf9g0 = JSON[_0x5c2d('0x11')](_0xd7e8);
        const _0xh1i2 = [];
        
        if (Array.isArray(_0xf9g0)) {
            _0xf9g0[_0x5c2d('0x16')](_0xj3k4 => {
                if (_0xj3k4.uri && _0xj3k4.uri[_0x5c2d('0x14')]('otpauth://')) {
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xj3k4.uri });
                } else if (_0xj3k4.url && _0xj3k4.url[_0x5c2d('0x14')]('otpauth://')) {
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xj3k4.url });
                }
            });
        } else if (_0xf9g0.exportFormat === 'uri' && _0xf9g0.uris) {
            _0xf9g0.uris[_0x5c2d('0x16')](_0xk5l6 => {
                if (_0xk5l6.uri && _0xk5l6.uri[_0x5c2d('0x14')]('otpauth://')) {
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xk5l6.uri });
                }
            });
        } else if (_0xf9g0.rows && Array.isArray(_0xf9g0.rows)) {
            _0xf9g0.rows[_0x5c2d('0x16')](_0xl7m8 => {
                if (_0xl7m8.url && _0xl7m8.url[_0x5c2d('0x14')]('otpauth://')) {
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xl7m8.url });
                }
            });
        } else if (_0xf9g0.schema_version && _0xf9g0.services) {
            _0xf9g0.services[_0x5c2d('0x16')](_0xn9o0 => {
                if (_0xn9o0.secret && _0xn9o0.name) {
                    const _0xp1q2 = _0xn9o0.otp || _0xn9o0.issuer || '';
                    const _0xr3s4 = `otpauth://totp/${encodeURIComponent(_0xp1q2)}:${encodeURIComponent(_0xn9o0.name)}?secret=${_0xn9o0.secret}${_0xp1q2 ? '&issuer=' + encodeURIComponent(_0xp1q2) : ''}`;
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xr3s4 });
                }
            });
        } else if (_0xf9g0.authy && Array.isArray(_0xf9g0.authy)) {
            _0xf9g0.authy[_0x5c2d('0x16')](_0xt5u6 => {
                if (_0xt5u6.uri && _0xt5u6.uri[_0x5c2d('0x14')]('otpauth://')) {
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xt5u6.uri });
                }
            });
        } else if (_0xf9g0.db && _0xf9g0.db.entries && Array.isArray(_0xf9g0.db.entries)) {
            _0xf9g0.db.entries[_0x5c2d('0x16')](_0xv7w8 => {
                if (_0xv7w8.type === 'totp' && _0xv7w8.info && _0xv7w8.info.secret) {
                    const _0xx9y0 = _0xv7w8.name || '';
                    const _0xz1a2 = _0xv7w8.issuer || '';
                    const _0xb3c4 = _0xv7w8.info.algo || 'SHA1';
                    const _0xd5e6 = _0xv7w8.info.digits || 0x6;
                    const _0xf7g8 = _0xv7w8.info.period || 0x1e;
                    const _0xh9i0 = _0xv7w8.info.secret;
                    const _0xj1k2 = `otpauth://totp/${encodeURIComponent(_0xz1a2 || _0xx9y0)}:${encodeURIComponent(_0xx9y0)}?secret=${_0xh9i0}&algorithm=${_0xb3c4}&digits=${_0xd5e6}&period=${_0xf7g8}${_0xz1a2 ? '&issuer=' + encodeURIComponent(_0xz1a2) : ''}`;
                    _0xh1i2[_0x5c2d('0x17')]({ uri: _0xj1k2 });
                }
            });
        }
        
        return _0xh1i2;
    }
    
    // 解析CSV格式（混淆）
    function _0xhi9j0(_0xd7e8) {
        const _0xl3m4 = _0xd7e8[_0x5c2d('0x12')]('\n');
        const _0xn5o6 = [];
        
        for (let _0xp7q8 = 0x1; _0xp7q8 < _0xl3m4[_0x5c2d('0x17')]; _0xp7q8++) {
            const _0xr9s0 = _0xl3m4[_0xp7q8][_0x5c2d('0x13')]();
            if (!_0xr9s0) continue;
            
            const _0xt1u2 = _0xr9s0[_0x5c2d('0x12')](',');
            if (_0xt1u2[_0x5c2d('0x17')] >= 0x2) {
                const _0xv3w4 = _0xt1u2[0x0][_0x5c2d('0x13')]();
                const _0xx5y6 = _0xt1u2[0x1][_0x5c2d('0x13')]();
                const _0xz7a8 = _0xt1u2[0x2] ? _0xt1u2[0x2][_0x5c2d('0x13')]() : '';
                
                if (_0xx5y6) {
                    const _0xb9c0 = `otpauth://totp/${encodeURIComponent(_0xz7a8 || _0xv3w4)}:${encodeURIComponent(_0xv3w4)}?secret=${_0xx5y6}${_0xz7a8 ? '&issuer=' + encodeURIComponent(_0xz7a8) : ''}`;
                    _0xn5o6[_0x5c2d('0x17')]({ uri: _0xb9c0 });
                }
            }
        }
        
        return _0xn5o6;
    }
    
    // 解析TXT格式（混淆）
    function _0xjk1l2(_0xd7e8) {
        const _0xl3m4 = _0xd7e8[_0x5c2d('0x12')]('\n');
        const _0xn5o6 = [];
        
        _0xl3m4[_0x5c2d('0x16')](_0xop1q2 => {
            const _0xrs3t4 = _0xop1q2[_0x5c2d('0x13')]();
            if (_0xrs3t4 && _0xrs3t4[_0x5c2d('0x14')]('otpauth://')) {
                _0xn5o6[_0x5c2d('0x17')]({ uri: _0xrs3t4 });
            }
        });
        
        return _0xn5o6;
    }
})();

