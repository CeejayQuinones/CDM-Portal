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
        builder.onResolve({filter:/services\/performance\/performanceMonitor$/},()=>({path:'performance',namespace:'performance'}))
        builder.onLoad({filter:/.*/,namespace:'performance'},()=>({contents:"export const performanceMonitor={beginRoute(){},markRouteRendered(){}};"}))
        builder.onResolve({filter:/services\/apiClient$/},()=>({path:'api',namespace:'api'}))
        builder.onLoad({filter:/.*/,namespace:'api'},()=>({contents:"export const apiState={calls:[],handler:async()=>({})}; const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)}; export const apiClient={get:(u,p)=>call('get',u,p),post:(u,p)=>call('post',u,p),put:(u,p)=>call('put',u,p)};"}))
        builder.onLoad({filter:/\.vue$/},async({path:filename})=>{
          if(!filename.includes('/modules/enrollment/')) return {contents:'export default {render(){return null}}'}
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
    const period={id:1,academic_year:'2026–2027',semester:'First',state:'open',opens_at:'2026-01-01T00:00:00Z',closes_at:'2027-01-01T00:00:00Z'}
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
      if(url==='/enrollment/periods')return response({periods:pagination([period]),academic_years:[{id:1,school_year:'2026–2027'}],semesters:[{id:1,semester_name:'First'}],document_types:[]})
      throw new Error('Unexpected endpoint '+url)
    }
    const mount=()=>{const root={children:[]},app=renderer.createApp(EnrollmentView);app.use(router);app.mount(root);return {root,app}}
    await t.test('wizard saves classification, confirms submission and renders status immediately',async()=>{
      auth.currentRole=ROLES.STUDENT;await router.push('/enrollment/status');apiState.handler=handler;apiState.calls=[];record=null
      const {root,app}=mount()
      try{
        await settle();assert.match(textOf(root),/26-001/);await click(root,'Start enrollment application');assert.match(textOf(root),/Confirm your academic information/)
        await click(root,'Continue');const irregular=all(root,'input').find(n=>n.props.value==='irregular');irregular.props['onUpdate:modelValue']('irregular');await settle()
        await click(root,'Save draft');assert.equal(record.classification,'irregular');await click(root,'Continue');assert.match(textOf(root),/Customized subject load/);await click(root,'Continue');assert.match(textOf(root),/Review your application/)
        await click(root,'Submit application');assert.ok(all(root,'div').some(n=>n.props.role==='dialog'));assert.equal(apiState.calls.filter(c=>c.url.endsWith('/submit')).length,0)
        await click(root,'Go back');await click(root,'Submit application');await click(root,'Confirm submit');assert.match(textOf(root),/awaiting staff review/);assert.equal(apiState.calls.filter(c=>c.url.endsWith('/submit')).length,1)
      }finally{app.unmount()}
    })
    await t.test('upcoming and closed periods block creation and show the correct state',async()=>{
      record=null
      for(const status of ['upcoming','closed']){
        apiState.handler=async(m,u,p)=>u==='/enrollment/status'?response({...state,eligible:false,reason:'enrollment_period_closed',period:{...period,state:status}}):handler(m,u,p)
        const {root,app}=mount();try{await settle();assert.match(textOf(root),new RegExp(status,'i'));assert.equal(button(root,'Start enrollment application'),undefined)}finally{app.unmount()}
      }
    })
    await t.test('all decision statuses and review notes are visible without draft actions',async()=>{
      apiState.handler=handler
      for(const status of ['submitted','under_review','approved','rejected']){
        record={id:1,period_id:1,period,status,classification:'returnee',version:3,review_notes:'Contact the Registrar'}
        const {root,app}=mount();try{await settle();await click(root,'View current application');assert.match(textOf(root),/Contact the Registrar/);assert.equal(button(root,'Save draft'),undefined);if(status==='approved')assert.match(textOf(root),/pending finalization/)}finally{app.unmount()}
      }
    })
    await t.test('Admin and Registrar share staff applications, filters and period forms',async()=>{
      apiState.handler=handler;record=null
      for(const role of [ROLES.ADMIN,ROLES.REGISTRAR_STAFF]){
        auth.currentRole=role;await router.push('/enrollment/applications')
        const nav=navigation.menuItemsForRole(role).find(n=>n.name==='enrollment');assert.deepEqual(nav.children.map(n=>n.label),['Applications','Enrollment Periods','Sections','Scheduling','Enrollment Records'])
        const {root,app}=mount();try{await settle();assert.match(textOf(root),/Student name or number/);const form=find(root,'form');await form.props.onSubmit({preventDefault(){}});await settle();assert.equal(apiState.calls.at(-1).url,'/enrollment/applications');await router.push('/enrollment/periods');await settle();await click(root,'Create period');assert.match(textOf(root),/Required documents by classification/);assert.match(textOf(root),/Enable period/)}finally{app.unmount()}
      }
    })
    await t.test('both staff roles confirm review and approval, with rejection notes required',async()=>{
      for(const role of [ROLES.ADMIN,ROLES.REGISTRAR_STAFF]){
        auth.currentRole=role;await router.push('/enrollment/applications');apiState.handler=handler
        record={id:1,period_id:1,period,status:'submitted',classification:'regular',version:2,student:{student_number:'26-001'},course:{course_name:'Computing'}}
        const {root,app}=mount();try{await settle();await click(root,'Inspect');let dialogs=all(root,'div').filter(n=>n.props.role==='dialog');assert.equal(dialogs.length,1);assert.match(textOf(dialogs[0]),/Application #1/);assert.match(textOf(dialogs[0]),/26-001/);assert.equal(all(root,'div').filter(n=>n.props.class==='en-card' && /Application #1/.test(textOf(n))).length,0);await click(root,'Close');assert.equal(all(root,'div').filter(n=>n.props.role==='dialog').length,0);assert.doesNotMatch(textOf(root),/Application #1/);await click(root,'Inspect');assert.ok(button(root,'Reject').props.disabled);await click(root,'Start Review');assert.equal(record.status,'submitted');await click(root,'Confirm');assert.equal(record.status,'under_review');await click(root,'Approve');await click(root,'Confirm');assert.equal(record.status,'approved');assert.equal(button(root,'Reject'),undefined)}finally{app.unmount()}
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
