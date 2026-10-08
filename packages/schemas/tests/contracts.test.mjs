import test from 'node:test';
import assert from 'node:assert/strict';
import { schemas, validateContract, convertMultipart, validateStoredVersion } from '../validate.mjs';
import { fixtures, writeSharedFixtures } from './fixtures.mjs';
writeSharedFixtures();
for (const fixture of fixtures) test(fixture.name, () => {
  const validate = () => validateContract(fixture.schema, fixture.multipart ? convertMultipart(fixture.value) : fixture.value, fixture.context);
  if (fixture.valid) assert.doesNotThrow(validate);
  else assert.throws(validate);
});
test('すべての製品契約に正常・拒否fixtureがある', () => {
  for (const name of Object.keys(schemas).filter(x => !['device-profile','prototype-manifest'].includes(x))) {
    assert.ok(fixtures.some(x => x.schema === name && x.valid));
    assert.ok(fixtures.some(x => x.schema === name && !x.valid));
  }
});
test('未知契約と非有限数を拒否する', () => {
  assert.throws(() => validateContract('unknown', {}), /odvr_unknown_contract/);
  const value = structuredClone(fixtures.find(x => x.schema === 'run-manifest' && x.valid).value);
  value.settings.pixel_threshold = NaN;
  assert.throws(() => validateContract('run-manifest', value));
  value.settings.pixel_threshold = Infinity;
  assert.throws(() => validateContract('run-manifest', value));
});

test('保存JSON Versionを独立に検証する', () => {
  for (const kind of ['settings','environment','metadata']) {
    assert.equal(validateStoredVersion(kind, 1), true);
    for (const value of [2, 0, '1', null, true]) assert.throws(() => validateStoredVersion(kind, value), /odvr_unsupported_storage_version/);
  }
  assert.throws(() => validateStoredVersion('software', 1));
});
