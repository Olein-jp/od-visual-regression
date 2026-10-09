import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, cp, writeFile, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

async function fixture() {
  const directory = await mkdtemp(join(tmpdir(), 'odvr-gateway-'));
  await cp('infra/wordpress/gateway', join(directory, 'odvr-runner-gateway'), { recursive: true });
  // WordPressを起動したか、受信した固定経路と設定だけをfixtureが返す。
  await writeFile(join(directory, 'wp-blog-header.php'), '<?php echo json_encode(["booted"=>true,"route"=>$_GET["rest_route"],"themes"=>WP_USE_THEMES,"bearer"=>$_SERVER["HTTP_AUTHORIZATION"]??null]);');
  return {
    directory,
    invoke(uri, method = 'GET', options = {}) {
      const values = { REQUEST_URI: uri, REQUEST_METHOD: method, QUERY_STRING: options.query ?? '',
        CONTENT_LENGTH: options.length ?? '0', HTTP_AUTHORIZATION: 'Bearer fixture-only' };
      const encoded = Buffer.from(JSON.stringify(values)).toString('base64');
      const php = `$_SERVER=json_decode(base64_decode('${encoded}'),true); $_GET=[]; register_shutdown_function(function(){echo "\\nSTATUS=".(http_response_code() ?: 200);}); require '${directory}/odvr-runner-gateway/index.php';`;
      const result = spawnSync('php', ['-d', `enable_post_data_reading=${options.raw === false ? 'On' : 'Off'}`, '-r', php], { encoding: 'utf8' });
      assert.equal(result.error, undefined);
      assert.equal(result.status, 0, result.stderr);
      assert.equal(result.stderr, '');
      const [body, status] = result.stdout.split('\nSTATUS=');
      return { body: body ? JSON.parse(body) : null, status: Number(status) };
    },
    close() { return rm(directory, { recursive: true, force: true }); },
  };
}
const uuid = '00000000-0000-4000-8000-000000000000';
const base = `/wp-json/odvr/v1/runner/runs/${uuid}`;

test('専用入口は元のURL/Bearerを保持して既存WordPress RESTへ渡す', async () => {
  const value = await fixture();
  try {
    for (const operation of ['manifest', 'credentials']) {
      const result = value.invoke(`${base}/${operation}`);
      assert.equal(result.status, 200);
      assert.deepEqual(result.body, { booted: true, route: `/odvr/v1/runner/runs/${uuid}/${operation}`,
        themes: false, bearer: 'Bearer fixture-only' });
    }
    const result = value.invoke('/wp-json/odvr/v1/runner/snapshots/1/baseline');
    assert.equal(result.body.route, '/odvr/v1/runner/snapshots/1/baseline');
    for (const operation of ['snapshots', 'progress', 'complete']) assert.equal(value.invoke(`${base}/${operation}`, 'POST').body.booted, true);
  } finally { await value.close(); }
});
test('直接入口・管理API・別method・query・encoded pathはWordPressを起動しない', async () => {
  const value = await fixture();
  try {
    for (const [uri, method, options] of [
      ['/odvr-runner-gateway/index.php', 'GET'], ['/wp-admin/', 'POST'],
      ['/wp-json/odvr/v1/settings', 'POST'], [`${base}/manifest`, 'POST'],
      [`${base}/complete`, 'GET'], [`${base}/manifest`, 'OPTIONS'],
      [`${base}/manifest?token=fixture`, 'GET'], [`${base}/manifest`, 'GET', { query: 'rest_route=/wp/v2/users' }],
      [`${base}/%6danifest`, 'GET'], ['/wp-json/odvr/v1/runner/snapshots/0/baseline', 'GET'],
    ]) assert.deepEqual(value.invoke(uri, method, options), { body: null, status: 404 });
  } finally { await value.close(); }
});
test('PHPの生本文非対応・長さ欠落・42MiB超過はWordPress起動前に拒否する', async () => {
  const value = await fixture();
  try {
    const unsupported = value.invoke(`${base}/manifest`, 'GET', { raw: false });
    assert.equal(unsupported.status, 503);
    assert.equal(unsupported.body.code, 'odvr_raw_upload_unavailable');
    assert.deepEqual(unsupported.body.checks, { per_directory_ini_supported: false,
      user_ini_filename_matches: true, user_ini_present: true, user_ini_readable: true });
    await rm(join(value.directory, 'odvr-runner-gateway/.user.ini'));
    const missing = value.invoke(`${base}/manifest`, 'GET', { raw: false });
    assert.equal(missing.body.checks.user_ini_present, false);
    assert.equal(missing.body.checks.user_ini_readable, false);
    for (const length of ['', '01', '-1', '1e2']) assert.deepEqual(value.invoke(`${base}/snapshots`, 'POST', { length }), { body: null, status: 411 });
    assert.deepEqual(value.invoke(`${base}/snapshots`, 'POST', { length: '44040193' }), { body: null, status: 413 });
    assert.equal(value.invoke(`${base}/snapshots`, 'POST', { length: '44040192' }).body.booted, true);
  } finally { await value.close(); }
});
test('配布設定は専用ディレクトリにだけ置き、FPM非対応のphp_flagを含まない', async () => {
  const ini = await readFile('infra/wordpress/gateway/.user.ini', 'utf8');
  const local = await readFile('infra/wordpress/gateway/.htaccess', 'utf8');
  const root = await readFile('infra/wordpress/root-htaccess.snippet', 'utf8');
  assert.match(ini, /^enable_post_data_reading = Off$/m);
  assert.ok(!/^\s*php_(?:flag|value)\s/m.test(local));
  assert.match(local, /LimitRequestBody 44040192/);
  assert.match(root, /^RewriteRule \^wp-json\/odvr\/v1\/runner\//m);
  assert.ok(!root.includes('enable_post_data_reading'));
});
