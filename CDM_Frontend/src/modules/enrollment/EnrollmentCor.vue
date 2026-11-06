<script setup>
import { computed } from 'vue'
import { hasSavedSchedule } from './enrollment'

const props=defineProps({record:{type:Object,required:true}})
const requiredTextFields=['student_number','name','academic_year','semester','section','enrollment_date']
const recordIssue=computed(()=>{
  if(!props.record||typeof props.record!=='object')return 'The Certificate of Registration record is unavailable.'
  if(!Array.isArray(props.record.subjects))return 'This academic record is incomplete because its subject load is unavailable.'
  if(requiredTextFields.some(field=>typeof props.record[field]!=='string'||!props.record[field].trim()))return 'This academic record is incomplete and cannot be displayed as an official Certificate of Registration.'
  if(props.record.subjects.some(subject=>!subject||typeof subject!=='object'||!subject.subject_code||!subject.subject_name))return 'This academic record contains an incomplete subject entry and cannot be displayed as an official Certificate of Registration.'
  return ''
})
const subjects=computed(()=>recordIssue.value?'':props.record.subjects)
const displayValue=value=>value===null||value===undefined||value===''?'Not available':value
const formatTime=value=>String(value).slice(0,5)
const scheduleMeetings=schedule=>schedule?.meetings?.length?schedule.meetings:hasSavedSchedule(schedule)?[{day:schedule.day,start_time:schedule.start_time,end_time:schedule.end_time,room:schedule.room}]:[]
const print=()=>window.print()
</script>
<template><article class="en-card en-cor"><header class="en-cor-header"><p>COLEGIO DE MONTALBAN</p><h2>Certificate of Registration</h2><p v-if="!recordIssue">Academic registration · {{ record.academic_year }} · {{ record.semester }}</p></header><p v-if="recordIssue" class="en-cor-error" role="alert">{{ recordIssue }}</p><template v-else><dl class="en-grid"><div><dt>Student number</dt><dd>{{ record.student_number }}</dd></div><div><dt>Student name</dt><dd>{{ record.name }}</dd></div><div><dt>Course / year level</dt><dd>{{ displayValue(record.course) }} / {{ displayValue(record.year_level) }}</dd></div><div><dt>Section</dt><dd>{{ record.section }}</dd></div></dl><div class="en-table"><table><thead><tr><th>Code</th><th>Subject</th><th>Section</th><th>Schedule / room</th><th>Lecture / lab hours</th><th>Units</th></tr></thead><tbody><tr v-for="s in subjects" :key="s.id"><td>{{ s.subject_code }}</td><td>{{ s.subject_name }}</td><td>{{ record.section }}</td><td><template v-if="scheduleMeetings(s.schedule).length"><span v-for="meeting in scheduleMeetings(s.schedule)" :key="meeting.day">{{ meeting.day }} {{ formatTime(meeting.start_time) }}–{{ formatTime(meeting.end_time) }} · {{ meeting.room }}<br></span></template><span v-else>To be announced</span></td><td>{{ displayValue(s.lecture_hours) }} / {{ displayValue(s.laboratory_hours) }}</td><td>{{ displayValue(s.units) }}</td></tr></tbody><tfoot><tr><th colspan="5">Total units</th><td>{{ displayValue(record.total_units) }}</td></tr></tfoot></table></div><p>Registration date: {{ record.enrollment_date }} · Record #{{ record.id }}</p><p>Academic registration information only. Financial assessment and official signatures are not included.</p><button class="en-no-print" @click="print">Print COR</button></template></article></template>
