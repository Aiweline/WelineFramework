
function csAdmin(url, options){
  options=options||{};
  var body=options.body;
  var headers=Object.assign({}, options.headers||{});
  if(body && typeof FormData!=='undefined' && body instanceof FormData){
    var p=new URLSearchParams(); body.forEach(function(v,k){p.append(k,String(v));}); body=p.toString();
  } else if(body && typeof URLSearchParams!=='undefined' && body instanceof URLSearchParams){
    body=body.toString();
  } else if(body && typeof body!=='string'){ try{body=JSON.stringify(body);}catch(e){body='';} }
  if(typeof body==='string' && body.indexOf('=')!==-1 && !headers['Content-Type'] && !headers['content-type']){
    headers['Content-Type']='application/x-www-form-urlencoded;charset=UTF-8';
  }
  var run=function(api){ return api.resource('customerService').adminRequest({url:url, method:options.method||'GET', headers:headers, body:body||''}); };
  if(window.Weline&&window.Weline.load) return window.Weline.load('api').then(run);
  return Promise.resolve(run(window.Weline.Api));
}

/**
 * 客服统计JavaScript
 */
const CustomerServiceStatistics = (function() {
    let config = {
        consoleUrl: '',
        currentPeriod: 'today'
    };
    
    /**
     * 初始化
     */
    function init(options) {
        config = Object.assign(config, options);
        
        // 绑定时间段切换事件
        bindPeriodSelector();
        
        // 加载统计数据
        loadStatistics(config.currentPeriod);
    }
    
    /**
     * 绑定时间段选择器
     */
    function bindPeriodSelector() {
        document.addEventListener('click', function(e) {
            const btn = e.target.closest && e.target.closest('.period-btn');
            if (!btn) {
                return;
            }
            e.preventDefault();

            document.querySelectorAll('.period-btn').forEach(el => {
                el.classList.remove('active');
                el.setAttribute('aria-pressed', 'false');
                el.setAttribute('data-tone', 'neutral');
                el.setAttribute('data-variant', 'outline');
            });

            btn.classList.add('active');
            btn.setAttribute('aria-pressed', 'true');
            btn.setAttribute('data-tone', 'primary');
            btn.removeAttribute('data-variant');

            const period = btn.dataset.period;
            config.currentPeriod = period;
            loadStatistics(period);
        });
    }
    
    /**
     * 加载统计数据
     */
    async function loadStatistics(period) {
        try {
            const data = await csAdmin(config.consoleUrl + '/statistics?period=' + period);
            
            if (data.success) {
                updateStatisticsDisplay(data.data);
            } else {
                console.error('Failed to load statistics:', data.message);
            }
        } catch (error) {
            console.error('Failed to load statistics:', error);
        }
    }
    
    /**
     * 更新统计数据显示
     */
    function updateStatisticsDisplay(statistics) {
        // 更新会话数
        const sessionsElement = document.getElementById('stat-sessions');
        if (sessionsElement) {
            sessionsElement.textContent = statistics.sessions.total || 0;
            
            // 更新详情
            const sessionsCard = sessionsElement.closest('.stat-card');
            if (sessionsCard) {
                const detailElement = sessionsCard.querySelector('.stat-detail');
                if (detailElement) {
                    detailElement.innerHTML = `
                        <span>${__('已关闭')}: ${statistics.sessions.closed || 0}</span>
                        <span>${__('进行中')}: ${statistics.sessions.active || 0}</span>
                    `;
                }
            }
        }
        
        // 更新消息数
        const messagesElement = document.getElementById('stat-messages');
        if (messagesElement) {
            messagesElement.textContent = statistics.messages.total || 0;
        }
        
        // 更新响应时间
        const responseTimeElement = document.getElementById('stat-response-time');
        if (responseTimeElement) {
            const avgTime = statistics.response_time.average || 0;
            responseTimeElement.textContent = formatResponseTime(avgTime);
            
            // 更新详情
            const responseTimeCard = responseTimeElement.closest('.stat-card');
            if (responseTimeCard) {
                const detailElement = responseTimeCard.querySelector('.stat-detail');
                if (detailElement) {
                    const minTime = statistics.response_time.min || 0;
                    const maxTime = statistics.response_time.max || 0;
                    detailElement.innerHTML = `
                        <span>${__('最快')}: ${formatResponseTime(minTime)}</span>
                        <span>${__('最慢')}: ${formatResponseTime(maxTime)}</span>
                    `;
                }
            }
        }
        
        // 更新会话时长
        const durationElement = document.getElementById('stat-duration');
        if (durationElement) {
            durationElement.textContent = statistics.session_duration.average || 0;
            
            // 更新详情
            const durationCard = durationElement.closest('.stat-card');
            if (durationCard) {
                const detailElement = durationCard.querySelector('.stat-detail');
                if (detailElement) {
                    const minDuration = statistics.session_duration.min || 0;
                    const maxDuration = statistics.session_duration.max || 0;
                    detailElement.innerHTML = `
                        <span>${__('最短')}: ${minDuration} ${__('分钟')}</span>
                        <span>${__('最长')}: ${maxDuration} ${__('分钟')}</span>
                    `;
                }
            }
        }
    }
    
    /**
     * 格式化响应时间
     */
    function formatResponseTime(seconds) {
        if (!seconds || seconds === 0) {
            return '0' + __('秒');
        }
        
        if (seconds < 60) {
            return seconds.toFixed(1) + __('秒');
        } else {
            return (seconds / 60).toFixed(1) + __('分钟');
        }
    }
    
    // 导出公共API
    return {
        init,
        loadStatistics
    };
})();
