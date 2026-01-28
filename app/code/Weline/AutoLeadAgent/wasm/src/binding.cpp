#include <emscripten.h>
#include <emscripten/bind.h>
#include "agent_core.h"
#include <string>

using namespace emscripten;

// ============================================================================
// 前向声明：agent_brain.cpp 中导出的核心接口
// ============================================================================
extern "C" {
    void wasm_start_task(const char* taskJson);
    const char* wasm_next_decision();
    void wasm_apply_tool_result(const char* resultJson);
    const char* wasm_get_status();
    void wasm_stop_task();
    int wasm_is_ready();
    const char* wasm_get_version();
    
    // 兼容旧接口
    void* createAgentBrain();
    const char* decideNextAction(const char* stateJson, const char* profileJson, const char* promptJson);
    void updateState(const char* stateJson);
}

// ============================================================================
// Agent Brain 封装函数（用于 Embind）
// ============================================================================

// 启动任务
void startTaskJS(const std::string& taskJson) {
    wasm_start_task(taskJson.c_str());
}

// 获取下一步决策
std::string nextDecisionJS() {
    const char* result = wasm_next_decision();
    return result ? std::string(result) : std::string("{}");
}

// 应用工具执行结果
void applyToolResultJS(const std::string& resultJson) {
    wasm_apply_tool_result(resultJson.c_str());
}

// 获取当前状态
std::string getStatusJS() {
    const char* result = wasm_get_status();
    return result ? std::string(result) : std::string("{}");
}

// 停止任务
void stopTaskJS() {
    wasm_stop_task();
}

// 检查是否就绪
bool isReadyJS() {
    return wasm_is_ready() != 0;
}

// 获取版本号
std::string getVersionJS() {
    const char* result = wasm_get_version();
    return result ? std::string(result) : std::string("unknown");
}

// ============================================================================
// 兼容旧接口封装
// ============================================================================

// 创建智能体
int createAgentBrainJS() {
    createAgentBrain();
    return 1;
}

// 决策下一步（旧接口兼容）
std::string decideNextActionJS(const std::string& stateJson, 
                                const std::string& profileJson, 
                                const std::string& promptJson) {
    const char* result = decideNextAction(
        stateJson.c_str(), 
        profileJson.c_str(), 
        promptJson.c_str()
    );
    return result ? std::string(result) : std::string("{}");
}

// 更新状态（旧接口兼容）
void updateStateJS(const std::string& stateJson) {
    updateState(stateJson.c_str());
}

// ============================================================================
// 工具函数封装（已废弃，建议使用端侧模型）
// ============================================================================

EMSCRIPTEN_KEEPALIVE
double calculateCustomerScoreJS(const char* profile_data, int profile_length) {
    return calculateCustomerScore(profile_data, profile_length);
}

EMSCRIPTEN_KEEPALIVE
int extractProfileFeaturesJS(const char* text, int text_length, double* features, int features_size) {
    return extractProfileFeatures(text, text_length, features, features_size);
}

EMSCRIPTEN_KEEPALIVE
double matchProfileJS(const double* profile1, const double* profile2, int vector_size) {
    return matchProfile(profile1, profile2, vector_size);
}

EMSCRIPTEN_KEEPALIVE
int cleanDataJS(const char* data, int data_length, char* cleaned_data, int cleaned_size) {
    return cleanData(data, data_length, cleaned_data, cleaned_size);
}

EMSCRIPTEN_KEEPALIVE
int analyzeProfileForKeywordsJS(const char* profile_json, int profile_length, 
                                char* keywords_json, int keywords_size) {
    return analyzeProfileForKeywords(profile_json, profile_length, keywords_json, keywords_size);
}

EMSCRIPTEN_KEEPALIVE
double matchWebContentWithProfileJS(const char* web_content, int content_length,
                                    const char* profile_json, int profile_length) {
    return matchWebContentWithProfile(web_content, content_length, profile_json, profile_length);
}

EMSCRIPTEN_KEEPALIVE
int extractContactInfoJS(const char* web_content, int content_length,
                         char* contact_json, int contact_size) {
    return extractContactInfo(web_content, content_length, contact_json, contact_size);
}

// ============================================================================
// Emscripten Bindings
// ============================================================================
EMSCRIPTEN_BINDINGS(agent_core) {
    // 新的智能体大脑接口
    function("startTask", &startTaskJS);
    function("nextDecision", &nextDecisionJS);
    function("applyToolResult", &applyToolResultJS);
    function("getStatus", &getStatusJS);
    function("stopTask", &stopTaskJS);
    function("isReady", &isReadyJS);
    function("getVersion", &getVersionJS);
    
    // 兼容旧接口
    function("createAgentBrain", &createAgentBrainJS);
    function("decideNextAction", &decideNextActionJS);
    function("updateState", &updateStateJS);
    
    // 工具函数（已废弃，保留兼容性）
    function("calculateCustomerScore", &calculateCustomerScoreJS);
    function("extractProfileFeatures", &extractProfileFeaturesJS);
    function("matchProfile", &matchProfileJS);
    function("cleanData", &cleanDataJS);
    function("analyzeProfileForKeywords", &analyzeProfileForKeywordsJS);
    function("matchWebContentWithProfile", &matchWebContentWithProfileJS);
    function("extractContactInfo", &extractContactInfoJS);
}

