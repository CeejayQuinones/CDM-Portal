<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {
  createPerformanceRecord,
  fetchStudyStudio,
  generateStudyFlashcards,
  generateStudyQuiz,
  generateStudyStudioPlan,
  generateTopicStudyPlan,
} from '../services/monitoringApi'

const mode = ref('home') // home | flashcards | learn | test | guide
const loading = ref(true)
const uploading = ref(false)
const generating = ref(false)
const error = ref('')
const studio = ref({ student_id: null, topics: [], records: [], week_plan: null, live_ai_configured: false, risk: null })
const selectedRecordId = ref(null)
const cards = ref([])
const cardIndex = ref(0)
const flipped = ref(false)
const knownIds = ref([])
const learningIds = ref([])
const quiz = ref([])
const quizIndex = ref(0)
const quizChoice = ref(null)
const quizScore = ref(0)
const quizDone = ref(false)
const revealed = ref(false)
const plan = ref(null)
const planTopic = ref('')
const topicPlan = ref(null)
const planBusy = ref(false)
const matchPairs = ref([])
const matchSelected = ref(null)
const matchSolved = ref([])
const matchDone = ref(false)

const files = computed(() => (studio.value.records || []).filter((record) => record.has_attachment))
const selectedFile = computed(() => files.value.find((record) => record.id === selectedRecordId.value) || null)
const focusTopic = computed(() => selectedFile.value?.attachment_name || selectedFile.value?.topic || '')
const currentCard = computed(() => cards.value[cardIndex.value] || null)
const currentQuestion = computed(() => quiz.value[quizIndex.value] || null)

const progressPct = computed(() => {
  if (!cards.value.length) return 0
  return Math.round((knownIds.value.length / cards.value.length) * 100)
})

const modes = [
  {
    id: 'flashcards',
    title: 'Flashcards',
    blurb: 'AI turns the file into terms and answers.',
    icon: 'M4 5h16v14H4zM8 9h8M8 13h5',
  },
  {
    id: 'test',
    title: 'Quiz',
    blurb: 'AI writes practice questions from the file.',
    icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  },
  {
    id: 'guide',
    title: 'Study guide',
    blurb: 'AI explains the file and lists what to review.',
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await fetchStudyStudio()
    studio.value = {
      ...data,
      risk: data.risk?.students?.[0] || data.risk || null,
    }
    if (!selectedRecordId.value) {
      selectedRecordId.value = files.value[0]?.id || null
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load study studio.'
  } finally {
    loading.value = false
  }
}

async function onUpload(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return
  const studentId = studio.value.student_id || studio.value.risk?.student_id
  if (!studentId) {
    error.value = 'Your student profile is not ready for file upload yet.'
    return
  }
  uploading.value = true
  error.value = ''
  try {
    const body = new FormData()
    const title = file.name.replace(/\.[^.]+$/, '') || 'Uploaded notes'
    body.append('subject_code', 'NOTES')
    body.append('subject_name', 'Uploaded notes')
    body.append('assessment_name', 'Uploaded file')
    body.append('topic', title)
    body.append('attachment', file)
    const saved = await createPerformanceRecord(studentId, body)
    await load()
    selectedRecordId.value = saved?.id || files.value[0]?.id || null
    uploading.value = false
    await openMode('guide')
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to upload that file. Use TXT, DOCX, or a text-based PDF.'
    uploading.value = false
  }
}

function usableCards(list) {
  return (Array.isArray(list) ? list : []).filter(
    (card) => card && String(card.front || '').trim() && String(card.back || '').trim(),
  )
}

function usableQuestions(list) {
  return (Array.isArray(list) ? list : []).filter(
    (question) => question && String(question.prompt || '').trim() && Array.isArray(question.choices) && question.choices.length,
  )
}

async function revealSession() {
  await nextTick()
  document.querySelector('.quizlet-studio .session')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function withTimeout(promise, ms = 8000) {
  return Promise.race([
    promise,
    new Promise((_, reject) => {
      setTimeout(() => reject(new Error('timeout')), ms)
    }),
  ])
}

async function makeTopicPlan() {
  const topic = planTopic.value.trim()
  if (!topic || planBusy.value) return
  planBusy.value = true
  error.value = ''
  try {
    topicPlan.value = await withTimeout(generateTopicStudyPlan(topic), 25000)
  } catch (err) {
    topicPlan.value = null
    error.value = err.response?.data?.message || 'The AI could not make a study plan for that topic.'
  } finally {
    planBusy.value = false
  }
}

async function openMode(nextMode) {
  if (!selectedRecordId.value) {
    error.value = 'Upload a file first, then choose flashcards, a quiz, or a study guide.'
    return
  }
  error.value = ''
  generating.value = true
  try {
    if (nextMode === 'flashcards') {
      const data = await withTimeout(generateStudyFlashcards(selectedRecordId.value), 25000)
      const incoming = usableCards(data?.cards)
      if (!incoming.length) throw new Error('empty')
      cards.value = incoming
      cardIndex.value = 0
      flipped.value = false
      knownIds.value = []
      learningIds.value = []
      mode.value = 'flashcards'
    } else if (nextMode === 'test') {
      const data = await withTimeout(generateStudyQuiz(selectedRecordId.value), 25000)
      const incoming = usableQuestions(data?.questions)
      if (!incoming.length) throw new Error('empty')
      quiz.value = incoming
      quizIndex.value = 0
      quizChoice.value = null
      quizScore.value = 0
      quizDone.value = false
      revealed.value = false
      mode.value = 'test'
    } else if (nextMode === 'guide') {
      const data = await withTimeout(generateStudyStudioPlan(selectedRecordId.value), 25000)
      if (!data?.plan) throw new Error('empty')
      plan.value = data
      mode.value = 'guide'
    }
    revealSession()
  } catch (err) {
    mode.value = 'home'
    error.value = err.response?.data?.message || 'The AI could not make a review from this file. Upload a TXT, DOCX, or text-based PDF and try again.'
  } finally {
    generating.value = false
  }
}

function buildMatchBoard(list) {
  const slice = list.slice(0, 6)
  const terms = slice.map((c, i) => ({ id: `t-${i}`, pair: i, text: c.front, kind: 'term' }))
  const defs = slice.map((c, i) => ({ id: `d-${i}`, pair: i, text: c.back, kind: 'def' }))
  matchPairs.value = [...terms, ...defs].sort(() => Math.random() - 0.5)
  matchSelected.value = null
  matchSolved.value = []
  matchDone.value = false
}

function goHome() {
  mode.value = 'home'
}

function nextCard(delta) {
  if (!cards.value.length) return
  cardIndex.value = (cardIndex.value + delta + cards.value.length) % cards.value.length
  flipped.value = false
}

function markCard(knewIt) {
  const id = cardIndex.value
  knownIds.value = knownIds.value.filter((x) => x !== id)
  learningIds.value = learningIds.value.filter((x) => x !== id)
  if (knewIt) knownIds.value = [...knownIds.value, id]
  else learningIds.value = [...learningIds.value, id]
  if (cardIndex.value < cards.value.length - 1) nextCard(1)
  else flipped.value = false
}

function selectChoice(index) {
  if (revealed.value || quizDone.value) return
  quizChoice.value = index
  revealed.value = true
  if (index === currentQuestion.value?.answer_index) quizScore.value += 1
}

function nextQuestion() {
  if (quizIndex.value >= quiz.value.length - 1) {
    quizDone.value = true
    return
  }
  quizIndex.value += 1
  quizChoice.value = null
  revealed.value = false
}

function onMatchTap(tile) {
  if (matchDone.value || matchSolved.value.includes(tile.pair)) return
  if (!matchSelected.value) {
    matchSelected.value = tile
    return
  }
  if (matchSelected.value.id === tile.id) {
    matchSelected.value = null
    return
  }
  if (matchSelected.value.pair === tile.pair && matchSelected.value.kind !== tile.kind) {
    matchSolved.value = [...matchSolved.value, tile.pair]
    matchSelected.value = null
    if (matchSolved.value.length >= Math.min(6, cards.value.length || 6)) matchDone.value = true
    return
  }
  matchSelected.value = tile
}

watch(selectedRecordId, () => {
  cards.value = []
  quiz.value = []
  plan.value = null
  flipped.value = false
  quizDone.value = false
  knownIds.value = []
  learningIds.value = []
  matchPairs.value = []
  mode.value = 'home'
})

onMounted(load)
</script>

<template>
  <section class="quizlet-studio">
    <header class="hero">
      <div class="hero-copy">
        <p class="eyebrow">Study Studio</p>
        <h2>Make a review from your file</h2>
        <p class="lead">Upload a file and the AI writes a study guide from it automatically. Flashcards and a quiz are still there if you want them. The chat is separate.</p>
      </div>
      <div class="hero-meta">
        <span class="chip">{{ studio.topics?.length || 0 }} study sets</span>
        <span class="chip soft">{{ studio.live_ai_configured ? 'Live AI ready' : 'Coach mode' }}</span>
      </div>
    </header>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading your study sets…</p>

    <template v-else>
      <form class="topic-plan" @submit.prevent="makeTopicPlan">
        <label>
          Study plan topic
          <input v-model="planTopic" maxlength="180" placeholder="Example: loops and nested conditionals" />
        </label>
        <button type="submit" class="pill ok" :disabled="planBusy || !planTopic.trim()">
          {{ planBusy ? 'Making plan…' : 'Make study plan' }}
        </button>
      </form>
      <article v-if="topicPlan" class="topic-result">
        <h3>{{ topicPlan.title }}</h3>
        <pre>{{ topicPlan.plan }}</pre>
      </article>

      <label class="upload">
        <input type="file" accept=".txt,.md,.csv,.pdf,.docx,text/plain,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" :disabled="uploading" @change="onUpload" />
        <span>{{ uploading ? 'Uploading and writing the study guide…' : 'Upload a file' }}</span>
        <small>TXT, DOCX, or a PDF with selectable text</small>
      </label>

      <div class="sets">
        <button
          v-for="item in files"
          :key="item.id"
          type="button"
          class="set"
          :class="{ active: selectedRecordId === item.id }"
          @click="selectedRecordId = item.id"
        >
          <div>
            <strong>{{ item.attachment_name || item.topic }}</strong>
            <span>Uploaded file</span>
          </div>
        </button>
        <p v-if="!files.length" class="muted empty">
          No file yet. Upload one and a study guide is written automatically.
        </p>
      </div>

      <p v-if="generating" class="muted">The AI is reading your file and writing the study guide…</p>

      <!-- HOME: mode picker -->
      <div v-if="mode === 'home'" class="modes" aria-label="Study modes">
        <button
          v-for="item in modes"
          :key="item.id"
          type="button"
          class="mode"
          :class="item.id"
          :disabled="!selectedRecordId || generating"
          @click="openMode(item.id)"
        >
          <span class="mode-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
              <path :d="item.icon" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </span>
          <strong>{{ item.title }}</strong>
          <small>{{ item.blurb }}</small>
        </button>
      </div>

      <!-- STUDY SESSION SHELL -->
      <div v-else class="session">
        <div class="session-bar">
          <button type="button" class="ghost" @click="goHome">← Study modes</button>
          <div class="session-title">
            <strong>{{ modes.find((m) => m.id === mode)?.title }}</strong>
            <span>{{ focusTopic }}</span>
          </div>
          <div v-if="mode === 'learn' || mode === 'flashcards'" class="progress-pill">
            <span class="bar"><i :style="{ width: `${progressPct}%` }" /></span>
            <em>{{ knownIds.length }}/{{ cards.length || 0 }} known</em>
          </div>
        </div>

        <!-- FLASHCARDS -->
        <div v-if="mode === 'flashcards' || mode === 'learn'" class="stage">
          <p class="progress-label">Card {{ cardIndex + 1 }} / {{ cards.length }}</p>
          <button type="button" class="flip-card" :class="{ flipped }" @click="flipped = !flipped">
            <span class="inner">
              <span class="face front">
                <small>Term · {{ cardIndex + 1 }} / {{ cards.length }}</small>
                <strong>{{ currentCard?.front }}</strong>
                <em>Click the card to flip</em>
              </span>
              <span class="face back">
                <small>Answer · {{ cardIndex + 1 }} / {{ cards.length }}</small>
                <strong>{{ currentCard?.back }}</strong>
                <em>Click to flip back</em>
              </span>
            </span>
          </button>
          <ul class="card-list">
            <li v-for="(card, index) in cards" :key="index" :class="{ on: index === cardIndex }">
              <button type="button" @click="cardIndex = index; flipped = false">
                <strong>{{ card.front }}</strong>
                <span>{{ card.back }}</span>
              </button>
            </li>
          </ul>

          <div class="controls">
            <button type="button" class="round" @click="nextCard(-1)" aria-label="Previous">‹</button>
            <template v-if="mode === 'learn'">
              <button type="button" class="pill danger" @click="markCard(false)">Still learning</button>
              <button type="button" class="pill ok" @click="markCard(true)">Knew it</button>
            </template>
            <button type="button" class="round" @click="nextCard(1)" aria-label="Next">›</button>
          </div>
        </div>

        <!-- TEST -->
        <div v-else-if="mode === 'test'" class="stage test-stage">
          <template v-if="quizDone">
            <div class="scoreboard">
              <p class="eyebrow">Results</p>
              <strong>{{ quizScore }} / {{ quiz.length }}</strong>
              <p class="score-copy">You got {{ quizScore }} out of {{ quiz.length }} correct. Review the ones you missed, then retake.</p>
              <div class="row-actions">
                <button type="button" class="pill ok" @click="openMode('test')">Retake test</button>
                <button type="button" class="ghost" @click="openMode('flashcards')">Review flashcards</button>
              </div>
            </div>
          </template>
          <template v-else>
            <p class="progress-label">Question {{ quizIndex + 1 }} of {{ quiz.length }}</p>
            <span class="quiz-progress" aria-hidden="true"><i :style="{ width: `${((quizIndex + (revealed ? 1 : 0)) / quiz.length) * 100}%` }" /></span>
            <h3 class="prompt">{{ currentQuestion?.prompt }}</h3>
            <div class="choices">
              <button
                v-for="(choice, index) in currentQuestion?.choices || []"
                :key="index"
                type="button"
                class="choice"
                :class="{
                  selected: quizChoice === index && !revealed,
                  correct: revealed && index === currentQuestion.answer_index,
                  wrong: revealed && quizChoice === index && index !== currentQuestion.answer_index,
                }"
                @click="selectChoice(index)"
              >
                <span class="letter">{{ String.fromCharCode(65 + index) }}</span>
                <span class="choice-label">{{ choice }}</span>
              </button>
            </div>
            <p v-if="revealed" class="explain">{{ currentQuestion?.explanation }}</p>
            <button v-if="revealed" type="button" class="pill ok" @click="nextQuestion">
              {{ quizIndex >= quiz.length - 1 ? 'See score' : 'Next question' }}
            </button>
          </template>
        </div>

        <!-- MATCH -->
        <div v-else-if="mode === 'match'" class="stage">
          <p v-if="matchDone" class="scoreboard">
            <strong>Set matched!</strong>
            <button type="button" class="pill ok" @click="buildMatchBoard(cards)">Play again</button>
          </p>
          <p v-else class="progress-label">Tap a term, then its definition</p>
          <div class="match-grid">
            <button
              v-for="tile in matchPairs"
              :key="tile.id"
              type="button"
              class="match-tile"
              :class="{
                selected: matchSelected?.id === tile.id,
                solved: matchSolved.includes(tile.pair),
                def: tile.kind === 'def',
              }"
              :disabled="matchSolved.includes(tile.pair)"
              @click="onMatchTap(tile)"
            >
              {{ tile.text }}
            </button>
          </div>
        </div>

        <!-- STUDY GUIDE -->
        <div v-else class="stage guide-stage">
          <h3>{{ plan?.title || 'Study guide' }}</h3>
          <pre class="guide-body">{{ plan?.plan }}</pre>
          <ul v-if="plan?.week?.length" class="week">
            <li v-for="day in plan.week" :key="day.day">
              <strong>{{ day.day }}</strong>
              <span>{{ day.focus }} · {{ day.minutes || day.duration_minutes || 60 }} min</span>
            </li>
          </ul>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.quizlet-studio {
  --ink: #282e3e;
  --muted: #586380;
  --line: #e4e6eb;
  --soft: #f6f7fb;
  --accent: #4255ff;
  --accent-deep: #282e3e;
  --ok: #178a4a;
  --danger: #d92d20;
  --shadow: 0 8px 24px rgba(40, 46, 62, 0.06);
  animation: enter 320ms ease both;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 16px;
  color: #282e3e;
  color-scheme: light;
  display: grid;
  gap: 1rem;
  padding: 1.25rem;
}

@keyframes enter {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

.hero {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  justify-content: space-between;
}

.eyebrow {
  color: #4255ff;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  margin: 0 0 0.35rem;
  text-transform: uppercase;
}

.hero h2 {
  color: var(--ink);
  font-size: clamp(1.55rem, 3vw, 2.1rem);
  letter-spacing: -0.03em;
  line-height: 1.15;
  margin: 0;
}

.lead,
.muted,
.progress-label,
.busy {
  color: var(--muted);
  margin: 0;
}

.explain {
  color: #243028;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
}

.lead {
  margin-top: 0.45rem;
  max-width: 46ch;
}

.hero-meta,
.row-actions,
.controls {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.chip {
  background: #eceeff;
  border-radius: 999px;
  color: #4255ff;
  font-size: 0.78rem;
  font-weight: 800;
  padding: 0.4rem 0.75rem;
}

.chip.soft {
  background: var(--soft);
  color: var(--muted);
}

.alert {
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 14px;
  color: #9f1239;
  margin: 0;
  padding: 0.75rem 0.9rem;
}

.topic-plan {
  align-items: end;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.topic-plan label {
  color: #282e3e;
  display: grid;
  flex: 1;
  font-size: 0.82rem;
  font-weight: 800;
  gap: 0.35rem;
  min-width: 220px;
}

.topic-plan input {
  border: 1.5px solid #d5dbe3;
  border-radius: 12px;
  color: #1a1d21;
  font: inherit;
  font-weight: 600;
  min-height: 46px;
  padding: 0.65rem 0.8rem;
}

.topic-result {
  background: #fff;
  border: 1px solid #e4e6eb;
  border-radius: 16px;
  color: #282e3e;
  display: grid;
  gap: 0.55rem;
  padding: 1rem;
}

.topic-result h3 {
  color: #282e3e;
  margin: 0;
}

.topic-result pre {
  color: #243028;
  font-family: inherit;
  line-height: 1.55;
  margin: 0;
  white-space: pre-wrap;
}

.upload {
  align-items: center;
  background: #f6f7fb;
  border: 1.5px dashed #98a2ff;
  border-radius: 16px;
  color: #282e3e;
  cursor: pointer;
  display: grid;
  gap: 0.15rem;
  justify-items: start;
  padding: 0.9rem 1rem;
}

.upload input {
  display: none;
}

.upload span {
  color: #4255ff;
  font-weight: 800;
}

.upload small {
  color: #586380;
}

.search {
  align-items: center;
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 999px;
  box-shadow: var(--shadow);
  display: flex;
  gap: 0.55rem;
  padding: 0.7rem 1rem;
}

.search svg {
  color: var(--muted);
  height: 18px;
  width: 18px;
}

.search input {
  border: 0;
  flex: 1;
  font: inherit;
  min-width: 0;
  outline: none;
}

.sr-only {
  height: 1px;
  overflow: hidden;
  position: absolute;
  width: 1px;
}

.sets {
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

.set {
  align-items: start;
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 16px;
  box-shadow: 0 8px 18px rgba(15, 23, 32, 0.04);
  cursor: pointer;
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
  padding: 0.9rem 1rem;
  text-align: left;
  transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
}

.set:hover,
.set.active {
  border-color: #4255ff;
  box-shadow: 0 10px 24px rgba(66, 85, 255, 0.12);
  transform: translateY(-2px);
}

.set strong {
  color: var(--ink);
  display: block;
}

.set span,
.set em {
  color: var(--muted);
  font-size: 0.82rem;
  font-style: normal;
}

.modes {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
}

.mode {
  align-items: center;
  background: #fff;
  border: 1px solid #e4e6eb;
  border-radius: 16px;
  color: #282e3e;
  color-scheme: light;
  cursor: pointer;
  display: grid;
  gap: 0.45rem;
  justify-items: center;
  min-height: 132px;
  padding: 1rem 0.75rem;
  text-align: center;
  transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
}

.mode:hover:not(:disabled) {
  border-color: var(--accent);
  box-shadow: var(--shadow);
  transform: translateY(-3px);
}

.mode:disabled {
  cursor: wait;
  opacity: 0.65;
}

.mode-icon {
  align-items: center;
  background: #eceeff;
  border-radius: 14px;
  color: #4255ff;
  display: grid;
  height: 44px;
  place-items: center;
  width: 44px;
}

.mode.learn .mode-icon {
  background: #e7f8f6;
  color: #139e9e;
}

.mode.test .mode-icon {
  background: #f4e9fb;
  color: #8b3fc7;
}

.mode.match .mode-icon {
  background: #fff6dc;
  color: #c98900;
}

.mode.guide .mode-icon {
  background: #e8f7ef;
  color: #0d7856;
}

.mode-icon svg {
  height: 22px;
  width: 22px;
}

.mode strong {
  color: #282e3e;
  font-size: 0.98rem;
}

.mode small {
  color: #586380;
  line-height: 1.35;
}

.session {
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 22px;
  box-shadow: var(--shadow);
  display: grid;
  gap: 1rem;
  padding: 1rem;
}

.session-bar {
  align-items: center;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: space-between;
}

.session-title {
  display: grid;
  gap: 0.1rem;
}

.session-title strong {
  color: var(--ink);
}

.session-title span {
  color: var(--muted);
  font-size: 0.86rem;
}

.progress-pill {
  align-items: center;
  display: flex;
  gap: 0.55rem;
}

.progress-pill .bar {
  background: #e8eef2;
  border-radius: 999px;
  display: block;
  height: 8px;
  overflow: hidden;
  width: 110px;
}

.progress-pill .bar i {
  background: linear-gradient(90deg, var(--accent), #34d399);
  display: block;
  height: 100%;
  transition: width 220ms ease;
}

.progress-pill em {
  color: var(--muted);
  font-size: 0.78rem;
  font-style: normal;
  font-weight: 700;
}

.ghost,
.pill,
.round {
  border: 0;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
}

.ghost {
  background: transparent;
  color: #4255ff;
  padding: 0.35rem 0.2rem;
}

.pill {
  background: var(--soft);
  border-radius: 999px;
  color: var(--ink);
  padding: 0.7rem 1.05rem;
}

.pill.ok {
  background: #4255ff;
  color: #fff;
}

.pill.danger {
  background: #ffe4e6;
  color: var(--danger);
}

.round {
  align-items: center;
  background: var(--soft);
  border-radius: 999px;
  color: var(--ink);
  display: grid;
  font-size: 1.4rem;
  height: 46px;
  place-items: center;
  width: 46px;
}

.stage {
  display: grid;
  gap: 0.9rem;
  justify-items: center;
  padding: 0.35rem 0 0.5rem;
  width: 100%;
}

.study-card {
  align-content: center;
  background: #fff;
  border: 1px solid #e4e6eb;
  border-radius: 16px;
  box-shadow: 0 10px 28px rgba(40, 46, 62, 0.08);
  color: #282e3e;
  display: grid;
  gap: 0.85rem;
  justify-items: center;
  min-height: 280px;
  padding: 1.6rem 1.5rem;
  text-align: center;
  width: min(100%, 680px);
}

.study-card h3 {
  color: #282e3e;
  font-size: clamp(1.45rem, 2.8vw, 2rem);
  font-weight: 700;
  line-height: 1.3;
  margin: 0;
}

.card-kicker {
  color: #586380;
  letter-spacing: 0.08em;
  margin: 0;
  text-transform: uppercase;
  font-size: 0.75rem;
  font-weight: 800;
}

.card-list {
  display: grid;
  gap: 0.45rem;
  list-style: none;
  margin: 0;
  padding: 0;
  width: min(100%, 640px);
}

.card-list button {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  cursor: pointer;
  display: grid;
  gap: 0.2rem;
  padding: 0.7rem 0.85rem;
  text-align: left;
  width: 100%;
}

.card-list li.on button {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px rgba(13, 120, 86, 0.15);
}

.card-list strong {
  color: var(--ink);
}

.card-list span {
  color: var(--muted);
  font-size: 0.88rem;
}

.flip-card {
  background: transparent;
  border: 0;
  color-scheme: light;
  cursor: pointer;
  height: 320px;
  max-width: 640px;
  padding: 0;
  perspective: 1400px;
  width: min(100%, 640px);
}

.inner {
  display: block;
  height: 100%;
  min-height: 320px;
  position: relative;
  transform-style: preserve-3d;
  transition: transform 560ms cubic-bezier(0.2, 0.8, 0.2, 1);
  width: 100%;
}

.flip-card.flipped .inner {
  transform: rotateY(180deg);
}

.face {
  align-content: center;
  backface-visibility: hidden;
  -webkit-backface-visibility: hidden;
  background: #fff;
  border: 1px solid #e4e6eb;
  border-radius: 18px;
  box-shadow: 0 16px 36px rgba(40, 46, 62, 0.12);
  color: #1a1d21;
  display: grid;
  gap: 0.75rem;
  inset: 0;
  justify-items: center;
  padding: 1.6rem;
  place-content: center;
  position: absolute;
  text-align: center;
}

.face.back {
  background: #4255ff;
  border-color: #4255ff;
  color: #fff;
  transform: rotateY(180deg);
}

.face small,
.face em {
  color: inherit;
  font-size: 0.78rem;
  font-style: normal;
  font-weight: 700;
  letter-spacing: 0.08em;
  opacity: 0.8;
  text-transform: uppercase;
}

.face strong {
  color: inherit;
  font-size: clamp(1.35rem, 2.8vw, 1.9rem);
  font-weight: 700;
  line-height: 1.35;
  max-width: 28ch;
}

.test-stage,
.guide-stage {
  justify-items: stretch;
  max-width: 720px;
  margin: 0 auto;
  width: 100%;
}

.quiz-progress {
  background: #e7ebf0;
  border-radius: 999px;
  display: block;
  height: 8px;
  overflow: hidden;
  width: 100%;
}

.quiz-progress i {
  background: #4257ff;
  display: block;
  height: 100%;
}

.prompt {
  color: #1a1d21;
  font-size: clamp(1.25rem, 2.4vw, 1.7rem);
  font-weight: 800;
  letter-spacing: -0.02em;
  line-height: 1.3;
  margin: 0.15rem 0 0.2rem;
}

.choices {
  display: grid;
  gap: 0.7rem;
  width: 100%;
}

.choice {
  align-items: center;
  background: #fff;
  border: 2px solid #d5dbe3;
  border-radius: 14px;
  color: #1a1d21;
  color-scheme: light;
  cursor: pointer;
  display: flex;
  gap: 0.85rem;
  min-height: 58px;
  padding: 0.85rem 1rem;
  text-align: left;
  transition: border-color 140ms ease, background 140ms ease, box-shadow 140ms ease;
}

.choice:hover {
  background: #f7f8fb;
  border-color: #98a2b3;
}

.choice-label {
  color: #1a1d21;
  font-size: 1.05rem;
  font-weight: 600;
  line-height: 1.35;
}

.choice .letter {
  align-items: center;
  background: #fff;
  border: 2px solid #c5ced8;
  border-radius: 999px;
  color: #1a1d21;
  display: grid;
  flex: 0 0 auto;
  font-size: 0.92rem;
  font-weight: 800;
  height: 34px;
  place-items: center;
  width: 34px;
}

.choice.selected {
  background: #f4f6ff;
  border-color: #4257ff;
  box-shadow: 0 0 0 3px rgba(66, 87, 255, 0.16);
}

.choice.selected .letter {
  background: #4257ff;
  border-color: #4257ff;
  color: #fff;
}

.choice.correct {
  background: #e8f8ef;
  border-color: #178a4a;
}

.choice.correct .choice-label,
.choice.correct {
  color: #14532d;
}

.choice.correct .letter {
  background: #178a4a;
  border-color: #178a4a;
  color: #fff;
}

.choice.wrong {
  background: #fdeeee;
  border-color: #d92d20;
}

.choice.wrong .choice-label,
.choice.wrong {
  color: #9f1239;
}

.choice.wrong .letter {
  background: #d92d20;
  border-color: #d92d20;
  color: #fff;
}

.scoreboard {
  display: grid;
  gap: 0.65rem;
  justify-items: center;
  padding: 1.25rem 0 0.4rem;
  text-align: center;
}

.scoreboard strong {
  color: #282e3e;
  font-size: 3.4rem;
  font-weight: 800;
  letter-spacing: -0.04em;
  line-height: 1;
}

.score-copy {
  color: #3d4555;
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0;
  max-width: 36ch;
}

.match-grid {
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  width: 100%;
}

.match-tile {
  background: #fff;
  border: 2px solid #d5dbe3;
  border-radius: 14px;
  color: #1a1d21;
  color-scheme: light;
  cursor: pointer;
  font-weight: 600;
  min-height: 88px;
  padding: 0.75rem;
  text-align: left;
  transition: transform 140ms ease, border-color 140ms ease, background 140ms ease;
}

.match-tile.def {
  background: var(--soft);
}

.match-tile.selected {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(13, 120, 86, 0.15);
  transform: scale(1.02);
}

.match-tile.solved {
  background: #ecfdf5;
  border-color: var(--ok);
  opacity: 0.7;
}

.guide-body {
  background: var(--soft);
  border-radius: 16px;
  color: var(--ink);
  font-family: inherit;
  line-height: 1.55;
  margin: 0;
  padding: 1rem;
  white-space: pre-wrap;
}

.week {
  display: grid;
  gap: 0.45rem;
  list-style: none;
  margin: 0;
  padding: 0;
}

.week li {
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 12px;
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
  padding: 0.7rem 0.85rem;
}

.empty {
  grid-column: 1 / -1;
}

.busy {
  font-weight: 600;
}

@media (max-width: 720px) {
  .quizlet-studio {
    padding: 1rem;
  }

  .face {
    min-height: 240px;
  }

  .inner {
    min-height: 240px;
  }
}
</style>
