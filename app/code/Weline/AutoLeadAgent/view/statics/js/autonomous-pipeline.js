/**
 * 自主寻客执行管道
 * 
 * 编排完整的自主寻客流程：
 *   画像分析 → 关键词矩阵 → 平台选择 → 逐平台搜索 → 页面提取 → 深度挖掘 → 去重评分 → API录入
 * 
 * 依赖：
 *   - PlatformStrategies: 平台适配策略
 *   - MCPClient: 浏览器工具调用（via content script relay）
 *   - HFModelManager / ModelLifecycle: 端侧 AI 模型（可选）
 */
var AutonomousPipeline = (function () {
    'use strict';

    // ================================================================
    // 管道状态
    // ================================================================

    var pipelineState = {
        running: false,
        cancelled: false,
        taskId: null,
        profile: null,
        keywordMatrix: null,
        selectedPlatforms: [],
        foundCandidates: [],    // 去重后的候选人
        seenUrls: {},           // URL 去重集
        seenEmails: {},         // Email 去重集
        seenPhones: {},         // Phone 去重集
        totalSearched: 0,       // 搜索次数
        totalExtracted: 0,      // 提取次数
        totalSaved: 0,          // 保存次数
        currentPhase: 'idle',   // 当前阶段
        startTime: null,
        errors: []
    };

    // 配置
    var pipelineConfig = {
        apiBaseUrl: '',
        maxCandidatesPerTask: 50,     // 每个任务最多找多少候选人
        maxSearchesPerPlatform: 8,    // 每个平台最多搜索多少次
        deepExtractTopN: 10,          // 对排名前 N 的候选人做深度提取
        saveBatchSize: 5,             // 每批保存数量
        searchDelay: 3000,            // 搜索间隔 ms
        extractDelay: 2000,           // 提取间隔 ms
        preferredSearchEngine: 'google', // google / baidu
        scoreThreshold: 30,           // 最低匹配分数
        enableDeepExtract: true       // 是否启用深度提取
    };

    // 日志回调
    var logCallback = null;

    // ================================================================
    // 日志与进度
    // ================================================================

    function log(level, message, data) {
        var entry = {
            time: new Date().toLocaleTimeString(),
            level: level,
            message: message,
            phase: pipelineState.currentPhase,
            taskId: pipelineState.taskId
        };
        console.log('[Pipeline][' + level + '] ' + message, data || '');

        // 通知浮动面板（通过 content script）
        try {
            window.postMessage({
                type: 'TASK_PROGRESS',
                log: entry
            }, '*');
        } catch (e) { /* ignore */ }

        if (typeof logCallback === 'function') {
            logCallback(entry, data);
        }
    }

    // ================================================================
    // 管道入口
    // ================================================================

    /**
     * 启动自主寻客管道
     * @param {Object} options
     *   - taskId: 任务 ID
     *   - profile: 来源画像 { name, description, industry, region, keywords, products, language, ... }
     *   - selectedWebsites: 目标网站 domain 列表（可选）
     *   - selectedSearchEngines: 搜索引擎列表（可选）
     *   - apiBaseUrl: 后端 API 基础路径
     *   - onLog: 日志回调
     *   - onCandidateFound: 发现候选人回调
     *   - onComplete: 完成回调
     */
    async function start(options) {
        if (pipelineState.running) {
            log('warn', '管道已在运行中');
            return false;
        }

        // 初始化状态
        pipelineState = {
            running: true,
            cancelled: false,
            taskId: options.taskId,
            profile: options.profile,
            keywordMatrix: null,
            selectedPlatforms: [],
            foundCandidates: [],
            seenUrls: {},
            seenEmails: {},
            seenPhones: {},
            totalSearched: 0,
            totalExtracted: 0,
            totalSaved: 0,
            currentPhase: 'initializing',
            startTime: Date.now(),
            errors: []
        };

        pipelineConfig.apiBaseUrl = options.apiBaseUrl || pipelineConfig.apiBaseUrl;
        logCallback = options.onLog || null;
        var onCandidateFound = options.onCandidateFound || function () {};
        var onComplete = options.onComplete || function () {};

        if (options.preferredSearchEngine) {
            pipelineConfig.preferredSearchEngine = options.preferredSearchEngine;
        }

        log('info', '===== 自主寻客管道启动 =====');
        log('info', '任务 ID: ' + options.taskId);
        log('info', '画像: ' + (options.profile.name || options.profile.description || '').substring(0, 80));

        try {
            // ── 阶段 1：画像分析 → 关键词矩阵 ──
            pipelineState.currentPhase = 'analyzing';
            log('info', '▶ 阶段1：画像分析与关键词生成');
            await updateTaskStatus('inferring');

            var matrix = await analyzeProfile(options.profile);
            pipelineState.keywordMatrix = matrix;

            log('success', '关键词矩阵生成完成:');
            log('info', '  行业词: ' + matrix.industry.join(', '));
            log('info', '  地域词: ' + matrix.region.join(', '));
            log('info', '  需求词: ' + matrix.demand.join(', '));
            log('info', '  人群词: ' + matrix.crowd.join(', '));
            log('info', '  产品词: ' + matrix.product.join(', '));
            log('info', '  场景词: ' + matrix.scene.join(', '));
            log('info', '  组合搜索词 (' + matrix.combined.length + '): ' + matrix.combined.slice(0, 5).join(' | ') + (matrix.combined.length > 5 ? ' ...' : ''));

            checkCancelled();

            // ── 阶段 2：平台选择与路由 ──
            pipelineState.currentPhase = 'routing';
            log('info', '▶ 阶段2：平台选择与搜索路由');

            var platformList = PlatformStrategies.selectPlatforms(
                options.profile,
                options.selectedWebsites || []
            );
            pipelineState.selectedPlatforms = platformList;

            log('info', '选中平台 (' + platformList.length + '): ' + platformList.map(function (p) { return p.platform.name; }).join(', '));

            checkCancelled();

            // ── 阶段 3：逐平台搜索与提取 ──
            pipelineState.currentPhase = 'searching';
            log('info', '▶ 阶段3：多平台搜索与提取');
            await updateTaskStatus('crawling');

            for (var pi = 0; pi < platformList.length; pi++) {
                checkCancelled();
                if (pipelineState.foundCandidates.length >= pipelineConfig.maxCandidatesPerTask) {
                    log('info', '已达到最大候选人数量 (' + pipelineConfig.maxCandidatesPerTask + ')，停止搜索');
                    break;
                }

                var platformInfo = platformList[pi];
                log('info', '── 平台 [' + (pi + 1) + '/' + platformList.length + ']: ' + platformInfo.platform.name + ' ──');

                await searchOnPlatform(platformInfo, matrix, onCandidateFound);
            }

            // ── 阶段 4：深度提取（对高分候选人） ──
            if (pipelineConfig.enableDeepExtract && pipelineState.foundCandidates.length > 0) {
                pipelineState.currentPhase = 'deep_extracting';
                log('info', '▶ 阶段4：深度提取高分候选人信息');

                await deepExtractTopCandidates(onCandidateFound);
            }

            // ── 阶段 5：批量保存到后端 ──
            pipelineState.currentPhase = 'saving';
            log('info', '▶ 阶段5：保存候选人到后端');

            var unsaved = pipelineState.foundCandidates.filter(function (c) { return !c._saved; });
            if (unsaved.length > 0) {
                await saveCandidatesBatch(unsaved);
            }

            // ── 完成 ──
            pipelineState.currentPhase = 'completed';
            pipelineState.running = false;
            await updateTaskStatus('completed');

            var elapsed = ((Date.now() - pipelineState.startTime) / 1000).toFixed(1);
            log('success', '===== 自主寻客管道完成 =====');
            log('info', '耗时: ' + elapsed + 's | 搜索: ' + pipelineState.totalSearched + '次 | 提取: ' + pipelineState.totalExtracted + ' | 录入: ' + pipelineState.totalSaved);
            log('info', '发现候选人: ' + pipelineState.foundCandidates.length);

            onComplete({
                success: true,
                totalFound: pipelineState.foundCandidates.length,
                totalSaved: pipelineState.totalSaved,
                elapsed: elapsed,
                candidates: pipelineState.foundCandidates
            });

        } catch (err) {
            pipelineState.running = false;
            pipelineState.currentPhase = 'failed';
            log('error', '管道执行失败: ' + err.message);
            await updateTaskStatus('failed');

            onComplete({ success: false, error: err.message, totalFound: pipelineState.foundCandidates.length });
        }
    }

    /**
     * 取消管道
     */
    function cancel() {
        pipelineState.cancelled = true;
        pipelineState.running = false;
        log('warn', '管道已取消');
    }

    function checkCancelled() {
        if (pipelineState.cancelled) throw new Error('任务已取消');
    }

    // ================================================================
    // 阶段 1：画像分析
    // ================================================================

    async function analyzeProfile(profile) {
        // 基础关键词矩阵（规则驱动，不依赖模型）
        var matrix = PlatformStrategies.generateKeywordMatrix(profile);

        // 如果端侧 AI 模型可用，用模型增强关键词
        if (typeof HFModelManager !== 'undefined' && HFModelManager && HFModelManager.isLoaded &&
            typeof AgentPrompts !== 'undefined' && AgentPrompts) {
            try {
                log('info', '  使用 AI 模型增强关键词分析...');
                var profileText = JSON.stringify(profile, null, 2);
                var prompt = AgentPrompts.generateKeywordGenerationPrompt(profile, profile.language || 'zh');
                var aiResult = await HFModelManager.generate(prompt, { maxTokens: 512 });
                if (aiResult) {
                    // 尝试解析 JSON
                    var jsonMatch = aiResult.match(/\{[\s\S]*\}/);
                    if (jsonMatch) {
                        var aiKeywords = JSON.parse(jsonMatch[0]);
                        // 合并 AI 生成的关键词到矩阵
                        if (aiKeywords.industryKeywords) matrix.industry = mergeArrays(matrix.industry, aiKeywords.industryKeywords);
                        if (aiKeywords.demandKeywords) matrix.demand = mergeArrays(matrix.demand, aiKeywords.demandKeywords);
                        if (aiKeywords.crowdKeywords) matrix.crowd = mergeArrays(matrix.crowd, aiKeywords.crowdKeywords);
                        if (aiKeywords.productKeywords) matrix.product = mergeArrays(matrix.product, aiKeywords.productKeywords);
                        if (aiKeywords.regionKeywords) matrix.region = mergeArrays(matrix.region, aiKeywords.regionKeywords);
                        if (aiKeywords.sceneKeywords) matrix.scene = mergeArrays(matrix.scene, aiKeywords.sceneKeywords);
                        // AI 生成的组合搜索词直接追加
                        if (aiKeywords.combinedQueries) {
                            aiKeywords.combinedQueries.forEach(function (q) {
                                if (matrix.combined.indexOf(q) < 0) matrix.combined.push(q);
                            });
                        }
                        log('success', '  AI 增强完成：新增 ' +
                            (aiKeywords.combinedQueries ? aiKeywords.combinedQueries.length : 0) + ' 个搜索词');
                    }
                }
            } catch (e) {
                log('warn', '  AI 增强失败（使用基础关键词）: ' + e.message);
            }
        }

        return matrix;
    }

    function mergeArrays(base, extra) {
        var seen = {};
        base.forEach(function (k) { seen[k.toLowerCase()] = true; });
        extra.forEach(function (k) {
            if (!seen[k.toLowerCase()]) {
                base.push(k);
                seen[k.toLowerCase()] = true;
            }
        });
        return base;
    }

    // ================================================================
    // 阶段 3：平台搜索
    // ================================================================

    async function searchOnPlatform(platformInfo, keywordMatrix, onCandidateFound) {
        var searchUrls = PlatformStrategies.generateSearchUrls(
            platformInfo,
            keywordMatrix,
            pipelineConfig.preferredSearchEngine
        );

        var maxSearches = Math.min(searchUrls.length, pipelineConfig.maxSearchesPerPlatform);
        log('info', '  生成搜索 URL: ' + searchUrls.length + ' 个，执行前 ' + maxSearches + ' 个');

        for (var i = 0; i < maxSearches; i++) {
            checkCancelled();
            if (pipelineState.foundCandidates.length >= pipelineConfig.maxCandidatesPerTask) break;

            var searchItem = searchUrls[i];
            log('info', '  [' + (i + 1) + '/' + maxSearches + '] ' + searchItem.strategy + ': ' + searchItem.keyword);

            try {
                // 1. 导航到搜索 URL
                var navResult = await callTool('go_to_url', { url: searchItem.url });
                pipelineState.totalSearched++;

                // 等待页面加载
                await delay(pipelineConfig.searchDelay);

                // 2. 获取页面快照
                var snapshot = await callTool('browser_snapshot', {});
                if (!snapshot || !snapshot.interactiveElements) {
                    log('warn', '  页面快照获取失败，跳过');
                    continue;
                }

                // 3. 检查登录墙
                if (isLoginWall(snapshot, platformInfo.platform)) {
                    log('warn', '  检测到登录墙，跳过此平台');
                    break;
                }

                // 4. 提取用户信息
                var extracted = await extractUsersFromPage(platformInfo.key, searchItem);
                pipelineState.totalExtracted++;

                if (extracted && extracted.users && extracted.users.length > 0) {
                    log('success', '  提取到 ' + extracted.users.length + ' 个用户');

                    // 5. 去重 + 评分 + 加入候选人列表
                    extracted.users.forEach(function (user) {
                        var candidate = processCandidate(user, searchItem, platformInfo);
                        if (candidate) {
                            pipelineState.foundCandidates.push(candidate);
                            onCandidateFound(candidate);
                            log('info', '    + ' + candidate.name + ' (评分: ' + candidate.score + ')');
                        }
                    });

                    // 每发现一批就增量保存
                    var unsavedCount = pipelineState.foundCandidates.filter(function (c) { return !c._saved; }).length;
                    if (unsavedCount >= pipelineConfig.saveBatchSize) {
                        await saveCandidatesBatch(pipelineState.foundCandidates.filter(function (c) { return !c._saved; }));
                    }
                } else {
                    log('info', '  未发现用户');
                }

            } catch (err) {
                log('warn', '  搜索出错: ' + err.message);
                pipelineState.errors.push({ phase: 'search', url: searchItem.url, error: err.message });
            }

            // 搜索间隔（防反爬）
            var interval = platformInfo.platform.behaviors.rateLimit || pipelineConfig.searchDelay;
            if (platformInfo.platform.behaviors.antiCrawl && platformInfo.platform.behaviors.antiCrawl.randomDelay) {
                interval += Math.random() * 2000;
            }
            await delay(interval);
        }
    }

    // ================================================================
    // 页面提取
    // ================================================================

    async function extractUsersFromPage(platformKey, searchItem) {
        try {
            var extractConfig = PlatformStrategies.getExtractionScript(platformKey);
            if (!extractConfig) return null;

            // 通过 browser_extract 工具或 browser_snapshot 提取
            var result = await callTool('browser_extract', {
                selectors: {
                    email: true,
                    phone: true
                }
            });

            // 同时获取快照中的交互元素作为用户卡片
            var snapshot = await callTool('browser_snapshot', {});

            // 合并结果
            var users = [];
            if (snapshot && snapshot.interactiveElements) {
                // 从链接中提取可能的用户主页
                snapshot.interactiveElements.forEach(function (el) {
                    if (el.href && isUserProfileUrl(el.href, platformKey) && el.text) {
                        users.push({
                            name: el.text.substring(0, 50),
                            profileUrl: el.href,
                            bio: '',
                            contentPreview: '',
                            hasContactSignal: false,
                            sourceUrl: searchItem.url
                        });
                    }
                });
            }

            return {
                users: users,
                globalEmails: (result && result.emails) || [],
                globalPhones: (result && result.phones) || [],
                pageUrl: searchItem.url
            };
        } catch (err) {
            log('warn', '  提取失败: ' + err.message);
            return null;
        }
    }

    /**
     * 判断 URL 是否为用户主页
     */
    function isUserProfileUrl(url, platformKey) {
        if (!url) return false;
        var patterns = {
            zhihu: /zhihu\.com\/people\//,
            xiaohongshu: /xiaohongshu\.com\/user\/profile\//,
            weibo: /weibo\.com\/u\/\d+|weibo\.com\/[a-zA-Z]/,
            linkedin: /linkedin\.com\/in\//,
            douyin: /douyin\.com\/user\//,
            tieba: /tieba\.baidu\.com\/home\/main\?id=/,
            facebook: /facebook\.com\/[a-zA-Z0-9.]+$/,
            twitter: /x\.com\/[a-zA-Z0-9_]+$/
        };
        return patterns[platformKey] ? patterns[platformKey].test(url) : false;
    }

    /**
     * 检测登录墙
     */
    function isLoginWall(snapshot, platform) {
        if (!platform.behaviors || !platform.behaviors.loginWallKeywords) return false;
        var text = (snapshot.textContent || '').substring(0, 500).toLowerCase();
        return platform.behaviors.loginWallKeywords.some(function (kw) {
            return text.indexOf(kw.toLowerCase()) >= 0;
        });
    }

    // ================================================================
    // 阶段 4：深度提取
    // ================================================================

    async function deepExtractTopCandidates(onCandidateFound) {
        // 按评分排序，取前 N 个有主页 URL 的候选人
        var candidates = pipelineState.foundCandidates
            .filter(function (c) { return c.profileUrl && !c._deepExtracted; })
            .sort(function (a, b) { return b.score - a.score; })
            .slice(0, pipelineConfig.deepExtractTopN);

        log('info', '  深度提取前 ' + candidates.length + ' 名候选人');

        for (var i = 0; i < candidates.length; i++) {
            checkCancelled();
            var candidate = candidates[i];
            log('info', '  [' + (i + 1) + '/' + candidates.length + '] 深度提取: ' + candidate.name);

            try {
                // 导航到用户主页
                await callTool('go_to_url', { url: candidate.profileUrl });
                await delay(pipelineConfig.extractDelay);

                // 获取页面信息
                var result = await callTool('browser_extract', { selectors: { email: true, phone: true } });
                var snapshot = await callTool('browser_snapshot', {});

                // 提取联系方式
                if (result) {
                    if (result.emails && result.emails.length > 0) {
                        candidate.email = result.emails[0];
                        candidate.score += 20; // 有邮箱加分
                        log('success', '    发现邮箱: ' + candidate.email);
                    }
                    if (result.phones && result.phones.length > 0) {
                        candidate.phone = result.phones[0];
                        candidate.score += 15; // 有电话加分
                        log('success', '    发现电话: ' + candidate.phone);
                    }
                }

                // 提取更多个人信息
                if (snapshot && snapshot.textContent) {
                    var text = snapshot.textContent;
                    // 微信号
                    var wxMatch = text.match(/(?:微信|WeChat|WX|wx|vx|VX)[号：:\s]*([a-zA-Z0-9_-]{5,20})/i);
                    if (wxMatch) {
                        candidate.socialMediaAccounts = candidate.socialMediaAccounts || {};
                        candidate.socialMediaAccounts.wechat = wxMatch[1];
                        candidate.score += 15;
                        log('success', '    发现微信: ' + wxMatch[1]);
                    }
                    // QQ
                    var qqMatch = text.match(/(?:QQ|qq)[号：:\s]*(\d{5,12})/);
                    if (qqMatch) {
                        candidate.socialMediaAccounts = candidate.socialMediaAccounts || {};
                        candidate.socialMediaAccounts.qq = qqMatch[1];
                        candidate.score += 10;
                    }

                    // 更新 bio
                    if (snapshot.title && !candidate.bio) {
                        candidate.bio = snapshot.title;
                    }
                }

                candidate._deepExtracted = true;

            } catch (err) {
                log('warn', '  深度提取失败: ' + err.message);
                candidate._deepExtracted = true;
            }

            await delay(platformBehaviorDelay(candidate._platformKey));
        }
    }

    function platformBehaviorDelay(platformKey) {
        var p = PlatformStrategies.platforms[platformKey];
        var base = (p && p.behaviors && p.behaviors.rateLimit) || 3000;
        if (p && p.behaviors && p.behaviors.antiCrawl && p.behaviors.antiCrawl.randomDelay) {
            return base + Math.random() * 2000;
        }
        return base;
    }

    // ================================================================
    // 候选人处理：去重 + 评分
    // ================================================================

    function processCandidate(rawUser, searchItem, platformInfo) {
        // URL 去重
        var url = rawUser.profileUrl || rawUser.sourceUrl || '';
        if (url && pipelineState.seenUrls[url]) return null;

        // 名称去重
        var name = rawUser.name || '';
        if (!name) return null;

        // 基础评分
        var score = 0;
        var profile = pipelineState.profile;

        // 名称/bio 中包含行业关键词
        var fullText = (name + ' ' + (rawUser.bio || '') + ' ' + (rawUser.contentPreview || '')).toLowerCase();
        var matrix = pipelineState.keywordMatrix;

        if (matrix) {
            matrix.industry.forEach(function (kw) { if (fullText.indexOf(kw.toLowerCase()) >= 0) score += 15; });
            matrix.demand.forEach(function (kw) { if (fullText.indexOf(kw.toLowerCase()) >= 0) score += 10; });
            matrix.product.forEach(function (kw) { if (fullText.indexOf(kw.toLowerCase()) >= 0) score += 10; });
            matrix.crowd.forEach(function (kw) { if (fullText.indexOf(kw.toLowerCase()) >= 0) score += 10; });
            matrix.region.forEach(function (kw) { if (fullText.indexOf(kw.toLowerCase()) >= 0) score += 5; });
        }

        // 有联系方式信号加分
        if (rawUser.hasContactSignal) score += 10;

        // 最低分过滤
        if (score < pipelineConfig.scoreThreshold) return null;

        // 记录去重
        if (url) pipelineState.seenUrls[url] = true;

        return {
            name: name,
            profileUrl: url,
            bio: (rawUser.bio || '').substring(0, 200),
            contentPreview: (rawUser.contentPreview || '').substring(0, 300),
            email: rawUser.email || null,
            phone: rawUser.phone || null,
            socialMediaAccounts: rawUser.socialMediaAccounts || null,
            score: score,
            sourceUrl: searchItem.url,
            sourceUrls: [searchItem.url],
            platform: platformInfo.key,
            platformName: platformInfo.platform.name,
            keyword: searchItem.keyword,
            matchedTextSegments: extractMatchedSegments(fullText, matrix),
            _saved: false,
            _deepExtracted: false,
            _platformKey: platformInfo.key
        };
    }

    /**
     * 提取匹配的文本片段
     */
    function extractMatchedSegments(text, matrix) {
        var segments = [];
        if (!matrix) return segments;
        var allKeywords = [].concat(matrix.industry, matrix.demand, matrix.product, matrix.crowd);
        allKeywords.forEach(function (kw) {
            var idx = text.toLowerCase().indexOf(kw.toLowerCase());
            if (idx >= 0) {
                var start = Math.max(0, idx - 20);
                var end = Math.min(text.length, idx + kw.length + 20);
                segments.push('...' + text.substring(start, end) + '...');
            }
        });
        return segments.slice(0, 5);
    }

    // ================================================================
    // 保存到后端
    // ================================================================

    async function saveCandidatesBatch(candidates) {
        if (!candidates || candidates.length === 0) return;

        log('info', '  保存 ' + candidates.length + ' 个候选人到后端...');

        try {
            var payload = {
                task_id: pipelineState.taskId,
                candidates: candidates.map(function (c) {
                    return {
                        score: c.score,
                        profileUrl: c.profileUrl,
                        email: c.email || '',
                        phone: c.phone || '',
                        socialMediaAccounts: c.socialMediaAccounts || {},
                        matchedTextSegments: c.matchedTextSegments || [],
                        sourceUrls: c.sourceUrls || [],
                        bio: c.bio || '',
                        name: c.name || '',
                        platform: c.platform || '',
                        keyword: c.keyword || ''
                    };
                })
            };

            var url = pipelineConfig.apiBaseUrl + '/save-candidates';
            var res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            });

            var data = await res.json();
            if (data && data.success) {
                candidates.forEach(function (c) { c._saved = true; });
                pipelineState.totalSaved += candidates.length;
                log('success', '  成功保存 ' + candidates.length + ' 个候选人');

                // 更新任务进度
                await updateTaskProgress(pipelineState.foundCandidates.length);
            } else {
                log('warn', '  保存失败: ' + (data && data.message || 'Unknown error'));
            }
        } catch (err) {
            log('error', '  保存出错: ' + err.message);
        }
    }

    async function updateTaskStatus(status) {
        try {
            var url = pipelineConfig.apiBaseUrl + '/post-task-progress';
            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    task_id: pipelineState.taskId,
                    status: status,
                    found_count: pipelineState.foundCandidates.length
                })
            });
        } catch (e) { /* ignore */ }
    }

    async function updateTaskProgress(foundCount) {
        try {
            var url = pipelineConfig.apiBaseUrl + '/post-task-progress';
            await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    task_id: pipelineState.taskId,
                    status: 'crawling',
                    found_count: foundCount
                })
            });
        } catch (e) { /* ignore */ }
    }

    // ================================================================
    // 工具调用（通过 MCPClient 或 sendViaContentScript）
    // ================================================================

    async function callTool(toolName, args) {
        // 优先用 MCPClient
        if (typeof MCPClient !== 'undefined' && MCPClient.callTool) {
            return await MCPClient.callTool(toolName, args);
        }

        // 回退：通过 content script 中继
        return new Promise(function (resolve, reject) {
            var requestId = 'pipe_' + Date.now() + '_' + Math.random().toString(36).substr(2, 4);
            var timer = setTimeout(function () {
                window.removeEventListener('message', handler);
                reject(new Error('Tool call timeout'));
            }, 30000);

            function handler(event) {
                if (event.source !== window || !event.data) return;
                if (event.data.type === 'AUTOLEADAGENT_RESPONSE' && event.data.requestId === requestId) {
                    window.removeEventListener('message', handler);
                    clearTimeout(timer);
                    if (event.data.error) reject(new Error(event.data.error));
                    else resolve(event.data.response || {});
                }
            }
            window.addEventListener('message', handler);
            window.postMessage({
                type: 'AUTOLEADAGENT_REQUEST',
                payload: { type: 'WASM_EXECUTE_TOOL', name: toolName, arguments: args || {} },
                requestId: requestId
            }, '*');
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    // ================================================================
    // 公共 API
    // ================================================================

    return {
        start: start,
        cancel: cancel,
        getState: function () { return pipelineState; },
        isRunning: function () { return pipelineState.running; },
        setConfig: function (key, value) { pipelineConfig[key] = value; },
        getConfig: function () { return pipelineConfig; }
    };
})();
