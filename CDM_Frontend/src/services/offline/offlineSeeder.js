import { offlineDb } from './offlineDb.js'

const date = (offset) => { const d = new Date(); d.setDate(d.getDate() + offset); return d.toISOString().slice(0, 10) }
const now = () => new Date().toISOString()
const SEED_VERSION = 3
let seeding

export async function ensureOfflineSeeded() {
  const seedMarker = await offlineDb.get('meta', 'seed')
  if (seedMarker) {
    if (seedMarker.version !== SEED_VERSION) {
      await offlineDb.put('meta', { ...seedMarker, version: SEED_VERSION })
    }
    return
  }
  if (seeding) return seeding
  seeding = (async () => {
    const users = [
      { id: 1, username: 'student@demo.local', email: 'student@demo.local', password: 'demo1234', role: { id: 1, role_name: 'Student' }, profile: { first_name: 'Maya', last_name: 'Santos' }, is_first_login: false },
      { id: 2, username: 'registrar@demo.local', email: 'registrar@demo.local', password: 'demo1234', role: { id: 2, role_name: 'Registrar Staff' }, profile: { first_name: 'Rafael', last_name: 'Reyes' }, is_first_login: false },
    ]
    const types = ['Birth Certificate','Certificate of Enrollment','Form 137','Registration Form','Good Moral Certificate'].map((document_name, i) => ({ id:i+1, document_name, processing_fee:[100,75,150,50,100][i], processing_days:3, requires_appointment:true, is_active:true }))
    const requests = [
      { id:75001, request_reference:'REQ-075001', student_id:1, document_type_id:2, status:'pending', purpose:'Scholarship application', quantity:1, total_fee:75, created_at:now() },
      { id:75002, request_reference:'REQ-075002', student_id:1, document_type_id:5, status:'pending', purpose:'Employment', quantity:1, total_fee:100, created_at:now() },
      { id:75003, request_reference:'REQ-075003', student_id:1, document_type_id:4, status:'approved', purpose:'Personal copy', quantity:1, total_fee:50, verification_code:'246810', approved_at:now(), created_at:now() },
      { id:75004, request_reference:'REQ-075004', student_id:1, document_type_id:1, status:'completed', purpose:'Records', quantity:1, total_fee:100, completed_at:now(), created_at:now() },
    ]
    const appointments = [
      { id:101, document_request_id:75002, student_id:1, appointment_date:date(2), appointment_time:'10:00:00', status:'pending', created_at:now() },
      { id:102, document_request_id:75003, student_id:1, appointment_date:date(1), appointment_time:'09:00:00', status:'confirmed', created_at:now() },
      { id:103, document_request_id:75004, student_id:1, appointment_date:date(-2), appointment_time:'13:00:00', status:'completed', created_at:now() },
    ]
    for (const row of users) await offlineDb.put('users', row)
    await offlineDb.put('students', { id:1, user_id:1, student_number:'24-DEMO-001', course:{ id:1, course_code:'BSIT', course_name:'BS Information Technology' }, year_level:3, student_status:'active' })
    for (const row of types) await offlineDb.put('document_types', row)
    for (const row of requests) await offlineDb.put('document_requests', row)
    for (const row of appointments) await offlineDb.put('appointments', row)
    await offlineDb.put('physical_records', { id:1, student_id:1, cabinet_code:'CAB-DEMO-A', slot_code:'A-01' })
    await offlineDb.put('grades', { id:1, student_id:1, subject:'Web Development', grade:'1.50', term:'1st Semester' })
    await offlineDb.put('events', { id:1, name:'CDM Foundation Day', event_date:date(7), attended:false })
    await offlineDb.put('settings', { id:'availability', block_saturday:true, block_sunday:true })
    await offlineDb.put('meta', { id:'seed', version:SEED_VERSION })
  })().finally(() => { seeding = null })
  return seeding
}

export async function resetOfflineDemoData() {
  await offlineDb.clearAll()
  localStorage.removeItem('cdm_portal_auth')
  await ensureOfflineSeeded()
}
