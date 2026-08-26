<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import logoUrl from '../assets/styles/images/cdm_logo.png'

const route = useRoute()
const isPrivacy = computed(() => route.name === 'privacy')

const terms = [
  ['Account responsibility', 'Keep your login credentials private and notify the portal administrator if you believe your account has been compromised.'],
  ['Acceptable use', 'Use the portal only for legitimate school, academic, and registrar-related purposes.'],
  ['Accuracy of information', 'Provide complete and accurate information when registering or submitting a service request.'],
  ['Prohibited misuse', 'Do not attempt unauthorized access, disrupt services, impersonate another person, or use the portal for unlawful activity.'],
  ['System availability', 'Access may occasionally be limited for maintenance, security, or operational reasons.'],
  ['Policy updates', 'This draft may be revised as project and institutional requirements are finalized.'],
]

const privacy = [
  ['Information collected', 'The portal may collect identity, contact, account, and service-request information that you submit.'],
  ['Purpose of collection', 'Information is used to provide portal access, process available services, communicate about requests, and support school administration.'],
  ['Account and security data', 'Login and technical security records may be processed to protect accounts and maintain the service. Passwords are stored using secure hashing and are not displayed in the portal.'],
  ['How records are used', 'Authorized personnel may access records when needed to deliver school and registrar services under applicable institutional procedures.'],
  ['User responsibilities', 'Submit accurate information, protect your credentials, and avoid including unnecessary sensitive data in free-text fields.'],
  ['Contact and administration', 'Project contact details and the responsible privacy or records office will be added after institutional review.'],
]
</script>

<template>
  <main class="legal-page">
    <header><RouterLink to="/login" class="brand"><img :src="logoUrl" alt="Colegio de Montalban seal" /><span><strong>Colegio de Montalban</strong><small>CDM Portal</small></span></RouterLink><RouterLink to="/login" class="back">← Back to homepage</RouterLink></header>
    <section class="legal-hero"><p>CDM Portal · Project draft</p><h1>{{ isPrivacy ? 'Privacy Policy' : 'Terms & Conditions' }}</h1><span>Last updated: August 26, 2026</span></section>
    <article class="legal-content">
      <div class="draft-notice"><strong>Draft project content</strong><p>This page is provided for prototype and review purposes. It does not create legal guarantees and should be reviewed and approved by authorized Colegio de Montalban representatives before production use.</p></div>
      <p class="intro">{{ isPrivacy ? 'This draft explains, in general terms, what information the CDM Portal may process and why.' : 'By creating and using a CDM Portal account, users are expected to follow these project terms.' }}</p>
      <section v-for="(item, index) in (isPrivacy ? privacy : terms)" :key="item[0]"><span>{{ String(index + 1).padStart(2, '0') }}</span><div><h2>{{ item[0] }}</h2><p>{{ item[1] }}</p></div></section>
      <div class="legal-action"><p>Need to access your portal account?</p><RouterLink to="/login">Return to CDM Portal</RouterLink></div>
    </article>
    <footer>© 2026 Colegio de Montalban · CDM Portal project</footer>
  </main>
</template>

<style scoped>
.legal-page{min-height:100vh;background:#f4f7f4;color:#25362c}.legal-page>header{height:78px;background:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 max(24px,calc((100% - 1050px)/2));border-bottom:1px solid #e1e8e3}.brand{display:flex;align-items:center;gap:11px}.brand img{width:44px;height:44px}.brand strong{display:block;color:#165b38;font-family:Georgia,serif;font-size:.9rem;text-transform:uppercase}.brand small{display:block;color:#89948e;font-size:.6rem;font-weight:800;letter-spacing:.16em}.back{color:#176c42;font-size:.8rem;font-weight:800}.legal-hero{background:linear-gradient(120deg,#064729,#117347);color:#fff;padding:70px max(24px,calc((100% - 900px)/2))}.legal-hero p{margin:0;color:#e8cc56;font-size:.7rem;font-weight:900;letter-spacing:.17em;text-transform:uppercase}.legal-hero h1{margin:13px 0 10px;font-family:Georgia,serif;font-size:clamp(2.5rem,6vw,4.3rem);font-weight:500}.legal-hero span{color:rgba(255,255,255,.58);font-size:.76rem}.legal-content{width:min(900px,calc(100% - 40px));margin:45px auto 75px}.draft-notice{border-left:4px solid #d5af2e;background:#fff9df;border-radius:0 10px 10px 0;padding:16px 19px}.draft-notice strong{color:#755c09;font-size:.82rem}.draft-notice p{margin:5px 0 0;color:#776f55;font-size:.8rem;line-height:1.6}.intro{margin:35px 0;color:#596860;font-size:1rem;line-height:1.75}.legal-content>section{display:grid;grid-template-columns:45px 1fr;gap:16px;border-top:1px solid #dce4de;padding:25px 0}.legal-content>section>span{color:#b28e17;font-family:Georgia,serif;font-size:1.1rem}.legal-content h2{margin:0 0 8px;color:#17482f;font-family:Georgia,serif;font-size:1.25rem}.legal-content section p{margin:0;color:#66746c;font-size:.88rem;line-height:1.7}.legal-action{margin-top:30px;border-radius:12px;background:#0b5a34;color:#fff;padding:24px;display:flex;align-items:center;justify-content:space-between;gap:20px}.legal-action p{margin:0}.legal-action a{border-radius:7px;background:#efd052;color:#133e29;padding:11px 17px;font-size:.78rem;font-weight:850}.legal-page>footer{background:#052e1b;color:rgba(255,255,255,.5);padding:20px;text-align:center;font-size:.7rem}@media(max-width:600px){.legal-page>header{height:68px}.brand strong{font-size:.75rem}.back{font-size:0;width:34px}.back::after{content:'←';font-size:1.2rem}.legal-hero{padding-block:52px}.legal-action{align-items:stretch;flex-direction:column}.legal-action a{text-align:center}}
</style>
