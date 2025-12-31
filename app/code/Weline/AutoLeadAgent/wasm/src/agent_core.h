#ifndef AGENT_CORE_H
#define AGENT_CORE_H

#ifdef __cplusplus
extern "C" {
#endif

/**
 * Calculate customer score
 * NOTE: Deprecated - use model inference instead via JS bridge
 * 
 * @param profile_data JSON format customer profile data
 * @param profile_length Data length
 * @return Score (0-100), default 50.0
 */
double calculateCustomerScore(const char* profile_data, int profile_length);

/**
 * Extract profile features
 * NOTE: Deprecated - use model inference instead via JS bridge
 * 
 * @param text Text content
 * @param text_length Text length
 * @param features Output feature array
 * @param features_size Feature array size
 * @return Number of features extracted (basic statistics only)
 */
int extractProfileFeatures(const char* text, int text_length, double* features, int features_size);

/**
 * Match profile
 * NOTE: Deprecated - use model inference instead via JS bridge
 * 
 * @param profile1 Feature vector of profile 1
 * @param profile2 Feature vector of profile 2
 * @param vector_size Vector size
 * @return Match score (0-1), basic cosine similarity
 */
double matchProfile(const double* profile1, const double* profile2, int vector_size);

/**
 * Clean data - remove control characters
 * Utility function, kept for data processing
 * 
 * @param data Raw data
 * @param data_length Data length
 * @param cleaned_data Output buffer for cleaned data
 * @param cleaned_size Output buffer size
 * @return Length of cleaned data
 */
int cleanData(const char* data, int data_length, char* cleaned_data, int cleaned_size);

/**
 * Analyze profile for keywords
 * NOTE: Deprecated - use model inference instead via JS bridge
 * Only extracts from target_customers field
 * 
 * @param profile_json JSON format customer profile data
 * @param profile_length Profile data length
 * @param keywords_json Output buffer for keywords JSON array
 * @param keywords_size Output buffer size
 * @return Length of keywords JSON written, 0 on failure
 */
int analyzeProfileForKeywords(const char* profile_json, int profile_length, 
                              char* keywords_json, int keywords_size);

/**
 * Match web content with profile
 * NOTE: Deprecated - use model inference instead via JS bridge
 * 
 * @param web_content Web page text content
 * @param content_length Content length
 * @param profile_json JSON format customer profile data
 * @param profile_length Profile data length
 * @return Match score (0-100), default 50.0
 */
double matchWebContentWithProfile(const char* web_content, int content_length,
                                  const char* profile_json, int profile_length);

/**
 * Extract contact information from web content
 * Utility function for data extraction, kept
 * 
 * @param web_content Web page text content
 * @param content_length Content length
 * @param contact_json Output buffer for contact information JSON
 * @param contact_size Output buffer size
 * @return Length of contact information JSON written, 0 on failure
 */
int extractContactInfo(const char* web_content, int content_length,
                       char* contact_json, int contact_size);

#ifdef __cplusplus
}
#endif

#endif // AGENT_CORE_H

