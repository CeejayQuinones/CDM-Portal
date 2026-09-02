const stores = ['users','students','document_types','document_requests','appointments','grades','events','physical_records','activity','blocked_dates','settings','meta']
const memory = new Map(stores.map((name) => [name, new Map()]))
let dbPromise
const clone = (value) => value == null ? value : JSON.parse(JSON.stringify(value))

function openDb() {
  if (!globalThis.indexedDB) return Promise.resolve(null)
  if (!dbPromise) dbPromise = new Promise((resolve, reject) => {
    const request = indexedDB.open('cdm_portal_offline_demo', 1)
    request.onupgradeneeded = () => stores.forEach((name) => {
      if (!request.result.objectStoreNames.contains(name)) request.result.createObjectStore(name, { keyPath: 'id' })
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
    const db = await openDb()
    if (!db) return [...memory.get(store).values()].map(clone)
    return new Promise((resolve, reject) => {
      const request = db.transaction(store).objectStore(store).getAll()
      request.onsuccess = () => resolve(clone(request.result))
      request.onerror = () => reject(request.error)
    })
  },
  async get(store, id) {
    const db = await openDb()
    if (!db) return clone(memory.get(store).get(id))
    return new Promise((resolve, reject) => {
      const request = db.transaction(store).objectStore(store).get(id)
      request.onsuccess = () => resolve(clone(request.result))
      request.onerror = () => reject(request.error)
    })
  },
  put(store, value) { return operation(store, 'readwrite', (target, mem) => { mem ? target.set(value.id, clone(value)) : target.put(clone(value)); return { value } }) },
  remove(store, id) { return operation(store, 'readwrite', (target, mem) => { mem ? target.delete(id) : target.delete(id); return { value: true } }) },
  async clearAll() { for (const store of stores) await operation(store, 'readwrite', (target) => { target.clear(); return { value: true } }) },
  async nextId(store) { const rows = await this.all(store); return Math.max(0, ...rows.map((row) => Number(row.id) || 0)) + 1 },
}

