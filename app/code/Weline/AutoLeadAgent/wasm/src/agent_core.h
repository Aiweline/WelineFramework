#ifndef AGENT_CORE_H
#define AGENT_CORE_H

#ifdef __cplusplus
extern "C" {
#endif

/**
 * 计算客户评分
 * 
 * @param profile_data JSON格式的客户画像数据
 * @param profile_length 数据长度
 * @return 评分（0-100）
 */
double calculateCustomerScore(const char* profile_data, int profile_length);

/**
 * 提取特征
 * 
 * @param text 文本内容
 * @param text_length 文本长度
 * @param features 输出特征数组
 * @param features_size 特征数组大小
 * @return 实际提取的特征数量
 */
int extractProfileFeatures(const char* text, int text_length, double* features, int features_size);

/**
 * 匹配画像
 * 
 * @param profile1 画像1的特征向量
 * @param profile2 画像2的特征向量
 * @param vector_size 向量大小
 * @return 匹配分数（0-1）
 */
double matchProfile(const double* profile1, const double* profile2, int vector_size);

/**
 * 数据清洗
 * 
 * @param data 原始数据
 * @param data_length 数据长度
 * @param cleaned_data 清洗后的数据输出
 * @param cleaned_size 输出缓冲区大小
 * @return 清洗后的数据长度
 */
int cleanData(const char* data, int data_length, char* cleaned_data, int cleaned_size);

/**
 * 分析画像生成搜索关键词
 * 
 * @param profile_json JSON格式的客户画像数据
 * @param profile_length 画像数据长度
 * @param keywords_json 输出关键词JSON数组的缓冲区
 * @param keywords_size 输出缓冲区大小
 * @return 实际写入的关键词JSON长度，失败返回0
 */
int analyzeProfileForKeywords(const char* profile_json, int profile_length, 
                              char* keywords_json, int keywords_size);

/**
 * 匹配网页内容与画像
 * 
 * @param web_content 网页文本内容
 * @param content_length 内容长度
 * @param profile_json JSON格式的客户画像数据
 * @param profile_length 画像数据长度
 * @return 匹配分数（0-100）
 */
double matchWebContentWithProfile(const char* web_content, int content_length,
                                  const char* profile_json, int profile_length);

/**
 * 从网页内容中提取联系信息
 * 
 * @param web_content 网页文本内容
 * @param content_length 内容长度
 * @param contact_json 输出联系信息JSON的缓冲区
 * @param contact_size 输出缓冲区大小
 * @return 实际写入的联系信息JSON长度，失败返回0
 */
int extractContactInfo(const char* web_content, int content_length,
                       char* contact_json, int contact_size);

#ifdef __cplusplus
}
#endif

#endif // AGENT_CORE_H

