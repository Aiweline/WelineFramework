# WASM 编译说明

## 编译环境要求

1. 安装 Emscripten SDK
2. 安装 CMake (>= 3.15)

## 编译步骤

```bash
# 激活 Emscripten 环境
source /path/to/emsdk/emsdk_env.sh

# 创建构建目录
mkdir build
cd build

# 配置 CMake
emcmake cmake ..

# 编译
emmake make

# 复制生成的 WASM 文件
cp agent_core.wasm ../../view/statics/wasm/agent-core.wasm
```

## 注意事项

- 每次编译会生成不同的二进制文件（通过随机优化参数）
- 编译后需要更新数据库中的 WASM 哈希值
- 确保 WASM 文件路径正确

