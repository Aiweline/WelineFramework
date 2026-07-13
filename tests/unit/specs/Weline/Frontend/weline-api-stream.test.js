import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const projectRoot = resolve(__dirname, '../../../../..');
const apiScript = readFileSync(
  resolve(projectRoot, 'app/code/Weline/Frontend/view/statics/js/weline-api.js'),
  'utf-8'
);

class FakeEventSource extends window.EventTarget {
  static instances = [];

  constructor(url, options = {}) {
    super();
    this.url = url;
    this.withCredentials = options.withCredentials === true;
    this.readyState = 0;
    this.closed = false;
    FakeEventSource.instances.push(this);
  }

  close() {
    this.closed = true;
    this.readyState = 2;
  }

  open() {
    this.readyState = 1;
    this.dispatchEvent(new window.Event('open'));
  }

  fail() {
    this.dispatchEvent(new window.Event('error'));
  }

  emit(type, data, lastEventId = '') {
    this.dispatchEvent(new window.MessageEvent(type, {
      data,
      lastEventId,
      origin: window.location.origin,
    }));
  }
}

describe('Weline.Api StreamHandle', () => {
  let originalEventSource;

  beforeEach(() => {
    vi.useFakeTimers();
    FakeEventSource.instances = [];
    originalEventSource = window.EventSource;
    window.EventSource = FakeEventSource;
    window.sessionStorage.clear();
    delete window.Weline;
    delete window.WelineApiModule;
    eval(apiScript);
  });

  afterEach(() => {
    vi.useRealTimers();
    window.EventSource = originalEventSource;
    window.sessionStorage.clear();
    delete window.Weline;
    delete window.WelineApiModule;
  });

  const createClient = () => {
    let ticket = 0;
    return {
      send: vi.fn().mockImplementation(() => Promise.resolve({
        url: `/api/framework/stream?ticket=${++ticket}`,
      })),
      call: vi.fn().mockResolvedValue({ ok: true }),
    };
  };

  it('requests a fresh ticket and carries the durable cursor after reconnect', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-1',
      lease_id: 'lease-1',
    }, {
      lease: false,
      retryMinMs: 1,
      retryMaxMs: 1,
    });
    const events = [];

    await handle.start();
    handle.addEventListener('progress', event => events.push(event.data));
    const first = FakeEventSource.instances[0];
    first.emit('progress', '{"step":1}', '7');
    first.fail();

    await vi.advanceTimersByTimeAsync(1);

    expect(events).toEqual(['{"step":1}']);
    expect(client.send).toHaveBeenCalledTimes(2);
    expect(client.send.mock.calls[1][0].params.last_event_id).toBe('7');
    expect(FakeEventSource.instances).toHaveLength(2);
    handle.close();
  });

  it('keeps a recoverable handle when the initial ticket request fails', async () => {
    const client = createClient();
    client.send
      .mockRejectedValueOnce(new Error('temporary network failure'))
      .mockResolvedValueOnce({ url: '/api/framework/stream?ticket=recovered' });
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-initial-failure',
      lease_id: 'lease-initial-failure',
    }, {
      lease: false,
      retryMinMs: 1,
      retryMaxMs: 1,
    });

    await expect(handle.start()).resolves.toBe(handle);
    await vi.advanceTimersByTimeAsync(1);

    expect(client.send).toHaveBeenCalledTimes(2);
    expect(FakeEventSource.instances).toHaveLength(1);
    handle.close();
  });

  it('preserves a zero durable cursor when requesting a stream ticket', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-zero-cursor',
      lease_id: 'lease-zero-cursor',
      last_event_id: 0,
    }, { lease: false });

    await handle.start();

    expect(client.send.mock.calls[0][0].params.last_event_id).toBe('0');
    handle.close();
  });

  it('deduplicates replayed durable events and persists the cursor for a fresh handle', async () => {
    const client = createClient();
    const firstHandle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-2',
      lease_id: 'lease-2',
    }, { lease: false });
    const received = [];

    await firstHandle.start();
    firstHandle.addEventListener('progress', event => received.push(event.data));
    const firstSource = FakeEventSource.instances[0];
    firstSource.emit('progress', '{"step":2}', '12');
    firstSource.emit('progress', '{"step":2}', '12');
    firstSource.emit('progress', '{"step":1}', '11');
    firstHandle.close();

    const secondHandle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-2',
    }, { lease: false });
    await secondHandle.start();

    expect(received).toEqual(['{"step":2}']);
    expect(client.send.mock.calls[1][0].params).toMatchObject({
      task_id: 'task-2',
      lease_id: 'lease-2',
      last_event_id: '12',
    });
    secondHandle.close();
  });

  it('close detaches only and never calls the cancel operation', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-3',
      lease_id: 'lease-3',
    }, { lease: false });

    await handle.start();
    const source = FakeEventSource.instances[0];
    handle.close();
    source.fail();
    await vi.advanceTimersByTimeAsync(100);

    expect(source.closed).toBe(true);
    expect(client.call).not.toHaveBeenCalled();
    expect(client.send).toHaveBeenCalledTimes(1);
  });

  it('does not add runtime lease traffic to a legacy stream channel', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'legacy.events', {
      task_id: 'legacy-task',
      lease_id: 'legacy-lease',
    });

    await handle.start();
    await vi.advanceTimersByTimeAsync(60000);

    expect(client.call).not.toHaveBeenCalled();
    handle.close();
  });

  it('cancel uses the runtime resource with a stable intent id without relying on close', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-4',
      lease_id: 'lease-4',
    }, { lease: false });

    await handle.start();
    await handle.cancel('user requested');
    await handle.cancel('ignored second reason');

    expect(client.call).toHaveBeenCalledTimes(2);
    const first = client.call.mock.calls[0];
    const second = client.call.mock.calls[1];
    expect(first[0]).toBe('runtime_task');
    expect(first[1]).toBe('cancel');
    expect(first[2]).toMatchObject({ task_id: 'task-4', reason: 'user requested' });
    expect(second[2].intent_id).toBe(first[2].intent_id);
    expect(handle.readyState).not.toBe(2);
    handle.close();
  });

  it('stops reconnecting and lease renewal after a terminal event even without a consumer listener', async () => {
    const client = createClient();
    const handle = new window.Weline.Api.StreamHandle(client, 'runtime_task.events', {
      task_id: 'task-5',
      lease_id: 'lease-5',
    }, {
      retryMinMs: 1,
      retryMaxMs: 1,
      leaseIntervalMs: 1000,
    });

    await handle.start();
    const source = FakeEventSource.instances[0];
    source.emit('completed', '{"status":"completed"}', '20');
    source.fail();
    await vi.advanceTimersByTimeAsync(2000);

    expect(handle.readyState).toBe(2);
    expect(source.closed).toBe(true);
    expect(client.send).toHaveBeenCalledTimes(1);
  });
});
