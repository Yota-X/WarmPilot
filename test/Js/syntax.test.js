const assert = require('node:assert/strict');
const test = require('node:test');
const source = require('./support/admin-source');

test('admin script parses as JavaScript', () => {
  assert.doesNotThrow(() => new Function(source));
});
