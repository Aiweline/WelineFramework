/**
 * AgentTools — 智能体业务工具集
 *
 * 提供画像分析、网页提取、客户匹配、线索评分等高级能力。
 * 可被 ReAct 续行循环、AutonomousPipeline、ReactAgent 调用。
 *
 * 依赖: MCPClient（MCP 工具调用）、AutoLeadAgentPrompts（提示词）
 */
var AgentTools = (function () {
    'use strict';

    // ═══════════════════════════════════════════════
    // 1. 画像分析
    // ═══════════════════════════════════════════════

    /**
     * 分析商家画像，生成关键词矩阵和搜索策略
     * @param {Object} profile - 商家画像（industry, region, keywords, description 等）
     * @param {Object} options - { useAI: bool, generateFn: function }
     * @returns {Object} 分析结果 { keywords, searchQueries, platformHints, targetCustomerType, ... }
     */
    async function analyzeProfile(profile, options) {
        options = options || {};
        var result = {
            industry: profile.industry || '',
            region: profile.region || '',
            targetCustomerType: '',
            keywords: {
                industry: [],
                demand: [],
                crowd: [],
                product: [],
                region: [],
                scene: []
            },
            searchQueries: [],
            siteSearchQueries: [],
            platformHints: [],
            description: ''
        };

        // ── 规则引擎生成基础关键词 ──
        var industry = (profile.industry || '').trim();
        var region = (profile.region || '').trim();
        var desc = (profile.description || '').trim();
        var rawKeywords = profile.keywords || [];
        if (typeof rawKeywords === 'string') rawKeywords = rawKeywords.split(/[,，、\s]+/).filter(Boolean);

        // 行业词
        if (industry) {
            result.keywords.industry.push(industry);
            var industryParts = industry.split(/[\/\-、]/);
            for (var i = 0; i < industryParts.length; i++) {
                var p = industryParts[i].trim();
                if (p && result.keywords.industry.indexOf(p) < 0) result.keywords.industry.push(p);
            }
        }

        // 产品词
        for (var i = 0; i < rawKeywords.length; i++) {
            if (rawKeywords[i].trim()) result.keywords.product.push(rawKeywords[i].trim());
        }

        // 地区词
        if (region) {
            result.keywords.region.push(region);
            var regionParts = region.split(/[\/\-、省市区县]/);
            for (var i = 0; i < regionParts.length; i++) {
                var p = regionParts[i].trim();
                if (p && p.length > 1 && result.keywords.region.indexOf(p) < 0) result.keywords.region.push(p);
            }
        }

        // 需求词（规则推断）
        var demandTemplates = ['哪里买', '推荐', '怎么选', '求推荐', '有没有好的', '想找', '需要', '想买', '哪家好'];
        for (var i = 0; i < Math.min(3, rawKeywords.length); i++) {
            for (var j = 0; j < demandTemplates.length; j++) {
                result.keywords.demand.push(rawKeywords[i] + ' ' + demandTemplates[j]);
            }
        }

        // 场景词（从描述中提取）
        if (desc) {
            var scenePhrases = desc.match(/[\u4e00-\u9fa5a-zA-Z]{2,8}/g) || [];
            var sceneSet = {};
            for (var i = 0; i < scenePhrases.length && result.keywords.scene.length < 10; i++) {
                var ph = scenePhrases[i];
                if (!sceneSet[ph] && ph.length >= 2) {
                    sceneSet[ph] = true;
                    result.keywords.scene.push(ph);
                }
            }
        }

        // 组合搜索词
        var productList = result.keywords.product.slice(0, 5);
        var regionList = result.keywords.region.slice(0, 3);
        for (var i = 0; i < productList.length; i++) {
            result.searchQueries.push(productList[i]);
            if (regionList.length > 0) {
                result.searchQueries.push(regionList[0] + ' ' + productList[i]);
            }
            result.searchQueries.push(productList[i] + ' 推荐');
            result.searchQueries.push(productList[i] + ' 需求');
        }
        if (industry) {
            result.searchQueries.push(industry + ' 客户');
            result.searchQueries.push(industry + ' 买家');
        }

        // site: 搜索词
        var sites = ['zhihu.com', 'xiaohongshu.com', 'weibo.com', 'douban.com', 'tieba.baidu.com'];
        for (var i = 0; i < Math.min(3, productList.length); i++) {
            for (var j = 0; j < sites.length; j++) {
                result.siteSearchQueries.push('site:' + sites[j] + ' ' + productList[i]);
            }
        }

        // 平台建议
        result.platformHints = [
            { platform: '知乎', strategy: '搜索"' + (productList[0] || industry) + ' 推荐"，查看回答者和提问者' },
            { platform: '小红书', strategy: '搜索"' + (productList[0] || industry) + '"相关笔记，关注互动用户' },
            { platform: '微博', strategy: '搜索"' + (productList[0] || industry) + ' 求推荐"，找需求用户' },
            { platform: '百度贴吧', strategy: '搜索"' + industry + '吧"，查看发帖用户' }
        ];

        // ── 如果有 AI，用 AI 增强 ──
        if (options.useAI && typeof options.generateFn === 'function') {
            try {
                var prompt = '';
                if (typeof AutoLeadAgentPrompts !== 'undefined') {
                    prompt = AutoLeadAgentPrompts.generateKeywordGenerationPrompt(profile, 'zh');
                } else {
                    prompt = '请分析以下商家画像，生成搜索关键词矩阵（JSON格式）：\n' + JSON.stringify(profile);
                }
                var aiRaw = await options.generateFn(prompt, { maxTokens: 1024, temperature: 0.5 });
                if (aiRaw) {
                    var aiResult = parseJSON(aiRaw);
                    if (aiResult) {
                        // 合并 AI 结果
                        mergeArrayField(result.keywords, 'industry', aiResult.industryKeywords);
                        mergeArrayField(result.keywords, 'demand', aiResult.demandKeywords);
                        mergeArrayField(result.keywords, 'crowd', aiResult.crowdKeywords);
                        mergeArrayField(result.keywords, 'product', aiResult.productKeywords);
                        mergeArrayField(result.keywords, 'region', aiResult.regionKeywords);
                        mergeArrayField(result.keywords, 'scene', aiResult.sceneKeywords);
                        if (aiResult.combinedQueries) result.searchQueries = dedup(result.searchQueries.concat(aiResult.combinedQueries));
                        if (aiResult.siteSearchQueries) result.siteSearchQueries = dedup(result.siteSearchQueries.concat(aiResult.siteSearchQueries));
                    }
                }
            } catch (e) {
                console.warn('[AgentTools] AI 画像增强失败:', e);
            }
        }

        result.targetCustomerType = industry ? (industry + '的潜在客户') : '目标用户';
        result.description = '基于"' + (industry || '未知行业') + '"画像生成了 '
            + result.searchQueries.length + ' 个搜索词和 '
            + result.siteSearchQueries.length + ' 个站内搜索词';

        return result;
    }


    // ═══════════════════════════════════════════════
    // 2. 网页内容提取（结构化）
    // ═══════════════════════════════════════════════

    /**
     * 从快照中提取结构化内容（用户卡片、搜索结果、文章列表等）
     * @param {Object} snapshot - browser_snapshot 返回的数据
     * @returns {Object} { users[], articles[], contacts{}, summary }
     */
    function extractStructuredContent(snapshot) {
        if (!snapshot) return { users: [], articles: [], contacts: {}, summary: '' };

        var text = snapshot.textContent || '';
        var elements = snapshot.interactiveElements || [];
        var url = snapshot.url || '';
        var title = snapshot.title || '';

        var result = {
            users: [],
            articles: [],
            contacts: {
                emails: [],
                phones: [],
                wechats: [],
                qqs: []
            },
            links: [],
            summary: ''
        };

        // ── 提取联系方式 ──
        var emailPattern = /[\w.+-]+@[\w-]+\.[\w.-]+/g;
        var phonePattern = /(?:\+?\d{1,3}[-.\s]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/g;
        var wechatPattern = /(?:微信|wechat|wx)[号:\s]*[：:]?\s*([a-zA-Z0-9_-]{5,20})/gi;
        var qqPattern = /(?:QQ|qq)[号:\s]*[：:]?\s*(\d{5,12})/gi;

        var match;
        while ((match = emailPattern.exec(text)) !== null) {
            if (result.contacts.emails.indexOf(match[0]) < 0) result.contacts.emails.push(match[0]);
        }
        while ((match = phonePattern.exec(text)) !== null) {
            var cleaned = match[0].replace(/[\s-().]/g, '');
            if (cleaned.length >= 7 && result.contacts.phones.indexOf(match[0]) < 0) result.contacts.phones.push(match[0]);
        }
        while ((match = wechatPattern.exec(text)) !== null) {
            if (result.contacts.wechats.indexOf(match[1]) < 0) result.contacts.wechats.push(match[1]);
        }
        while ((match = qqPattern.exec(text)) !== null) {
            if (result.contacts.qqs.indexOf(match[1]) < 0) result.contacts.qqs.push(match[1]);
        }

        // ── 提取有效链接（排除功能性链接） ──
        var skipPatterns = /google\.com\/search|accounts\.|login|signup|javascript:|#$|support\.|help\./i;
        for (var i = 0; i < elements.length; i++) {
            var el = elements[i];
            if ((el.tag || '').toLowerCase() === 'a' && el.href && el.text && el.text.length > 3) {
                if (!skipPatterns.test(el.href)) {
                    result.links.push({ title: el.text.substring(0, 100), url: el.href, index: el.index });
                }
            }
        }

        // ── 识别用户卡片（基于文本模式） ──
        var userPatterns = [
            /(?:关注者?|粉丝|followers?)[:\s]*(\d+)/gi,
            /(?:回答|答案|answers?)[:\s]*(\d+)/gi,
            /(?:文章|posts?|articles?)[:\s]*(\d+)/gi
        ];
        var textLines = text.split('\n').filter(function (l) { return l.trim().length > 5; });
        var userBlock = [];
        for (var i = 0; i < textLines.length; i++) {
            var line = textLines[i].trim();
            var isUserSignal = false;
            for (var j = 0; j < userPatterns.length; j++) {
                userPatterns[j].lastIndex = 0;
                if (userPatterns[j].test(line)) { isUserSignal = true; break; }
            }
            if (isUserSignal || /^@[\w\u4e00-\u9fa5]+/.test(line)) {
                userBlock.push(line);
            }
        }
        if (userBlock.length > 0) {
            result.users.push({
                raw: userBlock.join(' | '),
                source: url
            });
        }

        // ── 提取文章/结果条目 ──
        for (var i = 0; i < result.links.length && result.articles.length < 20; i++) {
            var link = result.links[i];
            if (link.title.length > 8) {
                result.articles.push({
                    title: link.title,
                    url: link.url
                });
            }
        }

        // 摘要
        var contactCount = result.contacts.emails.length + result.contacts.phones.length
            + result.contacts.wechats.length + result.contacts.qqs.length;
        result.summary = '页面"' + title + '"：发现 ' + result.links.length + ' 个链接、'
            + contactCount + ' 个联系方式、' + result.articles.length + ' 篇文章/结果';

        return result;
    }


    // ═══════════════════════════════════════════════
    // 3. 客户匹配评估
    // ═══════════════════════════════════════════════

    /**
     * 评估快照/页面内容是否匹配客户画像
     * @param {Object} snapshot - browser_snapshot 数据
     * @param {Object} profile - 商家画像
     * @param {Object} options - { useAI, generateFn }
     * @returns {Object} { isMatch, score, reasons[], extractedInfo }
     */
    async function evaluateCustomerMatch(snapshot, profile, options) {
        options = options || {};
        var text = (snapshot && snapshot.textContent) || '';
        var title = (snapshot && snapshot.title) || '';
        var url = (snapshot && snapshot.url) || '';

        var result = {
            isMatch: false,
            score: 0,
            reasons: [],
            extractedInfo: extractStructuredContent(snapshot),
            matchedKeywords: [],
            url: url,
            title: title
        };

        if (!text || text.length < 20) {
            result.reasons.push('页面内容不足');
            return result;
        }

        var textLo = text.toLowerCase();

        // ── 规则匹配 ──
        var profileKeywords = (profile.keywords || []).concat(
            [profile.industry, profile.region].filter(Boolean)
        );
        if (typeof profileKeywords[0] === 'string' && profileKeywords[0].includes(',')) {
            profileKeywords = profileKeywords[0].split(/[,，、]/).concat(profileKeywords.slice(1));
        }

        var matchCount = 0;
        for (var i = 0; i < profileKeywords.length; i++) {
            var kw = (profileKeywords[i] || '').trim().toLowerCase();
            if (kw && kw.length > 1 && textLo.includes(kw)) {
                matchCount++;
                result.matchedKeywords.push(profileKeywords[i]);
            }
        }

        // 关键词匹配得分（每个关键词 15 分，上限 60）
        result.score += Math.min(60, matchCount * 15);
        if (matchCount > 0) result.reasons.push('匹配 ' + matchCount + ' 个关键词: ' + result.matchedKeywords.join(', '));

        // 联系方式加分
        var contacts = result.extractedInfo.contacts;
        if (contacts.emails.length > 0) { result.score += 10; result.reasons.push('发现邮箱: ' + contacts.emails[0]); }
        if (contacts.phones.length > 0) { result.score += 10; result.reasons.push('发现电话: ' + contacts.phones[0]); }
        if (contacts.wechats.length > 0) { result.score += 10; result.reasons.push('发现微信: ' + contacts.wechats[0]); }
        if (contacts.qqs.length > 0) { result.score += 5; result.reasons.push('发现QQ: ' + contacts.qqs[0]); }

        // 需求信号加分
        var needSignals = ['想买', '求推荐', '哪里有', '怎么选', '谁知道', '帮忙推荐', '有没有好的', '想找', '需要', 'looking for', 'recommend', 'need'];
        for (var i = 0; i < needSignals.length; i++) {
            if (textLo.includes(needSignals[i].toLowerCase())) {
                result.score += 5;
                result.reasons.push('检测到需求信号: "' + needSignals[i] + '"');
                break;
            }
        }

        result.score = Math.min(100, result.score);
        result.isMatch = result.score >= 30;

        // ── AI 增强评估 ──
        if (options.useAI && typeof options.generateFn === 'function' && result.score >= 20) {
            try {
                var prompt = '';
                if (typeof AutoLeadAgentPrompts !== 'undefined') {
                    prompt = AutoLeadAgentPrompts.generateUserEvaluationPrompt(text, profile, 'zh');
                } else {
                    prompt = '判断以下页面内容的用户是否为"' + (profile.industry || '') + '"行业的潜在客户，返回 JSON {isPotentialCustomer,confidence,reason}:\n' + text.substring(0, 1500);
                }
                var aiRaw = await options.generateFn(prompt, { maxTokens: 512, temperature: 0.3 });
                if (aiRaw) {
                    var aiResult = parseJSON(aiRaw);
                    if (aiResult) {
                        if (aiResult.confidence != null) {
                            // 混合 AI 评分和规则评分
                            result.score = Math.round(result.score * 0.4 + aiResult.confidence * 0.6);
                        }
                        if (aiResult.reason) result.reasons.push('AI: ' + aiResult.reason);
                        if (aiResult.isPotentialCustomer === false && result.score > 40) result.score = 30;
                        if (aiResult.extractedInfo) {
                            Object.assign(result.extractedInfo, aiResult.extractedInfo);
                        }
                        if (aiResult.matchedKeywords) {
                            result.matchedKeywords = dedup(result.matchedKeywords.concat(aiResult.matchedKeywords));
                        }
                        result.isMatch = result.score >= 30;
                    }
                }
            } catch (e) {
                console.warn('[AgentTools] AI 评估失败:', e);
            }
        }

        return result;
    }


    // ═══════════════════════════════════════════════
    // 4. 从快照提取潜在线索列表
    // ═══════════════════════════════════════════════

    /**
     * 从搜索结果页快照中提取可能的潜在客户链接
     * @param {Object} snapshot
     * @param {Object} profile
     * @returns {Object[]} 线索列表 [{ title, url, score, reason }]
     */
    function extractLeadsFromSearch(snapshot, profile) {
        if (!snapshot) return [];

        var structured = extractStructuredContent(snapshot);
        var leads = [];
        var profileKeywords = getAllKeywords(profile);

        // 用户资料页特征 URL
        var profileUrlPatterns = [
            /zhihu\.com\/people\//,
            /xiaohongshu\.com\/user\//,
            /weibo\.com\/u\//,
            /linkedin\.com\/in\//,
            /twitter\.com\/\w+$/,
            /facebook\.com\/\w+$/,
            /douyin\.com\/user\//,
            /tieba\.baidu\.com\/home\//,
            /github\.com\/\w+$/
        ];

        for (var i = 0; i < structured.links.length; i++) {
            var link = structured.links[i];
            var score = 0;
            var reasons = [];

            // URL 是否是用户资料页
            var isProfileUrl = false;
            for (var j = 0; j < profileUrlPatterns.length; j++) {
                if (profileUrlPatterns[j].test(link.url)) {
                    isProfileUrl = true;
                    score += 30;
                    reasons.push('用户资料页URL');
                    break;
                }
            }

            // 标题是否包含关键词
            var titleLo = (link.title || '').toLowerCase();
            for (var k = 0; k < profileKeywords.length; k++) {
                if (titleLo.includes(profileKeywords[k].toLowerCase())) {
                    score += 15;
                    reasons.push('标题含关键词: ' + profileKeywords[k]);
                    break;
                }
            }

            // 标题包含需求信号
            var needWords = ['推荐', '求助', '想买', '怎么选', '哪里', '需要', '帮忙'];
            for (var k = 0; k < needWords.length; k++) {
                if (titleLo.includes(needWords[k])) {
                    score += 10;
                    reasons.push('需求信号: ' + needWords[k]);
                    break;
                }
            }

            if (score >= 10) {
                leads.push({
                    title: link.title,
                    url: link.url,
                    index: link.index,
                    score: score,
                    isProfileUrl: isProfileUrl,
                    reasons: reasons
                });
            }
        }

        // 按分数排序
        leads.sort(function (a, b) { return b.score - a.score; });
        return leads;
    }


    // ═══════════════════════════════════════════════
    // 5. 用户主页深度提取
    // ═══════════════════════════════════════════════

    /**
     * 构建用户资料深度提取脚本（注入到页面执行）
     * 返回一个可以被 chrome.scripting.executeScript 使用的 func
     */
    function getUserProfileExtractionScript() {
        return function () {
            var text = document.body ? document.body.innerText : '';
            var title = document.title;
            var url = location.href;

            // 提取头像
            var avatarEl = document.querySelector('img[class*="avatar"], img[class*="Avatar"], img[alt*="头像"], .avatar img, .user-avatar img');
            var avatar = avatarEl ? avatarEl.src : '';

            // 提取用户名
            var nameEl = document.querySelector('h1, .username, .user-name, .ProfileHeader-name, .name, [class*="nickname"], [class*="UserName"]');
            var name = nameEl ? nameEl.innerText.trim().substring(0, 50) : '';

            // 提取简介
            var bioEl = document.querySelector('.bio, .description, .ProfileHeader-headline, [class*="signature"], [class*="intro"], [class*="desc"]');
            var bio = bioEl ? bioEl.innerText.trim().substring(0, 300) : '';

            // 提取统计数据
            var stats = {};
            var statEls = document.querySelectorAll('[class*="count"], [class*="stat"], [class*="number"], [class*="follow"]');
            statEls.forEach(function (el) {
                var t = el.innerText.trim();
                if (/\d/.test(t) && t.length < 30) {
                    var parent = el.parentElement;
                    var label = parent ? parent.innerText.replace(t, '').trim().substring(0, 20) : '';
                    if (label) stats[label] = t;
                }
            });

            // 提取联系方式
            var contacts = { emails: [], phones: [], wechats: [], qqs: [], links: [] };
            var emailR = /[\w.+-]+@[\w-]+\.[\w.-]+/g;
            var phoneR = /(?:\+?\d{1,3}[-.]?)?\d{3,4}[-.\s]?\d{3,4}[-.\s]?\d{3,4}/g;
            var wxR = /(?:微信|wechat|wx)[号:\s]*[：:]?\s*([a-zA-Z0-9_-]{5,20})/gi;
            var qqR = /(?:QQ|qq)[号:\s]*[：:]?\s*(\d{5,12})/gi;
            var m;
            while ((m = emailR.exec(text)) !== null) contacts.emails.push(m[0]);
            while ((m = phoneR.exec(text)) !== null) {
                var clean = m[0].replace(/[\s-().]/g, '');
                if (clean.length >= 7) contacts.phones.push(m[0]);
            }
            while ((m = wxR.exec(text)) !== null) contacts.wechats.push(m[1]);
            while ((m = qqR.exec(text)) !== null) contacts.qqs.push(m[1]);

            // 社交链接
            var socialLinks = document.querySelectorAll('a[href*="linkedin"], a[href*="twitter"], a[href*="github"], a[href*="weibo"], a[href*="t.me"]');
            socialLinks.forEach(function (a) { contacts.links.push(a.href); });

            // 最近内容/帖子
            var recentPosts = [];
            var postEls = document.querySelectorAll('.ContentItem, .feed-item, .note-item, article, [class*="post-item"], [class*="card-item"]');
            var count = 0;
            postEls.forEach(function (el) {
                if (count >= 5) return;
                var postTitle = '';
                var h = el.querySelector('h2, h3, .title, [class*="title"]');
                if (h) postTitle = h.innerText.trim().substring(0, 100);
                var postText = el.innerText.trim().substring(0, 200);
                if (postText.length > 20) {
                    recentPosts.push({ title: postTitle, preview: postText });
                    count++;
                }
            });

            return {
                name: name,
                bio: bio,
                avatar: avatar,
                stats: stats,
                contacts: contacts,
                recentPosts: recentPosts,
                pageTitle: title,
                pageUrl: url,
                fullText: text.substring(0, 3000)
            };
        };
    }


    // ═══════════════════════════════════════════════
    // 6. 线索评分（综合）
    // ═══════════════════════════════════════════════

    /**
     * 综合评分一个潜在线索
     * @param {Object} lead - { name, bio, contacts, recentPosts, fullText, ... }
     * @param {Object} profile - 商家画像
     * @returns {Object} { score, level, reasons[] }
     */
    function scoreLead(lead, profile) {
        var score = 0;
        var reasons = [];
        var profileKeywords = getAllKeywords(profile);

        // 联系方式 (+10 每类)
        var c = lead.contacts || {};
        if (c.emails && c.emails.length > 0) { score += 10; reasons.push('有邮箱'); }
        if (c.phones && c.phones.length > 0) { score += 10; reasons.push('有电话'); }
        if (c.wechats && c.wechats.length > 0) { score += 15; reasons.push('有微信'); }
        if (c.qqs && c.qqs.length > 0) { score += 5; reasons.push('有QQ'); }
        if (c.links && c.links.length > 0) { score += 5; reasons.push('有社交链接'); }

        // 简介/全文关键词匹配 (+10 每个, 上限 40)
        var textToCheck = ((lead.bio || '') + ' ' + (lead.fullText || '')).toLowerCase();
        var kwMatched = 0;
        for (var i = 0; i < profileKeywords.length; i++) {
            var kw = profileKeywords[i].toLowerCase();
            if (kw.length > 1 && textToCheck.includes(kw)) {
                kwMatched++;
                if (kwMatched <= 4) reasons.push('关键词: ' + profileKeywords[i]);
            }
        }
        score += Math.min(40, kwMatched * 10);

        // 活跃度（有最近发帖）
        if (lead.recentPosts && lead.recentPosts.length > 0) {
            score += 10;
            reasons.push('有 ' + lead.recentPosts.length + ' 条近期内容');
        }

        // 影响力（关注者/粉丝数量）
        if (lead.stats) {
            for (var key in lead.stats) {
                if (/粉丝|关注者|followers/i.test(key)) {
                    var num = parseInt((lead.stats[key] || '').replace(/[^\d]/g, ''));
                    if (num > 100) { score += 5; reasons.push('有一定粉丝量'); }
                    if (num > 1000) { score += 5; reasons.push('粉丝量较多'); }
                }
            }
        }

        // 完整名称
        if (lead.name && lead.name.length > 1) { score += 5; reasons.push('有用户名'); }

        score = Math.min(100, score);

        var level = 'low';
        if (score >= 70) level = 'high';
        else if (score >= 40) level = 'medium';

        return { score: score, level: level, reasons: reasons };
    }


    // ═══════════════════════════════════════════════
    // 7. 工具注册表（给 ReAct 循环和控制台使用）
    // ═══════════════════════════════════════════════

    /**
     * 获取所有可用的业务工具描述
     * 用于告诉 AI 有哪些工具可用
     */
    function getToolDescriptions() {
        return [
            {
                name: 'analyze_profile',
                description: '分析商家画像，生成关键词矩阵和搜索策略',
                params: { profile: '商家画像对象 (industry, region, keywords, description)' },
                returns: '{ keywords, searchQueries, siteSearchQueries, platformHints }'
            },
            {
                name: 'extract_page_content',
                description: '从当前页面快照中提取结构化内容（联系方式、链接、文章列表等）',
                params: { snapshot: 'browser_snapshot 返回的数据' },
                returns: '{ users[], articles[], contacts{emails,phones,wechats,qqs}, links[], summary }'
            },
            {
                name: 'evaluate_customer',
                description: '评估当前页面的用户是否匹配客户画像',
                params: { snapshot: '页面快照', profile: '商家画像' },
                returns: '{ isMatch, score, reasons[], matchedKeywords[], extractedInfo }'
            },
            {
                name: 'extract_leads_from_search',
                description: '从搜索结果页提取潜在客户链接列表',
                params: { snapshot: '搜索结果页快照', profile: '商家画像' },
                returns: '[{ title, url, score, isProfileUrl, reasons[] }]'
            },
            {
                name: 'extract_user_profile',
                description: '深度提取当前页面的用户资料（需 MCP 在目标页面执行）',
                params: {},
                returns: '{ name, bio, avatar, stats, contacts, recentPosts }'
            },
            {
                name: 'score_lead',
                description: '综合评分一个潜在线索',
                params: { lead: '线索对象', profile: '商家画像' },
                returns: '{ score, level, reasons[] }'
            }
        ];
    }


    // ═══════════════════════════════════════════════
    // 工具函数
    // ═══════════════════════════════════════════════

    function parseJSON(text) {
        if (!text) return null;
        // 尝试直接解析
        try { return JSON.parse(text); } catch (_) {}
        // 尝试提取 JSON 块
        var match = text.match(/\{[\s\S]*\}/);
        if (match) { try { return JSON.parse(match[0]); } catch (_) {} }
        return null;
    }

    function dedup(arr) {
        var seen = {};
        return arr.filter(function (item) {
            if (seen[item]) return false;
            seen[item] = true;
            return true;
        });
    }

    function mergeArrayField(target, key, source) {
        if (!source || !Array.isArray(source)) return;
        if (!target[key]) target[key] = [];
        for (var i = 0; i < source.length; i++) {
            if (target[key].indexOf(source[i]) < 0) target[key].push(source[i]);
        }
    }

    function getAllKeywords(profile) {
        var kws = [];
        if (profile.industry) kws.push(profile.industry);
        if (profile.region) kws.push(profile.region);
        if (profile.keywords) {
            var raw = profile.keywords;
            if (typeof raw === 'string') raw = raw.split(/[,，、\s]+/);
            kws = kws.concat(raw);
        }
        if (profile.target_customers) kws = kws.concat(profile.target_customers);
        if (profile.product_features) kws = kws.concat(profile.product_features);
        return kws.filter(function (k) { return k && k.trim().length > 1; });
    }


    // ═══════════════════════════════════════════════
    // 导出
    // ═══════════════════════════════════════════════
    return {
        analyzeProfile: analyzeProfile,
        extractStructuredContent: extractStructuredContent,
        evaluateCustomerMatch: evaluateCustomerMatch,
        extractLeadsFromSearch: extractLeadsFromSearch,
        getUserProfileExtractionScript: getUserProfileExtractionScript,
        scoreLead: scoreLead,
        getToolDescriptions: getToolDescriptions,
        parseJSON: parseJSON
    };

})();

if (typeof window !== 'undefined') {
    window.AgentTools = AgentTools;
}
