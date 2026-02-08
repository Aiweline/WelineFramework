/**
 * 推理 Worker
 * 使用 transformers.js 在 WebWorker 中运行本地模型推理
 */

// 动态导入 transformers.js
let pipeline = null;
let generator = null;
let tokenizer = null;
let currentModelId = null;
let isLoading = false;

// 消息处理
self.onmessage = async function (event) {
  const { type, id, payload } = event.data;

  try {
    switch (type) {
      case 'load':
        await handleLoad(id, payload);
        break;
      case 'generate':
        await handleGenerate(id, payload);
        break;
      case 'unload':
        await handleUnload(id);
        break;
      case 'status':
        handleStatus(id);
        break;
      default:
        throw new Error(`Unknown message type: ${type}`);
    }
  } catch (error) {
    self.postMessage({
      type: 'error',
      id,
      error: error.message || String(error),
    });
  }
};

/**
 * 常见模型 → ONNX 版本映射表
 * 用户可能选择了原始 PyTorch 模型（无 ONNX 文件），自动纠正为 ONNX 社区版本
 */
const ONNX_MODEL_MAP = {
  'Qwen/Qwen3-0.6B':            'onnx-community/Qwen3-0.6B-ONNX',
  'Qwen/Qwen3-1.7B':            'onnx-community/Qwen3-1.7B-ONNX',
  'Qwen/Qwen3-4B':              'onnx-community/Qwen3-4B-ONNX',
  'Qwen/Qwen2.5-0.5B-Instruct': 'onnx-community/Qwen2.5-0.5B-Instruct',
  'Qwen/Qwen2.5-1.5B-Instruct': 'onnx-community/Qwen2.5-1.5B-Instruct',
  'meta-llama/Llama-3.2-1B-Instruct': 'onnx-community/Llama-3.2-1B-Instruct-ONNX',
  'google/gemma-3-270m-it':      'onnx-community/gemma-3-270m-it-ONNX',
  'HuggingFaceTB/SmolLM2-360M':  'onnx-community/SmolLM2-360M-ONNX',
};

/**
 * 自动纠正模型 ID：如果是原始模型，映射到 ONNX 版本
 */
function resolveModelId(modelId) {
  if (!modelId) return modelId;
  // 精确匹配
  if (ONNX_MODEL_MAP[modelId]) {
    console.log('[InferenceWorker] 自动纠正模型 ID:', modelId, '->', ONNX_MODEL_MAP[modelId]);
    return ONNX_MODEL_MAP[modelId];
  }
  // 已经是 onnx-community 的模型，直接使用
  if (modelId.startsWith('onnx-community/')) return modelId;
  // 已经有 ONNX/onnx 后缀
  if (/[-_]ONNX$/i.test(modelId)) return modelId;
  return modelId;
}

/**
 * 加载模型
 */
async function handleLoad(id, payload) {
  let { modelId } = payload;

  // 自动纠正非 ONNX 模型 ID
  modelId = resolveModelId(modelId);

  if (isLoading) {
    throw new Error('Already loading a model');
  }

  if (currentModelId === modelId && generator) {
    // 模型已加载
    self.postMessage({ type: 'result', id, payload: 'Model already loaded' });
    return;
  }

  isLoading = true;

  try {
    // 卸载旧模型
    if (generator) {
      generator = null;
      tokenizer = null;
    }

    // 内存预检 —— 避免大模型导致浏览器崩溃
    const MODEL_SIZE_ESTIMATES = {
      'gemma-3-270m': 150, 'SmolLM2-360M': 200, 'Qwen2.5-0.5B': 300,
      'Qwen3-0.6B': 400, 'Llama-3.2-1B': 600, 'Qwen2.5-1.5B': 900,
    };
    let estimatedMB = 300; // 默认估计
    for (const [key, mb] of Object.entries(MODEL_SIZE_ESTIMATES)) {
      if (modelId.includes(key)) { estimatedMB = mb; break; }
    }
    // 检查可用内存（仅 Chromium 支持）
    if (typeof performance !== 'undefined' && performance.memory) {
      const usedMB = performance.memory.usedJSHeapSize / (1024 * 1024);
      const limitMB = performance.memory.jsHeapSizeLimit / (1024 * 1024);
      const availableMB = limitMB - usedMB;
      console.log(`[InferenceWorker] 内存: 已用 ${usedMB.toFixed(0)}MB, 限制 ${limitMB.toFixed(0)}MB, 可用 ${availableMB.toFixed(0)}MB, 模型预估 ${estimatedMB}MB`);
      // 模型加载后内存膨胀约 2-3 倍（ONNX Runtime + 推理缓冲区）
      const requiredMB = estimatedMB * 2.5;
      if (availableMB < requiredMB) {
        isLoading = false;
        throw new Error(`内存不足：模型预估需要 ${estimatedMB}MB（加载后约 ${Math.round(requiredMB)}MB），当前可用 ${Math.round(availableMB)}MB。请选择更小的模型（如 gemma-3-270m-it ~150MB）`);
      }
    }

    // 发送进度更新
    self.postMessage({
      type: 'progress',
      id,
      payload: { status: 'loading', message: `正在加载 transformers.js...（模型约 ${estimatedMB}MB）` },
    });

    // 从本地扩展目录导入 transformers.js（避免 CDN 被 CSP 阻止）
    const transformers = await import('./lib/transformers.min.js');
    pipeline = transformers.pipeline || transformers.default?.pipeline;

    // 输出版本信息用于调试
    const tjsVersion = transformers.env?.version || transformers.default?.env?.version || 'unknown';
    console.log('[InferenceWorker] transformers.js version:', tjsVersion);
    console.log('[InferenceWorker] pipeline function:', typeof pipeline);

    // 配置 transformers.js 环境 — 确保 WASM/ONNX 文件从 CDN 获取（扩展 CSP 允许 connect-src）
    if (transformers.env) {
      // 禁用本地模型检查（浏览器环境没有本地文件系统）
      transformers.env.allowLocalModels = false;
      // ONNX WASM 文件从 CDN 加载（不受 script-src CSP 限制，走 fetch）
      if (transformers.env.backends?.onnx) {
        transformers.env.backends.onnx.wasm = transformers.env.backends.onnx.wasm || {};
        transformers.env.backends.onnx.wasm.wasmPaths = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.22.0/dist/';
      }
    }

    // 文件级进度跟踪
    const fileTracker = { files: {}, totalLoaded: 0, totalSize: 0 };
    function handleProgressCallback(progress) {
      /* transformers.js progress_callback 格式:
       *  { status: 'initiate', name: 'onnx/model_q4.onnx', file: 'onnx/model_q4.onnx' }
       *  { status: 'download', name: 'onnx/model_q4.onnx', file: 'onnx/model_q4.onnx' }
       *  { status: 'progress', name: '...', file: '...', progress: 50, loaded: 5000000, total: 10000000 }
       *  { status: 'done', name: '...', file: '...' }
       *  { status: 'ready' }  — pipeline 完全就绪
       */
      const fileName = progress.file || progress.name || 'model';
      const pStatus = progress.status || 'progress';

      if (pStatus === 'initiate') {
        fileTracker.files[fileName] = { loaded: 0, total: 0, done: false };
      } else if (pStatus === 'progress' && progress.total) {
        if (!fileTracker.files[fileName]) fileTracker.files[fileName] = { loaded: 0, total: 0, done: false };
        fileTracker.files[fileName].loaded = progress.loaded || 0;
        fileTracker.files[fileName].total = progress.total || 0;
      } else if (pStatus === 'done') {
        if (fileTracker.files[fileName]) fileTracker.files[fileName].done = true;
      }

      // 汇总所有文件的进度
      let sumLoaded = 0, sumTotal = 0, doneCount = 0, totalCount = 0;
      for (const key in fileTracker.files) {
        const f = fileTracker.files[key];
        totalCount++;
        sumLoaded += f.loaded || 0;
        sumTotal += f.total || 0;
        if (f.done) doneCount++;
      }
      fileTracker.totalLoaded = sumLoaded;
      fileTracker.totalSize = sumTotal;

      const overallProgress = sumTotal > 0 ? (sumLoaded / sumTotal) * 100 : (progress.progress || 0);

      self.postMessage({
        type: 'progress',
        id,
        payload: {
          status: pStatus === 'ready' ? 'ready' : 'loading',
          message: pStatus === 'ready' ? '模型就绪' : `下载中: ${fileName}`,
          progress: overallProgress,
          // 详细文件级数据
          currentFile: fileName,
          currentFileProgress: progress.progress || 0,
          currentFileLoaded: progress.loaded || 0,
          currentFileTotal: progress.total || 0,
          downloadedSize: sumLoaded,
          totalSize: sumTotal,
          filesDone: doneCount,
          filesTotal: totalCount,
          isDownloading: pStatus !== 'ready' && pStatus !== 'done',
        },
      });
    }

    self.postMessage({
      type: 'progress',
      id,
      payload: { status: 'loading', message: `正在下载模型 ${modelId}...`, progress: 0, isDownloading: true, currentFile: '准备中...' },
    });

    // 创建文本生成 pipeline — 优先 WebGPU，fallback 到 WASM
    let device = 'wasm'; // 默认 WASM（Offscreen Document 通常不支持 WebGPU）
    try {
      if (typeof navigator !== 'undefined' && navigator.gpu) {
        const adapter = await navigator.gpu.requestAdapter();
        if (adapter) device = 'webgpu';
      }
    } catch (_) { device = 'wasm'; }

    console.log('[InferenceWorker] Using device:', device, 'for model:', modelId);

    // 超时保护 —— 5 分钟内未完成则中止，防止浏览器卡死
    const LOAD_TIMEOUT_MS = 5 * 60 * 1000;
    async function pipelineWithTimeout(pipelineArgs) {
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
          reject(new Error(`模型加载超时（${LOAD_TIMEOUT_MS / 60000} 分钟）。模型可能太大，请选择更小的模型`));
        }, LOAD_TIMEOUT_MS);
        pipeline(...pipelineArgs)
          .then((result) => { clearTimeout(timer); resolve(result); })
          .catch((err) => { clearTimeout(timer); reject(err); });
      });
    }

    try {
      generator = await pipelineWithTimeout(['text-generation', modelId, {
        dtype: 'q4',
        device,
        progress_callback: handleProgressCallback,
      }]);
    } catch (pipelineErr) {
      // 如果是 WebGPU 失败，回退到 WASM
      if (device === 'webgpu' && !String(pipelineErr.message).includes('超时')) {
        console.warn('[InferenceWorker] WebGPU failed, falling back to WASM:', pipelineErr.message);
        device = 'wasm';
        generator = await pipelineWithTimeout(['text-generation', modelId, {
          dtype: 'q4',
          device: 'wasm',
          progress_callback: handleProgressCallback,
        }]);
      } else {
        // WASM 也失败了，抛出详细错误
        const errMsg = pipelineErr.message || String(pipelineErr);
        if (errMsg.includes('Unsupported model type')) {
          throw new Error(`不支持的模型类型: ${modelId}。请选择 ONNX 格式的模型（如 onnx-community/Qwen3-0.6B-ONNX）`);
        }
        throw pipelineErr;
      }
    }

    currentModelId = modelId;
    isLoading = false;

    self.postMessage({
      type: 'result',
      id,
      payload: 'Model loaded successfully',
    });
  } catch (error) {
    isLoading = false;
    throw error;
  }
}

/**
 * 生成文本
 */
async function handleGenerate(id, payload) {
  const { messages, maxTokens, temperature, topP } = payload;

  if (!generator) {
    throw new Error('Model not loaded');
  }

  // 将消息数组转换为提示文本
  const prompt = formatMessages(messages);

  // 流式生成：通过 callback_function 逐 token 输出
  let lastText = '';
  const result = await generator(prompt, {
    max_new_tokens: maxTokens || 512,
    temperature: temperature || 0.7,
    top_p: topP || 0.9,
    do_sample: temperature > 0,
    return_full_text: false,
    callback_function: (output) => {
      // output 可能是数组或对象，提取当前已生成的文本
      let currentText = '';
      if (Array.isArray(output)) {
        currentText = output[0]?.generated_text || '';
      } else if (output && output.generated_text) {
        currentText = output.generated_text;
      } else if (typeof output === 'string') {
        currentText = output;
      }
      // 只发送新增的 token 部分
      if (currentText.length > lastText.length) {
        const newToken = currentText.substring(lastText.length);
        lastText = currentText;
        self.postMessage({
          type: 'token',
          id,
          payload: { token: newToken, fullText: currentText },
        });
      }
    },
  });

  // 提取最终生成的文本
  const generatedText = result[0]?.generated_text || lastText || '';

  self.postMessage({
    type: 'result',
    id,
    payload: generatedText,
  });
}

/**
 * 卸载模型
 */
async function handleUnload(id) {
  generator = null;
  tokenizer = null;
  currentModelId = null;

  // 尝试释放 GPU 资源
  if (typeof self.gc === 'function') {
    self.gc();
  }

  self.postMessage({
    type: 'result',
    id,
    payload: 'Model unloaded',
  });
}

/**
 * 获取状态
 */
function handleStatus(id) {
  self.postMessage({
    type: 'status',
    id,
    payload: {
      modelId: currentModelId,
      isLoaded: !!generator,
      isLoading,
    },
  });
}

/**
 * 将消息数组格式化为提示文本
 * 支持多种对话模板
 */
function formatMessages(messages) {
  // 检测模型类型并选择合适的模板
  const modelLower = (currentModelId || '').toLowerCase();

  if (modelLower.includes('qwen')) {
    return formatQwenMessages(messages);
  } else if (modelLower.includes('llama')) {
    return formatLlamaMessages(messages);
  } else if (modelLower.includes('phi')) {
    return formatPhiMessages(messages);
  } else {
    // 通用格式
    return formatGenericMessages(messages);
  }
}

/**
 * Qwen 对话模板
 */
function formatQwenMessages(messages) {
  let prompt = '';
  for (const msg of messages) {
    if (msg.role === 'system') {
      prompt += `<|im_start|>system\n${msg.content}<|im_end|>\n`;
    } else if (msg.role === 'user') {
      prompt += `<|im_start|>user\n${msg.content}<|im_end|>\n`;
    } else if (msg.role === 'assistant') {
      prompt += `<|im_start|>assistant\n${msg.content}<|im_end|>\n`;
    }
  }
  prompt += '<|im_start|>assistant\n';
  return prompt;
}

/**
 * Llama 对话模板
 */
function formatLlamaMessages(messages) {
  let prompt = '<|begin_of_text|>';
  for (const msg of messages) {
    if (msg.role === 'system') {
      prompt += `<|start_header_id|>system<|end_header_id|>\n\n${msg.content}<|eot_id|>`;
    } else if (msg.role === 'user') {
      prompt += `<|start_header_id|>user<|end_header_id|>\n\n${msg.content}<|eot_id|>`;
    } else if (msg.role === 'assistant') {
      prompt += `<|start_header_id|>assistant<|end_header_id|>\n\n${msg.content}<|eot_id|>`;
    }
  }
  prompt += '<|start_header_id|>assistant<|end_header_id|>\n\n';
  return prompt;
}

/**
 * Phi 对话模板
 */
function formatPhiMessages(messages) {
  let prompt = '';
  for (const msg of messages) {
    if (msg.role === 'system') {
      prompt += `<|system|>\n${msg.content}<|end|>\n`;
    } else if (msg.role === 'user') {
      prompt += `<|user|>\n${msg.content}<|end|>\n`;
    } else if (msg.role === 'assistant') {
      prompt += `<|assistant|>\n${msg.content}<|end|>\n`;
    }
  }
  prompt += '<|assistant|>\n';
  return prompt;
}

/**
 * 通用对话模板
 */
function formatGenericMessages(messages) {
  let prompt = '';
  for (const msg of messages) {
    if (msg.role === 'system') {
      prompt += `System: ${msg.content}\n\n`;
    } else if (msg.role === 'user') {
      prompt += `User: ${msg.content}\n\n`;
    } else if (msg.role === 'assistant') {
      prompt += `Assistant: ${msg.content}\n\n`;
    }
  }
  prompt += 'Assistant: ';
  return prompt;
}
