#include <emscripten.h>
#include <emscripten/bind.h>
#include "agent_core.h"

using namespace emscripten;

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

EMSCRIPTEN_BINDINGS(agent_core) {
    function("calculateCustomerScore", &calculateCustomerScoreJS);
    function("extractProfileFeatures", &extractProfileFeaturesJS);
    function("matchProfile", &matchProfileJS);
    function("cleanData", &cleanDataJS);
    function("analyzeProfileForKeywords", &analyzeProfileForKeywordsJS);
    function("matchWebContentWithProfile", &matchWebContentWithProfileJS);
    function("extractContactInfo", &extractContactInfoJS);
}

