#include "agent_core.h"
#include <cmath>
#include <cstring>
#include <algorithm>

/**
 * 计算客户评分
 */
double calculateCustomerScore(const char* profile_data, int profile_length) {
    // 简化版评分算法
    // 实际应该解析JSON并计算更复杂的特征
    
    if (!profile_data || profile_length <= 0) {
        return 0.0;
    }
    
    // 基础评分：根据数据长度和内容质量
    double score = 0.0;
    int keyword_count = 0;
    
    // 统计关键词出现次数（简化版）
    const char* keywords[] = {"客户", "产品", "服务", "质量", "价格", "品牌"};
    int keyword_count_total = sizeof(keywords) / sizeof(keywords[0]);
    
    for (int i = 0; i < keyword_count_total; i++) {
        const char* found = strstr(profile_data, keywords[i]);
        if (found) {
            keyword_count++;
        }
    }
    
    // 计算评分：关键词占比 * 100
    score = (double)keyword_count / keyword_count_total * 100.0;
    
    // 数据长度因子
    double length_factor = std::min(1.0, (double)profile_length / 1000.0);
    score = score * 0.7 + length_factor * 30.0;
    
    return std::min(100.0, std::max(0.0, score));
}

/**
 * 提取特征
 */
int extractProfileFeatures(const char* text, int text_length, double* features, int features_size) {
    if (!text || text_length <= 0 || !features || features_size < 5) {
        return 0;
    }
    
    // 基础特征提取
    int word_count = 0;
    int char_count = text_length;
    
    // 统计单词数（简化版，按空格分隔）
    for (int i = 0; i < text_length; i++) {
        if (text[i] == ' ' || text[i] == '\t' || text[i] == '\n') {
            word_count++;
        }
    }
    word_count++; // 最后一个单词
    
    // 关键词统计
    int industry_keywords = 0;
    int customer_keywords = 0;
    int product_keywords = 0;
    
    const char* industry_kw[] = {"零售", "电商", "餐饮", "服务", "制造"};
    const char* customer_kw[] = {"个人", "企业", "客户", "用户"};
    const char* product_kw[] = {"产品", "商品", "服务", "质量"};
    
    for (int i = 0; i < sizeof(industry_kw) / sizeof(industry_kw[0]); i++) {
        if (strstr(text, industry_kw[i])) industry_keywords++;
    }
    for (int i = 0; i < sizeof(customer_kw) / sizeof(customer_kw[0]); i++) {
        if (strstr(text, customer_kw[i])) customer_keywords++;
    }
    for (int i = 0; i < sizeof(product_kw) / sizeof(product_kw[0]); i++) {
        if (strstr(text, product_kw[i])) product_keywords++;
    }
    
    // 填充特征向量
    features[0] = (double)word_count;
    features[1] = (double)char_count;
    features[2] = (double)industry_keywords;
    features[3] = (double)customer_keywords;
    features[4] = (double)product_keywords;
    
    return 5;
}

/**
 * 匹配画像
 */
double matchProfile(const double* profile1, const double* profile2, int vector_size) {
    if (!profile1 || !profile2 || vector_size <= 0) {
        return 0.0;
    }
    
    // 计算余弦相似度
    double dot_product = 0.0;
    double norm1 = 0.0;
    double norm2 = 0.0;
    
    for (int i = 0; i < vector_size; i++) {
        dot_product += profile1[i] * profile2[i];
        norm1 += profile1[i] * profile1[i];
        norm2 += profile2[i] * profile2[i];
    }
    
    double denominator = sqrt(norm1) * sqrt(norm2);
    if (denominator == 0.0) {
        return 0.0;
    }
    
    return dot_product / denominator;
}

/**
 * 数据清洗
 */
int cleanData(const char* data, int data_length, char* cleaned_data, int cleaned_size) {
    if (!data || data_length <= 0 || !cleaned_data || cleaned_size <= 0) {
        return 0;
    }
    
    int cleaned_length = 0;
    
    for (int i = 0; i < data_length && cleaned_length < cleaned_size - 1; i++) {
        char c = data[i];
        
        // 移除控制字符，保留可打印字符
        if (c >= 32 || c == '\n' || c == '\t') {
            cleaned_data[cleaned_length++] = c;
        }
    }
    
    cleaned_data[cleaned_length] = '\0';
    return cleaned_length;
}

/**
 * 分析画像生成搜索关键词（客户画像关键词）
 * 从店铺画像推断客户画像，生成用于搜索的客户画像关键词
 */
int analyzeProfileForKeywords(const char* profile_json, int profile_length, 
                              char* keywords_json, int keywords_size) {
    if (!profile_json || profile_length <= 0 || !keywords_json || keywords_size <= 0) {
        return 0;
    }
    
    // 产品到客户映射规则（简化版，C++实现）
    // 识别关键词并映射到客户特征
    const char* product_keywords[] = {"女鞋", "男鞋", "运动鞋", "定制", "高端", "全球", "专业"};
    const char* customer_traits[][10] = {
        {"女性", "时尚", "爱美", "注重形象"},  // 女鞋
        {"男性", "商务", "实用", "注重品质"},  // 男鞋
        {"运动", "健康", "活力", "年轻"},      // 运动鞋
        {"追求个性", "注重品质", "有消费能力", "专业人士", "企业家"},  // 定制
        {"高消费能力", "注重品质", "追求卓越", "成功人士"},  // 高端
        {"国际化视野", "跨文化", "跨国企业", "国际商务人士"},  // 全球
        {"专业", "严谨", "注重细节", "专业人士", "专家"}  // 专业
    };
    
    // 临时存储客户画像关键词
    char customer_keywords[1000] = {0};
    int customer_pos = 0;
    bool first_keyword = true;
    
    // 简单的关键词识别和映射
    for (int i = 0; i < sizeof(product_keywords) / sizeof(product_keywords[0]); i++) {
        const char* product = product_keywords[i];
        if (strstr(profile_json, product)) {
            // 找到产品关键词，添加对应的客户特征
            for (int j = 0; j < 10 && customer_traits[i][j]; j++) {
                const char* trait = customer_traits[i][j];
                int trait_len = strlen(trait);
                
                // 检查是否已添加
                bool already_added = false;
                for (int k = 0; k < customer_pos; k++) {
                    if (strncmp(customer_keywords + k, trait, trait_len) == 0) {
                        already_added = true;
                        break;
                    }
                }
                
                if (!already_added && customer_pos + trait_len + 10 < sizeof(customer_keywords)) {
                    if (!first_keyword) {
                        customer_keywords[customer_pos++] = ',';
                        customer_keywords[customer_pos++] = ' ';
                    }
                    customer_keywords[customer_pos++] = '"';
                    for (int k = 0; k < trait_len; k++) {
                        customer_keywords[customer_pos++] = trait[k];
                    }
                    customer_keywords[customer_pos++] = '"';
                    first_keyword = false;
                }
            }
        }
    }
    
    // 从target_customers字段提取
    const char* target_start = strstr(profile_json, "\"target_customers\"");
    if (target_start) {
        const char* array_start = strchr(target_start, '[');
        if (array_start) {
            array_start++;
            while (*array_start && *array_start != ']') {
                if (*array_start == '"') {
                    array_start++;
                    const char* item_end = strchr(array_start, '"');
                    if (item_end && item_end - array_start > 0) {
                        int item_len = item_end - array_start;
                        if (item_len > 0 && item_len < 50) {
                            if (!first_keyword && customer_pos < sizeof(customer_keywords) - 20) {
                                customer_keywords[customer_pos++] = ',';
                                customer_keywords[customer_pos++] = ' ';
                            }
                            customer_keywords[customer_pos++] = '"';
                            for (int j = 0; j < item_len && customer_pos < sizeof(customer_keywords) - 5; j++) {
                                customer_keywords[customer_pos++] = array_start[j];
                            }
                            customer_keywords[customer_pos++] = '"';
                            first_keyword = false;
                        }
                    }
                    array_start = item_end ? item_end + 1 : array_start + 1;
                } else {
                    array_start++;
                }
            }
        }
    }
    
    // 构建JSON数组
    int json_pos = 0;
    if (json_pos < keywords_size - 1) keywords_json[json_pos++] = '[';
    
    // 复制客户画像关键词
    for (int i = 0; i < customer_pos && json_pos < keywords_size - 1; i++) {
        keywords_json[json_pos++] = customer_keywords[i];
    }
    
    if (json_pos < keywords_size - 1) keywords_json[json_pos++] = ']';
    keywords_json[json_pos] = '\0';
    
    return json_pos;
}

/**
 * 匹配网页内容与画像
 */
double matchWebContentWithProfile(const char* web_content, int content_length,
                                  const char* profile_json, int profile_length) {
    if (!web_content || content_length <= 0 || !profile_json || profile_length <= 0) {
        return 0.0;
    }
    
    // 简化版实现：基于关键词匹配和文本相似度
    // 实际应该使用更复杂的语义匹配算法
    
    double score = 0.0;
    int match_count = 0;
    int total_keywords = 0;
    
    // 从画像中提取关键词（简化：查找常见字段）
    const char* keywords[] = {"name", "industry", "description", "keywords", 
                              "products", "target_customers"};
    int keywords_count = sizeof(keywords) / sizeof(keywords[0]);
    
    // 统计画像中的关键词在网页内容中的出现次数
    for (int i = 0; i < keywords_count; i++) {
        const char* keyword = keywords[i];
        const char* keyword_in_profile = strstr(profile_json, keyword);
        if (keyword_in_profile) {
            total_keywords++;
            
            // 查找关键词后的值
            const char* value_start = strchr(keyword_in_profile + strlen(keyword), ':');
            if (value_start) {
                value_start++;
                while (*value_start == ' ' || *value_start == '\t') value_start++;
                
                // 提取值（简化：取前50个字符）
                char value[51] = {0};
                int value_pos = 0;
                while (*value_start && *value_start != ',' && *value_start != '}' && 
                       *value_start != ']' && value_pos < 50) {
                    if (*value_start != '"' && *value_start != ' ') {
                        value[value_pos++] = *value_start;
                    }
                    value_start++;
                }
                value[value_pos] = '\0';
                
                // 在网页内容中查找这个值
                if (value_pos > 0 && strstr(web_content, value)) {
                    match_count++;
                }
            }
        }
    }
    
    // 计算匹配分数
    if (total_keywords > 0) {
        score = (double)match_count / total_keywords * 100.0;
    }
    
    // 文本长度因子（内容越丰富，匹配可能性越高）
    double length_factor = std::min(1.0, (double)content_length / 500.0);
    score = score * 0.8 + length_factor * 20.0;
    
    return std::min(100.0, std::max(0.0, score));
}

/**
 * 从网页内容中提取联系信息
 */
int extractContactInfo(const char* web_content, int content_length,
                       char* contact_json, int contact_size) {
    if (!web_content || content_length <= 0 || !contact_json || contact_size <= 0) {
        return 0;
    }
    
    // 邮箱正则表达式模式
    const char* email_pattern = "@";
    const char* phone_patterns[] = {"+", "电话", "phone", "tel", "mobile"};
    int phone_patterns_count = sizeof(phone_patterns) / sizeof(phone_patterns[0]);
    
    // 社媒平台模式
    const char* social_patterns[] = {"linkedin.com", "twitter.com", "facebook.com", 
                                     "instagram.com", "youtube.com", "x.com"};
    int social_patterns_count = sizeof(social_patterns) / sizeof(social_patterns[0]);
    
    char email[256] = {0};
    char phone[64] = {0};
    char social_accounts[512] = {0};
    bool has_social = false;
    
    // 提取邮箱（简化：查找@符号前后的有效字符）
    const char* at_pos = strstr(web_content, email_pattern);
    if (at_pos) {
        // 向前查找邮箱前缀
        const char* email_start = at_pos - 1;
        int prefix_len = 0;
        while (email_start >= web_content && prefix_len < 50) {
            char c = *email_start;
            if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || 
                (c >= '0' && c <= '9') || c == '.' || c == '_' || c == '-' || c == '+') {
                prefix_len++;
                email_start--;
            } else {
                break;
            }
        }
        email_start++;
        
        // 向后查找邮箱后缀
        const char* email_end = at_pos + 1;
        int suffix_len = 0;
        while (*email_end && suffix_len < 50) {
            char c = *email_end;
            if ((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || 
                (c >= '0' && c <= '9') || c == '.' || c == '-') {
                suffix_len++;
                email_end++;
            } else {
                break;
            }
        }
        
        // 验证邮箱格式
        if (prefix_len > 0 && suffix_len > 3) {
            int email_pos = 0;
            for (int i = 0; i < prefix_len && email_pos < sizeof(email) - 1; i++) {
                email[email_pos++] = email_start[i];
            }
            email[email_pos++] = '@';
            for (int i = 0; i < suffix_len && email_pos < sizeof(email) - 1; i++) {
                email[email_pos++] = (at_pos + 1)[i];
            }
            email[email_pos] = '\0';
        }
    }
    
    // 提取电话（简化：查找数字序列）
    for (int i = 0; i < phone_patterns_count; i++) {
        const char* pattern = phone_patterns[i];
        const char* pattern_pos = strstr(web_content, pattern);
        if (pattern_pos) {
            // 查找模式后的数字
            const char* num_start = pattern_pos + strlen(pattern);
            while (*num_start == ' ' || *num_start == ':' || *num_start == '=') num_start++;
            
            int num_pos = 0;
            while (*num_start && num_pos < sizeof(phone) - 1) {
                char c = *num_start;
                if ((c >= '0' && c <= '9') || c == '+' || c == '-' || c == ' ' || 
                    c == '(' || c == ')') {
                    phone[num_pos++] = c;
                    num_start++;
                } else if (num_pos > 5) {
                    break;
                } else {
                    num_start++;
                    num_pos = 0;
                }
            }
            if (num_pos > 5) {
                phone[num_pos] = '\0';
                break;
            }
        }
    }
    
    // 提取社媒账户
    int social_pos = 0;
    social_accounts[social_pos++] = '{';
    bool first_social = true;
    for (int i = 0; i < social_patterns_count && social_pos < sizeof(social_accounts) - 50; i++) {
        const char* pattern = social_patterns[i];
        const char* pattern_pos = strstr(web_content, pattern);
        if (pattern_pos) {
            has_social = true;
            if (!first_social) {
                social_accounts[social_pos++] = ',';
                social_accounts[social_pos++] = ' ';
            }
            
            // 提取平台名
            const char* platform_name = pattern;
            if (strcmp(pattern, "x.com") == 0) platform_name = "twitter";
            else if (strcmp(pattern, "linkedin.com") == 0) platform_name = "linkedin";
            else if (strcmp(pattern, "facebook.com") == 0) platform_name = "facebook";
            else if (strcmp(pattern, "instagram.com") == 0) platform_name = "instagram";
            else if (strcmp(pattern, "youtube.com") == 0) platform_name = "youtube";
            
            // 构建JSON
            social_accounts[social_pos++] = '"';
            int name_len = strlen(platform_name);
            for (int j = 0; j < name_len && social_pos < sizeof(social_accounts) - 20; j++) {
                social_accounts[social_pos++] = platform_name[j];
            }
            social_accounts[social_pos++] = '"';
            social_accounts[social_pos++] = ':';
            social_accounts[social_pos++] = '"';
            
            // 提取URL
            const char* url_start = pattern_pos;
            while (url_start > web_content && 
                   (*(url_start - 1) == '/' || *(url_start - 1) == ':' || 
                    *(url_start - 1) == 'h' || *(url_start - 1) == 't' || 
                    *(url_start - 1) == 'p' || *(url_start - 1) == 's')) {
                url_start--;
            }
            
            int url_len = 0;
            const char* url_end = pattern_pos + strlen(pattern);
            while (*url_end && url_len < 100) {
                char c = *url_end;
                if (c == ' ' || c == '"' || c == '\'' || c == '>' || c == '<' || c == '\n') {
                    break;
                }
                url_end++;
                url_len++;
            }
            
            for (int j = 0; j < (pattern_pos - url_start) + strlen(pattern) + url_len && 
                 social_pos < sizeof(social_accounts) - 5; j++) {
                if (j < (pattern_pos - url_start)) {
                    social_accounts[social_pos++] = url_start[j];
                } else if (j < (pattern_pos - url_start) + strlen(pattern)) {
                    social_accounts[social_pos++] = pattern[j - (pattern_pos - url_start)];
                } else {
                    int offset = (pattern_pos - url_start) + strlen(pattern);
                    social_accounts[social_pos++] = (pattern_pos + strlen(pattern))[j - offset];
                }
            }
            
            social_accounts[social_pos++] = '"';
            first_social = false;
        }
    }
    social_accounts[social_pos++] = '}';
    social_accounts[social_pos] = '\0';
    
    // 构建联系信息JSON
    int json_pos = 0;
    if (json_pos < contact_size - 1) contact_json[json_pos++] = '{';
    
    // 添加邮箱
    if (email[0] != '\0') {
        if (json_pos < contact_size - 20) {
            const char* email_json = "\"email\":\"";
            for (int i = 0; email_json[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = email_json[i];
            }
            for (int i = 0; email[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = email[i];
            }
            contact_json[json_pos++] = '"';
        }
    }
    
    // 添加电话
    if (phone[0] != '\0') {
        if (json_pos < contact_size - 20) {
            if (email[0] != '\0') contact_json[json_pos++] = ',';
            const char* phone_json = "\"phone\":\"";
            for (int i = 0; phone_json[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = phone_json[i];
            }
            for (int i = 0; phone[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = phone[i];
            }
            contact_json[json_pos++] = '"';
        }
    }
    
    // 添加社媒账户
    if (has_social && social_accounts[1] != '}') {
        if (json_pos < contact_size - 20) {
            if (email[0] != '\0' || phone[0] != '\0') contact_json[json_pos++] = ',';
            const char* social_json = "\"socialMediaAccounts\":";
            for (int i = 0; social_json[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = social_json[i];
            }
            for (int i = 0; social_accounts[i] && json_pos < contact_size - 1; i++) {
                contact_json[json_pos++] = social_accounts[i];
            }
        }
    }
    
    if (json_pos < contact_size - 1) contact_json[json_pos++] = '}';
    contact_json[json_pos] = '\0';
    
    return json_pos;
}

