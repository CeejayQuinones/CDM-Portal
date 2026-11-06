import assert from 'node:assert/strict'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import test from 'node:test'
import { build } from 'esbuild'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createRenderer, nextTick } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import 'vue-router'
import { ROLES } from '../src/config/accessControl.js'

const frontend = fileURLToPath(new URL('../', import.meta.url))
test('Enrollment academic UI supports subjects, scheduling, COR and protected navigation', async t => {
  const temporary = await mkdtemp(path.join(frontend, 'test/.enrollment-'))
  const priorDocument = globalThis.document, priorDocumentClass=globalThis.Document, priorFrame = globalThis.requestAnimationFrame, priorWindow=globalThis.window, priorCreateObjectUrl=globalThis.URL.createObjectURL, priorRevokeObjectUrl=globalThis.URL.revokeObjectURL
  let prints=0,pdfPrints=0,downloads=0,blobIndex=0;globalThis.window={print(){prints++}}
  globalThis.URL.createObjectURL=()=>`blob:ovrf-${++blobIndex}`;globalThis.URL.revokeObjectURL=()=>{}
  globalThis.Document=class {};globalThis.document = Object.assign(new globalThis.Document(),{title:'',activeElement:null,body:{style:{overflow:''},appendChild(){}},createElement:()=>({click(){downloads++},remove(){}})}); globalThis.requestAnimationFrame = fn => fn()
  try {
    await build({
      stdin:{contents:`export {default as router} from './src/router/index.js';
        export {default as EnrollmentView} from './src/modules/enrollment/EnrollmentAcademicView.vue';
        export {useNavigationStore} from './src/stores/navigation.js';
        export {auth} from './src/stores/authStore'; export {apiState} from './src/services/apiClient';`, resolveDir:frontend},
      outfile:path.join(temporary,'harness.mjs'),bundle:true,platform:'node',format:'esm',packages:'external',
      plugins:[{name:'enrollment-test',setup(builder) {
        builder.onLoad({filter:/\.css$/},()=>({contents:'',loader:'js'}))
        builder.onResolve({filter:/^vue-router$/,namespace:'file'},()=>({path:'router',namespace:'memory'}))
        builder.onLoad({filter:/.*/,namespace:'memory'},()=>({contents:"export * from 'vue-router'; import {createMemoryHistory} from 'vue-router'; export const createWebHashHistory = () => createMemoryHistory('/');",resolveDir:frontend}))
        builder.onResolve({filter:/stores\/authStore$/},()=>({path:'auth',namespace:'auth'}))
        builder.onLoad({filter:/.*/,namespace:'auth'},()=>({contents:"import {reactive} from 'vue'; export const auth=reactive({currentRole:'Student',currentUser:{id:1},isAuthenticated:true,async initialize(){}}); export const useAuthStore=()=>auth;",resolveDir:frontend}))
        builder.onResolve({filter:/config\/clientPlatform$/},()=>({path:'client-platform',namespace:'platform'}))
        builder.onLoad({filter:/.*/,namespace:'platform'},()=>({contents:"export const clientPlatform='web'; export const isRoleAllowedOnClient=()=>true;"}))
        builder.onResolve({filter:/services\/performance\/performanceMonitor$/},()=>({path:'performance',namespace:'performance'}))
        builder.onLoad({filter:/.*/,namespace:'performance'},()=>({contents:"export const performanceMonitor={beginRoute(){},markRouteRendered(){}};"}))
        builder.onResolve({filter:/services\/apiClient$/},()=>({path:'api',namespace:'api'}))
        builder.onLoad({filter:/.*/,namespace:'api'},()=>({contents:"export const apiState={calls:[],handler:async()=>({})}; const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)}; export const apiClient={get:(u,p)=>call('get',u,p),post:(u,p)=>call('post',u,p),put:(u,p)=>call('put',u,p),delete:(u,p)=>call('delete',u,p),patch:(u,p)=>call('patch',u,p)};"}))
        builder.onLoad({filter:/\.vue$/},async({path:filename})=>{
          if(!filename.includes('/modules/enrollment/')&&!filename.includes('/components/website/')&&!filename.includes('/design-system/')) return {contents:'export default {render(){return null}}'}
          const {descriptor}=parse(await readFile(filename,'utf8'),{filename})
          return {contents:compileScript(descriptor,{id:filename,inlineTemplate:true}).content,resolveDir:path.dirname(filename)}
        })
      }}],
    })
    const {router,auth,EnrollmentView,useNavigationStore,apiState}=await import(pathToFileURL(path.join(temporary,'harness.mjs')))
    setActivePinia(createPinia())
    const navigation=useNavigationStore()
    await t.test('Student and staff navigation have the correct boundaries',async()=>{
      for(const role of Object.values(ROLES)){
        auth.currentRole=role
        const item=navigation.menuItemsForRole(role).find(i=>i.name==='enrollment')
        assert.equal(Boolean(item),[ROLES.STUDENT,ROLES.ADMIN,ROLES.REGISTRAR_STAFF,ROLES.PROFESSOR].includes(role))
        for(const target of ['/enrollment','/enrollment/status','/enrollment/applications','/enrollment/periods']){
          await router.push('/'); await router.push(target)
          const allowed=role===ROLES.PROFESSOR && target==='/enrollment' || role===ROLES.STUDENT && ['/enrollment','/enrollment/status'].includes(target) || target!=='/enrollment/status' && [ROLES.ADMIN,ROLES.REGISTRAR_STAFF].includes(role)
          assert.equal(router.currentRoute.value.name==='unauthorized',!allowed,role+' '+target)
          if(allowed) assert.equal(router.currentRoute.value.meta.requiresAuth,true)
        }
      }
      auth.isAuthenticated=false
      await router.push('/enrollment/status')
      assert.equal(router.currentRoute.value.name,'login')
      auth.isAuthenticated=true
    })
    const renderer=createRenderer({
      createElement:tag=>({tag,tagName:tag.toUpperCase(),children:[],props:{},listeners:{},...(tag==='iframe'?{contentWindow:{focus(){},print(){pdfPrints++}}}:{}),addEventListener(name,fn){this.listeners[name]=fn},removeEventListener(){},setAttribute(){},removeAttribute(){},getRootNode(){return globalThis.document},get options(){return this.children},get value(){return this.props.value},set value(v){this.props.value=v},getClientRects(){return [1]},querySelectorAll(){return []},focus(){globalThis.document.activeElement=this}}),createText:text=>({text}),createComment:()=>({text:''}),
      setText:(node,text)=>{node.text=text},setElementText:(node,text)=>{node.text=text;node.children=[]},
      patchProp:(node,key,prev,next)=>{node.props[key]=next},
      insert(node,parent,anchor){if(node.parent) node.parent.children.splice(node.parent.children.indexOf(node),1); const i=anchor?parent.children.indexOf(anchor):-1;parent.children.splice(i<0?parent.children.length:i,0,node);node.parent=parent},
      remove(node){node.parent?.children.splice(node.parent.children.indexOf(node),1)},parentNode:node=>node.parent,nextSibling:node=>node.parent?.children[node.parent.children.indexOf(node)+1] || null,
    })
    const textOf=node=>[node.text||'',...(node.children||[]).map(textOf)].join(' ')
    const find=(node,tag)=>node.tag===tag?node:(node.children||[]).map(child=>find(child,tag)).find(Boolean)
    const settle=async()=>{await new Promise(resolve=>setTimeout(resolve,0));await nextTick()}
    const all=(node,tag)=>[...(node.tag===tag?[node]:[]),...(node.children||[]).flatMap(c=>all(c,tag))]
    const button=(root,text)=>all(root,'button').find(n=>textOf(n).trim()===text)
    const click=async(root,text)=>{const b=button(root,text);assert.ok(b,'Missing button '+text);assert.ok(!b.props.disabled,'Disabled '+text);await b.props.onClick();await settle()}
    const response=data=>({data:{success:true,data}}),pagination=data=>({data,current_page:1,last_page:1,total:data.length})
    const period={id:1,academic_year:{id:1,school_year:'2026–2027'},semester:{id:1,semester_name:'First'}}
    const subject={id:10,subject_code:'CS101',subject_name:'Programming',units:3,lecture_hours:3,laboratory_hours:0,completed:false,prerequisite_met:true}
    const section={id:1,section_name:'CS-1A',course:{course_name:'Computing'},...period,course_id:1,academic_year_id:1,semester_id:1,year_level:1,capacity:30,enrolled_count:1,reserved_count:0,status:'open',version:1}
    const professor={id:1,employee_number:'P-001',user:{profile:{first_name:'Test',last_name:'Professor'}}}
    const replacementProfessor={id:2,employee_number:'P-002',user:{profile:{first_name:'Replacement',last_name:'Professor'}}}
    const schedule={id:1,section_id:1,subject_id:10,professor_id:1,subject,professor,day:'Monday',start_time:'09:00:00',end_time:'10:00:00',room:'Lab 1',version:1}
    const unscheduledSubject={...subject,id:11,subject_code:'IT101',subject_name:'Computer Programming 1'}
    const unscheduled={id:2,section_id:1,subject_id:11,professor_id:1,subject:unscheduledSubject,professor,day:null,start_time:null,end_time:null,room:null,version:1}
    const academicRecord={id:42,student_number:'26-ACADEMIC',name:'Academic Student',course:'Computing',year_level:1,academic_year:'2026–2027',semester:'First',section:'CS-1A',enrollment_date:'2026-09-27',subjects:[{...subject,schedule_status:'scheduled',schedule},{...unscheduledSubject,schedule_status:'pending',schedule:null}],total_units:6}
    const recordRow={...period,id:42,status:'enrolled',section,student:{student_number:'26-ACADEMIC',user_profile:{first_name:'Academic',last_name:'Student'},course:section.course},application:{classification:'regular'}}
    let classification='regular',selected=[],scheduleFailure=null,validationErrors=null,availabilityFailure=null,availabilityResponse=null,availabilityHandler=null,ovrfFailure=null
    const application=()=>({id:1,period,status:'approved',classification,version:1,student:recordRow.student})
    const handler=async(method,url,payload)=>{
      if(url==='/enrollment/academic/notifications')return response(pagination([]))
      if(url==='/enrollment/applications/mine')return response(pagination([application()]))
      if(url==='/enrollment/academic/applications/1')return response({application:application(),load:{candidates:[subject],standard_subject_ids:[10],selected_subject_ids:selected},sections:pagination([section])})
      if(url==='/enrollment/academic/applications/1/subjects'){selected=payload.subject_ids;return response(application())}
      if(url==='/enrollment/academic/options')return response({courses:[{id:1,course_name:'Computing',years:4}],academic_years:[period.academic_year],semesters:[period.semester],professors:pagination([professor,replacementProfessor])})
      if(url==='/enrollment/academic/sections')return response(method==='post'?section:pagination([section]))
      if(url==='/enrollment/academic/schedules'){
        if(method==='post'){if(validationErrors)throw {response:{status:422,data:{errors:validationErrors}}};if(scheduleFailure)throw {response:scheduleFailure};return response(schedule)}
        return response({section,schedules:pagination([schedule,unscheduled]),subjects:[subject,unscheduledSubject],days:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']})
      }
      if(url==='/enrollment/academic/schedules/availability'){
        if(availabilityFailure)throw {response:availabilityFailure}
        if(availabilityHandler)return availabilityHandler(payload)
        return response(availabilityResponse || {available:true,meetings:payload.meetings.map(meeting=>({...meeting,available:true,conflicts:[]}))})
      }
      if(url==='/enrollment/academic/schedules/1'&&method==='put')return response({...schedule,...payload,professor:replacementProfessor,version:2})
      if(url==='/enrollment/academic/records')return response(pagination([recordRow]))
      if(url==='/enrollment/academic/records/42')return response(academicRecord)
      if(url==='/enrollment/academic/records/42/ovrf'){
        if(ovrfFailure)throw {response:ovrfFailure}
        return {data:new Blob(['%PDF-test'],{type:'application/pdf'}),headers:{'content-disposition':'attachment; filename="OVRF-26-ACADEMIC.pdf"'}}
      }
      if(url==='/enrollment/academic/professor')return response(pagination([{...section,schedules:[schedule]}]))
      if(url==='/enrollment/academic/professor/sections/1')return response(pagination([recordRow]))
      throw new Error('Unexpected '+method+' '+url)
    }
    const mount=()=>{const root={children:[]},app=renderer.createApp(EnrollmentView);app.use(router);app.mount(root);return {root,app}}
    const field=(root,title,tag)=>all(root,'label').find(n=>textOf(n).trim().startsWith(title))?.children.find(n=>n.tag===tag)
    const model=async(node,value)=>{assert.ok(node,'Missing input');node.props['onUpdate:modelValue'](value);await settle()}
    const timeGroups=(root,label)=>all(root,'div').filter(node=>node.props.role==='group'&&node.props['aria-label']===label)
    const setSpinner=async(group,hour,minute,period='AM')=>{
      assert.ok(group,'Missing time spinner')
      const inputs=all(group,'input')
      await model(inputs.find(node=>String(node.props['aria-label']).endsWith(' hour')),String(hour))
      await inputs.find(node=>String(node.props['aria-label']).endsWith(' hour')).props.onInput()
      await model(inputs.find(node=>String(node.props['aria-label']).endsWith(' minute')),String(minute).padStart(2,'0'))
      await inputs.find(node=>String(node.props['aria-label']).endsWith(' minute')).props.onInput()
      const periodButton=all(group,'button').find(node=>node.props.class==='en-time-period-value')
      if(textOf(periodButton).trim()!==period){await periodButton.props.onClick();await settle()}
    }
    const waitAvailability=async()=>{await new Promise(resolve=>setTimeout(resolve,430));await settle()}
    await t.test('new routes restrict staff, Student and Professor operations',async()=>{
      for(const role of Object.values(ROLES)){
        auth.currentRole=role
        for(const name of ['sections','subject-management','scheduling','records','subjects','schedule','cor','teaching']){
          const allowed=['sections','subject-management','scheduling','records'].includes(name)?[ROLES.ADMIN,ROLES.REGISTRAR_STAFF].includes(role):name==='teaching'?role===ROLES.PROFESSOR:role===ROLES.STUDENT
          await router.push('/');await router.push('/enrollment/'+name);assert.equal(router.currentRoute.value.name==='unauthorized',!allowed)
        }
      }
      assert.deepEqual(navigation.menuItemsForRole(ROLES.STUDENT).find(n=>n.name==='enrollment').children.map(n=>n.label),['Status','Courses Offered','Enrollment Guide','Subjects','My Schedule','COR'])
      assert.deepEqual(navigation.menuItemsForRole(ROLES.ADMIN).find(n=>n.name==='enrollment').children,navigation.menuItemsForRole(ROLES.REGISTRAR_STAFF).find(n=>n.name==='enrollment').children)
    })
    await t.test('Regular load is read-only; Irregular selection saves controlled subject IDs',async()=>{
      for(const kind of ['regular','irregular']){
        classification=kind;selected=[];auth.currentRole=ROLES.STUDENT;await router.push('/enrollment/subjects');apiState.handler=handler;apiState.calls=[]
        const {root,app}=mount();try{await settle();await click(root,'View subject load');assert.match(textOf(root),/Programming/);const checkbox=all(root,'input').find(n=>n.props.type==='checkbox');assert.equal(Boolean(checkbox.props.disabled),kind==='regular');if(kind==='irregular'){await model(checkbox,[10]);await click(root,'Save selected subjects');assert.deepEqual(apiState.calls.find(c=>c.method==='post').payload.subject_ids,[10])}assert.equal(button(root,'Finalize academic enrollment'),undefined)}finally{app.unmount()}
      }
    })
    await t.test('staff can create sections and inspect enrollment records with filters',async()=>{
      auth.currentRole=ROLES.ADMIN;apiState.handler=handler;await router.push('/enrollment/sections');const {root,app}=mount()
      try{await settle();assert.match(textOf(root),/CS-1A/);await click(root,'Create section');const form=all(root,'form').at(-1);await model(field(form,'Section name','input'),'CS-1B');await model(field(form,'Course','select'),1);await model(field(form,'Academic year','select'),1);await model(field(form,'Semester','select'),1);await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(root),/Saved successfully/)}finally{app.unmount()}
      await router.push('/enrollment/records');const records=mount();try{await settle();assert.match(textOf(records.root),/26-ACADEMIC/);assert.ok(button(records.root,'Preview OVRF'));assert.ok(button(records.root,'Download OVRF'));assert.ok(button(records.root,'Print OVRF'));await model(field(records.root,'Sort','select'),'name');await all(records.root,'form')[0].props.onSubmit({preventDefault(){}});await settle();assert.equal(apiState.calls.filter(c=>c.url==='/enrollment/academic/records').at(-1).payload.params.sort,'name');await click(records.root,'View COR');assert.equal(apiState.calls.at(-1).url,'/enrollment/academic/records/42');const dialogs=all(records.root,'div').filter(n=>n.props.role==='dialog');assert.equal(dialogs.length,1);assert.equal(dialogs[0].props['aria-modal'],'true');assert.match(textOf(dialogs[0]),/Certificate of Registration/);await click(records.root,'Close');assert.equal(all(records.root,'div').filter(n=>n.props.role==='dialog').length,0);assert.doesNotMatch(textOf(records.root),/Certificate of Registration/)}finally{records.app.unmount()}
    })
    await t.test('scheduling supports views, copy and safe server conflict feedback',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{
        await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();assert.match(textOf(root),/Programming/);await click(root,'Timetable');assert.match(textOf(root),/Monday/);await click(root,'List');assert.match(textOf(root),/Room \/ Professor/);await click(root,'Copy');const form=all(root,'form').at(-1);assert.match(textOf(form),/copy destination/);await waitAvailability()
        for(const kind of ['professor','section','room']){
          scheduleFailure={status:409,data:{message:`Monday, 09:00–10:00 conflicts with an existing schedule for this ${kind} (CS-1A / CS101).`}}
          await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(form),new RegExp(`existing schedule for this ${kind}`))
          assert.equal(all(root,'div').filter(node=>String(node.props.class||'').includes('en-modern-feedback')).some(node=>new RegExp(`existing schedule for this ${kind}`).test(textOf(node))),false)
          await model(field(form,'Room','input'),`Room ${kind}`);await waitAvailability()
        }
        scheduleFailure={status:503,data:{message:'Scheduling service is temporarily unavailable.'}}
        await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(form),/temporarily unavailable/)
        assert.equal(all(root,'div').filter(node=>String(node.props.class||'').includes('en-modern-feedback')).some(node=>/temporarily unavailable/.test(textOf(node))),false)
      }finally{scheduleFailure=null;app.unmount()}
    })
    await t.test('unscheduled assignments use Add Schedule and POST while saved schedules use Edit and PUT',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;apiState.calls=[];await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{
        await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle()
        assert.ok(button(root,'Add Schedule'));assert.equal(all(root,'button').filter(n=>textOf(n).trim()==='Edit').length,1)
        await click(root,'Timetable');assert.match(textOf(root),/Schedule pending/);assert.ok(button(root,'Add Schedule'))
        await click(root,'List');assert.ok(button(root,'Add Schedule'));assert.equal(all(root,'button').filter(n=>textOf(n).trim()==='Edit').length,1)
        await click(root,'Cards');await click(root,'Add Schedule')
        const addForm=all(root,'form').at(-1)
        assert.equal(field(addForm,'Section / copy destination','select').props.disabled,true)
        assert.equal(field(addForm,'Subject','select').props.disabled,true)
        assert.equal(button(addForm,'Save schedule').props.disabled,true)
        assert.match(textOf(addForm),/Choose both a start and end time/)
        await setSpinner(timeGroups(addForm,'Start time')[0],10,0);await setSpinner(timeGroups(addForm,'End time')[0],9,45);await model(field(addForm,'Room','input'),'Room 101')
        assert.match(textOf(addForm),/End time must be later/);assert.equal(button(addForm,'Save schedule').props.disabled,true)
        await setSpinner(timeGroups(addForm,'End time')[0],11,0)
        await click(addForm,'Tue')
        await setSpinner(timeGroups(addForm,'Start time')[1],1,0,'PM');await setSpinner(timeGroups(addForm,'End time')[1],2,30,'PM')
        assert.match(textOf(addForm),/Total weekly class hours/);assert.match(textOf(addForm),/2 hr 30 min/);await waitAvailability()
        const preview=apiState.calls.filter(c=>c.method==='post'&&c.url==='/enrollment/academic/schedules/availability').at(-1)
        assert.ok(preview);assert.equal(Object.hasOwn(preview.payload,'schedule_id'),false);assert.equal(Object.hasOwn(preview.payload,'assignment_id'),false)
        assert.equal(button(addForm,'Save schedule').props.disabled,false)
        await addForm.props.onSubmit({preventDefault(){}});await settle()
        const post=apiState.calls.find(c=>c.method==='post'&&c.url==='/enrollment/academic/schedules')
        assert.ok(post);assert.equal(post.payload.assignment_id,2);assert.equal(post.payload.id,undefined);assert.equal(post.payload.section_id,1);assert.equal(post.payload.subject_id,11);assert.equal(post.payload.professor_id,1)
        assert.deepEqual(post.payload.meetings,[
          {day:'Monday',start_time:'10:00',end_time:'11:00'},
          {day:'Tuesday',start_time:'13:00',end_time:'14:30'},
        ])
        assert.match(textOf(root),/Saved successfully/)
        await click(root,'Edit');const editForm=all(root,'form').at(-1);await waitAvailability();await editForm.props.onSubmit({preventDefault(){}});await settle()
        assert.ok(apiState.calls.find(c=>c.method==='put'&&c.url==='/enrollment/academic/schedules/1'))
        assert.equal(apiState.calls.some(c=>c.url.includes('/undefined')),false)
      }finally{app.unmount()}
    })
    await t.test('live schedule availability is debounced, structured, failure-safe, and ignores stale responses',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;apiState.calls=[];await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{
        await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();await click(root,'Add Schedule')
        const form=all(root,'form').at(-1)
        await model(field(form,'Subject','select'),10);await model(field(form,'Professor','select'),1)
        await setSpinner(timeGroups(form,'Start time')[0],9,0);await setSpinner(timeGroups(form,'End time')[0],10,0)
        availabilityResponse={available:false,meetings:[{day:'Monday',start_time:'09:00',end_time:'10:00',available:false,conflicts:[{type:'professor',message:'Professor is already assigned during this time.',existing:{schedule_id:9,start_time:'09:00',end_time:'10:00',room:'LAB 1',section:{name:'CS-1A'},subject:{code:'CS101'},professor:{name:'Test Professor'}}}]}]}
        apiState.calls=[]
        await model(field(form,'Room','input'),'Lab');await model(field(form,'Room','input'),'Lab 1');await model(field(form,'Room','input'),'Lab 100')
        assert.match(textOf(form),/Checking availability/);await waitAvailability()
        assert.equal(apiState.calls.filter(call=>call.url==='/enrollment/academic/schedules/availability').length,1)
        assert.match(textOf(form),/Conflict/);assert.match(textOf(form),/Professor conflict: CS101 · CS-1A · Test Professor/)
        assert.equal(button(form,'Save schedule').props.disabled,true)

        availabilityResponse={available:true,meetings:[{day:'Monday',start_time:'09:00',end_time:'10:00',available:true,conflicts:[]}]}
        await model(field(form,'Room','input'),'Lab 101');await waitAvailability()
        assert.match(textOf(form),/Available/);assert.equal(button(form,'Save schedule').props.disabled,false)
        const checksBeforeSpinner=apiState.calls.filter(call=>call.url==='/enrollment/academic/schedules/availability').length
        await setSpinner(timeGroups(form,'End time')[0],10,15);assert.match(textOf(form),/Checking availability/);await waitAvailability()
        assert.equal(apiState.calls.filter(call=>call.url==='/enrollment/academic/schedules/availability').length,checksBeforeSpinner+1)

        availabilityFailure={status:503,data:{message:'Availability service unavailable.'}}
        await model(field(form,'Room','input'),'Lab 102');await waitAvailability()
        assert.match(textOf(form),/Unable to check availability/);assert.equal(button(form,'Save schedule').props.disabled,true)
        availabilityFailure=null

        const pending=[];availabilityHandler=payload=>new Promise(resolve=>pending.push({payload,resolve}))
        await model(field(form,'Room','input'),'Old room');await waitAvailability();assert.equal(pending.length,1)
        await model(field(form,'Room','input'),'Current room');await waitAvailability();assert.equal(pending.length,2)
        pending[1].resolve(response({available:true,meetings:[{day:'Monday',start_time:'09:00',end_time:'10:00',available:true,conflicts:[]}]}));await settle()
        pending[0].resolve(response({available:false,meetings:[{day:'Monday',start_time:'09:00',end_time:'10:00',available:false,conflicts:[{type:'room',existing:{room:'OLD'}}]}]}));await settle()
        assert.match(textOf(form),/Available/);assert.doesNotMatch(textOf(form),/Room conflict/)
      }finally{availabilityResponse=null;availabilityFailure=null;availabilityHandler=null;app.unmount()}
    })
    await t.test('schedule validation errors remain inside the affected meeting row',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;apiState.calls=[];await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{
        await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();await click(root,'Add Schedule')
        const form=all(root,'form').at(-1)
        await model(field(form,'Subject','select'),10);await model(field(form,'Professor','select'),1);await model(field(form,'Room','input'),'Room 250')
        await setSpinner(timeGroups(form,'Start time')[0],1,0,'PM');await setSpinner(timeGroups(form,'End time')[0],2,0,'PM');await waitAvailability()
        validationErrors={professor_id:['The selected Professor is unavailable.'],'meetings.0.end_time':['The selected end time is invalid.']}
        await form.props.onSubmit({preventDefault(){}});await settle()
        assert.match(textOf(form),/selected end time is invalid/)
        assert.match(textOf(form),/selected Professor is unavailable/)
        assert.equal(all(root,'div').filter(node=>String(node.props.class||'').includes('en-modern-feedback')).some(node=>/selected end time is invalid/.test(textOf(node))),false)
        await setSpinner(timeGroups(form,'End time')[0],2,15,'PM');await waitAvailability()
        assert.doesNotMatch(textOf(form),/selected end time is invalid/)
        await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(form),/selected end time is invalid/)
        await all(form,'button').find(node=>node.props['aria-label']==='Close editor').props.onClick();await settle();await click(root,'Add schedule')
        assert.doesNotMatch(textOf(all(root,'form').at(-1)),/selected end time is invalid/)
      }finally{validationErrors=null;app.unmount()}
    })
    await t.test('Professor reassignment requires explicit confirmation',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;apiState.calls=[];await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();await click(root,'Edit');const form=all(root,'form').at(-1);await model(field(form,'Professor','select'),2);await waitAvailability();await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(root),/Reassign this teaching assignment/);assert.equal(apiState.calls.some(c=>c.method==='put'),false);await click(root,'Confirm reassignment');assert.equal(apiState.calls.find(c=>c.method==='put').payload.professor_id,2)}finally{app.unmount()}
    })
    await t.test('Scheduling explains missing curriculum subjects without inventing options',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;await router.push('/enrollment/scheduling')
      apiState.handler=async(method,url,payload)=>{
        const result=await handler(method,url,payload)
        if(url==='/enrollment/academic/schedules')result.data.data.subjects=[]
        return result
      }
      const {root,app}=mount()
      try{await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();assert.match(textOf(root),/No active subjects are configured for/);assert.match(textOf(root),/Open Subject Management/)}finally{app.unmount();apiState.handler=handler}
    })
    await t.test('Student sees real schedule and printable COR; Professor sees assigned roster',async()=>{
      auth.currentRole=ROLES.STUDENT;apiState.handler=handler;await router.push('/enrollment/schedule');const mounted=mount()
      try{await settle();assert.match(textOf(mounted.root),/Section CS-1A/);await click(mounted.root,'View schedule');assert.equal(apiState.calls.at(-1).url,'/enrollment/academic/records/42');assert.match(textOf(mounted.root),/Programming/);assert.match(textOf(mounted.root),/09:00–10:00/);assert.match(textOf(mounted.root),/Lab 1/);assert.match(textOf(mounted.root),/Computer Programming 1/);assert.match(textOf(mounted.root),/Schedule pending/);assert.ok(button(mounted.root,'Close record'));await click(mounted.root,'Close record');assert.doesNotMatch(textOf(mounted.root),/Viewing 2026/)}finally{mounted.app.unmount()}
      await router.push('/enrollment/cor');const cor=mount();try{await settle();await click(cor.root,'View COR');assert.match(textOf(cor.root),/26-ACADEMIC/);assert.match(textOf(cor.root),/Total units/);assert.match(textOf(cor.root),/To be announced/);assert.match(textOf(cor.root),/Financial assessment and official signatures are not included/);await click(cor.root,'Print COR');assert.equal(prints,1);await click(cor.root,'Preview OVRF');assert.equal(apiState.calls.at(-1).url,'/enrollment/academic/records/42/ovrf');assert.equal(apiState.calls.at(-1).payload.params.disposition,'inline');assert.match(textOf(cor.root),/Physical verification required/);const closePreview=all(cor.root,'button').find(node=>node.props['aria-label']==='Close OVRF preview');await closePreview.props.onClick();await settle();await click(cor.root,'Download OVRF');assert.equal(apiState.calls.at(-1).payload.params.disposition,'attachment');assert.equal(downloads,1);await click(cor.root,'Print OVRF');assert.equal(pdfPrints,1)}finally{cor.app.unmount()}
      auth.currentRole=ROLES.PROFESSOR;await router.push('/enrollment/teaching');const prof=mount();try{await settle();await click(prof.root,'View enrolled Students');assert.match(textOf(prof.root),/Academic Student/);assert.equal(button(prof.root,'Create section'),undefined)}finally{prof.app.unmount()}
    })
    await t.test('OVRF preview reports retrieval failures, retries, and fits one bounded PDF region',async()=>{
      auth.currentRole=ROLES.STUDENT;apiState.handler=handler;await router.push('/');await router.push('/enrollment/cor');const cor=mount()
      try{
        await settle();await click(cor.root,'View COR');ovrfFailure={status:404,data:{message:'Enrollment record not found.'}};await click(cor.root,'Preview OVRF')
        assert.match(textOf(cor.root),/Enrollment record not found/);assert.ok(button(cor.root,'Try again'));assert.equal(all(cor.root,'iframe').length,0)
        ovrfFailure=null;await click(cor.root,'Try again');const preview=all(cor.root,'iframe')[0];assert.ok(preview);assert.match(preview.props.src,/zoom=page-width/)
        const source=await readFile(path.join(frontend,'src/modules/enrollment/EnrollmentOvrfActions.vue'),'utf8')
        assert.match(source,/grid-template-rows: auto minmax\(0, 1fr\) auto/);assert.match(source,/\.en-ovrf-document \{[^}]*overflow: hidden/);assert.doesNotMatch(source,/min-height: 560px/)
      }finally{ovrfFailure=null;cor.app.unmount()}
    })
    await t.test('COR renders every authorized meeting for a multi-day subject schedule',async()=>{
      auth.currentRole=ROLES.STUDENT;await router.push('/');await router.push('/enrollment/cor')
      apiState.handler=async(method,url,payload)=>{
        if(url==='/enrollment/academic/records/42')return response({...academicRecord,subjects:[{...subject,schedule:{...schedule,meetings:[
          {day:'Monday',start_time:'09:00:00',end_time:'10:00:00',room:'Lab 1'},
          {day:'Wednesday',start_time:'13:00:00',end_time:'15:00:00',room:'Lab 2'},
        ]}}]})
        return handler(method,url,payload)
      }
      const cor=mount()
      try{await settle();await click(cor.root,'View COR');assert.match(textOf(cor.root),/Monday 09:00–10:00 · Lab 1/);assert.match(textOf(cor.root),/Wednesday 13:00–15:00 · Lab 2/)}finally{cor.app.unmount();apiState.handler=handler}
    })
    await t.test('COR handles incomplete optional schedules without hiding invalid academic records',async()=>{
      auth.currentRole=ROLES.STUDENT;await router.push('/enrollment/cor')
      apiState.handler=async(method,url,payload)=>{
        if(url==='/enrollment/academic/records/42')return response({...academicRecord,subjects:[{...subject,schedule:{...schedule,start_time:null,end_time:null,room:null}}]})
        return handler(method,url,payload)
      }
      const incompleteSchedule=mount()
      try{await settle();await click(incompleteSchedule.root,'View COR');assert.match(textOf(incompleteSchedule.root),/To be announced/);assert.ok(button(incompleteSchedule.root,'Print COR'))}finally{incompleteSchedule.app.unmount()}

      apiState.handler=async(method,url,payload)=>{
        if(url==='/enrollment/academic/records/42')return response({...academicRecord,subjects:null})
        return handler(method,url,payload)
      }
      await router.push('/');await router.push('/enrollment/cor')
      const invalidRecord=mount()
      try{await settle();await click(invalidRecord.root,'View COR');assert.match(textOf(invalidRecord.root),/subject load is unavailable/);assert.equal(button(invalidRecord.root,'Print COR'),undefined)}finally{invalidRecord.app.unmount();apiState.handler=handler}
    })
  }finally{globalThis.document=priorDocument;globalThis.Document=priorDocumentClass;globalThis.requestAnimationFrame=priorFrame;globalThis.window=priorWindow;globalThis.URL.createObjectURL=priorCreateObjectUrl;globalThis.URL.revokeObjectURL=priorRevokeObjectUrl;await rm(temporary,{recursive:true,force:true})}
})
