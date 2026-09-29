'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const file = path.resolve(__dirname, '../../view/statics/js/backend/fpc-policy-management.js');
const sandbox = {window: {}, document: {querySelector: () => null}};
vm.runInNewContext(fs.readFileSync(file, 'utf8'), sandbox);
const ui = sandbox.window.WelineCdnFpcPolicyModule;
const plain = value => JSON.parse(JSON.stringify(value));
const baseline = {override_enabled: null, override_ttl: 120, code_enabled: true, code_ttl: 300};
assert.deepEqual(plain(ui.buildChanges(baseline, {inheritEnabled: true, enabled: true, inheritTtl: false, ttl: '120'})), {});
assert.deepEqual(plain(ui.buildChanges(baseline, {inheritEnabled: false, enabled: false, inheritTtl: false, ttl: '120'})), {enabled: false});
assert.deepEqual(plain(ui.buildChanges(baseline, {inheritEnabled: true, enabled: true, inheritTtl: true, ttl: '120'})), {ttl: null});
for (const ttl of ['0', '-3', '2.5', '', '1e2']) {
    assert.throws(() => ui.buildChanges(baseline, {inheritEnabled: true, enabled: true, inheritTtl: false, ttl}), /ttl/);
}
for (const policy of [{...baseline, code_enabled: false}, {...baseline, code_ttl: 0}]) {
    assert.throws(() => ui.buildChanges(policy, {inheritEnabled: false, enabled: true, inheritTtl: true, ttl: '300'}), /code/);
}
const record = {desired_version: 13, origin_version: 12, cloud_version: 12, purge_version: 12, verified_version: 0, cloud_receipt: {success: true}, purge_receipt: null, http_verification: null, status: 'pending'};
const stages = plain(ui.stageFacts(record));
assert.equal(stages.cloud.version, 12);
assert.equal(stages.cloud.pending, true);
assert.equal(stages.purge.proven, false);
assert.equal(stages.verified.proven, false);
assert.equal(plain(ui.stageFacts({...record, http_verification: {success: false}, verified_version: 13})).verified.proven, false);
const httpSample = {coverage: 'sample', status: 'verified', verified: true, samples: [{url: 'https://example.test/sample', verified: true, observations: [{status: 200}, {status: 200}]}]};
assert.equal(plain(ui.stageFacts({...record, http_verification: httpSample, verified_version: 13})).verified.proven, true);
assert.equal(plain(ui.stageFacts({...record, http_verification: {...httpSample, status: 'unmet', verified: false}, verified_version: 13})).verified.proven, false);
const noSample = plain(ui.stageFacts({...record, http_verification: {coverage: 'sample', status: 'no_public_sample', verified: false, samples: []}})).verified;
assert.equal(noSample.proven, false);
assert.equal(noSample.failed, false);
assert.equal(plain(ui.stageFacts({...record, job_key: 'origin', domain_ids: []})).cloud.notApplicable, true);
const confirmed = {...record, origin_version: 13, snapshot_version: 7, snapshot_revision: 'unchanged-revision', snapshot_error: null};
assert.equal(plain(ui.stageFacts(confirmed)).origin.version, 13);
assert.equal(plain(ui.stageFacts(confirmed)).verified.proven, false);
assert.deepEqual(plain(ui.snapshotFacts(confirmed)), {version: 7, revision: 'unchanged-revision', error: null});
assert.deepEqual(plain(ui.snapshotFacts({...confirmed, snapshot_version: null, snapshot_revision: null, snapshot_error: 'unreadable'})), {version: null, revision: null, error: 'unreadable'});
console.log('FPC frontend: nullable edits, TTL validation, receipt evidence and independent snapshot version passed');
