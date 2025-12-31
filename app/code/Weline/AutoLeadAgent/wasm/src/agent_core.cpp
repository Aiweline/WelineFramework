#include "agent_core.h"
#include <cmath>
#include <cstring>
#include <algorithm>

/**
 * Calculate customer score
 * NOTE: This function is deprecated. Use model inference instead.
 * This is a placeholder that returns a default value.
 * The actual scoring should be done by the AI model via JS bridge.
 */
double calculateCustomerScore(const char* profile_data, int profile_length) {
    // This function is deprecated - use model inference instead
    // Return default score to maintain API compatibility
    if (!profile_data || profile_length <= 0) {
        return 0.0;
    }
    // Return a default score - actual scoring should use model
    return 50.0;
}

/**
 * Extract profile features
 * NOTE: This function is deprecated. Use model inference instead.
 * This is a placeholder that returns basic statistics only.
 * The actual feature extraction should be done by the AI model via JS bridge.
 */
int extractProfileFeatures(const char* text, int text_length, double* features, int features_size) {
    if (!text || text_length <= 0 || !features || features_size < 5) {
        return 0;
    }
    
    // Only extract basic statistics - actual feature extraction should use model
    int word_count = 0;
    int char_count = text_length;
    
    // Count words (simplified, split by spaces)
    for (int i = 0; i < text_length; i++) {
        if (text[i] == ' ' || text[i] == '\t' || text[i] == '\n') {
            word_count++;
        }
    }
    word_count++; // Last word
    
    // Return basic statistics only
    features[0] = (double)word_count;
    features[1] = (double)char_count;
    features[2] = 0.0; // Should be extracted by model
    features[3] = 0.0; // Should be extracted by model
    features[4] = 0.0; // Should be extracted by model
    
    return 5;
}

/**
 * Match profile
 * NOTE: This function is deprecated. Use model inference instead.
 * This is a placeholder that calculates basic cosine similarity.
 * The actual matching should be done by the AI model via JS bridge.
 */
double matchProfile(const double* profile1, const double* profile2, int vector_size) {
    if (!profile1 || !profile2 || vector_size <= 0) {
        return 0.0;
    }
    
    // Basic cosine similarity - actual matching should use model
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
 * Clean data - remove control characters
 * This is a utility function, not inference, so it's kept.
 */
int cleanData(const char* data, int data_length, char* cleaned_data, int cleaned_size) {
    if (!data || data_length <= 0 || !cleaned_data || cleaned_size <= 0) {
        return 0;
    }
    
    int cleaned_length = 0;
    
    for (int i = 0; i < data_length && cleaned_length < cleaned_size - 1; i++) {
        char c = data[i];
        
        // Remove control characters, keep printable characters
        if (c >= 32 || c == '\n' || c == '\t') {
            cleaned_data[cleaned_length++] = c;
        }
    }
    
    cleaned_data[cleaned_length] = '\0';
    return cleaned_length;
}

/**
 * Analyze profile for keywords
 * NOTE: This function is deprecated. Use model inference instead.
 * This is a placeholder that extracts keywords from target_customers field only.
 * The actual keyword generation should be done by the AI model via JS bridge.
 */
int analyzeProfileForKeywords(const char* profile_json, int profile_length, 
                              char* keywords_json, int keywords_size) {
    if (!profile_json || profile_length <= 0 || !keywords_json || keywords_size <= 0) {
        return 0;
    }
    
    // Only extract from target_customers field - actual generation should use model
    int json_pos = 0;
    if (json_pos < keywords_size - 1) keywords_json[json_pos++] = '[';
    
    // Extract from target_customers field
    const char* target_start = strstr(profile_json, "\"target_customers\"");
    if (target_start) {
        const char* array_start = strchr(target_start, '[');
        if (array_start) {
            array_start++;
            bool first_keyword = true;
            while (*array_start && *array_start != ']' && json_pos < keywords_size - 10) {
                if (*array_start == '"') {
                    array_start++;
                    const char* item_end = strchr(array_start, '"');
                    if (item_end && item_end - array_start > 0) {
                        int item_len = item_end - array_start;
                        if (item_len > 0 && item_len < 50) {
                            if (!first_keyword && json_pos < keywords_size - 5) {
                                keywords_json[json_pos++] = ',';
                                keywords_json[json_pos++] = ' ';
                            }
                            keywords_json[json_pos++] = '"';
                            for (int j = 0; j < item_len && json_pos < keywords_size - 2; j++) {
                                keywords_json[json_pos++] = array_start[j];
                            }
                            keywords_json[json_pos++] = '"';
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
    
    if (json_pos < keywords_size - 1) keywords_json[json_pos++] = ']';
    keywords_json[json_pos] = '\0';
    
    return json_pos;
}

/**
 * Match web content with profile
 * NOTE: This function is deprecated. Use model inference instead.
 * This is a placeholder that returns a default score.
 * The actual matching should be done by the AI model via JS bridge.
 */
double matchWebContentWithProfile(const char* web_content, int content_length,
                                  const char* profile_json, int profile_length) {
    if (!web_content || content_length <= 0 || !profile_json || profile_length <= 0) {
        return 0.0;
    }
    
    // This function is deprecated - use model inference instead
    // Return default score to maintain API compatibility
    // Actual matching should use model via JS bridge
    return 50.0;
}

/**
 * Extract contact information from web content
 * This is a utility function for data extraction, not inference, so it's kept.
 */
int extractContactInfo(const char* web_content, int content_length,
                       char* contact_json, int contact_size) {
    if (!web_content || content_length <= 0 || !contact_json || contact_size <= 0) {
        return 0;
    }
    
    // Email pattern
    const char* email_pattern = "@";
    const char* phone_patterns[] = {"+", "phone", "tel", "mobile"};
    int phone_patterns_count = sizeof(phone_patterns) / sizeof(phone_patterns[0]);
    
    // Social media platform patterns
    const char* social_patterns[] = {"linkedin.com", "twitter.com", "facebook.com", 
                                     "instagram.com", "youtube.com", "x.com"};
    int social_patterns_count = sizeof(social_patterns) / sizeof(social_patterns[0]);
    
    char email[256] = {0};
    char phone[64] = {0};
    char social_accounts[512] = {0};
    bool has_social = false;
    
    // Extract email (simplified: find valid characters around @ symbol)
    const char* at_pos = strstr(web_content, email_pattern);
    if (at_pos) {
        // Find email prefix (backward)
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
        
        // Find email suffix (forward)
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
        
        // Validate email format
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
    
    // Extract phone (simplified: find digit sequences)
    for (int i = 0; i < phone_patterns_count; i++) {
        const char* pattern = phone_patterns[i];
        const char* pattern_pos = strstr(web_content, pattern);
        if (pattern_pos) {
            // Find digits after pattern
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
    
    // Extract social media accounts
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
            
            // Extract platform name
            const char* platform_name = pattern;
            if (strcmp(pattern, "x.com") == 0) platform_name = "twitter";
            else if (strcmp(pattern, "linkedin.com") == 0) platform_name = "linkedin";
            else if (strcmp(pattern, "facebook.com") == 0) platform_name = "facebook";
            else if (strcmp(pattern, "instagram.com") == 0) platform_name = "instagram";
            else if (strcmp(pattern, "youtube.com") == 0) platform_name = "youtube";
            
            // Build JSON
            social_accounts[social_pos++] = '"';
            int name_len = strlen(platform_name);
            for (int j = 0; j < name_len && social_pos < sizeof(social_accounts) - 20; j++) {
                social_accounts[social_pos++] = platform_name[j];
            }
            social_accounts[social_pos++] = '"';
            social_accounts[social_pos++] = ':';
            social_accounts[social_pos++] = '"';
            
            // Extract URL
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
    
    // Build contact information JSON
    int json_pos = 0;
    if (json_pos < contact_size - 1) contact_json[json_pos++] = '{';
    
    // Add email
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
    
    // Add phone
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
    
    // Add social media accounts
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

