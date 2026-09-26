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
test('Enrollment foundation uses existing Student identity and protected own-status routes', async t => {
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
        builder.onResolve({filter:/^vue-router$/,namespace:'file'},()=>({path:'router',namespace:'memory'}))
        builder.onLoad({filter:/.*/,namespace:'memory'},()=>({contents:"export * from 'vue-router'; import {createMemoryHistory} from 'vue-router'; export const createWebHashHistory = () => createMemoryHistory('/');",resolveDir:frontend}))
        builder.onResolve({filter:/stores\/authStore$/},()=>({path:'auth',namespace:'auth'}))
        builder.onLoad({filter:/.*/,namespace:'auth'},()=>({contents:"import {reactive} from 'vue'; export const auth=reactive({currentRole:'Student',currentUser:{id:1},isAuthenticated:true,async initialize(){}}); export const useAuthStore=()=>auth;",resolveDir:frontend}))
        builder.onResolve({filter:/services\/performance\/performanceMonitor$/},()=>({path:'performance',namespace:'performance'}))
        builder.onLoad({filter:/.*/,namespace:'performance'},()=>({contents:"export const performanceMonitor={beginRoute(){},markRouteRendered(){}};"}))
        builder.onResolve({filter:/services\/apiClient$/},()=>({path:'api',namespace:'api'}))
        builder.onLoad({filter:/.*/,namespace:'api'},()=>({contents:"export const apiState={calls:[],handler:async()=>({})}; export const apiClient={get(url){apiState.calls.push(url);return apiState.handler();}};"}))
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
    await t.test('Student can open landing/status, staff only the existing placeholder, other roles neither',async()=>{
      for(const role of Object.values(ROLES)){
        auth.currentRole=role
        const item=navigation.menuItemsForRole(role).find(i=>i.name==='enrollment')
        assert.equal(Boolean(item),[ROLES.STUDENT,ROLES.ADMIN,ROLES.REGISTRAR_STAFF].includes(role))
        for(const target of ['/enrollment','/enrollment/status']){
          await router.push('/'); await router.push(target)
          const allowed=role===ROLES.STUDENT || target==='/enrollment' && [ROLES.ADMIN,ROLES.REGISTRAR_STAFF].includes(role)
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
      createElement:tag=>({tag,children:[],props:{}}),createText:text=>({text}),createComment:()=>({text:''}),
      setText:(node,text)=>{node.text=text},setElementText:(node,text)=>{node.text=text;node.children=[]},
      patchProp:(node,key,prev,next)=>{node.props[key]=next},
      insert(node,parent,anchor){if(node.parent) node.parent.children.splice(node.parent.children.indexOf(node),1); const i=anchor?parent.children.indexOf(anchor):-1;parent.children.splice(i<0?parent.children.length:i,0,node);node.parent=parent},
      remove(node){node.parent?.children.splice(node.parent.children.indexOf(node),1)},parentNode:node=>node.parent,nextSibling:node=>node.parent?.children[node.parent.children.indexOf(node)+1] || null,
    })
    const textOf=node=>[node.text||'',...(node.children||[]).map(textOf)].join(' ')
    const find=(node,tag)=>node.tag===tag?node:(node.children||[]).map(child=>find(child,tag)).find(Boolean)
    const settle=async()=>{await new Promise(resolve=>setTimeout(resolve,0));await nextTick()}
    const state={academic_ready:true,eligible:false,reason:'enrollment_period_unavailable',applications_enabled:false,enrollments:[],student:{id:7,name:'Converted Student',student_number:'26-001',year_level:1,course:{course_name:'Computing'},curriculum:{curriculum_name:'Computing 2026'}}}
    const response=data=>({data:{success:true,data}})
    await t.test('converted Student sees reused identity and honest period placeholder without writes',async()=>{
      auth.currentRole=ROLES.STUDENT;apiState.calls=[];apiState.handler=async()=>response(state)
      const root={children:[]},app=renderer.createApp(EnrollmentView);app.mount(root)
      try{await settle();assert.match(textOf(root),/Academic record ready/);assert.match(textOf(root),/26-001/);assert.match(textOf(root),/No enrollment period/);assert.match(textOf(root),/not open/);assert.match(textOf(root),/subject selection/);assert.deepEqual(apiState.calls,['/enrollment/status']);assert.equal(find(root,'form'),undefined)}
      finally{app.unmount()}
    })
    await t.test('incomplete Student sees reason and staff placeholder fetches no Student data',async()=>{
      for(const role of [ROLES.STUDENT,ROLES.ADMIN,ROLES.REGISTRAR_STAFF]){
        auth.currentRole=role;apiState.calls=[];apiState.handler=async()=>response({...state,academic_ready:false,student:null,reason:'student_record_required'})
        const root={children:[]},app=renderer.createApp(EnrollmentView);app.mount(root)
        try{await settle();if(role===ROLES.STUDENT){assert.match(textOf(root),/academic Student record is not available/)}else{assert.match(textOf(root),/later step/);assert.deepEqual(apiState.calls,[])}assert.doesNotMatch(textOf(root),/26-001/)}
        finally{app.unmount()}
      }
    })
    await t.test('errors are safe and an old account response cannot leak identity',async()=>{
      auth.currentRole=ROLES.STUDENT;auth.currentUser={id:1}
      let resolve
      apiState.handler=()=>new Promise(r=>{resolve=r})
      const root={children:[]},app=renderer.createApp(EnrollmentView);app.mount(root)
      try{await nextTick();auth.currentRole=ROLES.ADMIN;await settle();resolve(response(state));await settle();assert.doesNotMatch(textOf(root),/26-001/)}
      finally{app.unmount()}
      auth.currentRole=ROLES.STUDENT;apiState.handler=async()=>{throw new Error('secret database value')}
      const failed={children:[]},retryApp=renderer.createApp(EnrollmentView);retryApp.mount(failed)
      try{await settle();assert.match(textOf(failed),/temporarily unavailable/);assert.doesNotMatch(textOf(failed),/secret database/);apiState.handler=async()=>response(state);find(failed,'button').props.onClick();await settle();assert.match(textOf(failed),/26-001/)}
      finally{retryApp.unmount()}
    })
  }finally{globalThis.document=priorDocument;globalThis.requestAnimationFrame=priorFrame;await rm(temporary,{recursive:true,force:true})}
})
