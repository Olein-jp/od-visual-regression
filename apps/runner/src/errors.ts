import { createHash } from 'node:crypto';
import type { SnapshotErrorCode } from '@odvr/shared';
const messages: Record<SnapshotErrorCode, string> = {
  HTTP_ERROR: 'HTTP応答に失敗しました', NAVIGATION_TIMEOUT: 'ページ遷移がタイムアウトしました',
  DNS_ERROR: 'DNS解決に失敗しました', TLS_ERROR: 'TLS接続に失敗しました', PAGE_CRASH: 'ページがクラッシュしました',
  IMAGE_LOAD_FAILED: '画像の読み込みに失敗しました', SCREENSHOT_FAILED: '画面の撮影に失敗しました',
  ORIGIN_BLOCKED: '許可されないOriginです', IP_BLOCKED: '非公開IPへのアクセスは禁止です',
  REDIRECT_BLOCKED: 'リダイレクトは禁止です', URL_BLOCKED: '許可されないURL形式です',
  NETWORK_ERROR: '通信に失敗しました', RESOURCE_BLOCKED: '取得できないリソースがあります',
  NETWORK_LIMIT_EXCEEDED: '通信量または要求数の上限を超えました', NETWORK_TIMEOUT: '通信の期限を超えました',
  CONNECTION_MISMATCH: '検査済み接続先と実際の接続先が一致しません', REQUEST_ABORTED: '通信が中止されました',
  FILE_SAVE_FAILED: '画像ファイルの保存に失敗しました', BROWSER_ERROR: 'Browserの処理に失敗しました',
  CONTEXT_CLOSE_FAILED: 'Browser Contextの終了に失敗しました', SNAPSHOT_FAILED: 'Snapshotの処理に失敗しました',
};
export class SnapshotError extends Error {
  constructor(public readonly code: SnapshotErrorCode, public readonly http_status?: number) { super(messages[code]); }
}
export function classifyError(error: unknown, fallback: SnapshotErrorCode): SnapshotError {
  if (error instanceof SnapshotError) return error;
  // 元のメッセージは分類だけに使い、URLや資格情報を診断結果へコピーしない。
  const message = error instanceof Error ? error.message.split('\n')[0].replace(/https?:\/\/\S+/g, '') : '';
  if (/ERR_NAME_NOT_RESOLVED|ENOTFOUND|EAI_AGAIN|ENODATA|getaddrinfo/i.test(message)) return new SnapshotError('DNS_ERROR');
  if (/ERR_CERT_|ERR_SSL_|ERR_TLS_|CERT_HAS_EXPIRED|UNABLE_TO_VERIFY_LEAF_SIGNATURE|self[- ]signed certificate|certificate (?:has expired|verify failed)|TLS handshake|SSL routines/i.test(message)) return new SnapshotError('TLS_ERROR');
  if (/page crashed|Page crash/i.test(message)) return new SnapshotError('PAGE_CRASH');
  if (fallback === 'NAVIGATION_TIMEOUT' && !/timeout|timed out/i.test(message)) return new SnapshotError('NETWORK_ERROR');
  return new SnapshotError(fallback);
}
export function safeUrl(value: string): string {
  try {
    const url = new URL(value);
    url.username = ''; url.password = ''; url.search = ''; url.hash = '';
    return url.href;
  } catch { return '[無効なURL]'; }
}

// URLの一致判定には値を保存せずダイジェストを使う。
export function urlFingerprint(value: string): string {
  return createHash('sha256').update(value).digest('hex');
}
