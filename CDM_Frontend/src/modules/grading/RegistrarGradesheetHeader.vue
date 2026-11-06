<script setup>
import CDMSeal from '../../components/website/CDMSeal.vue'
import { institutionProfile } from '../../config/publicHomepage.js'

defineProps({
  classContext: { type: Object, required: true },
  currentPeriod: { type: String, required: true },
  periodState: { type: Object, required: true },
  sheetStatus: { type: String, required: true },
  studentCount: { type: Number, required: true },
  formatDate: { type: Function, required: true },
})
</script>

<template>
  <section class="registrar-sheet-header" aria-labelledby="registrar-gradesheet-title">
    <div class="registrar-sheet-institution">
      <CDMSeal class="registrar-sheet-seal" />
      <div class="registrar-sheet-name">
        <span>{{institutionProfile.location}}</span>
        <strong>{{institutionProfile.name}}</strong>
        <small>Academic grade-entry workspace</small>
      </div>
      <div class="registrar-sheet-identification">
        <span>Professor working copy</span>
        <h2 id="registrar-gradesheet-title">GRADESHEET</h2>
        <small>Not an officially released record</small>
      </div>
    </div>

    <dl class="registrar-sheet-academic-fields">
      <div><dt>Course / Program</dt><dd>{{classContext.program}}</dd></div>
      <div><dt>Year and Section</dt><dd>Year {{classContext.year_level}} · {{classContext.section}}</dd></div>
      <div><dt>Semester</dt><dd>{{classContext.semester}}</dd></div>
      <div><dt>Academic Year</dt><dd>{{classContext.academic_year}}</dd></div>
      <div><dt>Subject Code</dt><dd>{{classContext.subject_code}}</dd></div>
      <div class="registrar-sheet-field-wide"><dt>Subject Description</dt><dd>{{classContext.subject_name}}</dd></div>
      <div><dt>Total Units</dt><dd>{{classContext.units}}</dd></div>
      <div v-if="classContext.institute"><dt>Institute</dt><dd>{{classContext.institute}}</dd></div>
    </dl>

    <div class="registrar-sheet-note">
      <p><strong>Entry note:</strong> Scores remain draft changes until Save changes succeeds. Official submission is completed from the separate Grade Submission page.</p>
      <dl><div><dt>Current period</dt><dd>{{currentPeriod==='final'?'Final Grade':currentPeriod}}</dd></div><div><dt>Period status</dt><dd>{{periodState.state}}</dd></div><div><dt>{{periodState.state==='Upcoming'?'Opens':'Deadline'}}</dt><dd>{{formatDate(periodState.state==='Upcoming'?periodState.opens_at:periodState.deadline)}}</dd></div><div><dt>Students</dt><dd>{{studentCount}}</dd></div><div><dt>Sheet status</dt><dd>{{sheetStatus}}</dd></div></dl>
    </div>
  </section>
</template>
