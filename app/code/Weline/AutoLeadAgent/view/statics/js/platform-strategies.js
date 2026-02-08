/**
 * 平台适配策略模块
 * 
 * 为不同社交/内容平台提供差异化的搜索、浏览、提取策略
 * 智能体根据画像分析结果，在各平台以最有效的方式寻找潜在客户
 * 
 * 架构：
 *   画像分析 → 关键词矩阵 → 平台路由 → 平台策略执行 → 结果提取 → 录入
 */
var PlatformStrategies = (function () {
    'use strict';

    // ================================================================
    // 平台注册表：每个平台的搜索/浏览/提取策略
    // ================================================================

    var platforms = {

        // ── 知乎 ──
        zhihu: {
            name: '知乎',
            domain: 'zhihu.com',
            icon: 'https://static.zhihu.com/heifetz/favicon.ico',
            // 搜索策略：多入口
            searchStrategies: [
                { type: 'site_search', template: 'site:zhihu.com {keyword} {industry}', engine: 'google', desc: 'Google 站内搜索' },
                { type: 'site_search', template: 'site:zhihu.com {keyword} {industry}', engine: 'baidu', desc: '百度站内搜索' },
                { type: 'direct_search', url: 'https://www.zhihu.com/search?type=people&q={keyword}', desc: '知乎站内搜人' },
                { type: 'direct_search', url: 'https://www.zhihu.com/search?type=content&q={keyword} {industry}', desc: '知乎站内搜内容' },
                { type: 'topic_browse', url: 'https://www.zhihu.com/topic/{topicId}/hot', desc: '话题热门浏览' }
            ],
            // 页面解析策略
            parseStrategy: {
                // 搜索结果页：如何识别用户
                searchResultSelectors: {
                    userCards: '.SearchResult-Card .AuthorInfo, .ContentItem .AuthorInfo',
                    userName: '.AuthorInfo-name, .UserLink-link',
                    userUrl: '.AuthorInfo-name a, .UserLink-link',
                    userBio: '.AuthorInfo-detail, .AuthorInfo .Bio',
                    contentPreview: '.RichContent-inner, .SearchItem-content'
                },
                // 用户主页：如何提取详细信息
                profileSelectors: {
                    name: '.ProfileHeader-name, .UserProfileCard-name',
                    bio: '.ProfileHeader-headline, .UserProfileCard-headline',
                    description: '.ProfileHeader-description',
                    location: '.ProfileHeader-detailItem .Icon--position + span',
                    industry: '.ProfileHeader-detailItem .Icon--topic + span',
                    followers: '.NumberBoard-itemValue',
                    answers: '.Profile-sideColumnItemValue',
                    articles: '.Profile-sideColumnItemValue'
                },
                // 判断是否为潜在客户的信号
                qualifySignals: ['私信', '合作', '联系方式', '微信', 'WeChat', 'WX', '邮箱', 'email', '电话', '手机']
            },
            // 平台特殊行为
            behaviors: {
                needsLogin: false,
                rateLimit: 3000,     // 请求间隔 ms
                maxPagesPerQuery: 3, // 每个查询最多翻几页
                antiCrawl: { scrollDelay: 1500, randomDelay: true },
                loginWallKeywords: ['登录', '注册', '请先登录']
            }
        },

        // ── 小红书 ──
        xiaohongshu: {
            name: '小红书',
            domain: 'xiaohongshu.com',
            icon: 'https://www.xiaohongshu.com/favicon.ico',
            searchStrategies: [
                { type: 'site_search', template: 'site:xiaohongshu.com {keyword} {industry} {region}', engine: 'google', desc: 'Google 站内搜索' },
                { type: 'site_search', template: 'site:xiaohongshu.com {keyword} {industry}', engine: 'baidu', desc: '百度站内搜索' },
                { type: 'direct_search', url: 'https://www.xiaohongshu.com/search_result?keyword={keyword}&type=1', desc: '小红书站内搜笔记' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '.note-item, .search-result-item',
                    userName: '.author-name, .name',
                    userUrl: '.author-name a, .note-item a',
                    contentPreview: '.note-content, .desc'
                },
                profileSelectors: {
                    name: '.user-name, .info .name',
                    bio: '.user-desc, .info .desc',
                    location: '.user-location',
                    followers: '.count',
                    notes: '.tab-item .count'
                },
                qualifySignals: ['合作', '私信', '微信', 'v信', 'WX', '联系', '欢迎咨询', '邮箱']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 5000,
                maxPagesPerQuery: 2,
                antiCrawl: { scrollDelay: 2000, randomDelay: true },
                loginWallKeywords: ['登录', '注册', '请先登录', '打开小红书App']
            }
        },

        // ── 微博 ──
        weibo: {
            name: '微博',
            domain: 'weibo.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:weibo.com {keyword} {industry}', engine: 'google', desc: 'Google 站内搜索' },
                { type: 'site_search', template: 'site:weibo.com {keyword} {industry}', engine: 'baidu', desc: '百度站内搜索' },
                { type: 'direct_search', url: 'https://s.weibo.com/weibo?q={keyword}+{industry}', desc: '微博综合搜索' },
                { type: 'direct_search', url: 'https://s.weibo.com/user?q={keyword}+{industry}', desc: '微博搜人' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '.card-wrap .card, .m-user-card',
                    userName: '.name, .m-text-cut',
                    userUrl: '.name a, .m-text-cut a',
                    userBio: '.info .txt, .m-text-cut',
                    contentPreview: '.txt[node-type="feed_list_content"], .weibo-text'
                },
                profileSelectors: {
                    name: '.ProfileHeader_name, [class*="ProfileHeader_name"]',
                    bio: '.ProfileHeader_info, [class*="ProfileHeader_des"]',
                    location: '[class*="location"]',
                    followers: '[class*="followers"] .woo-box-flex',
                    posts: '[class*="StatusTotal"]'
                },
                qualifySignals: ['私信', '合作', '联系', '微信', '邮箱', '电话', 'VX', '合作联系']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 4000,
                maxPagesPerQuery: 3,
                antiCrawl: { scrollDelay: 1500, randomDelay: true },
                loginWallKeywords: ['请先登录', '微博登录']
            }
        },

        // ── LinkedIn ──
        linkedin: {
            name: 'LinkedIn',
            domain: 'linkedin.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:linkedin.com/in/ {keyword} {industry} {region}', engine: 'google', desc: 'Google LinkedIn 搜人' },
                { type: 'site_search', template: 'site:linkedin.com/company/ {keyword} {industry}', engine: 'google', desc: 'Google LinkedIn 搜公司' },
                { type: 'direct_search', url: 'https://www.linkedin.com/search/results/people/?keywords={keyword}+{industry}', desc: 'LinkedIn 搜人' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '.reusable-search__result-container, .search-result__wrapper',
                    userName: '.entity-result__title-text a, .actor-name',
                    userUrl: '.entity-result__title-text a',
                    userBio: '.entity-result__primary-subtitle, .search-result__truncate',
                    contentPreview: '.entity-result__summary, .search-result__snippets'
                },
                profileSelectors: {
                    name: '.text-heading-xlarge, h1.top-card-layout__title',
                    bio: '.text-body-medium, .top-card-layout__headline',
                    location: '.text-body-small .inline-show-more-text, .top-card__subline-item',
                    industry: '.text-body-small[aria-label*="Current company"]',
                    connections: '.t-bold',
                    experience: '#experience ~ .pvs-list__outer-container'
                },
                qualifySignals: ['Open to', 'contact', 'email', 'phone', 'connect', 'message']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 5000,
                maxPagesPerQuery: 2,
                antiCrawl: { scrollDelay: 2000, randomDelay: true },
                loginWallKeywords: ['Sign in', 'Join now', 'Log in']
            }
        },

        // ── 抖音 / TikTok ──
        douyin: {
            name: '抖音',
            domain: 'douyin.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:douyin.com {keyword} {industry}', engine: 'google', desc: 'Google 站内搜索' },
                { type: 'site_search', template: 'site:douyin.com {keyword} {industry}', engine: 'baidu', desc: '百度站内搜索' },
                { type: 'direct_search', url: 'https://www.douyin.com/search/{keyword}?type=user', desc: '抖音搜用户' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '.user-card, .search-result-card',
                    userName: '.nickname, .user-name',
                    userUrl: 'a[href*="/user/"]',
                    userBio: '.signature, .user-desc'
                },
                profileSelectors: {
                    name: '.nickname, [class*="nickname"]',
                    bio: '.signature, [class*="signature"]',
                    location: '.user-location, [class*="location"]',
                    followers: '.follow-info .count'
                },
                qualifySignals: ['私信', '合作', '微信', '联系', '橱窗', '带货']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 5000,
                maxPagesPerQuery: 2,
                antiCrawl: { scrollDelay: 2500, randomDelay: true },
                loginWallKeywords: ['登录', '请先登录', '打开抖音App']
            }
        },

        // ── 百度贴吧 ──
        tieba: {
            name: '百度贴吧',
            domain: 'tieba.baidu.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:tieba.baidu.com {keyword} {industry}', engine: 'baidu', desc: '百度站内搜索' },
                { type: 'direct_search', url: 'https://tieba.baidu.com/f?kw={keyword}', desc: '进入贴吧' },
                { type: 'direct_search', url: 'https://tieba.baidu.com/f/search/res?ie=utf-8&qw={keyword}+{industry}', desc: '贴吧搜索' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '.s_post_list .s_post, .threadlist_lz',
                    userName: '.p_author_name, .frs-author-name-wrap a',
                    userUrl: '.p_author_name a, .frs-author-name-wrap a',
                    contentPreview: '.p_excerpt, .threadlist_abs'
                },
                profileSelectors: {
                    name: '.userinfo_username, #userinfo_wrap .username',
                    bio: '.userinfo_detail, .userinfo_sign'
                },
                qualifySignals: ['联系', '微信', '电话', '手机', '合作', 'QQ', '邮箱']
            },
            behaviors: {
                needsLogin: false,
                rateLimit: 2000,
                maxPagesPerQuery: 5,
                antiCrawl: { scrollDelay: 1000, randomDelay: false }
            }
        },

        // ── Facebook ──
        facebook: {
            name: 'Facebook',
            domain: 'facebook.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:facebook.com {keyword} {industry} {region}', engine: 'google', desc: 'Google Facebook 搜索' },
                { type: 'direct_search', url: 'https://www.facebook.com/search/people/?q={keyword}+{industry}', desc: 'Facebook 搜人' },
                { type: 'direct_search', url: 'https://www.facebook.com/search/groups/?q={keyword}+{industry}', desc: 'Facebook 搜群组' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '[role="article"], .search-result',
                    userName: 'a[role="presentation"] span, .x1lliihq',
                    userUrl: 'a[role="presentation"]',
                    contentPreview: '.xdj266r, .x11i5rnm'
                },
                profileSelectors: {
                    name: 'h1, [data-testid="profile_name"]',
                    bio: '.x1heor9g, [data-testid="profile_intro"]',
                    location: '[data-testid="profile_intro_card"] .x1heor9g'
                },
                qualifySignals: ['message', 'contact', 'email', 'phone', 'WhatsApp', 'connect']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 5000,
                maxPagesPerQuery: 2,
                antiCrawl: { scrollDelay: 2000, randomDelay: true },
                loginWallKeywords: ['Log in', 'Sign up', 'Create new account']
            }
        },

        // ── Twitter / X ──
        twitter: {
            name: 'X (Twitter)',
            domain: 'x.com',
            searchStrategies: [
                { type: 'site_search', template: 'site:x.com {keyword} {industry}', engine: 'google', desc: 'Google X 搜索' },
                { type: 'direct_search', url: 'https://x.com/search?q={keyword}+{industry}&f=user', desc: 'X 搜用户' },
                { type: 'direct_search', url: 'https://x.com/search?q={keyword}+{industry}', desc: 'X 搜帖子' }
            ],
            parseStrategy: {
                searchResultSelectors: {
                    userCards: '[data-testid="UserCell"], [data-testid="cellInnerDiv"]',
                    userName: '[data-testid="User-Name"] a, .css-1jxf684',
                    userUrl: '[data-testid="User-Name"] a[role="link"]',
                    userBio: '[data-testid="UserDescription"]',
                    contentPreview: '[data-testid="tweetText"]'
                },
                profileSelectors: {
                    name: '[data-testid="UserName"] span',
                    bio: '[data-testid="UserDescription"]',
                    location: '[data-testid="UserProfileHeader_Items"] [data-testid="UserLocation"]',
                    website: '[data-testid="UserProfileHeader_Items"] a[href*="t.co"]',
                    followers: 'a[href*="followers"] span'
                },
                qualifySignals: ['DM', 'contact', 'email', 'WhatsApp', 'business', 'collab']
            },
            behaviors: {
                needsLogin: true,
                rateLimit: 4000,
                maxPagesPerQuery: 3,
                antiCrawl: { scrollDelay: 1500, randomDelay: true },
                loginWallKeywords: ['Sign in', 'Log in', 'Create account']
            }
        }
    };

    // ================================================================
    // 关键词矩阵生成器
    // ================================================================

    /**
     * 从画像生成多维关键词矩阵
     * @param {Object} profile - 来源画像
     * @returns {Object} 关键词矩阵
     */
    function generateKeywordMatrix(profile) {
        var matrix = {
            industry: [],     // 行业词：女装、服饰、时尚
            region: [],       // 地域词：广州、深圳、浙江
            demand: [],       // 需求词：批发、代理、加盟、进货
            crowd: [],        // 人群词：店主、买手、代购、微商
            product: [],      // 产品词：连衣裙、T恤、夏装
            scene: [],        // 场景词：穿搭、搭配、种草
            combined: []      // 组合关键词（最终搜索用）
        };

        // 从画像提取各维度
        if (profile.industry) {
            matrix.industry = splitKeywords(profile.industry);
        }
        if (profile.region) {
            matrix.region = splitKeywords(profile.region);
        }
        if (profile.keywords && Array.isArray(profile.keywords)) {
            profile.keywords.forEach(function (kw) {
                matrix.product.push(kw);
            });
        }
        if (profile.products && Array.isArray(profile.products)) {
            profile.products.forEach(function (p) {
                var name = typeof p === 'string' ? p : (p.name || p.title || '');
                if (name) matrix.product.push(name);
            });
        }
        if (profile.description) {
            // 从描述中提取关键信息
            var desc = profile.description;
            // 需求词
            var demandPatterns = ['批发', '代理', '加盟', '零售', '进货', '供货', '招商', '合作', '定制', '采购', 'wholesale', 'agent', 'franchise', 'retail', 'supply'];
            demandPatterns.forEach(function (d) {
                if (desc.indexOf(d) >= 0) matrix.demand.push(d);
            });
            // 人群词
            var crowdPatterns = ['店主', '买手', '代购', '微商', '电商', '淘宝', '拼多多', '商家', '老板', '经销商', '供应商', 'buyer', 'seller', 'merchant', 'dealer'];
            crowdPatterns.forEach(function (c) {
                if (desc.indexOf(c) >= 0) matrix.crowd.push(c);
            });
            // 场景词
            var scenePatterns = ['穿搭', '搭配', '种草', '测评', '推荐', '分享', '日常', '街拍', 'OOTD', 'fashion', 'style', 'outfit'];
            scenePatterns.forEach(function (s) {
                if (desc.indexOf(s) >= 0) matrix.scene.push(s);
            });
        }

        // 如果某些维度为空，设置默认值
        if (matrix.demand.length === 0) {
            matrix.demand = ['合作', '进货', '采购'];
        }
        if (matrix.crowd.length === 0 && matrix.industry.length > 0) {
            matrix.crowd = [matrix.industry[0] + '爱好者', matrix.industry[0] + '买家'];
        }

        // 生成组合关键词（用于搜索）
        matrix.combined = generateCombinedKeywords(matrix);

        return matrix;
    }

    /**
     * 从文本中分割关键词
     */
    function splitKeywords(text) {
        if (!text) return [];
        // 按常见分隔符分割
        return text.split(/[,，、;；\s\/]+/).filter(function (k) { return k.trim().length > 0; }).map(function (k) { return k.trim(); });
    }

    /**
     * 从矩阵生成组合关键词
     */
    function generateCombinedKeywords(matrix) {
        var combined = [];

        // 策略1：行业 + 需求
        matrix.industry.forEach(function (ind) {
            matrix.demand.forEach(function (dem) {
                combined.push(ind + ' ' + dem);
            });
        });

        // 策略2：行业 + 地域
        matrix.industry.forEach(function (ind) {
            matrix.region.slice(0, 2).forEach(function (reg) {
                combined.push(reg + ' ' + ind);
            });
        });

        // 策略3：产品 + 人群
        matrix.product.slice(0, 3).forEach(function (prod) {
            matrix.crowd.slice(0, 2).forEach(function (cr) {
                combined.push(prod + ' ' + cr);
            });
        });

        // 策略4：行业 + 场景
        matrix.industry.forEach(function (ind) {
            matrix.scene.slice(0, 2).forEach(function (sc) {
                combined.push(ind + ' ' + sc);
            });
        });

        // 策略5：单独的高价值关键词
        matrix.product.slice(0, 3).forEach(function (prod) {
            combined.push(prod);
        });

        // 去重
        var seen = {};
        return combined.filter(function (k) {
            if (seen[k]) return false;
            seen[k] = true;
            return true;
        });
    }

    // ================================================================
    // 平台路由：根据画像选择最优平台和策略
    // ================================================================

    /**
     * 根据画像和配置选择要搜索的平台列表
     * @param {Object} profile - 来源画像
     * @param {Array} selectedWebsites - 用户选择的目标网站 domain 列表
     * @returns {Array} 排序后的平台策略列表
     */
    function selectPlatforms(profile, selectedWebsites) {
        var result = [];
        var language = (profile.language || 'zh').toLowerCase();
        var isChineseMarket = language.indexOf('zh') >= 0;

        // 根据语言/市场选择平台优先级
        var priorityOrder = isChineseMarket
            ? ['zhihu', 'xiaohongshu', 'weibo', 'douyin', 'tieba', 'linkedin', 'facebook', 'twitter']
            : ['linkedin', 'facebook', 'twitter', 'zhihu'];

        // 如果用户指定了网站，只使用这些
        if (selectedWebsites && selectedWebsites.length > 0) {
            selectedWebsites.forEach(function (domain) {
                for (var key in platforms) {
                    if (platforms[key].domain === domain || domain.indexOf(platforms[key].domain) >= 0) {
                        result.push({ key: key, platform: platforms[key], priority: result.length });
                    }
                }
            });
        } else {
            // 自动选择前 4 个平台
            priorityOrder.slice(0, 4).forEach(function (key) {
                if (platforms[key]) {
                    result.push({ key: key, platform: platforms[key], priority: result.length });
                }
            });
        }

        return result;
    }

    /**
     * 为指定平台生成搜索 URL 列表
     * @param {Object} platformInfo - 平台信息 { key, platform }
     * @param {Object} keywordMatrix - 关键词矩阵
     * @param {string} searchEngine - 搜索引擎偏好 ('google'/'baidu')
     * @returns {Array} 搜索 URL 列表
     */
    function generateSearchUrls(platformInfo, keywordMatrix, searchEngine) {
        var urls = [];
        var p = platformInfo.platform;
        var keywords = keywordMatrix.combined.slice(0, 6); // 最多 6 个关键词组合
        var industry = (keywordMatrix.industry[0] || '');
        var region = (keywordMatrix.region[0] || '');

        p.searchStrategies.forEach(function (strategy) {
            // 过滤搜索引擎
            if (strategy.type === 'site_search' && strategy.engine !== searchEngine) return;

            keywords.forEach(function (keyword) {
                var url = '';
                if (strategy.type === 'site_search') {
                    // 构造搜索引擎 URL
                    var query = strategy.template
                        .replace('{keyword}', keyword)
                        .replace('{industry}', industry)
                        .replace('{region}', region);
                    if (searchEngine === 'google') {
                        url = 'https://www.google.com/search?q=' + encodeURIComponent(query);
                    } else {
                        url = 'https://www.baidu.com/s?wd=' + encodeURIComponent(query);
                    }
                } else if (strategy.type === 'direct_search') {
                    url = strategy.url
                        .replace('{keyword}', encodeURIComponent(keyword))
                        .replace('{industry}', encodeURIComponent(industry))
                        .replace('{region}', encodeURIComponent(region));
                }

                if (url) {
                    urls.push({
                        url: url,
                        platform: platformInfo.key,
                        strategy: strategy.desc,
                        keyword: keyword,
                        type: strategy.type
                    });
                }
            });
        });

        return urls;
    }

    /**
     * 生成页面提取脚本（注入到目标页面执行）
     * @param {string} platformKey - 平台标识
     * @returns {Function} 提取函数（在页面上下文中执行）
     */
    function getExtractionScript(platformKey) {
        var p = platforms[platformKey];
        if (!p) return null;

        var selectors = p.parseStrategy.searchResultSelectors;
        var qualifySignals = p.parseStrategy.qualifySignals;

        // 返回可序列化的提取逻辑描述（供 chrome.scripting.executeScript 使用）
        return {
            selectors: selectors,
            qualifySignals: qualifySignals,
            platformName: p.name,
            // 通用提取函数代码（字符串，注入页面执行）
            extractionCode: function (sel, signals) {
                var results = [];
                var pageText = document.body ? document.body.innerText : '';
                var pageUrl = location.href;
                var pageTitle = document.title;

                // 提取用户卡片
                var cards = document.querySelectorAll(sel.userCards || 'a');
                var seen = {};
                cards.forEach(function (card, index) {
                    if (index > 30) return; // 限制数量
                    var nameEl = card.querySelector(sel.userName || 'a');
                    var urlEl = card.querySelector(sel.userUrl || 'a');
                    var bioEl = card.querySelector(sel.userBio || '');
                    var previewEl = card.querySelector(sel.contentPreview || '');

                    var name = nameEl ? (nameEl.innerText || '').trim() : '';
                    var url = urlEl ? (urlEl.href || '') : '';
                    var bio = bioEl ? (bioEl.innerText || '').trim() : '';
                    var preview = previewEl ? (previewEl.innerText || '').trim().substring(0, 200) : '';

                    if (!name || seen[url || name]) return;
                    seen[url || name] = true;

                    // 检查是否包含联系方式信号
                    var fullText = (name + ' ' + bio + ' ' + preview).toLowerCase();
                    var hasSignal = signals.some(function (s) { return fullText.indexOf(s.toLowerCase()) >= 0; });

                    results.push({
                        name: name,
                        profileUrl: url,
                        bio: bio.substring(0, 200),
                        contentPreview: preview,
                        hasContactSignal: hasSignal,
                        sourceUrl: pageUrl,
                        index: index
                    });
                });

                // 全局联系方式提取
                var emails = (pageText.match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || []).filter(function (e, i, a) { return a.indexOf(e) === i; });
                var phones = (pageText.match(/(?:\+?\d{1,3}[-.]?)?\(?\d{2,4}\)?[-.\s]?\d{3,4}[-.\s]?\d{3,4}/g) || []).filter(function (p, i, a) { return a.indexOf(p) === i; });

                return {
                    users: results,
                    globalEmails: emails.slice(0, 10),
                    globalPhones: phones.slice(0, 10),
                    pageTitle: pageTitle,
                    pageUrl: pageUrl,
                    totalFound: results.length
                };
            }.toString()
        };
    }

    /**
     * 获取用户主页深度提取脚本
     * @param {string} platformKey - 平台标识
     * @returns {Object} 提取选择器和信号
     */
    function getProfileExtractionConfig(platformKey) {
        var p = platforms[platformKey];
        if (!p) return null;
        return {
            selectors: p.parseStrategy.profileSelectors,
            qualifySignals: p.parseStrategy.qualifySignals,
            platformName: p.name,
            behaviors: p.behaviors
        };
    }

    // ================================================================
    // 公共 API
    // ================================================================

    return {
        // 平台注册表
        platforms: platforms,

        // 关键词矩阵
        generateKeywordMatrix: generateKeywordMatrix,

        // 平台路由
        selectPlatforms: selectPlatforms,
        generateSearchUrls: generateSearchUrls,

        // 提取配置
        getExtractionScript: getExtractionScript,
        getProfileExtractionConfig: getProfileExtractionConfig,

        // 工具方法
        splitKeywords: splitKeywords,
        generateCombinedKeywords: generateCombinedKeywords,

        /**
         * 获取所有已注册平台列表
         */
        listPlatforms: function () {
            var list = [];
            for (var key in platforms) {
                list.push({ key: key, name: platforms[key].name, domain: platforms[key].domain });
            }
            return list;
        },

        /**
         * 根据 domain 查找平台
         */
        findPlatformByDomain: function (domain) {
            for (var key in platforms) {
                if (domain.indexOf(platforms[key].domain) >= 0) {
                    return { key: key, platform: platforms[key] };
                }
            }
            return null;
        },

        /**
         * 注册自定义平台（扩展性）
         */
        registerPlatform: function (key, config) {
            platforms[key] = config;
        }
    };
})();
