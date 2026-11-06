const DEFAULT_MAX_AGE = 30_000
const MAX_ENTRIES = 48
const entries = new Map()

export function studentRecordsCacheScope(user) {
  return user?.id ?? user?.username ?? null
}

function cacheKey(scope, key) {
  return scope === null || scope === undefined || scope === '' ? null : `${scope}:${key}`
}

export function readStudentRecordsCache(scope, key, maxAge = DEFAULT_MAX_AGE) {
  const scopedKey = cacheKey(scope, key)
  if (!scopedKey) return undefined
  const entry = entries.get(scopedKey)
  if (!entry) return undefined
  if (Date.now() - entry.storedAt > maxAge) {
    entries.delete(scopedKey)
    return undefined
  }
  return entry.value
}

export function writeStudentRecordsCache(scope, key, value) {
  const scopedKey = cacheKey(scope, key)
  if (!scopedKey) return value
  entries.delete(scopedKey)
  entries.set(scopedKey, { storedAt: Date.now(), value })
  while (entries.size > MAX_ENTRIES) entries.delete(entries.keys().next().value)
  return value
}

export function invalidateStudentRecordsCache(scope, prefix = '') {
  const scopedPrefix = cacheKey(scope, prefix)
  if (!scopedPrefix) return
  for (const key of entries.keys()) {
    if (key.startsWith(scopedPrefix)) entries.delete(key)
  }
}

export function clearStudentRecordsCache() {
  entries.clear()
}
