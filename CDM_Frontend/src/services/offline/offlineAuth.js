import { offlineDb } from './offlineDb.js'
import { ensureOfflineSeeded } from './offlineSeeder.js'
const KEY = 'cdm_portal_auth'
const clean = ({ password, ...user }) => user
export async function loginOffline({ username, email, password }) {
  await ensureOfflineSeeded()
  const user = (await offlineDb.all('users')).find((u) => [u.username,u.email].includes(username || email) && u.password === password)
  if (!user) throw Object.assign(new Error('Invalid demo credentials.'), { response:{ status:422, data:{ message:'Invalid demo credentials.' } } })
  const safe = clean(user), token = `offline-demo-${user.id}`
  localStorage.setItem(KEY, JSON.stringify({ token, currentUser:safe, currentRole:safe.role.role_name, firstLogin:false }))
  return { token, user:safe }
}
export async function currentOfflineUser() {
  await ensureOfflineSeeded()
  let auth; try { auth = JSON.parse(localStorage.getItem(KEY)) } catch { auth = null }
  const id = Number(auth?.token?.replace('offline-demo-', ''))
  const user = await offlineDb.get('users', id)
  if (!user) throw Object.assign(new Error('Unauthenticated.'), { response:{ status:401, data:{ message:'Unauthenticated.' } } })
  return clean(user)
}
