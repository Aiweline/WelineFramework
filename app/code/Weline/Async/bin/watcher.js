#!/usr/bin/env node

/**
 * 文件监控和同步脚本
 * 使用 chokidar 监控文件变化，通过 rsync 同步到远程服务器
 */

const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const chokidar = require('chokidar');

// 获取配置文件路径
const configFile = process.argv[2];
if (!configFile || !fs.existsSync(configFile)) {
    console.error('错误: 配置文件不存在');
    process.exit(1);
}

// 读取配置
const config = JSON.parse(fs.readFileSync(configFile, 'utf8'));
const mappingId = config.mapping_id || 'unknown';
const host = config.host;
const mapping = config.mapping;

// PID文件路径
const mappingIdStr = String(mappingId);
const pidFile = path.join(__dirname, '../../../../var/async/pids', `mapping_${mappingIdStr}.pid`);
const logFile = path.join(__dirname, '../../../../var/async/logs', `mapping_${mappingIdStr}.log`);

// 确保目录存在
const pidDir = path.dirname(pidFile);
const logDir = path.dirname(logFile);
if (!fs.existsSync(pidDir)) {
    fs.mkdirSync(pidDir, { recursive: true });
}
if (!fs.existsSync(logDir)) {
    fs.mkdirSync(logDir, { recursive: true });
}

// 写入PID
fs.writeFileSync(pidFile, process.pid.toString());

// 日志函数
function log(message) {
    const timestamp = new Date().toISOString();
    const logMessage = `[${timestamp}] ${message}\n`;
    fs.appendFileSync(logFile, logMessage);
    console.log(logMessage.trim());
}

// 执行rsync同步
function syncFile(filePath) {
    return new Promise((resolve, reject) => {
        const localPath = mapping.local_path;
        // 支持多个远程路径
        const remotePaths = mapping.remote_paths || (mapping.remote_path ? [mapping.remote_path] : []);
        
        if (remotePaths.length === 0) {
            log('错误: 没有配置远程路径');
            reject(new Error('没有配置远程路径'));
            return;
        }
        
        // 对每个远程路径执行同步
        const syncPromises = remotePaths.map(remotePath => syncToRemote(filePath, remotePath));
        Promise.all(syncPromises)
            .then(() => resolve())
            .catch(err => reject(err));
    });
}

// 同步到指定的远程路径
function syncToRemote(filePath, remotePath) {
    return new Promise((resolve, reject) => {
        const localPath = mapping.local_path;
        
        // 构建rsync命令
        const rsyncArgs = [
            '-avz',
            '--delete',
            '--progress',
        ];
        
        // 添加排除模式
        if (mapping.exclude_patterns && mapping.exclude_patterns.length > 0) {
            mapping.exclude_patterns.forEach(pattern => {
                rsyncArgs.push('--exclude', pattern);
            });
        }
        
        // 处理 include_paths
        if (mapping.include_paths && mapping.include_paths.length > 0) {
            // 如果指定了 include_paths，只同步这些路径
            if (filePath) {
                // 如果指定了文件路径，检查是否在 include_paths 中
                let shouldSync = false;
                mapping.include_paths.forEach(includePath => {
                    const fullPath = path.isAbsolute(includePath) 
                        ? includePath 
                        : path.join(localPath, includePath);
                    if (filePath.startsWith(fullPath) || filePath.startsWith(path.resolve(fullPath))) {
                        shouldSync = true;
                    }
                });
                if (shouldSync) {
                    rsyncArgs.push(filePath);
                } else {
                    // 文件不在 include_paths 中，跳过同步
                    resolve();
                    return;
                }
            } else {
                // 全量同步时，同步所有 include_paths
                mapping.include_paths.forEach(includePath => {
                    const fullPath = path.isAbsolute(includePath) 
                        ? includePath 
                        : path.join(localPath, includePath);
                    try {
                        const stat = fs.statSync(fullPath);
                        rsyncArgs.push(fullPath + (stat.isDirectory() ? '/' : ''));
                    } catch (e) {
                        // 路径不存在，跳过
                    }
                });
            }
        } else {
            // 如果没有指定 include_paths，同步整个 local_path
            if (filePath) {
                const relativePath = path.relative(localPath, filePath);
                rsyncArgs.push(path.join(localPath, relativePath));
            } else {
                rsyncArgs.push(localPath + '/');
            }
        }
        
        // 构建SSH选项
        const sshOptions = [];
        if (host.key_path && fs.existsSync(host.key_path)) {
            sshOptions.push('-i', host.key_path);
        }
        sshOptions.push('-o', 'StrictHostKeyChecking=no');
        sshOptions.push('-o', 'UserKnownHostsFile=/dev/null');
        
        // 构建远程路径
        const remote = `${host.user}@${host.host}:${remotePath}/`;
        
        // 执行rsync
        const rsync = spawn('rsync', [
            ...rsyncArgs,
            '-e', `ssh ${sshOptions.join(' ')} -p ${host.port || 22}`,
            remote
        ]);
        
        let output = '';
        let errorOutput = '';
        
        rsync.stdout.on('data', (data) => {
            output += data.toString();
        });
        
        rsync.stderr.on('data', (data) => {
            errorOutput += data.toString();
        });
        
        rsync.on('close', (code) => {
            if (code === 0) {
                log(`同步成功: ${filePath || '全量同步'} -> ${remotePath}`);
                resolve();
            } else {
                log(`同步失败 (退出码 ${code}): ${filePath || '全量同步'} -> ${remotePath}`);
                log(`错误输出: ${errorOutput}`);
                reject(new Error(`rsync failed with code ${code}: ${errorOutput}`));
            }
        });
        
        rsync.on('error', (err) => {
            log(`同步错误: ${err.message}`);
            reject(err);
        });
    });
}

// 防抖函数
let syncTimer = null;
const SYNC_DELAY = 1000; // 1秒防抖

function debouncedSync(filePath) {
    if (syncTimer) {
        clearTimeout(syncTimer);
    }
    
    syncTimer = setTimeout(() => {
        syncFile(filePath).catch(err => {
            log(`同步失败: ${err.message}`);
        });
    }, SYNC_DELAY);
}

// 启动监控
const localPath = mapping.local_path;
const includePaths = mapping.include_paths || [];
const excludePatterns = mapping.exclude_patterns || [];

log(`开始监控: ${localPath}`);
const remotePaths = mapping.remote_paths || (mapping.remote_path ? [mapping.remote_path] : []);
log(`目标主机: ${host.user}@${host.host}`);
log(`远程路径: ${remotePaths.join(', ')}`);

// 如果指定了 include_paths，只监控这些路径；否则监控整个 local_path
const watchPaths = includePaths.length > 0 
    ? includePaths.map(p => path.isAbsolute(p) ? p : path.join(localPath, p))
    : [localPath];

log(`监控路径: ${watchPaths.join(', ')}`);
if (includePaths.length > 0) {
    log(`包含路径: ${includePaths.join(', ')}`);
}
if (excludePatterns.length > 0) {
    log(`排除模式: ${excludePatterns.join(', ')}`);
}

// 创建多个 watcher（如果指定了多个 include_paths）
const watchers = watchPaths.map(watchPath => {
    return chokidar.watch(watchPath, {
        ignored: excludePatterns,
        persistent: true,
        ignoreInitial: false,
        followSymlinks: false,
    });
});

// 统一处理所有 watcher 的事件
const watcher = {
    on: function(event, callback) {
        watchers.forEach(w => w.on(event, callback));
    },
    close: function() {
        watchers.forEach(w => w.close());
    }
};

// 文件变化事件
watcher.on('change', (filePath) => {
    log(`文件变化: ${filePath}`);
    debouncedSync(filePath);
});

watcher.on('add', (filePath) => {
    log(`文件新增: ${filePath}`);
    debouncedSync(filePath);
});

watcher.on('unlink', (filePath) => {
    log(`文件删除: ${filePath}`);
    debouncedSync(filePath);
});

watcher.on('addDir', (dirPath) => {
    log(`目录新增: ${dirPath}`);
    debouncedSync();
});

watcher.on('unlinkDir', (dirPath) => {
    log(`目录删除: ${dirPath}`);
    debouncedSync();
});

watcher.on('error', (error) => {
    log(`监控错误: ${error.message}`);
});

watcher.on('ready', () => {
    log('监控已就绪');
});

// 优雅退出
process.on('SIGTERM', () => {
    log('收到SIGTERM信号，正在关闭...');
    watcher.close();
    if (fs.existsSync(pidFile)) {
        fs.unlinkSync(pidFile);
    }
    process.exit(0);
});

process.on('SIGINT', () => {
    log('收到SIGINT信号，正在关闭...');
    watcher.close();
    if (fs.existsSync(pidFile)) {
        fs.unlinkSync(pidFile);
    }
    process.exit(0);
});
