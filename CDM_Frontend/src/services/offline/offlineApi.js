import { offlineDb } from './offlineDb.js'
import { currentOfflineUser, loginOffline } from './offlineAuth.js'
import { ensureOfflineSeeded } from './offlineSeeder.js'
import { performanceMonitor } from '../performance/performanceMonitor.js'
const wrap = (data, status=200) => ({ data:{ data }, status, headers:{}, config:{} })
const fail = (message, status=422) => { throw Object.assign(new Error(message), { response:{ status, data:{ message } }, config:{} }) }
const today = () => new Date().toISOString().slice(0,10)
const paginator = (data) => ({ data, current_page:1, last_page:1, per_page:data.length || 15, total:data.length })
async function hydrate(studentId = null) {
  const [requests, appointments, types, students] = await Promise.all([
    studentId ? offlineDb.allByIndex('document_requests', 'student_id', studentId) : offlineDb.all('document_requests'),
    studentId ? offlineDb.allByIndex('appointments', 'student_id', studentId) : offlineDb.all('appointments'),
    offlineDb.all('document_types'),
    studentId ? offlineDb.get('students', studentId).then((student) => student ? [student] : []) : offlineDb.all('students'),
  ])
  const [users, records] = studentId && students[0]
    ? await Promise.all([
        offlineDb.get('users', students[0].user_id).then((user) => user ? [user] : []),
        offlineDb.allByIndex('physical_records', 'student_id', studentId),
      ])
    : await Promise.all([offlineDb.all('users'), offlineDb.all('physical_records')])
  const studentOf = (id) => { const s=students.find(x=>x.id===id), u=users.find(x=>x.id===s?.user_id), r=records.find(x=>x.student_id===id); return s && {...s,user:{...u,profile:u.profile},user_profile:u.profile,physical_record_location:r && {cabinet_slot:{slot_code:r.slot_code,cabinet:{cabinet_code:r.cabinet_code}}}} }
  const hydratedRequests = requests.map((r) => ({...r,document_type:types.find(t=>t.id===r.document_type_id),student:studentOf(r.student_id),appointments:appointments.filter(a=>a.document_request_id===r.id)}))
  const hydratedAppointments = appointments.map((a) => ({...a,student:studentOf(a.student_id),document_request:hydratedRequests.find(r=>r.id===a.document_request_id)}))
  return { requests, appointments, types, hydratedRequests, hydratedAppointments }
}
const activeStatuses = ['pending','confirmed']
async function dispatch(method, rawUrl, body={}, config={}) {
  await ensureOfflineSeeded(); const parsed=new URL(rawUrl,'https://demo.local'); const path=parsed.pathname; const params={...Object.fromEntries(parsed.searchParams),...(config.params||{})}
  if (method==='post' && path==='/login') return wrap(await loginOffline(body))
  if (method==='post' && path==='/logout') { localStorage.removeItem('cdm_portal_auth'); return wrap({ message:'Logged out.' }) }
  const user=await currentOfflineUser()
  if (method==='get' && path==='/me') return wrap(user)
  const student=await offlineDb.firstByIndex('students','user_id',user.id)
  const registrar=user.role.role_name==='Registrar Staff'
  const data=await hydrate(registrar ? null : student?.id)
  if (method==='get' && path==='/document-types') return wrap(data.types.filter(t=>t.is_active))
  if (method==='get' && path==='/document-requests') return wrap(data.hydratedRequests.filter(r=>r.student_id===student?.id))
  if (method==='post' && path==='/document-requests') { const type=data.types.find(t=>t.id===Number(body.document_type_id)); if(!type) fail('Document type is required.'); const id=await offlineDb.nextId('document_requests'); const row={id,request_reference:`REQ-${String(id).padStart(6,'0')}`,student_id:student.id,document_type_id:type.id,status:'pending',purpose:body.purpose||body.remarks,quantity:Number(body.quantity||1),total_fee:type.processing_fee*Number(body.quantity||1),created_at:new Date().toISOString()}; await offlineDb.put('document_requests',row); return wrap(row,201) }
  let match=path.match(/^\/document-requests\/(\d+)\/cancel$/)
  if(method==='patch'&&match){ const row=await offlineDb.get('document_requests',Number(match[1])); if(!row||row.student_id!==student?.id) fail('Request not found.',404); if(row.status!=='pending') fail('Only pending requests may be cancelled.'); if(!(body.reason||body.cancellation_reason)?.trim()) fail('Cancellation reason is required.'); row.status='cancelled'; row.cancellation_reason=body.reason||body.cancellation_reason; row.cancelled_at=new Date().toISOString(); await offlineDb.put('document_requests',row); for(const a of data.appointments.filter(a=>a.document_request_id===row.id&&activeStatuses.includes(a.status))){a.status='cancelled';a.cancellation_reason=row.cancellation_reason;await offlineDb.put('appointments',a)} return wrap(row) }
  if(method==='get'&&path==='/appointment-availability'){ const settings=await offlineDb.get('settings','availability'); const blocked=await offlineDb.all('blocked_dates'); return wrap({month:params.month,blocked_dates:blocked,settings}) }
  if(method==='get'&&path==='/appointment-slots'){ const slots=['09:00','10:00','11:00','13:00','14:00','15:00']; const blocked=(await offlineDb.all('blocked_dates')).some(b=>b.blocked_date===params.date); const day=new Date(`${params.date}T00:00:00`).getDay(), settings=await offlineDb.get('settings','availability'); const unavailableReason=blocked?'This date is blocked.':(day===0&&settings.block_sunday)||(day===6&&settings.block_saturday)?'Appointments are unavailable on weekends.':''; return wrap({date:params.date,unavailable_reason:unavailableReason,slots:slots.map(time=>{const count=data.appointments.filter(a=>a.appointment_date===params.date&&a.appointment_time.startsWith(time)&&activeStatuses.includes(a.status)).length; const unavailable=Boolean(unavailableReason)||count>=2; return {time:`${time}:00`,label:time,available:!unavailable,reason:unavailableReason?'Blocked':count>=2?'Full':null,count,capacity:2}})}) }
  match=path.match(/^\/document-requests\/(\d+)\/appointments$/)
  if(method==='post'&&match){ const request=await offlineDb.get('document_requests',Number(match[1])); if(!request||request.student_id!==student?.id) fail('Request not found.',404); if(['cancelled','rejected','released'].includes(request.status)) fail('This request is not eligible for booking.'); if(data.appointments.some(a=>a.document_request_id===request.id&&activeStatuses.includes(a.status))) fail('This request already has an active appointment.'); const id=await offlineDb.nextId('appointments'); const row={id,document_request_id:request.id,student_id:student.id,appointment_date:body.appointment_date||body.date,appointment_time:body.appointment_time||body.time,status:'pending',created_at:new Date().toISOString()}; await offlineDb.put('appointments',row); return wrap(row,201) }
  match=path.match(/^\/appointments\/(\d+)\/cancel$/)
  if(method==='patch'&&match){const a=await offlineDb.get('appointments',Number(match[1]));if(!a||a.student_id!==student?.id)fail('Appointment not found.',404);if(!activeStatuses.includes(a.status))fail('This appointment cannot be cancelled.');const r=await offlineDb.get('document_requests',a.document_request_id);if(r?.status==='released')fail('A finalized appointment cannot be cancelled.');a.status='cancelled';a.cancellation_reason=body.reason||body.cancellation_reason;await offlineDb.put('appointments',a);return wrap(a)}
  if(!registrar) fail('Forbidden.',403)
  if(method==='get'&&path==='/registrar/document-requests'){let rows=data.hydratedRequests;if(params.status)rows=rows.filter(r=>r.status===params.status);if(params.view==='work_queues')return wrap({pending:paginator(rows.filter(r=>r.status==='pending')),processing:paginator(rows.filter(r=>r.status==='processing'))});return wrap(paginator(rows))}
  if(method==='get'&&path==='/registrar/document-requests/history')return wrap({requests:paginator(data.hydratedRequests.filter(r=>['released','rejected','cancelled'].includes(r.status))),appointments:paginator(data.hydratedAppointments.filter(a=>['completed','cancelled','no_show'].includes(a.status)))})
  if(method==='get'&&path==='/registrar/document-request-activity')return wrap(await offlineDb.all('activity'))
  match=path.match(/^\/registrar\/document-requests\/(\d+)$/)
  if(method==='get'&&match)return wrap(data.hydratedRequests.find(r=>r.id===Number(match[1]))||fail('Request not found.',404))
  if(method==='patch'&&match){const r=await offlineDb.get('document_requests',Number(match[1]));if(!r)fail('Request not found.',404);const action=body.action||body.status;if(action==='approve'&&r.status==='pending')r.status='processing';else if(['ready_for_release','mark_ready'].includes(action)&&r.status==='processing')r.status='ready_for_release';else if(action==='return_to_processing'&&r.status==='ready_for_release')r.status='processing';else if(action==='reject'&&['pending','processing'].includes(r.status))r.status='rejected';else if(action==='release'&&r.status==='ready_for_release'){const a=data.appointments.find(a=>a.document_request_id===r.id&&a.status==='confirmed'&&(!body.appointment_id||a.id===Number(body.appointment_id)));if(!a)fail('A confirmed appointment is required.');r.status='released';a.status='completed';await offlineDb.put('appointments',a)}else fail('Invalid workflow transition.');await offlineDb.put('document_requests',r);const fresh=await hydrate();return wrap(fresh.hydratedRequests.find(x=>x.id===r.id))}
  if(method==='get'&&path==='/registrar/appointments')return wrap(paginator(data.hydratedAppointments.filter(a=>!params.status||a.status===params.status)))
  match=path.match(/^\/registrar\/appointments\/(\d+)$/)
  if(method==='patch'&&match){const a=await offlineDb.get('appointments',Number(match[1]));if(!a)fail('Appointment not found.',404);const status=body.status||body.action;if(status==='confirmed'&&a.status==='pending')a.status='confirmed';else if(status==='cancelled'&&activeStatuses.includes(a.status)){a.status='cancelled';a.cancellation_reason=body.reason||body.remarks}else if(status==='completed'){const r=await offlineDb.get('document_requests',a.document_request_id);if(a.status!=='confirmed'||r.status!=='ready_for_release')fail('The request must be ready and appointment confirmed.');a.status='completed';r.status='released';await offlineDb.put('document_requests',r)}else fail('Invalid appointment transition.');await offlineDb.put('appointments',a);const fresh=await hydrate();return wrap(fresh.hydratedAppointments.find(x=>x.id===a.id))}
  if(method==='get'&&path==='/registrar/document-types')return wrap(data.types)
  if(method==='get'&&path==='/registrar/appointment-availability/settings')return wrap(await offlineDb.get('settings','availability'))
  if(method==='patch'&&path==='/registrar/appointment-availability/settings'){const row={...(await offlineDb.get('settings','availability')),...body,id:'availability'};await offlineDb.put('settings',row);return wrap(row)}
  if(method==='get'&&path==='/registrar/appointment-blocked-dates')return wrap(await offlineDb.all('blocked_dates'))
  if(method==='post'&&path==='/registrar/appointment-blocked-dates'){const row={id:await offlineDb.nextId('blocked_dates'),...body};await offlineDb.put('blocked_dates',row);return wrap(row,201)}
  match=path.match(/^\/registrar\/appointment-blocked-dates\/(\d+)$/)
  if(method==='patch'&&match){const row={...(await offlineDb.get('blocked_dates',Number(match[1]))),...body,id:Number(match[1])};await offlineDb.put('blocked_dates',row);return wrap(row)}
  if(method==='delete'&&match){await offlineDb.remove('blocked_dates',Number(match[1]));return wrap({message:'Blocked date removed.'})}
  if(method==='post'&&path==='/registrar/document-types'){const row={id:await offlineDb.nextId('document_types'),...body};await offlineDb.put('document_types',row);return wrap(row,201)}
  match=path.match(/^\/registrar\/document-types\/(\d+)$/)
  if(method==='patch'&&match){const row={...(await offlineDb.get('document_types',Number(match[1]))),...body,id:Number(match[1])};await offlineDb.put('document_types',row);return wrap(row)}
  if(method==='get'&&path==='/registrar/dashboard')return wrap({summary:{pending_requests:data.requests.filter(r=>r.status==='pending').length,processing_requests:data.requests.filter(r=>r.status==='processing').length,todays_appointments:data.appointments.filter(a=>a.appointment_date===today()).length},recent_requests:data.hydratedRequests.slice(-5),todays_appointments:data.hydratedAppointments.filter(a=>a.appointment_date===today())})
  fail(`Offline demo does not support ${method.toUpperCase()} ${path}.`,501)
}
async function monitoredDispatch(method, url, body, config) {
  const request = performanceMonitor.beginApiRequest(method, url, '', config?.params)
  try {
    const response = await dispatch(method, url, body, config)
    performanceMonitor.endApiRequest(request, response.status, response.data)
    return response
  } catch (error) {
    performanceMonitor.endApiRequest(request, error.response?.status, error.response?.data)
    throw error
  }
}

export function createOfflineApiClient(){return {get:(u,c)=>monitoredDispatch('get',u,{},c),delete:(u,c)=>monitoredDispatch('delete',u,{},c),post:(u,b,c)=>monitoredDispatch('post',u,b,c),put:(u,b,c)=>monitoredDispatch('put',u,b,c),patch:(u,b,c)=>monitoredDispatch('patch',u,b,c)}}
