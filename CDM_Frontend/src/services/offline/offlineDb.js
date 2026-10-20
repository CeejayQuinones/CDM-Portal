import { performanceMonitor } from '../performance/performanceMonitor.js'

const DATABASE_NAME = 'cdm_portal_offline_demo'
const DATABASE_VERSION = 2
const stores = ['users','students','document_types','document_requests','appointments','grades','events','physical_records','activity','blocked_dates','settings','meta']
const indexes = {
  students: ['user_id'],
  document_requests: ['student_id', 'status'],
  appointments: ['student_id', 'document_request_id', 'appointment_date', 'status'],
  physical_records: ['student_id'],
  blocked_dates: ['blocked_date'],
}
const memory = new Map(stores.map((name) => [name, new Map()]))
let dbPromise
const clone = (value) => value == null ? value : JSON.parse(JSON.stringify(value))
const now = () => globalThis.performance?.now?.() ?? Date.now()

async function measured(table, operationName, action) {
  const startedAt = now()
  const result = await action()
  const recordsReturned = Array.isArray(result) ? result.length : result == null ? 0 : 1
  performanceMonitor.recordIndexedDb(operationName, table, now() - startedAt, recordsReturned)
  return result
}

function openDb() {
  if (!globalThis.indexedDB) return Promise.resolve(null)
  if (!dbPromise) dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION)
    request.onupgradeneeded = () => stores.forEach((name) => {
      const store = request.result.objectStoreNames.contains(name)
        ? request.transaction.objectStore(name)
        : request.result.createObjectStore(name, { keyPath: 'id' })

      for (const indexName of indexes[name] || []) {
        if (!store.indexNames.contains(indexName)) store.createIndex(indexName, indexName, { unique: false })
      }
    })
    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
  return dbPromise
}

async function operation(store, mode, action) {
  const db = await openDb()
  if (!db) return action(memory.get(store), true)
  return new Promise((resolve, reject) => {
    const tx = db.transaction(store, mode)
    const result = action(tx.objectStore(store), false)
    tx.oncomplete = () => resolve(clone(result.value))
    tx.onerror = () => reject(tx.error)
  })
}

export const offlineDb = {
  async all(store) {
    return measured(store, 'getAll', async () => {
      const db = await openDb()
      if (!db) return [...memory.get(store).values()].map(clone)
      return new Promise((resolve, reject) => {
        const request = db.transaction(store).objectStore(store).getAll()
        request.onsuccess = () => resolve(clone(request.result))
        request.onerror = () => reject(request.error)
      })
    })
  },
  async allByIndex(store, index, value) {
    return measured(store, `index:${index}`, async () => {
      const db = await openDb()
      if (!db) return [...memory.get(store).values()].filter((row) => row[index] === value).map(clone)
      return new Promise((resolve, reject) => {
        const request = db.transaction(store).objectStore(store).index(index).getAll(value)
        request.onsuccess = () => resolve(clone(request.result))
        request.onerror = () => reject(request.error)
      })
    })
  },
  async firstByIndex(store, index, value) {
    return measured(store, `index:${index}:first`, async () => {
      const db = await openDb()
      if (!db) return clone([...memory.get(store).values()].find((row) => row[index] === value))
      return new Promise((resolve, reject) => {
        const request = db.transaction(store).objectStore(store).index(index).get(value)
        request.onsuccess = () => resolve(clone(request.result))
        request.onerror = () => reject(request.error)
      })
    })
  },
  async get(store, id) {
    return measured(store, 'get', async () => {
      const db = await openDb()
      if (!db) return clone(memory.get(store).get(id))
      return new Promise((resolve, reject) => {
        const request = db.transaction(store).objectStore(store).get(id)
        request.onsuccess = () => resolve(clone(request.result))
        request.onerror = () => reject(request.error)
      })
    })
  },
  put(store, value) {
    return measured(store, 'put', () => operation(store, 'readwrite', (target, mem) => {
      mem ? target.set(value.id, clone(value)) : target.put(clone(value))
      return { value }
    }))
  },
  remove(store, id) {
    return measured(store, 'remove', () => operation(store, 'readwrite', (target) => {
      target.delete(id)
      return { value: true }
    }))
  },
  async clearAll() {
    for (const store of stores) {
      await measured(store, 'clear', () => operation(store, 'readwrite', (target) => {
        target.clear()
        return { value: true }
      }))
    }
  },
  async nextId(store) {
    return measured(store, 'nextId', async () => {
      const db = await openDb()
      if (!db) return Math.max(0, ...memory.get(store).keys()) + 1
      return new Promise((resolve, reject) => {
        const request = db.transaction(store).objectStore(store).openKeyCursor(null, 'prev')
        request.onsuccess = () => resolve((Number(request.result?.key) || 0) + 1)
        request.onerror = () => reject(request.error)
      })
    })
  },
}
