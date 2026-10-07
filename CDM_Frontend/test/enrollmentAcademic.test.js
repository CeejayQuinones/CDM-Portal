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
  const priorDocument = globalThis.document, priorDocumentClass=globalThis.Document, priorFrame = globalThis.requestAnimationFrame, priorWindow=globalThis.window
  let prints=0;globalThis.window={print(){prints++}}
  globalThis.Document=class {};globalThis.document = Object.assign(new globalThis.Document(),{title:'',activeElement:null}); globalThis.requestAnimationFrame = fn => fn()
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
        builder.onResolve({filter:/services\/performance\/performanceMonitor$/},()=>({path:'performance',namespace:'performance'}))
        builder.onLoad({filter:/.*/,namespace:'performance'},()=>({contents:"export const performanceMonitor={beginRoute(){},markRouteRendered(){}};"}))
        builder.onResolve({filter:/services\/apiClient$/},()=>({path:'api',namespace:'api'}))
        builder.onLoad({filter:/.*/,namespace:'api'},()=>({contents:"export const apiState={calls:[],handler:async()=>({})}; const call=(method,url,payload)=>{apiState.calls.push({method,url,payload});return apiState.handler(method,url,payload)}; export const apiClient={get:(u,p)=>call('get',u,p),post:(u,p)=>call('post',u,p),put:(u,p)=>call('put',u,p),delete:(u,p)=>call('delete',u,p),patch:(u,p)=>call('patch',u,p)};"}))
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
      createElement:tag=>({tag,tagName:tag.toUpperCase(),children:[],props:{},listeners:{},addEventListener(name,fn){this.listeners[name]=fn},removeEventListener(){},setAttribute(){},removeAttribute(){},getRootNode(){return globalThis.document},get options(){return this.children},get value(){return this.props.value},set value(v){this.props.value=v}}),createText:text=>({text}),createComment:()=>({text:''}),
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
    const academicRecord={id:42,student_number:'26-ACADEMIC',name:'Academic Student',course:'Computing',year_level:1,academic_year:'2026–2027',semester:'First',section:'CS-1A',enrollment_date:'2026-09-27',subjects:[{...subject,schedule}],total_units:3}
    const recordRow={...period,id:42,status:'enrolled',section,student:{student_number:'26-ACADEMIC',user_profile:{first_name:'Academic',last_name:'Student'},course:section.course},application:{classification:'regular'}}
    let classification='regular',selected=[],conflict=false
    const application=()=>({id:1,period,status:'approved',classification,version:1,student:recordRow.student})
    const handler=async(method,url,payload)=>{
      if(url==='/enrollment/academic/notifications')return response(pagination([]))
      if(url==='/enrollment/applications/mine')return response(pagination([application()]))
      if(url==='/enrollment/academic/applications/1')return response({application:application(),load:{candidates:[subject],standard_subject_ids:[10],selected_subject_ids:selected},sections:pagination([section])})
      if(url==='/enrollment/academic/applications/1/subjects'){selected=payload.subject_ids;return response(application())}
      if(url==='/enrollment/academic/options')return response({courses:[{id:1,course_name:'Computing',years:4}],academic_years:[period.academic_year],semesters:[period.semester],professors:pagination([professor,replacementProfessor])})
      if(url==='/enrollment/academic/sections')return response(method==='post'?section:pagination([section]))
      if(url==='/enrollment/academic/schedules'){
        if(method==='post'){if(conflict)throw {response:{status:409,data:{message:'Schedule overlaps an existing section, Professor or room meeting.'}}};return response(schedule)}
        return response({section,schedules:pagination([schedule]),subjects:[subject],days:['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']})
      }
      if(url==='/enrollment/academic/schedules/1'&&method==='put')return response({...schedule,...payload,professor:replacementProfessor,version:2})
      if(url==='/enrollment/academic/records')return response(pagination([recordRow]))
      if(url==='/enrollment/academic/records/42')return response(academicRecord)
      if(url==='/enrollment/academic/professor')return response(pagination([{...section,schedules:[schedule]}]))
      if(url==='/enrollment/academic/professor/sections/1')return response(pagination([recordRow]))
      throw new Error('Unexpected '+method+' '+url)
    }
    const mount=()=>{const root={children:[]},app=renderer.createApp(EnrollmentView);app.use(router);app.mount(root);return {root,app}}
    const field=(root,title,tag)=>all(root,'label').find(n=>textOf(n).trim().startsWith(title))?.children.find(n=>n.tag===tag)
    const model=async(node,value)=>{assert.ok(node,'Missing input');node.props['onUpdate:modelValue'](value);await settle()}
    await t.test('new routes restrict staff, Student and Professor operations',async()=>{
      for(const role of Object.values(ROLES)){
        auth.currentRole=role
        for(const name of ['sections','scheduling','records','subjects','schedule','cor','teaching']){
          const allowed=['sections','scheduling','records'].includes(name)?[ROLES.ADMIN,ROLES.REGISTRAR_STAFF].includes(role):name==='teaching'?role===ROLES.PROFESSOR:role===ROLES.STUDENT
          await router.push('/');await router.push('/enrollment/'+name);assert.equal(router.currentRoute.value.name==='unauthorized',!allowed)
        }
      }
      assert.deepEqual(navigation.menuItemsForRole(ROLES.STUDENT).find(n=>n.name==='enrollment').children.map(n=>n.label),['Status','Subjects','My Schedule','COR'])
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
      await router.push('/enrollment/records');const records=mount();try{await settle();assert.match(textOf(records.root),/26-ACADEMIC/);await model(field(records.root,'Sort','select'),'name');await all(records.root,'form')[0].props.onSubmit({preventDefault(){}});await settle();assert.equal(apiState.calls.filter(c=>c.url==='/enrollment/academic/records').at(-1).payload.params.sort,'name');await click(records.root,'View COR');assert.equal(apiState.calls.at(-1).url,'/enrollment/academic/records/42');const dialogs=all(records.root,'div').filter(n=>n.props.role==='dialog');assert.equal(dialogs.length,1);assert.equal(dialogs[0].props['aria-modal'],'true');assert.match(textOf(dialogs[0]),/Certificate of Registration/);await click(records.root,'Close');assert.equal(all(records.root,'div').filter(n=>n.props.role==='dialog').length,0);assert.doesNotMatch(textOf(records.root),/Certificate of Registration/)}finally{records.app.unmount()}
    })
    await t.test('scheduling supports views, copy and safe server conflict feedback',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();assert.match(textOf(root),/Programming/);await click(root,'Timetable');assert.match(textOf(root),/Monday/);await click(root,'List');assert.match(textOf(root),/Room \/ Professor/);await click(root,'Copy');const form=all(root,'form').at(-1);assert.match(textOf(form),/copy destination/);conflict=true;await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(root),/Schedule overlaps/)}finally{conflict=false;app.unmount()}
    })
    await t.test('Professor reassignment requires explicit confirmation',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;apiState.handler=handler;apiState.calls=[];await router.push('/enrollment/scheduling');const {root,app}=mount()
      try{await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();await click(root,'Edit');const form=all(root,'form').at(-1);await model(field(form,'Professor','select'),2);await form.props.onSubmit({preventDefault(){}});await settle();assert.match(textOf(root),/Reassign this teaching assignment/);assert.equal(apiState.calls.some(c=>c.method==='put'),false);await click(root,'Confirm reassignment');assert.equal(apiState.calls.find(c=>c.method==='put').payload.professor_id,2)}finally{app.unmount()}
    })
    await t.test('Scheduling explains missing curriculum subjects without inventing options',async()=>{
      auth.currentRole=ROLES.REGISTRAR_STAFF;await router.push('/enrollment/scheduling')
      apiState.handler=async(method,url,payload)=>{
        const result=await handler(method,url,payload)
        if(url==='/enrollment/academic/schedules')result.data.data.subjects=[]
        return result
      }
      const {root,app}=mount()
      try{await settle();const selector=all(root,'select').find(n=>n.props.onChange);await model(selector,1);await selector.props.onChange();await settle();assert.match(textOf(root),/No active curriculum subjects are configured/)}finally{app.unmount();apiState.handler=handler}
    })
    await t.test('Student sees real schedule and printable COR; Professor sees assigned roster',async()=>{
      auth.currentRole=ROLES.STUDENT;apiState.handler=handler;await router.push('/enrollment/schedule');const mounted=mount()
      try{await settle();await click(mounted.root,'View schedule');assert.equal(apiState.calls.at(-1).url,'/enrollment/academic/records/42');assert.match(textOf(mounted.root),/Programming/)}finally{mounted.app.unmount()}
      await router.push('/enrollment/cor');const cor=mount();try{await settle();await click(cor.root,'View COR');assert.match(textOf(cor.root),/26-ACADEMIC/);assert.match(textOf(cor.root),/Total units/);assert.match(textOf(cor.root),/Financial assessment and official signatures are not included/);await click(cor.root,'Print COR');assert.equal(prints,1)}finally{cor.app.unmount()}
      auth.currentRole=ROLES.PROFESSOR;await router.push('/enrollment/teaching');const prof=mount();try{await settle();await click(prof.root,'View enrolled Students');assert.match(textOf(prof.root),/Academic Student/);assert.equal(button(prof.root,'Create section'),undefined)}finally{prof.app.unmount()}
    })
  }finally{globalThis.document=priorDocument;globalThis.Document=priorDocumentClass;globalThis.requestAnimationFrame=priorFrame;globalThis.window=priorWindow;await rm(temporary,{recursive:true,force:true})}
})
