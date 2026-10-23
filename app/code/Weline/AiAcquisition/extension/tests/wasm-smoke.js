'use strict';

/**
 * WASM 冒烟测试：校验魔数、导出与三组固定打分结果。
 * 运行：node app/code/Weline/AiAcquisition/extension/tests/wasm-smoke.js
 * 通过时最后一行输出 WASM_OK。
 */

const fs = require('node:fs');
const path = require('node:path');

const WASM_PATH = path.join(__dirname, '..', 'wasm', 'agent_core.wasm');

function fail(message) {
  console.error(`WASM_FAIL ${message}`);
  process.exit(1);
}

function assertEqual(actual, expected, label) {
  if (actual !== expected) {
    fail(`${label} 期望 ${expected}，实际 ${actual}`);
  }
}

(async () => {
  if (!fs.existsSync(WASM_PATH)) {
    fail(`找不到 ${WASM_PATH}，请先运行 build.sh`);
  }

  const bytes = fs.readFileSync(WASM_PATH);
  if (bytes.length < 8) {
    fail('WASM 文件过短');
  }
  if (bytes[0] !== 0x00 || bytes[1] !== 0x61 || bytes[2] !== 0x73 || bytes[3] !== 0x6d) {
    fail('WASM 魔数不正确');
  }
  const version = bytes.readUInt32LE(4);
  if (version !== 1) {
    fail(`WASM 版本不正确：${version}`);
  }

  const { instance } = await WebAssembly.instantiate(bytes, {});
  const exports = instance.exports;

  for (const name of ['score_lead', 'should_continue', 'next_query_slot']) {
    if (typeof exports[name] !== 'function') {
      fail(`缺少导出 ${name}`);
    }
  }

  assertEqual(exports.score_lead(2, 2, 2, 2, 2), 10, 'score_lead(2,2,2,2,2)');
  assertEqual(exports.score_lead(0, 0, 0, 0, 0), 0, 'score_lead(0,0,0,0,0)');
  assertEqual(exports.score_lead(3, -1, 2, 1, 0), 5, 'score_lead(3,-1,2,1,0) 钳制');

  assertEqual(exports.should_continue(8, 0, 8), 0, 'should_continue(8,0,8)');
  assertEqual(exports.should_continue(0, 0, 8), 1, 'should_continue(0,0,8)');
  assertEqual(exports.should_continue(0, 8, 8), 0, 'should_continue(0,8,8)');

  assertEqual(exports.next_query_slot(3, 3), -1, 'next_query_slot(3,3)');
  assertEqual(exports.next_query_slot(0, 3), 0, 'next_query_slot(0,3)');
  assertEqual(exports.next_query_slot(2, 3), 2, 'next_query_slot(2,3)');
  assertEqual(exports.next_query_slot(-1, 3), -1, 'next_query_slot(-1,3)');

  console.log('WASM_OK');
})().catch((error) => {
  fail(error && error.message ? error.message : String(error));
});
