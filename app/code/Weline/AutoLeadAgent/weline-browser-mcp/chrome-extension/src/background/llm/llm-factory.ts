/**
 * LLM 工厂
 * 统一管理本地模型和远程 API 的创建
 */

import { ChatOpenAI } from '@langchain/openai';
import { ChatGoogleGenerativeAI } from '@langchain/google-genai';
import { ChatAnthropic } from '@langchain/anthropic';
import { ChatOllama } from '@langchain/ollama';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import { LocalChatModel, createLocalChatModel, isLocalInferenceSupported, SUPPORTED_LOCAL_MODELS } from './local-chat-model';
import { createLogger } from '@src/background/log';
import { ProviderTypeEnum } from '@extension/storage';

const logger = createLogger('LLMFactory');

// LLM 提供商类型
export enum LLMProviderType {
  LOCAL = 'local',           // 本地 WASM 推理
  OPENAI = 'openai',
  GOOGLE = 'google',
  ANTHROPIC = 'anthropic',
  OLLAMA = 'ollama',
  GROQ = 'groq',
  XAI = 'xai',
  DEEPSEEK = 'deepseek',
  CUSTOM = 'custom',
}

// LLM 配置
export interface LLMConfig {
  provider: LLMProviderType | ProviderTypeEnum | string;
  modelId: string;
  apiKey?: string;
  baseUrl?: string;
  temperature?: number;
  maxTokens?: number;
  topP?: number;
}

// 本地模型配置
export interface LocalLLMConfig {
  modelId: string;
  temperature?: number;
  maxTokens?: number;
  topP?: number;
}

/**
 * 创建 LLM 实例
 */
export function createLLM(config: LLMConfig): BaseChatModel | LocalChatModel {
  const provider = config.provider.toLowerCase();

  logger.info('Creating LLM with provider:', provider, 'model:', config.modelId);

  // 本地模型
  if (provider === LLMProviderType.LOCAL || provider === 'local') {
    if (!isLocalInferenceSupported()) {
      throw new Error('本地推理不支持，请确保浏览器支持 WebWorker 和 WebAssembly');
    }

    return createLocalChatModel({
      modelId: config.modelId,
      temperature: config.temperature,
      maxTokens: config.maxTokens,
      topP: config.topP,
    });
  }

  // OpenAI 及兼容 API
  if (provider === LLMProviderType.OPENAI || provider === ProviderTypeEnum.OpenAI) {
    return new ChatOpenAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      openAIApiKey: config.apiKey,
      configuration: config.baseUrl ? { baseURL: config.baseUrl } : undefined,
    });
  }

  // Google Gemini
  if (provider === LLMProviderType.GOOGLE || provider === ProviderTypeEnum.Gemini) {
    return new ChatGoogleGenerativeAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxOutputTokens: config.maxTokens,
      apiKey: config.apiKey,
    });
  }

  // Anthropic Claude
  if (provider === LLMProviderType.ANTHROPIC || provider === ProviderTypeEnum.Anthropic) {
    return new ChatAnthropic({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      anthropicApiKey: config.apiKey,
    });
  }

  // Ollama (本地)
  if (provider === LLMProviderType.OLLAMA || provider === ProviderTypeEnum.Ollama) {
    return new ChatOllama({
      model: config.modelId,
      temperature: config.temperature || 0.7,
      baseUrl: config.baseUrl || 'http://localhost:11434',
    });
  }

  // DeepSeek
  if (provider === LLMProviderType.DEEPSEEK || provider === ProviderTypeEnum.DeepSeek) {
    return new ChatOpenAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      openAIApiKey: config.apiKey,
      configuration: {
        baseURL: config.baseUrl || 'https://api.deepseek.com/v1',
      },
    });
  }

  // Groq
  if (provider === ProviderTypeEnum.Groq) {
    return new ChatOpenAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      openAIApiKey: config.apiKey,
      configuration: {
        baseURL: config.baseUrl || 'https://api.groq.com/openai/v1',
      },
    });
  }

  // xAI
  if (provider === ProviderTypeEnum.xAI) {
    return new ChatOpenAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      openAIApiKey: config.apiKey,
      configuration: {
        baseURL: config.baseUrl || 'https://api.x.ai/v1',
      },
    });
  }

  // 自定义 OpenAI 兼容
  if (provider === LLMProviderType.CUSTOM || provider === ProviderTypeEnum.Custom) {
    if (!config.baseUrl) {
      throw new Error('自定义提供商需要指定 baseUrl');
    }
    return new ChatOpenAI({
      modelName: config.modelId,
      temperature: config.temperature || 0.7,
      maxTokens: config.maxTokens,
      openAIApiKey: config.apiKey || 'sk-placeholder',
      configuration: {
        baseURL: config.baseUrl,
      },
    });
  }

  throw new Error(`不支持的 LLM 提供商: ${provider}`);
}

/**
 * 获取支持的本地模型列表
 */
export function getSupportedLocalModels() {
  return SUPPORTED_LOCAL_MODELS;
}

/**
 * 检查本地推理是否可用
 */
export function checkLocalInferenceSupport(): {
  supported: boolean;
  webWorker: boolean;
  webAssembly: boolean;
  webGpu: boolean;
} {
  const webWorker = typeof Worker !== 'undefined';
  const webAssembly = typeof WebAssembly !== 'undefined';
  // @ts-ignore
  const webGpu = typeof navigator !== 'undefined' && !!navigator.gpu;

  return {
    supported: webWorker && webAssembly,
    webWorker,
    webAssembly,
    webGpu,
  };
}

/**
 * 创建适配 LangChain BaseChatModel 的本地模型包装器
 * 这个包装器让 LocalChatModel 可以像 LangChain 的模型一样使用
 */
export function createLocalLLMAdapter(localModel: LocalChatModel): BaseChatModel {
  // 创建一个代理对象，将 LocalChatModel 的方法映射到 BaseChatModel 接口
  const adapter = {
    modelName: localModel.modelName,
    model_name: localModel.model_name,

    async invoke(messages: unknown[], options?: unknown) {
      // 转换 LangChain 消息格式
      const formattedMessages = (messages as Array<{ content: string; _getType(): string }>).map(msg => ({
        role: msg._getType() === 'system' ? 'system' : msg._getType() === 'human' ? 'user' : 'assistant',
        content: typeof msg.content === 'string' ? msg.content : JSON.stringify(msg.content),
      }));

      const response = await localModel.invoke(formattedMessages);

      // 返回类似 LangChain AIMessage 的对象
      return {
        content: response,
        _getType: () => 'ai',
      };
    },

    withStructuredOutput(schema: unknown, options?: unknown) {
      return {
        async invoke(messages: unknown[], callOptions?: unknown) {
          const formattedMessages = (messages as Array<{ content: string; _getType(): string }>).map(msg => ({
            role: msg._getType() === 'system' ? 'system' : msg._getType() === 'human' ? 'user' : 'assistant',
            content: typeof msg.content === 'string' ? msg.content : JSON.stringify(msg.content),
          }));

          const result = await localModel.invokeWithStructuredOutput(
            formattedMessages,
            schema as Record<string, unknown>,
          );

          return {
            parsed: result.parsed,
            raw: {
              content: result.raw.content,
              _getType: () => 'ai',
            },
          };
        },
      };
    },
  };

  return adapter as unknown as BaseChatModel;
}
