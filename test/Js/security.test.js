const assert = require('node:assert/strict');
const test = require('node:test');
const source = require('./support/admin-source');

test('all mutating AJAX requests include the shared nonce payload', () => {
  assert.match(source, /function payload\(extra = \{\}\)/);
  assert.match(source, /nonce:\s*WarmPilotAdmin\.nonce/);
  assert.match(source, /serializeForm\(\$form,\s*action\)/);
});

test('dynamic table values are escaped before HTML insertion', () => {
  assert.match(source, /function esc\(value\)/);
  assert.match(source, /esc\(row\.url\)/);
  assert.match(source, /esc\(row\.cache_headers \|\| row\.cf_cache_status \|\| ''\)/);
});
