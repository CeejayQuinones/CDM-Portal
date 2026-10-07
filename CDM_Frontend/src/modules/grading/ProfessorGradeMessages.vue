<script setup>
import { onMounted, ref } from 'vue'
import GradeConversationPanel from './GradeConversationPanel.vue'
import { gradingError, gradingService } from './gradingService'

const loading=ref(true),error=ref(''),items=ref([]),selected=ref(null)
async function load(silent=false){if(!silent)loading.value=true;error.value='';try{items.value=await gradingService.professorConversations()}catch(e){error.value=gradingError(e)}finally{if(!silent)loading.value=false}}
onMounted(load)
</script>
<template><section class="professor-grade-messages"><p v-if="error" class="grading-alert error" role="alert">{{error}}</p><div v-if="loading" class="grading-state">Loading grade conversations…</div><div v-else-if="!items.length" class="grading-state"><strong>No conversations</strong><span>Student grade concerns appear after a published subject conversation begins.</span></div><div v-else class="grade-message-layout"><aside class="grade-conversation-list"><button v-for="item in items" :key="item.id" :class="{active:selected===item.id}" @click="selected=item.id"><strong>{{item.student_name}}</strong><span>{{item.student_number}} · {{item.subject_code}} · {{item.section}}</span><small>{{item.latest_message||'No messages yet'}}</small><em v-if="item.unread_count" class="grade-unread">{{item.unread_count}}</em></button></aside><GradeConversationPanel v-if="selected" :conversation-id="selected" @changed="load(true)" /><div v-else class="grading-state"><strong>Select a conversation</strong><span>Open a Student thread to read or reply.</span></div></div></section></template>
