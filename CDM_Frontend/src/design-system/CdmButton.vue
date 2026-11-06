<script setup>
import { RouterLink } from 'vue-router'
defineOptions({ inheritAttrs: false })
defineProps({ variant: { type: String, default: 'primary' }, size: { type: String, default: 'md' }, loading: Boolean, to: { type: [String,Object], default: null } })
</script>
<template><component :is="to?RouterLink:'button'" v-bind="$attrs" :to="to||undefined" class="cdm-button" :class="[`cdm-button--${variant}`,`cdm-button--${size}`]" :disabled="!to&&(loading || $attrs.disabled)" :aria-disabled="to&&(loading||$attrs.disabled)?'true':undefined" :tabindex="to&&(loading||$attrs.disabled)?-1:undefined" :aria-busy="loading || undefined"><span v-if="loading" class="cdm-button__spinner" aria-hidden="true"></span><slot /></component></template>
<style scoped>
.cdm-button { display:inline-flex; align-items:center; justify-content:center; gap:7px; border:1px solid transparent; border-radius:var(--cdm-radius-sm); font-family:var(--cdm-font-sans); font-weight:700; line-height:1.2; white-space:nowrap; text-decoration:none; cursor:pointer; transition:background-color 140ms,border-color 140ms,color 140ms; }
.cdm-button--sm { min-height:var(--cdm-control-sm); padding:5px 10px; font-size:12px; }.cdm-button--md { min-height:var(--cdm-control-md); padding:8px 14px; font-size:13px; }.cdm-button--lg { min-height:var(--cdm-control-lg); padding:11px 18px; font-size:14px; }.cdm-button--icon { width:var(--cdm-control-md); height:var(--cdm-control-md); padding:0; }
.cdm-button--primary { background:var(--cdm-primary); border-color:var(--cdm-primary); color:var(--cdm-on-primary); }.cdm-button--primary:hover:not(:disabled) { background:var(--cdm-primary-hover); border-color:var(--cdm-primary-hover); }
.cdm-button--secondary { background:var(--cdm-text); border-color:var(--cdm-text); color:var(--cdm-surface); }.cdm-button--outline { background:var(--cdm-surface); border-color:var(--cdm-border-strong); color:var(--cdm-text-secondary); }.cdm-button--outline:hover:not(:disabled),.cdm-button--ghost:hover:not(:disabled) { background:var(--cdm-surface-muted); color:var(--cdm-primary); }
.cdm-button--ghost { background:transparent; color:var(--cdm-primary); }.cdm-button--danger { background:var(--cdm-danger); border-color:var(--cdm-danger); color:#fff; }
.cdm-button:focus-visible { outline:2px solid var(--cdm-focus); outline-offset:2px; }.cdm-button:disabled,.cdm-button[aria-disabled=true] { opacity:.52; cursor:not-allowed; pointer-events:none; }
.cdm-button__spinner { width:14px; height:14px; border:2px solid currentColor; border-top-color:transparent; border-radius:50%; animation:cdm-spin .7s linear infinite; } @keyframes cdm-spin { to { transform:rotate(360deg); } } @media(prefers-reduced-motion:reduce){.cdm-button{transition:none}.cdm-button__spinner{animation:none}}
</style>
