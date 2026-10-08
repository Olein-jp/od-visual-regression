/** JSON Schemaと意味検証を通した値だけを返す。エラーに入力値を含めない。 */
export function validateContract<T = unknown>(name: string, value: unknown, context?: Record<string, unknown>): T;
export function validateStoredVersion(kind: string, version: unknown): true;
export function convertMultipart(parts: readonly (readonly [string, string])[]): Record<string, unknown>;
export const schemas: Readonly<Record<string, unknown>>;
