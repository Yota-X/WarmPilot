const assert = require('node:assert/strict');
const test = require('node:test');
const source = require('./support/admin-source');

test('log viewer supports all, success, and error modes', () => {
  assert.ok(source.includes("mode === 'errors'"));
  assert.ok(source.includes("mode === 'success'"));
  assert.ok(source.includes('errors_only'));
  assert.ok(source.includes('success_only'));
});

test('job log list refreshes when its tab is opened', () => {
  assert.match(source, /if \(name === 'log'\)\s*\{\s*loadJobLogs\(\)/);
  assert.match(source, /action:\s*'warmpilot_job_logs'/);
  assert.match(source, /function renderJobLogs\(logs\)/);
});
