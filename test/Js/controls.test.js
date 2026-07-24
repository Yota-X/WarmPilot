const assert = require('node:assert/strict');
const test = require('node:test');
const source = require('./support/admin-source');

test('manual and cron controls expose start and stop flows', () => {
  for (const selector of ['.warmpilot-start', '.warmpilot-stop', '.warmpilot-run-cron', '.warmpilot-stop-cron']) {
    assert.ok(source.includes(selector), `missing handler for ${selector}`);
  }
});
