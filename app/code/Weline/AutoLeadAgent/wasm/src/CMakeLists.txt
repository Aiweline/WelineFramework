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
    binding.cpp
)

# 头文件
set(HEADERS
    agent_core.h
)

# 创建可执行文件
add_executable(agent_core ${SOURCES} ${HEADERS})

# 编译为WASM
set_target_properties(agent_core PROPERTIES
    LINK_FLAGS "-s WASM=1 -s EXPORTED_FUNCTIONS='[\"_malloc\",\"_free\",\"calculateCustomerScore\",\"extractProfileFeatures\",\"matchProfile\",\"cleanData\"]' -s ALLOW_MEMORY_GROWTH=1 -O2"
)

