<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const open = ref(false)
const dismissed = ref(false)
const notices = [
  { title: 'Welcome to CDM Portal!', text: 'I’m CDM Buddy! Check your dashboard for enrollment updates and document request status.', type: 'guide' },
  { title: 'Student records reminder', text: 'Keep your contact details updated and review your official student records regularly.', type: 'reminder' },
  { title: 'Official advisories', text: 'Check announcements from Colegio de Montalban for verified class suspension and weather advisories.', type: 'guide' },
]
const selected = ref(0)
const notice = computed(() => notices[selected.value])
const onDashboard = computed(() => ['student-dashboard', 'guest-dashboard'].includes(route.name))
const next = () => { selected.value = (selected.value + 1) % notices.length }
</script>

<template>
  <aside v-if="onDashboard && !dismissed" class="cdm-buddy" aria-label="CDM Buddy portal assistant">
    <section v-if="open" class="buddy-card" aria-live="polite">
      <div class="buddy-head"><strong>{{ notice.title }}</strong><button type="button" aria-label="Close CDM Buddy message" @click="open = false">×</button></div>
      <p>{{ notice.text }}</p>
      <div class="buddy-actions"><button type="button" @click="next">Next tip →</button><button type="button" @click="dismissed = true">Hide for now</button></div>
      <small>CDM Buddy tips • Not official real-time advisories</small>
    </section>
    <button class="buddy-trigger" type="button" :aria-expanded="open" aria-label="Toggle CDM Buddy guidance" @click="open = !open">
      <svg viewBox="0 0 100 112" role="img" aria-label="Smiling green mountain explorer mascot" xmlns="http://www.w3.org/2000/svg">
        <path d="M16 102 Q49 84 84 102 L87 110 H13Z" fill="#286b45"/>
        <path d="M35 70 L22 82 L29 103 L72 103 L78 82 L65 70" fill="#4d9d61" stroke="#19472e" stroke-width="3"/>
        <path d="M29 79 L16 93 M71 79 L84 91" stroke="#e9b288" stroke-width="10" stroke-linecap="round"/>
        <path d="M33 99 L31 110 M67 99 L69 110" stroke="#77563d" stroke-width="13" stroke-linecap="round"/>
        <path d="M27 31 Q17 57 29 73 Q49 91 72 71 Q85 53 73 30Z" fill="#f5c89e" stroke="#5c392b" stroke-width="2.5"/>
        <path d="M23 41 Q27 15 53 17 Q76 18 79 42 Q63 36 55 28 Q46 44 23 41" fill="#513729"/>
        <path d="M12 34 Q27 7 47 6 Q70 7 90 35 Q77 42 50 40 Q25 42 12 34" fill="#2e804a" stroke="#19472e" stroke-width="3"/>
        <path d="M35 14 L45 5 L54 13 L62 7 L75 24 Q51 21 26 25Z" fill="#e5f6d4"/>
        <ellipse cx="37" cy="53" rx="6" ry="8" fill="#392a22"/><ellipse cx="62" cy="53" rx="6" ry="8" fill="#392a22"/>
        <circle cx="39" cy="50" r="2" fill="white"/><circle cx="64" cy="50" r="2" fill="white"/>
        <path d="M42 68 Q50 77 59 68" fill="none" stroke="#943d39" stroke-width="3.5" stroke-linecap="round"/>
        <path d="M43 79 L50 86 L58 79" fill="none" stroke="#eaf2d8" stroke-width="3"/>
        <path d="M26 77 Q18 67 14 56" fill="none" stroke="#e9b288" stroke-width="9" stroke-linecap="round"/>
        <circle cx="12" cy="50" r="8" fill="#f5c89e"/>
      </svg>
      <span class="buddy-label">CDM Buddy</span>
    </button>
  </aside>
</template>

<style scoped>
.cdm-buddy{position:fixed;z-index:38;right:max(16px,env(safe-area-inset-right));bottom:calc(20px + env(safe-area-inset-bottom));display:flex;flex-direction:column;align-items:flex-end;gap:10px;pointer-events:none;font-family:inherit}
.cdm-buddy>*{pointer-events:auto}
.buddy-trigger{background:#e9f8e7;border:2px solid #256b43;border-radius:22px;padding:5px 7px 3px;box-shadow:0 8px 22px #0003;cursor:pointer;display:grid;justify-items:center;transition:transform .2s ease}
.buddy-trigger:hover{transform:translateY(-3px)}
.buddy-trigger svg{width:72px;height:78px;display:block}
.buddy-label{font-size:11px;font-weight:800;color:#165338;line-height:1.5}
.buddy-card{max-width:min(340px,calc(100vw - 32px));background:#fff;color:#183727;border:2px solid #347c52;border-radius:20px;padding:15px 17px;box-shadow:0 12px 34px #0003;line-height:1.45}
.buddy-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.buddy-head strong{font-size:15px}
.buddy-head button{border:0;background:transparent;font-size:25px;cursor:pointer;color:#25553c;line-height:1}
.buddy-card p{font-size:14px;margin:8px 0}
.buddy-actions{display:flex;gap:12px;flex-wrap:wrap}
.buddy-actions button{background:none;color:#176341;border:0;padding:5px 0;font-size:13px;font-weight:700;cursor:pointer}
.buddy-card small{display:block;color:#627668;font-size:11px;margin-top:8px}
@media(max-width:480px){.cdm-buddy{bottom:calc(12px + env(safe-area-inset-bottom));right:12px}.buddy-trigger svg{width:52px;height:58px}.buddy-card{max-width:min(300px,calc(100vw - 24px))}}
@media(prefers-reduced-motion:reduce){.buddy-trigger{transition:none}.buddy-trigger:hover{transform:none}}
</style>
