const env = import.meta.env ?? {}

export const performanceMonitoringEnabled = Boolean(env.DEV || env.MODE === 'demo')

const API_WARNING_MS = 500
const INDEXED_DB_WARNING_MS = 100
const DUPLICATE_WINDOW_MS = 1_000
const MAX_ENTRIES = 200
const encoder = typeof TextEncoder === 'undefined' ? null : new TextEncoder()
const now = () => globalThis.performance?.now?.() ?? Date.now()

const state = {
  startupAt: now(),
  startupDuration: null,
  currentRoute: null,
  routeSequence: 0,
  routeStartedAt: null,
  routeRenderedAt: null,
  activeRequests: 0,
  requestCount: 0,
  slowestApi: null,
  largestResponse: null,
  slowestIndexedDb: null,
  recentRequests: new Map(),
  entries: [],
}

function sanitizeUrl(rawUrl = '', baseUrl = '', params = null) {
  try {
    const url = new URL(rawUrl, baseUrl || globalThis.location?.origin || 'https://performance.local')
    for (const [key, value] of Object.entries(params || {})) {
      if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, '')
    }
    const path = url.pathname.replace(/\/\d+(?=\/|$)/g, '/:id')
    const search = [...new Set(url.searchParams.keys())]
      .sort()
      .map((key) => `${encodeURIComponent(key)}=[redacted]`)
      .join('&')

    return `${path}${search ? `?${search}` : ''}`
  } catch {
    return String(rawUrl).split('?')[0].replace(/\/\d+(?=\/|$)/g, '/:id')
  }
}

function payloadSize(value) {
  if (typeof value === 'string') return encoder ? encoder.encode(value).byteLength : value.length
  if (typeof Blob !== 'undefined' && value instanceof Blob) return value.size
  if (value instanceof ArrayBuffer) return value.byteLength
  if (ArrayBuffer.isView(value)) return value.byteLength

  try {
    const serialized = JSON.stringify(value)
    return serialized && encoder ? encoder.encode(serialized).byteLength : serialized?.length || null
  } catch {
    return null
  }
}

function append(entry) {
  state.entries.push(entry)
  if (state.entries.length > MAX_ENTRIES) state.entries.shift()
}

function safeConsole(level, label, entry) {
  if (!performanceMonitoringEnabled || typeof console === 'undefined') return
  console[level](`[CDM performance] ${label}`, entry)
}

function maybeCompleteRoute() {
  if (!state.currentRoute || state.routeRenderedAt === null || state.activeRequests > 0) return

  const entry = {
    type: 'route',
    route: state.currentRoute,
    durationMs: Math.round((now() - state.routeStartedAt) * 10) / 10,
    renderMs: Math.round((state.routeRenderedAt - state.routeStartedAt) * 10) / 10,
    apiRequestCount: state.requestCount,
    slowestApi: state.slowestApi,
    largestResponse: state.largestResponse,
  }
  append(entry)
  safeConsole('info', 'route settled', entry)
  state.routeRenderedAt = null
}

export const performanceMonitor = {
  markStartupComplete() {
    if (!performanceMonitoringEnabled || state.startupDuration !== null) return
    state.startupDuration = Math.round((now() - state.startupAt) * 10) / 10
    safeConsole('info', 'application mounted', { durationMs: state.startupDuration })
  },

  beginRoute(route) {
    if (!performanceMonitoringEnabled) return
    state.currentRoute = String(route || 'unknown')
    state.routeSequence += 1
    state.routeStartedAt = now()
    state.routeRenderedAt = null
    state.activeRequests = 0
    state.requestCount = 0
    state.slowestApi = null
    state.largestResponse = null
    state.recentRequests.clear()
  },

  markRouteRendered() {
    if (!performanceMonitoringEnabled || !state.currentRoute) return
    state.routeRenderedAt = now()
    globalThis.setTimeout?.(maybeCompleteRoute, 0)
  },

  beginApiRequest(method, rawUrl, baseUrl = '', params = null) {
    if (!performanceMonitoringEnabled) return null
    const startedAt = now()
    const normalizedMethod = String(method || 'GET').toUpperCase()
    const url = sanitizeUrl(rawUrl, baseUrl, params)
    const key = `${normalizedMethod} ${url}`
    const previous = state.recentRequests.get(key)
    state.recentRequests.set(key, startedAt)
    state.activeRequests += 1
    state.requestCount += 1

    if (previous !== undefined && startedAt - previous <= DUPLICATE_WINDOW_MS) {
      const duplicate = { type: 'duplicate-api', method: normalizedMethod, url, withinMs: Math.round(startedAt - previous) }
      append(duplicate)
      safeConsole('warn', 'duplicate API request', duplicate)
    }

    return { startedAt, method: normalizedMethod, url, routeSequence: state.routeSequence }
  },

  endApiRequest(request, status, payload) {
    if (!performanceMonitoringEnabled || !request) return
    const durationMs = Math.round((now() - request.startedAt) * 10) / 10
    const responseBytes = payloadSize(payload)
    const entry = { type: 'api', method: request.method, url: request.url, status: status || 0, durationMs, responseBytes }
    append(entry)
    if (request.routeSequence !== state.routeSequence) return
    state.activeRequests = Math.max(0, state.activeRequests - 1)
    if (!state.slowestApi || durationMs > state.slowestApi.durationMs) state.slowestApi = entry
    if (responseBytes !== null && (!state.largestResponse || responseBytes > state.largestResponse.responseBytes)) {
      state.largestResponse = entry
    }
    safeConsole(durationMs > API_WARNING_MS ? 'warn' : 'info', durationMs > API_WARNING_MS ? 'slow API request' : 'API request', entry)
    maybeCompleteRoute()
  },

  recordIndexedDb(operation, table, durationMs, recordsReturned = null) {
    if (!performanceMonitoringEnabled) return
    const entry = {
      type: 'indexeddb',
      operation: String(operation),
      table: String(table),
      durationMs: Math.round(durationMs * 10) / 10,
      recordsReturned,
    }
    append(entry)
    if (!state.slowestIndexedDb || entry.durationMs > state.slowestIndexedDb.durationMs) state.slowestIndexedDb = entry
    safeConsole(
      entry.durationMs > INDEXED_DB_WARNING_MS ? 'warn' : 'info',
      entry.durationMs > INDEXED_DB_WARNING_MS ? 'slow IndexedDB operation' : 'IndexedDB operation',
      entry,
    )
  },

  snapshot() {
    return {
      enabled: performanceMonitoringEnabled,
      startupDurationMs: state.startupDuration,
      currentRoute: state.currentRoute,
      requestCount: state.requestCount,
      slowestApi: state.slowestApi,
      largestResponse: state.largestResponse,
      slowestIndexedDb: state.slowestIndexedDb,
      entries: [...state.entries],
    }
  },
}

if (performanceMonitoringEnabled && typeof window !== 'undefined') {
  Object.defineProperty(window, '__CDM_PERFORMANCE__', {
    configurable: true,
    value: performanceMonitor,
  })
}
