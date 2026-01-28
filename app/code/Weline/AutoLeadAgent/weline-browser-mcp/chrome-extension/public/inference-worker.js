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
 * 加载模型
 */
async function handleLoad(id, payload) {
  const { modelId } = payload;

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

    // 发送进度更新
    self.postMessage({
      type: 'progress',
      id,
      payload: { status: 'loading', message: '正在加载 transformers.js...' },
    });

    // 动态导入 transformers.js
    const transformers = await import('https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.4.1/dist/transformers.min.js');
    pipeline = transformers.pipeline;

    self.postMessage({
      type: 'progress',
      id,
      payload: { status: 'loading', message: `正在下载模型 ${modelId}...`, progress: 0 },
    });

    // 创建文本生成 pipeline
    generator = await pipeline('text-generation', modelId, {
      dtype: 'q4',  // 4-bit 量化以减少内存使用
      device: 'webgpu',  // 优先使用 WebGPU
      progress_callback: (progress) => {
        self.postMessage({
          type: 'progress',
          id,
          payload: {
            status: 'loading',
            message: `下载中: ${progress.file || 'model'}`,
            progress: progress.progress || 0,
          },
        });
      },
    }).catch(async (err) => {
      // WebGPU 不可用时回退到 WASM
      console.warn('WebGPU not available, falling back to WASM:', err);
      return pipeline('text-generation', modelId, {
        dtype: 'q4',
        progress_callback: (progress) => {
          self.postMessage({
            type: 'progress',
            id,
            payload: {
              status: 'loading',
              message: `下载中 (WASM): ${progress.file || 'model'}`,
              progress: progress.progress || 0,
            },
          });
        },
      });
    });

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

  // 生成文本
  const result = await generator(prompt, {
    max_new_tokens: maxTokens || 512,
    temperature: temperature || 0.7,
    top_p: topP || 0.9,
    do_sample: temperature > 0,
    return_full_text: false,
  });

  // 提取生成的文本
  const generatedText = result[0]?.generated_text || '';

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
