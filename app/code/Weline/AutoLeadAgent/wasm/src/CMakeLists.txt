cmake_minimum_required(VERSION 3.15)
project(agent_core)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Emscripten 编译选项
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -O2 -s WASM=1 -s EXPORTED_FUNCTIONS='[\"_malloc\",\"_free\"]' -s ALLOW_MEMORY_GROWTH=1")

# 添加随机优化参数（确保每次编译输出不同）
set(RANDOM_SEED ${CMAKE_CURRENT_SOURCE_DIR}/.random_seed)
if(NOT EXISTS ${RANDOM_SEED})
    file(WRITE ${RANDOM_SEED} "${CMAKE_SYSTEM_PROCESSOR}-${CMAKE_CXX_COMPILER_ID}-${CMAKE_CXX_COMPILER_VERSION}")
endif()
file(READ ${RANDOM_SEED} RANDOM_STRING)
string(SHA256 RANDOM_HASH ${RANDOM_STRING})
string(SUBSTRING ${RANDOM_HASH} 0 8 RANDOM_SUFFIX)
set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -DRANDOM_SUFFIX=${RANDOM_SUFFIX}")

# 源文件
set(SOURCES
    agent_core.cpp
    agent_brain.cpp
    mcp_protocol.cpp
    binding.cpp
)

# 头文件
set(HEADERS
    agent_core.h
)

# 创建可执行文件
add_executable(agent_core ${SOURCES} ${HEADERS})

# 编译为WASM - 导出所有新的智能体大脑函数
set_target_properties(agent_core PROPERTIES
    LINK_FLAGS "-s WASM=1 -s EXPORTED_FUNCTIONS='[\"_malloc\",\"_free\",\"_wasm_start_task\",\"_wasm_next_decision\",\"_wasm_apply_tool_result\",\"_wasm_get_status\",\"_wasm_stop_task\",\"_wasm_is_ready\",\"_wasm_get_version\",\"_createAgentBrain\",\"_decideNextAction\",\"_updateState\",\"_calculateCustomerScore\",\"_extractProfileFeatures\",\"_matchProfile\",\"_cleanData\",\"_analyzeProfileForKeywords\",\"_matchWebContentWithProfile\",\"_extractContactInfo\"]' -s ALLOW_MEMORY_GROWTH=1 -s EXPORTED_RUNTIME_METHODS='[\"ccall\",\"cwrap\",\"UTF8ToString\",\"stringToUTF8\",\"lengthBytesUTF8\"]' -O2"
)
