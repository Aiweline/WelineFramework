/**
 * AutoLeadAgent 扩展弹窗脚本
 */

document.addEventListener('DOMContentLoaded', function() {
    // 从存储中加载统计数据
    chrome.storage.local.get(['crawlCount', 'foundCount'], function(result) {
        document.getElementById('crawlCount').textContent = result.crawlCount || 0;
        document.getElementById('foundCount').textContent = result.foundCount || 0;
    });
    
    // 检查扩展状态
    const statusEl = document.getElementById('status');
    statusEl.textContent = '✓ 已激活';
    statusEl.classList.add('active');
});

