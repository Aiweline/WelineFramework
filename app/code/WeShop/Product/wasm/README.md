# WeShop Product WASM 价格计算模块

## 概述

本目录包含用于价格计算的 WebAssembly (WASM) 模块源码。

## 目录结构

```
wasm/
├── src/                    # 源码目录
│   ├── price_calculator.cpp    # 价格计算实现
│   └── price_calculator.h      # 头文件
└── output/                 # 编译输出目录（自动生成）
    └── price-calculator.wasm
```

## 编译方法

### 使用 WASI SDK（推荐）

```bash
# 1. 下载并安装 WASI SDK
# 访问 https://github.com/WebAssembly/wasi-sdk/releases
# 下载对应平台的压缩包并解压

# 2. 编译
cd wasm/src
/path/to/wasi-sdk/bin/clang \
    --target=wasm32-wasi \
    --sysroot=/path/to/wasi-sdk/share/wasi-sysroot \
    -O2 \
    -Wl,--export-all \
    -Wl,--no-entry \
    -o ../output/price-calculator.wasm \
    price_calculator.cpp
```

### 使用 Emscripten

```bash
# 1. 安装 Emscripten SDK
# 参考：https://emscripten.org/docs/getting_started/downloads.html

# 2. 激活环境
source /path/to/emsdk/emsdk_env.sh

# 3. 编译
cd wasm/src
emcc price_calculator.cpp \
    -O2 \
    -s WASM=1 \
    -s EXPORTED_FUNCTIONS='["_calculate_total_price","_apply_price_adjustment","_calculate_base_price","_format_price","_validate_price_components","_malloc","_free"]' \
    -s EXPORTED_RUNTIME_METHODS='["ccall","cwrap"]' \
    -o ../output/price-calculator.wasm
```

## 导出的函数

- `calculate_total_price`: 计算总价
- `apply_price_adjustment`: 应用价格调整
- `calculate_base_price`: 计算基础价格
- `format_price`: 格式化价格
- `validate_price_components`: 验证价格数据

## 使用说明

编译后的 WASM 文件应放置在：
```
app/code/WeShop/Product/view/statics/frontend/wasm/price-calculator.wasm
```

或通过静态资源URL访问：
```
/static/WeShop/Product/wasm/price-calculator.wasm
```

## 注意事项

1. WASM 模块是可选的，如果加载失败会自动降级到 JavaScript 计算
2. 确保 WASM 文件路径与 `product.js` 中的路径一致
3. 编译时确保导出所有需要的函数
