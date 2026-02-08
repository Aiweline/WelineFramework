import 'webextension-polyfill';
import {
  agentModelStore,
  AgentNameEnum,
  firewallStore,
  generalSettingsStore,
  llmProviderStore,
  analyticsSettingsStore,
} from '@extension/storage';
import { t } from '@extension/i18n';
import BrowserContext from './browser/context';
import { Executor } from './agent/executor';
import { createLogger } from './log';
import { ExecutionState } from './agent/event/types';
import { createChatModel } from './agent/helper';
import type { BaseChatModel } from '@langchain/core/language_models/chat_models';
import { DEFAULT_AGENT_OPTIONS } from './agent/types';
import { SpeechToTextService } from './services/speechToText';
import { injectBuildDomTreeScripts } from './browser/dom/service';
import { analytics } from './services/analytics';

const logger = createLogger('background');

const browserContext = new BrowserContext({});
let currentExecutor: Executor | null = null;
let currentPort: chrome.runtime.Port | null = null;
const SIDE_PANEL_URL = chrome.runtime.getURL('side-panel/index.html');

// Setup side panel behavior
chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(error => console.error(error));

chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
  if (tabId && changeInfo.status === 'complete' && tab.url?.startsWith('http')) {
    await injectBuildDomTreeScripts(tabId);
  }
});

// Listen for debugger detached event
// if canceled_by_user, remove the tab from the browser context
chrome.debugger.onDetach.addListener(async (source, reason) => {
  console.log('Debugger detached:', source, reason);
  if (reason === 'canceled_by_user') {
    if (source.tabId) {
      currentExecutor?.cancel();
      await browserContext.cleanup();
    }
  }
});

// Cleanup when tab is closed
chrome.tabs.onRemoved.addListener(tabId => {
  browserContext.removeAttachedPage(tabId);
});

logger.info('background loaded');

// Initialize analytics
analytics.init().catch(error => {
  logger.error('Failed to initialize analytics:', error);
});

// Listen for analytics settings changes
analyticsSettingsStore.subscribe(() => {
  analytics.updateSettings().catch(error => {
    logger.error('Failed to update analytics settings:', error);
  });
});

// ====== Inference Worker management for local model hosting ======
let inferenceWorker: Worker | null = null;
const pendingWorkerRequests = new Map<string, { resolve: (v: unknown) => void; reject: (e: Error) => void; timer: ReturnType<typeof setTimeout> }>();
let workerRequestCounter = 0;

function getInferenceWorker(): Worker {
  if (!inferenceWorker) {
    const workerUrl = chrome.runtime.getURL('inference-worker.js');
    inferenceWorker = new Worker(workerUrl);

    inferenceWorker.onmessage = (event: MessageEvent) => {
      const { type, id, payload, error } = event.data;
      const pending = pendingWorkerRequests.get(id);
      if (!pending) return;

      clearTimeout(pending.timer);
      pendingWorkerRequests.delete(id);

      if (type === 'error') {
        pending.reject(new Error(error || 'Worker error'));
      } else if (type === 'result' || type === 'status') {
        pending.resolve(payload);
      }
    };

    inferenceWorker.onerror = (error: ErrorEvent) => {
      logger.error('Inference worker error:', error);
      for (const [, pending] of pendingWorkerRequests.entries()) {
        clearTimeout(pending.timer);
        pending.reject(new Error('Worker crashed'));
      }
      pendingWorkerRequests.clear();
      inferenceWorker = null;
    };
  }
  return inferenceWorker;
}

function sendWorkerMessage(type: string, payload: unknown, timeoutMs = 120000): Promise<unknown> {
  return new Promise((resolve, reject) => {
    const id = 'bg_' + ++workerRequestCounter;
    const worker = getInferenceWorker();
    const timer = setTimeout(() => {
      pendingWorkerRequests.delete(id);
      reject(new Error('Worker request timeout'));
    }, timeoutMs);
    pendingWorkerRequests.set(id, { resolve, reject, timer });
    worker.postMessage({ type, id, payload });
  });
}

// ====== 页面追踪：目标页面检测 + 模型自动生命周期 ======
const TARGET_URL_PATTERNS = [
  /\/auto-lead-agent\/backend\/config/i,
  /\/auto-lead-agent\/backend\/index/i,
];
const targetTabs = new Map<number, { url: string; timestamp: number }>();
let unloadTimer: ReturnType<typeof setTimeout> | null = null;
const UNLOAD_DELAY_MS = 15000;
let currentModelState = { modelId: null as string | null, isLoaded: false, isLoading: false };

function isTargetUrl(url: string): boolean {
  return TARGET_URL_PATTERNS.some((p) => p.test(url));
}

async function onTargetPageEnter(tabId: number, url: string): Promise<void> {
  const wasEmpty = targetTabs.size === 0;
  targetTabs.set(tabId, { url, timestamp: Date.now() });
  saveTargetPageUrl(url);
  if (unloadTimer) { clearTimeout(unloadTimer); unloadTimer = null; }
  if (wasEmpty && !currentModelState.isLoaded && !currentModelState.isLoading) {
    await autoLoadModel();
  }
  broadcastModelEvent('status', currentModelState, tabId);
  if (progressLogCache.length > 0) {
    chrome.tabs.sendMessage(tabId, { type: 'TASK_PROGRESS_BATCH', logs: progressLogCache }).catch(() => {});
  }
}

function onTargetPageLeave(tabId: number): void {
  targetTabs.delete(tabId);
  if (targetTabs.size === 0 && currentModelState.isLoaded) {
    unloadTimer = setTimeout(async () => {
      unloadTimer = null;
      if (targetTabs.size === 0 && currentModelState.isLoaded) {
        try {
          await sendWorkerMessage('unload', {}, 10000);
          currentModelState = { modelId: null, isLoaded: false, isLoading: false };
          broadcastModelEvent('unloaded', {});
        } catch (e) { logger.error('Auto-unload failed:', e); }
      }
    }, UNLOAD_DELAY_MS);
  }
}

async function autoLoadModel(): Promise<void> {
  const data = await chrome.storage.local.get(['ala_model_id', 'ala_model_enabled']);
  if (!data.ala_model_id || data.ala_model_enabled === false) return;
  const modelId = data.ala_model_id as string;
  currentModelState.isLoading = true;
  broadcastModelEvent('loading', { modelId });
  try {
    await sendWorkerMessage('load', { modelId }, 300000);
    currentModelState = { modelId, isLoaded: true, isLoading: false };
    broadcastModelEvent('loaded', { modelId });
  } catch (err: any) {
    currentModelState.isLoading = false;
    broadcastModelEvent('load_error', { error: err.message });
  }
}

function saveTargetPageUrl(url: string): void {
  try {
    const u = new URL(url);
    const base = u.origin + u.pathname.replace(/\/auto-lead-agent\/backend\/.*$/, '');
    chrome.storage.local.set({ ala_config_url: base + '/auto-lead-agent/backend/config', ala_index_url: base + '/auto-lead-agent/backend/index' });
  } catch { /* ignore */ }
}

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status !== 'complete' || !tab.url) return;
  if (isTargetUrl(tab.url)) onTargetPageEnter(tabId, tab.url);
  else if (targetTabs.has(tabId)) onTargetPageLeave(tabId);
});
chrome.tabs.onRemoved.addListener((tabId) => { if (targetTabs.has(tabId)) onTargetPageLeave(tabId); });
chrome.tabs.query({}, (tabs) => { for (const tab of tabs) { if (tab.id && tab.url && isTargetUrl(tab.url)) onTargetPageEnter(tab.id, tab.url); } });

// ====== 任务进度中继 ======
const MAX_PROGRESS_CACHE = 200;
let progressLogCache: any[] = [];

function addProgressLog(log: any): void {
  progressLogCache.push(log);
  if (progressLogCache.length > MAX_PROGRESS_CACHE) progressLogCache = progressLogCache.slice(-MAX_PROGRESS_CACHE);
}

function broadcastProgress(log: any, excludeTabId?: number): void {
  addProgressLog(log);
  for (const [tabId] of targetTabs) {
    if (tabId === excludeTabId) continue;
    chrome.tabs.sendMessage(tabId, { type: 'TASK_PROGRESS', log }).catch(() => {});
  }
}

// ====== 工具函数 ======
function broadcastModelEvent(event: string, data: unknown, onlyTabId?: number): void {
  const msg = { type: 'MODEL_EVENT', event, data };
  if (onlyTabId) { chrome.tabs.sendMessage(onlyTabId, msg).catch(() => {}); return; }
  chrome.tabs.query({}, (tabs) => {
    for (const tab of tabs) {
      if (tab.id) chrome.tabs.sendMessage(tab.id, msg).catch(() => {});
    }
  });
}

// ====== 消息处理 ======
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (!message || typeof message.type !== 'string') return false;
  const senderTabId = sender?.tab?.id;

  switch (message.type) {
    case 'PING':
      sendResponse({ success: true, version: chrome.runtime.getManifest().version, features: ['model_inference', 'browser_automation', 'auto_lifecycle'] });
      return false;

    case 'PAGE_ENTER':
      if (senderTabId && message.url) onTargetPageEnter(senderTabId, message.url);
      sendResponse({ success: true, modelState: currentModelState });
      return false;

    case 'PAGE_LEAVE':
      if (senderTabId) onTargetPageLeave(senderTabId);
      sendResponse({ success: true });
      return false;

    case 'MODEL_SAVE_CONFIG': {
      const cfg = message.payload || message;
      chrome.storage.local.set({ ala_model_id: cfg.modelId || null, ala_model_enabled: cfg.enabled !== false }, () => {
        sendResponse({ success: true });
      });
      return true;
    }

    case 'GET_TARGET_URLS':
      chrome.storage.local.get(['ala_config_url', 'ala_index_url'], (data) => {
        sendResponse({ success: true, configUrl: data.ala_config_url || null, indexUrl: data.ala_index_url || null });
      });
      return true;

    case 'GET_FULL_STATUS':
      chrome.storage.local.get(['ala_model_id', 'ala_model_enabled'], (data) => {
        sendResponse({ success: true, modelState: currentModelState, config: { modelId: data.ala_model_id || null, enabled: data.ala_model_enabled !== false }, targetPagesCount: targetTabs.size });
      });
      return true;

    case 'TASK_PROGRESS':
      broadcastProgress(message.log || message.payload, senderTabId ?? undefined);
      sendResponse({ success: true });
      return false;

    case 'GET_PROGRESS_CACHE':
      sendResponse({ success: true, logs: progressLogCache });
      return false;

    case 'CLEAR_PROGRESS':
      progressLogCache = [];
      sendResponse({ success: true });
      return false;

    case 'MODEL_STATUS':
      sendWorkerMessage('status', {})
        .then((status) => { currentModelState = status as any || currentModelState; sendResponse({ success: true, status: currentModelState }); })
        .catch(() => sendResponse({ success: true, status: currentModelState }));
      return true;

    case 'MODEL_LOAD': {
      const modelId = message.modelId || message.payload?.modelId;
      if (!modelId) { sendResponse({ success: false, error: 'No modelId provided' }); return false; }
      currentModelState.isLoading = true;
      broadcastModelEvent('loading', { modelId });
      chrome.storage.local.set({ ala_model_id: modelId, ala_model_enabled: true });
      sendWorkerMessage('load', { modelId }, 300000)
        .then((result) => {
          currentModelState = { modelId, isLoaded: true, isLoading: false };
          sendResponse({ success: true, result });
          broadcastModelEvent('loaded', { modelId });
        })
        .catch((err: Error) => {
          currentModelState.isLoading = false;
          sendResponse({ success: false, error: err.message });
          broadcastModelEvent('load_error', { error: err.message });
        });
      return true;
    }

    case 'MODEL_UNLOAD':
      sendWorkerMessage('unload', {}, 10000)
        .then((result) => {
          currentModelState = { modelId: null, isLoaded: false, isLoading: false };
          sendResponse({ success: true, result });
          broadcastModelEvent('unloaded', {});
        })
        .catch((err: Error) => sendResponse({ success: false, error: err.message }));
      return true;

    case 'MODEL_INFERENCE': {
      const prompt = message.prompt || message.payload?.prompt;
      const options = message.options || message.payload?.options || {};
      if (!prompt) { sendResponse({ success: false, error: 'No prompt provided' }); return false; }
      const messages = [
        { role: 'system', content: 'You are a helpful assistant.' },
        { role: 'user', content: prompt },
      ];
      sendWorkerMessage('generate', { messages, maxTokens: options.maxTokens || 512, temperature: options.temperature || 0.7 })
        .then((result) => sendResponse({ success: true, result }))
        .catch((err: Error) => sendResponse({ success: false, error: err.message }));
      return true;
    }

    default:
      return false;
  }
});

// Setup connection listener for long-lived connections (e.g., side panel)
chrome.runtime.onConnect.addListener(port => {
  if (port.name === 'side-panel-connection') {
    const senderUrl = port.sender?.url;
    const senderId = port.sender?.id;

    if (!senderUrl || senderId !== chrome.runtime.id || senderUrl !== SIDE_PANEL_URL) {
      logger.warning('Blocked unauthorized side-panel-connection', senderId, senderUrl);
      port.disconnect();
      return;
    }

    currentPort = port;

    port.onMessage.addListener(async message => {
      try {
        switch (message.type) {
          case 'heartbeat':
            // Acknowledge heartbeat
            port.postMessage({ type: 'heartbeat_ack' });
            break;

          case 'new_task': {
            if (!message.task) return port.postMessage({ type: 'error', error: t('bg_cmd_newTask_noTask') });
            if (!message.tabId) return port.postMessage({ type: 'error', error: t('bg_errors_noTabId') });

            logger.info('new_task', message.tabId, message.task);
            currentExecutor = await setupExecutor(message.taskId, message.task, browserContext);
            subscribeToExecutorEvents(currentExecutor);

            const result = await currentExecutor.execute();
            logger.info('new_task execution result', message.tabId, result);
            break;
          }

          case 'follow_up_task': {
            if (!message.task) return port.postMessage({ type: 'error', error: t('bg_cmd_followUpTask_noTask') });
            if (!message.tabId) return port.postMessage({ type: 'error', error: t('bg_errors_noTabId') });

            logger.info('follow_up_task', message.tabId, message.task);

            // If executor exists, add follow-up task
            if (currentExecutor) {
              currentExecutor.addFollowUpTask(message.task);
              // Re-subscribe to events in case the previous subscription was cleaned up
              subscribeToExecutorEvents(currentExecutor);
              const result = await currentExecutor.execute();
              logger.info('follow_up_task execution result', message.tabId, result);
            } else {
              // executor was cleaned up, can not add follow-up task
              logger.info('follow_up_task: executor was cleaned up, can not add follow-up task');
              return port.postMessage({ type: 'error', error: t('bg_cmd_followUpTask_cleaned') });
            }
            break;
          }

          case 'cancel_task': {
            if (!currentExecutor) return port.postMessage({ type: 'error', error: t('bg_errors_noRunningTask') });
            await currentExecutor.cancel();
            break;
          }

          case 'resume_task': {
            if (!currentExecutor) return port.postMessage({ type: 'error', error: t('bg_cmd_resumeTask_noTask') });
            await currentExecutor.resume();
            return port.postMessage({ type: 'success' });
          }

          case 'pause_task': {
            if (!currentExecutor) return port.postMessage({ type: 'error', error: t('bg_errors_noRunningTask') });
            await currentExecutor.pause();
            return port.postMessage({ type: 'success' });
          }

          case 'screenshot': {
            if (!message.tabId) return port.postMessage({ type: 'error', error: t('bg_errors_noTabId') });
            const page = await browserContext.switchTab(message.tabId);
            const screenshot = await page.takeScreenshot();
            logger.info('screenshot', message.tabId, screenshot);
            return port.postMessage({ type: 'success', screenshot });
          }

          case 'state': {
            try {
              const browserState = await browserContext.getState(true);
              const elementsText = browserState.elementTree.clickableElementsToString(
                DEFAULT_AGENT_OPTIONS.includeAttributes,
              );

              logger.info('state', browserState);
              logger.info('interactive elements', elementsText);
              return port.postMessage({ type: 'success', msg: t('bg_cmd_state_printed') });
            } catch (error) {
              logger.error('Failed to get state:', error);
              return port.postMessage({ type: 'error', error: t('bg_cmd_state_failed') });
            }
          }

          case 'nohighlight': {
            const page = await browserContext.getCurrentPage();
            await page.removeHighlight();
            return port.postMessage({ type: 'success', msg: t('bg_cmd_nohighlight_ok') });
          }

          case 'speech_to_text': {
            try {
              if (!message.audio) {
                return port.postMessage({
                  type: 'speech_to_text_error',
                  error: t('bg_cmd_stt_noAudioData'),
                });
              }

              logger.info('Processing speech-to-text request...');

              // Get all providers for speech-to-text service
              const providers = await llmProviderStore.getAllProviders();

              // Create speech-to-text service with all providers
              const speechToTextService = await SpeechToTextService.create(providers);

              // Extract base64 audio data (remove data URL prefix if present)
              let base64Audio = message.audio;
              if (base64Audio.startsWith('data:')) {
                base64Audio = base64Audio.split(',')[1];
              }

              // Transcribe audio
              const transcribedText = await speechToTextService.transcribeAudio(base64Audio);

              logger.info('Speech-to-text completed successfully');
              return port.postMessage({
                type: 'speech_to_text_result',
                text: transcribedText,
              });
            } catch (error) {
              logger.error('Speech-to-text failed:', error);
              return port.postMessage({
                type: 'speech_to_text_error',
                error: error instanceof Error ? error.message : t('bg_cmd_stt_failed'),
              });
            }
          }

          case 'replay': {
            if (!message.tabId) return port.postMessage({ type: 'error', error: t('bg_errors_noTabId') });
            if (!message.taskId) return port.postMessage({ type: 'error', error: t('bg_errors_noTaskId') });
            if (!message.historySessionId)
              return port.postMessage({ type: 'error', error: t('bg_cmd_replay_noHistory') });
            logger.info('replay', message.tabId, message.taskId, message.historySessionId);

            try {
              // Switch to the specified tab
              await browserContext.switchTab(message.tabId);
              // Setup executor with the new taskId and a dummy task description
              currentExecutor = await setupExecutor(message.taskId, message.task, browserContext);
              subscribeToExecutorEvents(currentExecutor);

              // Run replayHistory with the history session ID
              const result = await currentExecutor.replayHistory(message.historySessionId);
              logger.debug('replay execution result', message.tabId, result);
            } catch (error) {
              logger.error('Replay failed:', error);
              return port.postMessage({
                type: 'error',
                error: error instanceof Error ? error.message : t('bg_cmd_replay_failed'),
              });
            }
            break;
          }

          default:
            return port.postMessage({ type: 'error', error: t('errors_cmd_unknown', [message.type]) });
        }
      } catch (error) {
        console.error('Error handling port message:', error);
        port.postMessage({
          type: 'error',
          error: error instanceof Error ? error.message : t('errors_unknown'),
        });
      }
    });

    port.onDisconnect.addListener(() => {
      // this event is also triggered when the side panel is closed, so we need to cancel the task
      console.log('Side panel disconnected');
      currentPort = null;
      currentExecutor?.cancel();
    });
  }
});

async function setupExecutor(taskId: string, task: string, browserContext: BrowserContext) {
  const providers = await llmProviderStore.getAllProviders();
  // if no providers, need to display the options page
  if (Object.keys(providers).length === 0) {
    throw new Error(t('bg_setup_noApiKeys'));
  }

  // Clean up any legacy validator settings for backward compatibility
  await agentModelStore.cleanupLegacyValidatorSettings();

  const agentModels = await agentModelStore.getAllAgentModels();
  // verify if every provider used in the agent models exists in the providers
  for (const agentModel of Object.values(agentModels)) {
    if (!providers[agentModel.provider]) {
      throw new Error(t('bg_setup_noProvider', [agentModel.provider]));
    }
  }

  const navigatorModel = agentModels[AgentNameEnum.Navigator];
  if (!navigatorModel) {
    throw new Error(t('bg_setup_noNavigatorModel'));
  }
  // Log the provider config being used for the navigator
  const navigatorProviderConfig = providers[navigatorModel.provider];
  const navigatorLLM = createChatModel(navigatorProviderConfig, navigatorModel);

  let plannerLLM: BaseChatModel | null = null;
  const plannerModel = agentModels[AgentNameEnum.Planner];
  if (plannerModel) {
    // Log the provider config being used for the planner
    const plannerProviderConfig = providers[plannerModel.provider];
    plannerLLM = createChatModel(plannerProviderConfig, plannerModel);
  }

  // Apply firewall settings to browser context
  const firewall = await firewallStore.getFirewall();
  if (firewall.enabled) {
    browserContext.updateConfig({
      allowedUrls: firewall.allowList,
      deniedUrls: firewall.denyList,
    });
  } else {
    browserContext.updateConfig({
      allowedUrls: [],
      deniedUrls: [],
    });
  }

  const generalSettings = await generalSettingsStore.getSettings();
  browserContext.updateConfig({
    minimumWaitPageLoadTime: generalSettings.minWaitPageLoad / 1000.0,
    displayHighlights: generalSettings.displayHighlights,
  });

  const executor = new Executor(task, taskId, browserContext, navigatorLLM, {
    plannerLLM: plannerLLM ?? navigatorLLM,
    agentOptions: {
      maxSteps: generalSettings.maxSteps,
      maxFailures: generalSettings.maxFailures,
      maxActionsPerStep: generalSettings.maxActionsPerStep,
      useVision: generalSettings.useVision,
      useVisionForPlanner: true,
      planningInterval: generalSettings.planningInterval,
    },
    generalSettings: generalSettings,
  });

  return executor;
}

// Update subscribeToExecutorEvents to use port
async function subscribeToExecutorEvents(executor: Executor) {
  // Clear previous event listeners to prevent multiple subscriptions
  executor.clearExecutionEvents();

  // Subscribe to new events
  executor.subscribeExecutionEvents(async event => {
    try {
      if (currentPort) {
        currentPort.postMessage(event);
      }
    } catch (error) {
      logger.error('Failed to send message to side panel:', error);
    }

    if (
      event.state === ExecutionState.TASK_OK ||
      event.state === ExecutionState.TASK_FAIL ||
      event.state === ExecutionState.TASK_CANCEL
    ) {
      await currentExecutor?.cleanup();
    }
  });
}
