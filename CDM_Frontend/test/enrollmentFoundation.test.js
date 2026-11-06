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
test('Enrollment workflow preserves role boundaries, identity and controlled UI transitions', async t => {
  const temporary = await mkdtemp(path.join(frontend, 'test/.enrollment-'))
  const priorDocument = globalThis.document, priorFrame = globalThis.requestAnimationFrame
  globalThis.document = {title:''}; globalThis.requestAnimationFrame = fn => fn()
  try {
    await build({
      stdin:{contents:`export {default as router} from './src/router/index.js';
        export {default as EnrollmentView} from './src/modules/enrollment/EnrollmentView.vue';
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
        builder.onLoad({filter:/.*/,namespace:'api'},()=>({contents:"export const apiState={calls:[],handler:async()=>({})}; const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)}; export const apiClient={get:(u,p)=>call('get',u,p),post:(u,p)=>call('post',u,p),put:(u,p)=>call('put',u,p)};"}))
        builder.onLoad({filter:/\.vue$/},async({path:filename})=>{
          if(!filename.includes('/modules/enrollment/')&&!filename.includes('/components/website/')) return {contents:'export default {render(){return null}}'}
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
    await t.test('only an authoritative admitted Guest sees the Enrollment handoff',async()=>{
      auth.currentRole=ROLES.GUEST;auth.currentUser={id:1,admission:{completed:false,admitted_guest:false}}
      assert.equal(navigation.menuItemsForRole(auth.currentRole,auth.currentUser.admission).find(i=>i.name==='enrollment'),undefined)
      await router.push('/');await router.push('/enrollment/status');assert.equal(router.currentRoute.value.name,'unauthorized')
      auth.currentUser.admission={completed:true,admitted_guest:true}
      const items=navigation.menuItemsForRole(auth.currentRole,auth.currentUser.admission)
      const enrollment=items.find(i=>i.name==='enrollment')
      assert.deepEqual(enrollment.children.map(i=>i.label),['Status'])
      assert.equal(items.find(i=>i.name==='admission-menu'),undefined)
      await router.push('/enrollment/status');assert.equal(router.currentRoute.value.name,'enrollment-status')
      await router.push('/admission/exam');assert.equal(router.currentRoute.value.name,'admission')
      auth.currentRole=ROLES.STUDENT;auth.currentUser={id:1}
    })
    const renderer=createRenderer({
      createElement:tag=>({tag,tagName:tag.toUpperCase(),children:[],props:{},listeners:{},addEventListener(name,fn){this.listeners[name]=fn},removeEventListener(){},setAttribute(){},removeAttribute(){},get options(){return this.children},get value(){return this.props.value},set value(v){this.props.value=v}}),createText:text=>({text}),createComment:()=>({text:''}),
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
    const period={id:1,academic_year_id:1,semester_id:1,academic_year:'2026–2027',semester:'First',state:'open',enabled:false,opens_at:'2026-01-01T00:00:00Z',closes_at:'2027-01-01T00:00:00Z'}
    const state={academic_ready:true,eligible:true,reason:null,applications_enabled:true,period,enrollments:[],student:{id:7,name:'Converted Student',student_number:'26-001',year_level:1,course:{course_name:'Computing'},curriculum:{curriculum_name:'Computing 2026'}}}
    const response=data=>({data:{success:true,data}})
    const pagination=data=>({data,current_page:1,last_page:1,total:data.length})
    let record=null
    const handler=async(method,url,payload)=>{
      if(url==='/enrollment/academic/notifications')return response(pagination([]))
      if(url==='/enrollment/academic/applications/1')return response({application:{...record},load:{candidates:[],standard_subject_ids:[],selected_subject_ids:[]},sections:pagination([])})
      if(url==='/enrollment/status')return response(state)
      if(url==='/enrollment/applications/mine')return response(pagination(record?[record]:[]))
      if(url==='/enrollment/applications'&&method==='post'){record={id:1,period_id:1,period,classification:payload.classification,status:'draft',version:1};return response({...record})}
      if(url==='/enrollment/applications/1')return response({application:{...record},requirements:[]})
      if(url.endsWith('/save')){record={...record,classification:payload.classification,version:record.version+1};return response({...record})}
      if(url.endsWith('/submit')){record={...record,status:'submitted',version:record.version+1};return response({...record})}
      if(['/review','/approve','/reject'].some(s=>url.endsWith(s))){record={...record,status:url.endsWith('/review')?'under_review':url.endsWith('/approve')?'approved':'rejected',review_notes:payload.notes,version:record.version+1};return response({...record})}
      if(url==='/enrollment/applications')return response({applications:pagination(record?[record]:[]),courses:[],periods:[period]})
      if(url==='/enrollment/periods/1'&&method==='put')return response({...period,...payload})
      if(url==='/enrollment/periods')return response({periods:pagination([period]),academic_years:[{id:1,school_year:'2026–2027',status:'active',start_date:'2026-06-01',end_date:'2027-05-31'}],semesters:[{id:1,semester_name:'First'}],document_types:[]})
      throw new Error('Unexpected endpoint '+url)
    }
    const mount=()=>{const root={children:[]},app=renderer.createApp(EnrollmentView);app.use(router);app.mount(root);return {root,app}}
    await t.test('wizard saves classification, confirms submission and renders status immediately',async()=>{
      auth.currentRole=ROLES.STUDENT;await router.push('/enrollment/status');apiState.handler=handler;apiState.calls=[];record=null
      const {root,app}=mount()
      try{
        await settle();assert.match(textOf(root),/26-001/);await click(root,'Start New Enrollment Application');assert.match(textOf(root),/Personal details/)
        await click(root,'Continue');const irregular=all(root,'input').find(n=>n.props.value==='irregular');irregular.props['onUpdate:modelValue']('irregular');await settle()
        await click(root,'Save draft');assert.equal(record.classification,'irregular');await click(root,'Continue');assert.match(textOf(root),/Customized subject load/);assert.match(textOf(root),/Final review/)
        await click(root,'Submit application');assert.ok(all(root,'div').some(n=>n.props.role==='dialog'));assert.equal(apiState.calls.filter(c=>c.url.endsWith('/submit')).length,0)
        await click(root,'Go back');await click(root,'Submit application');await click(root,'Confirm submit');assert.match(textOf(root),/awaiting staff review/);assert.equal(apiState.calls.filter(c=>c.url.endsWith('/submit')).length,1)
      }finally{app.unmount()}
    })
    await t.test('saved private image renders again after reopening Enrollment',async()=>{
      auth.currentRole=ROLES.STUDENT;auth.currentUser={id:1};await router.push('/enrollment/status');apiState.calls=[]
      record={id:1,period_id:1,period,classification:'regular',status:'draft',version:2,personal_details:{}}
      const privatePreview='/enrollment/applications/1/documents/9?preview=1'
      const documentRequirement={id:9,name:'Birth certificate',attached:true,reusable:false,preview_kind:'image',mime_type:'image/png',preview_url:privatePreview}
      const previewHandler=async(method,url,payload)=>{
        if(url==='/enrollment/academic/notifications')return response(pagination([]))
        if(url==='/enrollment/status')return response({...state,current_application:{id:1,period_id:1,status:'draft'}})
        if(url==='/enrollment/applications/mine')return response(pagination([record]))
        if(url==='/enrollment/applications/1')return response({application:record,requirements:[documentRequirement]})
        if(url===privatePreview){assert.equal(payload.responseType,'blob');return {data:new Blob(['saved-image'],{type:'image/png'})}}
        throw new Error('Unexpected endpoint '+url)
      }
      const priorCreateObjectURL=globalThis.URL.createObjectURL, priorRevokeObjectURL=globalThis.URL.revokeObjectURL
      let blobSequence=0, revoked=[]
      globalThis.URL.createObjectURL=blob=>{assert.equal(blob.type,'image/png');return `blob:enrollment-preview-${++blobSequence}`}
      globalThis.URL.revokeObjectURL=url=>revoked.push(url)
      apiState.handler=previewHandler
      try{
        for(let visit=1;visit<=2;visit++){
          const {root,app}=mount()
          try{
            await settle();await click(root,'View current application');await settle()
            const image=all(root,'img').find(node=>node.props.alt==='Preview of Birth certificate')
            assert.equal(image?.props.src,`blob:enrollment-preview-${visit}`)
            const view=all(root,'button').find(node=>node.props['aria-label']==='View full Birth certificate image')
            assert.ok(view);await view.props.onClick({stopPropagation(){}});await settle()
            assert.ok(all(root,'img').some(node=>node.props.alt==='Full preview of Birth certificate'&&node.props.src===image.props.src))
          }finally{app.unmount()}
        }
        assert.equal(apiState.calls.filter(call=>call.url===privatePreview).length,2)
        assert.deepEqual(revoked,['blob:enrollment-preview-1','blob:enrollment-preview-2'])
      }finally{
        globalThis.URL.createObjectURL=priorCreateObjectURL;globalThis.URL.revokeObjectURL=priorRevokeObjectURL;apiState.handler=handler;record=null
      }
    })
    await t.test('admitted Guest can apply without a Student number',async()=>{
      auth.currentRole=ROLES.GUEST;auth.currentUser={id:2,admission:{completed:true,admitted_guest:true}};record=null
      const guestState={academic_ready:false,eligible:true,reason:'student_identity_pending',applications_enabled:true,period,enrollments:[],student:null,admitted_guest:true,admission:{name:'Admitted Guest',applicant_number:'APP-2026-00001',course:{course_name:'Computing'},curriculum:{curriculum_name:'Computing 2026'}}}
      apiState.handler=(method,url,payload)=>url==='/enrollment/status'?response(guestState):handler(method,url,payload)
      await router.push('/enrollment/status')
      const {root,app}=mount()
      try{
        await settle();assert.match(textOf(root),/Student identity Not created yet/);assert.doesNotMatch(textOf(root),/Student number.*26-/)
        await click(root,'Start New Enrollment Application');assert.match(textOf(root),/First year after finalization/)
        await click(root,'Continue');await click(root,'Continue');assert.match(textOf(root),/Admitted Guest · APP-2026-00001/)
      }finally{app.unmount()}
      auth.currentRole=ROLES.STUDENT;auth.currentUser={id:1}
    })
    await t.test('upcoming and closed periods block creation and show the correct state',async()=>{
      record=null
      for(const status of ['upcoming','closed']){
        apiState.handler=async(m,u,p)=>u==='/enrollment/status'?response({...state,eligible:false,reason:'enrollment_period_closed',period:{...period,state:status}}):handler(m,u,p)
        const {root,app}=mount();try{await settle();assert.match(textOf(root),new RegExp(status,'i'));assert.match(textOf(root),/when an authorized enrollment period opens/);assert.equal(button(root,'Start New Enrollment Application'),undefined)}finally{app.unmount()}
      }
    })
    await t.test('an admitted Guest can cancel a draft, retain history, and reapply without receiving a Student identity',async()=>{
      auth.currentRole=ROLES.GUEST;auth.currentUser={id:2,admission:{completed:true,admitted_guest:true}}
      let original={id:1,period_id:1,period,classification:'regular',status:'draft',version:1}
      let replacement=null
      const guestState={...state,academic_ready:false,student:null,admitted_guest:true,reason:'student_identity_pending',admission:{name:'Admitted Guest',applicant_number:'APP-2026-00001',course:{course_name:'Computing'},curriculum:{curriculum_name:'Computing 2026'}}}
      apiState.handler=async(method,url,payload)=>{
        if(url==='/enrollment/academic/notifications')return response(pagination([]))
        if(url==='/enrollment/status')return response({...guestState,current_application:replacement?{id:replacement.id,period_id:1,status:'draft'}:original.status==='draft'?{id:original.id,period_id:1,status:'draft'}:null})
        if(url==='/enrollment/applications/mine')return response(pagination(replacement?[replacement,original]:[original]))
        if(url==='/enrollment/applications/1')return response({application:original,requirements:[]})
        if(url==='/enrollment/applications/1/cancel'){original={...original,status:'cancelled',version:2};return response(original)}
        if(url==='/enrollment/applications'&&method==='post'){replacement={id:2,period_id:1,period,classification:payload.classification,status:'draft',version:1};return response(replacement)}
        if(url==='/enrollment/applications/2')return response({application:replacement,requirements:[]})
        throw new Error('Unexpected endpoint '+url)
      }
      const {root,app}=mount()
      try{
        await settle();await click(root,'View current application');await click(root,'Cancel Application');assert.match(textOf(root),/Cancellation ends this unsubmitted draft/);await click(root,'Confirm cancellation')
        await settle();assert.match(textOf(root),/Application history/);assert.match(textOf(root),/Cancelled/i)
        await click(root,'Start New Enrollment Application')
        assert.match(textOf(root),/Personal details/)
        assert.match(textOf(root),/Student identity Not created yet/)
        assert.equal(apiState.calls.filter(c=>c.method==='post'&&c.url==='/enrollment/applications').at(-1).payload.period_id,1)
        await click(root,'All applications')
        assert.match(textOf(root),/Application history/)
        assert.match(textOf(root),/Cancelled/i)
        assert.ok(button(root,'View current application'))
      }finally{app.unmount();apiState.handler=handler}
    })
    await t.test('all decision statuses and review notes are visible without draft actions',async()=>{
      apiState.handler=handler
      for(const status of ['submitted','under_review','approved','rejected']){
        record={id:1,period_id:1,period,status,classification:'returnee',version:3,review_notes:'Contact the Registrar'}
        const {root,app}=mount();try{await settle();await click(root,'View current application');assert.match(textOf(root),/Contact the Registrar/);assert.equal(button(root,'Save draft'),undefined);assert.equal(button(root,'Cancel Application'),undefined);if(status==='approved')assert.match(textOf(root),/pending finalization/)}finally{app.unmount()}
      }
    })
    await t.test('Admin and Registrar share staff applications, filters and period forms',async()=>{
      apiState.handler=handler;record=null
      for(const role of [ROLES.ADMIN,ROLES.REGISTRAR_STAFF]){
        auth.currentRole=role;await router.push('/enrollment/applications')
        const nav=navigation.menuItemsForRole(role).find(n=>n.name==='enrollment');assert.deepEqual(nav.children.map(n=>n.label),['Applications','Enrollment Periods','Sections','Subjects','Scheduling','Enrollment Records'])
        const {root,app}=mount();try{await settle();assert.match(textOf(root),/Applicant name or number/);assert.equal(all(root,'nav').filter(n=>/Applications.*Enrollment Periods/.test(textOf(n))).length,0);const searchForm=find(root,'form');await searchForm.props.onSubmit({preventDefault(){}});await settle();assert.equal(apiState.calls.at(-1).url,'/enrollment/applications');await click(root,'Pending');assert.equal(apiState.calls.at(-1).payload.params.status,'submitted');await click(root,'Filters');assert.match(textOf(root),/All courses/);await click(root,'Clear');assert.equal(apiState.calls.at(-1).payload.params.status,'');await router.push('/enrollment/periods');await settle();assert.match(textOf(root),/Academic calendar/);assert.match(textOf(root),/Manage window/);await click(root,'Create period');assert.match(textOf(root),/Documents by classification/);assert.match(textOf(root),/Enable period/);assert.match(textOf(root),/Required/);assert.match(textOf(root),/Optional/)}finally{app.unmount()}
      }
    })
    await t.test('enabling an enrollment window requires confirmation before the existing PUT contract',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;await router.push('/enrollment/periods');apiState.handler=handler;apiState.calls=[]
      const {root,app}=mount();try{await settle();await click(root,'Manage window');const checkbox=all(root,'input').find(node=>node.props.type==='checkbox');assert.ok(checkbox);checkbox.props['onUpdate:modelValue'](true);await settle();const periodForm=all(root,'form').find(node=>/Save period/.test(textOf(node)));await periodForm.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(root),/Open this enrollment window/);assert.equal(apiState.calls.filter(call=>call.method==='put').length,0);await click(root,'Confirm period change');assert.equal(apiState.calls.filter(call=>call.url==='/enrollment/periods/1'&&call.method==='put').length,1)}finally{app.unmount()}
    })
    await t.test('both staff roles confirm review and approval, with rejection notes required',async()=>{
      for(const role of [ROLES.ADMIN,ROLES.REGISTRAR_STAFF]){
        auth.currentRole=role;await router.push('/enrollment/applications');apiState.handler=handler
        record={id:1,period_id:1,period,status:'submitted',classification:'regular',version:2,student:{student_number:'26-001'},course:{course_name:'Computing'}}
        const {root,app}=mount();try{await settle();await click(root,'Review');let dialogs=all(root,'div').filter(n=>n.props.role==='dialog');assert.equal(dialogs.length,1);assert.match(textOf(dialogs[0]),/Application #1/);assert.match(textOf(dialogs[0]),/26-001/);assert.equal(all(root,'div').filter(n=>n.props.class==='en-card' && /Application #1/.test(textOf(n))).length,0);await click(root,'Close');assert.equal(all(root,'div').filter(n=>n.props.role==='dialog').length,0);assert.doesNotMatch(textOf(root),/Application #1/);await click(root,'Review');assert.ok(button(root,'Reject').props.disabled);await click(root,'Start Review');assert.equal(record.status,'submitted');await click(root,'Confirm');assert.equal(record.status,'under_review');await click(root,'Approve');await click(root,'Confirm');assert.equal(record.status,'approved');assert.equal(button(root,'Reject'),undefined)}finally{app.unmount()}
      }
    })
    await t.test('errors stay safe and changing account discards old Student responses',async()=>{
      record=null;auth.currentRole=ROLES.STUDENT;auth.currentUser={id:1};await router.push('/enrollment/status');let resolve
      apiState.handler=(m,u)=>u==='/enrollment/status'?new Promise(r=>{resolve=r}):response(pagination([]))
      const {root,app}=mount();try{await nextTick();auth.currentRole=ROLES.ADMIN;apiState.handler=handler;await settle();resolve(response(state));await settle();assert.doesNotMatch(textOf(root),/26-001/)}finally{app.unmount()}
      auth.currentRole=ROLES.STUDENT;apiState.handler=async()=>{throw new Error('secret database value')};const failed=mount();try{await settle();assert.match(textOf(failed.root),/temporarily unavailable/);assert.doesNotMatch(textOf(failed.root),/secret database/);apiState.handler=handler;await click(failed.root,'Reload');assert.match(textOf(failed.root),/26-001/)}finally{failed.app.unmount()}
    })
  }finally{globalThis.document=priorDocument;globalThis.requestAnimationFrame=priorFrame;await rm(temporary,{recursive:true,force:true})}
})
