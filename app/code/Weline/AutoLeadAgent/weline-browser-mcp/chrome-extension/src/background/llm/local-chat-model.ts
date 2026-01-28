/**
 * 本地 WASM 推理适配器
 * 替换 LangChain 的外部 API 调用，使用 transformers.js 进行本地推理
 */

import { createLogger } from '@src/background/log';

const logger = createLogger('LocalChatModel');

// 推理 Worker 消息类型
interface WorkerMessage {
  type: 'generate' | 'load' | 'unload' | 'status';
  id: string;
  payload?: unknown;
}

interface WorkerResponse {
  type: 'result' | 'error' | 'progress' | 'status';
  id: string;
  payload?: unknown;
  error?: string;
}

// 模型配置
export interface LocalModelConfig {
  modelId: string;
  maxTokens?: number;
  temperature?: number;
  topP?: number;
}

// 默认支持的本地模型列表
export const SUPPORTED_LOCAL_MODELS = [
  {
    id: 'Qwen/Qwen2.5-0.5B-Instruct',
    name: 'Qwen2.5 0.5B',
    size: '500MB',
    description: '轻量级中文对话模型',
  },
  {
    id: 'Qwen/Qwen2.5-1.5B-Instruct',
    name: 'Qwen2.5 1.5B',
    size: '1.5GB',
    description: '中等规模中文对话模型',
  },
  {
    id: 'microsoft/Phi-3-mini-4k-instruct-onnx-web',
    name: 'Phi-3 Mini',
    size: '2.3GB',
    description: '微软小型推理模型',
  },
  {
    id: 'onnx-community/Llama-3.2-1B-Instruct',
    name: 'Llama 3.2 1B',
    size: '1.2GB',
    description: 'Meta 小型对话模型',
  },
];

/**
 * 本地聊天模型类
 * 兼容 LangChain 的 BaseChatModel 接口
 */
export class LocalChatModel {
  private worker: Worker | null = null;
  private modelId: string;
  private isLoaded = false;
  private pendingRequests: Map<string, {
    resolve: (value: string) => void;
    reject: (error: Error) => void;
  }> = new Map();
  private config: LocalModelConfig;

  constructor(config: LocalModelConfig) {
    this.config = config;
    this.modelId = config.modelId;
    logger.info('LocalChatModel created with model:', this.modelId);
  }

  /**
   * 初始化推理 Worker
   */
  async initialize(): Promise<void> {
    if (this.worker) {
      logger.info('Worker already initialized');
      return;
    }

    return new Promise((resolve, reject) => {
      try {
        // 创建 Worker - 在扩展环境中使用 chrome.runtime.getURL
        const workerUrl = chrome.runtime.getURL('inference-worker.js');
        this.worker = new Worker(workerUrl, { type: 'module' });

        this.worker.onmessage = (event: MessageEvent<WorkerResponse>) => {
          this.handleWorkerMessage(event.data);
        };

        this.worker.onerror = (error) => {
          logger.error('Worker error:', error);
          reject(new Error(`Worker error: ${error.message}`));
        };

        // 发送初始化消息
        const initId = this.generateId();
        this.pendingRequests.set(initId, { resolve: () => resolve(), reject });

        this.worker.postMessage({
          type: 'load',
          id: initId,
          payload: {
            modelId: this.modelId,
          },
        } as WorkerMessage);

        // 超时处理
        setTimeout(() => {
          if (this.pendingRequests.has(initId)) {
            this.pendingRequests.delete(initId);
            reject(new Error('Model loading timeout'));
          }
        }, 300000); // 5 分钟超时（模型下载可能需要时间）
      } catch (error) {
        logger.error('Failed to create worker:', error);
        reject(error);
      }
    });
  }

  /**
   * 处理 Worker 消息
   */
  private handleWorkerMessage(message: WorkerResponse): void {
    const { type, id, payload, error } = message;

    if (type === 'progress') {
      // 进度更新，可以触发事件
      logger.debug('Loading progress:', payload);
      return;
    }

    const pending = this.pendingRequests.get(id);
    if (!pending) {
      logger.warn('No pending request for id:', id);
      return;
    }

    this.pendingRequests.delete(id);

    if (type === 'error') {
      pending.reject(new Error(error || 'Unknown error'));
    } else if (type === 'result') {
      if (id.startsWith('load-')) {
        this.isLoaded = true;
        logger.info('Model loaded successfully');
      }
      pending.resolve(payload as string);
    } else if (type === 'status') {
      pending.resolve(JSON.stringify(payload));
    }
  }

  /**
   * 生成文本
   * 兼容 LangChain 的 invoke 接口
   */
  async invoke(messages: Array<{ role: string; content: string }>): Promise<string> {
    if (!this.worker || !this.isLoaded) {
      await this.initialize();
    }

    return new Promise((resolve, reject) => {
      const requestId = this.generateId();

      this.pendingRequests.set(requestId, { resolve, reject });

      this.worker!.postMessage({
        type: 'generate',
        id: requestId,
        payload: {
          messages,
          maxTokens: this.config.maxTokens || 512,
          temperature: this.config.temperature || 0.7,
          topP: this.config.topP || 0.9,
        },
      } as WorkerMessage);

      // 超时处理
      setTimeout(() => {
        if (this.pendingRequests.has(requestId)) {
          this.pendingRequests.delete(requestId);
          reject(new Error('Generation timeout'));
        }
      }, 120000); // 2 分钟超时
    });
  }

  /**
   * 带结构化输出的调用
   * 模拟 LangChain 的 withStructuredOutput
   */
  async invokeWithStructuredOutput(
    messages: Array<{ role: string; content: string }>,
    schema: Record<string, unknown>,
  ): Promise<{ parsed: unknown; raw: { content: string } }> {
    // 在提示中加入 JSON Schema 要求
    const systemMessage = messages.find(m => m.role === 'system');
    const schemaInstructions = `

你必须以 JSON 格式返回响应，严格遵循以下 JSON Schema：
${JSON.stringify(schema, null, 2)}

只返回 JSON，不要包含任何其他文本或解释。`;

    if (systemMessage) {
      systemMessage.content += schemaInstructions;
    } else {
      messages.unshift({
        role: 'system',
        content: schemaInstructions,
      });
    }

    const response = await this.invoke(messages);

    // 尝试解析 JSON
    try {
      // 清理响应，提取 JSON
      let jsonStr = response.trim();
      
      // 移除 markdown 代码块
      if (jsonStr.startsWith('```')) {
        const lines = jsonStr.split('\n');
        lines.shift();
        if (lines[lines.length - 1].trim() === '```') {
          lines.pop();
        }
        jsonStr = lines.join('\n').trim();
      }

      // 提取 JSON 对象
      const jsonMatch = jsonStr.match(/\{[\s\S]*\}/);
      if (jsonMatch) {
        const parsed = JSON.parse(jsonMatch[0]);
        return {
          parsed,
          raw: { content: response },
        };
      }

      throw new Error('No JSON found in response');
    } catch (error) {
      logger.error('Failed to parse structured output:', error);
      return {
        parsed: null,
        raw: { content: response },
      };
    }
  }

  /**
   * 卸载模型
   */
  async unload(): Promise<void> {
    if (!this.worker) {
      return;
    }

    return new Promise((resolve) => {
      const unloadId = this.generateId();
      this.pendingRequests.set(unloadId, {
        resolve: () => {
          this.isLoaded = false;
          this.worker?.terminate();
          this.worker = null;
          resolve();
        },
        reject: () => resolve(),
      });

      this.worker.postMessage({
        type: 'unload',
        id: unloadId,
      } as WorkerMessage);

      // 超时强制关闭
      setTimeout(() => {
        if (this.pendingRequests.has(unloadId)) {
          this.pendingRequests.delete(unloadId);
          this.worker?.terminate();
          this.worker = null;
          this.isLoaded = false;
          resolve();
        }
      }, 5000);
    });
  }

  /**
   * 检查模型是否已加载
   */
  getIsLoaded(): boolean {
    return this.isLoaded;
  }

  /**
   * 获取模型信息
   */
  getModelInfo(): { modelId: string; isLoaded: boolean } {
    return {
      modelId: this.modelId,
      isLoaded: this.isLoaded,
    };
  }

  /**
   * 生成唯一 ID
   */
  private generateId(): string {
    return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
  }

  /**
   * 获取模型名称（兼容 LangChain）
   */
  get modelName(): string {
    return this.modelId;
  }

  /**
   * 获取模型名称（兼容 LangChain - snake_case）
   */
  get model_name(): string {
    return this.modelId;
  }
}

/**
 * 创建本地聊天模型的工厂函数
 */
export function createLocalChatModel(config: LocalModelConfig): LocalChatModel {
  return new LocalChatModel(config);
}

/**
 * 检查是否支持本地推理
 */
export function isLocalInferenceSupported(): boolean {
  // 检查 WebWorker 支持
  if (typeof Worker === 'undefined') {
    return false;
  }

  // 检查 WebAssembly 支持
  if (typeof WebAssembly === 'undefined') {
    return false;
  }

  // 检查是否在扩展环境中
  if (typeof chrome === 'undefined' || !chrome.runtime) {
    return false;
  }

  return true;
}
