<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {
  fetchMyRisk,
  fetchMySentPlans,
  fetchPerformanceRecords,
  fetchStudyStudio,
  generateStudyFlashcards,
  generateStudyQuiz,
  generateStudyStudioPlan,
} from '../services/monitoringApi'

const mode = ref('home') // home | flashcards | learn | test | guide
const loading = ref(true)
const error = ref('')
const topicQuery = ref('')
const studio = ref({ topics: [], records: [], week_plan: null, live_ai_configured: false, risk: null })
const selectedTopic = ref('')
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
const matchPairs = ref([])
const matchSelected = ref(null)
const matchSolved = ref([])
const matchDone = ref(false)

const focusTopic = computed(() => selectedTopic.value || studio.value.topics?.[0]?.topic || 'General academic recovery')
const currentCard = computed(() => cards.value[cardIndex.value] || null)
const currentQuestion = computed(() => quiz.value[quizIndex.value] || null)

const filteredTopics = computed(() => {
  const q = topicQuery.value.trim().toLowerCase()
  const list = studio.value.topics || []
  if (!q) return list
  return list.filter((item) =>
    [item.topic, item.subject_code, item.subject_name, item.assessment_name]
      .filter(Boolean)
      .some((v) => String(v).toLowerCase().includes(q)),
  )
})

const progressPct = computed(() => {
  if (!cards.value.length) return 0
  return Math.round((knownIds.value.length / cards.value.length) * 100)
})

const modes = [
  {
    id: 'flashcards',
    title: 'Flashcards',
    blurb: 'Flip terms and definitions — classic Quizlet feel.',
    icon: 'M4 5h16v14H4zM8 9h8M8 13h5',
  },
  {
    id: 'learn',
    title: 'Learn',
    blurb: 'Mark Knew it / Still learning to track mastery.',
    icon: 'M12 3l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7l8-4z',
  },
  {
    id: 'test',
    title: 'Test',
    blurb: 'Practice quiz with instant feedback.',
    icon: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  },
  {
    id: 'match',
    title: 'Match',
    blurb: 'Tap matching term ↔ definition pairs.',
    icon: 'M8 7h3M13 7h3M8 12h8M8 17h5M5 5h14v14H5z',
  },
  {
    id: 'guide',
    title: 'Study guide',
    blurb: 'AI recovery plan from your weak topics.',
    icon: 'M7 3h7l4 4v14H7zM14 3v5h5M10 12h5M10 16h5',
  },
]

function localFlashcards(topic) {
  return [
    { front: `What is the core idea of ${topic}?`, back: 'State the main definition in one sentence, then give one example.' },
    { front: `Common mistake in ${topic}`, back: 'Skipping prerequisites or mixing similar terms. Recheck definitions first.' },
    { front: `How do I practice ${topic} today?`, back: 'Do 3 short problems, then explain your solution out loud.' },
    { front: `When is ${topic} used?`, back: 'In class activities, quizzes, and follow-up graded work.' },
    { front: 'Quick check', back: `If you cannot explain ${topic} without notes, review again.` },
    { front: 'Ask your professor', back: `Which part of ${topic} matters most for the next assessment?` },
    { front: 'Memory tip', back: 'Link the idea to a real example so it sticks longer.' },
    { front: 'Next step', back: 'Take a short practice quiz after one flashcard pass.' },
  ]
}

function localQuiz(topic) {
  return [
    {
      prompt: `Best first step when studying ${topic}?`,
      choices: ['Memorize random facts', 'Review key definitions', 'Skip to hardest item', 'Ignore instructor notes'],
      answer_index: 1,
      explanation: 'Start with clear definitions before harder practice.',
    },
    {
      prompt: `Why was ${topic} flagged?`,
      choices: ['Already mastered', 'Weak area to recover', 'Optional forever', 'Replaces all grades'],
      answer_index: 1,
      explanation: 'Professor topic logs highlight where support is needed.',
    },
    {
      prompt: 'Most effective practice loop?',
      choices: ['Read once only', 'Flashcards → quiz → review misses', 'Only watch videos', 'Avoid practice'],
      answer_index: 1,
      explanation: 'Active recall plus checking mistakes builds mastery.',
    },
    {
      prompt: 'What should you bring to consultation?',
      choices: ['No questions', 'Specific confusing steps', 'Only final answers', 'Unrelated topics'],
      answer_index: 1,
      explanation: 'Specific questions help instructors coach faster.',
    },
    {
      prompt: 'When is a topic ready?',
      choices: ['You can explain and solve without notes', 'You recognized the title', 'A friend said it is easy', 'You opened the file once'],
      answer_index: 0,
      explanation: 'True readiness means you can teach and apply it.',
    },
  ]
}

function localPlan(topic) {
  return {
    title: `Study guide · ${topic}`,
    plan: `Focus: ${topic}\n\nDay 1: Review notes and mark confusing parts.\nDay 2: Flip flashcards twice and note misses.\nDay 3: Take a practice test and check explanations.\nDay 4: Rework the weakest items from instructor feedback.\nDay 5: Teach the topic out loud and list questions for your professor.`,
    week: [
      { day: 'Day 1', focus: 'Diagnose gaps', minutes: 45 },
      { day: 'Day 2', focus: 'Flashcard reps', minutes: 60 },
      { day: 'Day 3', focus: 'Practice test', minutes: 45 },
      { day: 'Day 4', focus: 'Fix misses', minutes: 60 },
      { day: 'Day 5', focus: 'Teach & ask', minutes: 40 },
    ],
    source: 'local-coach',
  }
}

function buildTopicsFromLegacy(riskPayload, records, sentPlans) {
  const topics = []
  const seen = new Set()
  for (const record of records || []) {
    const key = `${record.subject_code}|${record.topic}`.toLowerCase()
    if (seen.has(key) || !record.topic) continue
    seen.add(key)
    topics.push({
      topic: record.topic,
      subject_code: record.subject_code,
      subject_name: record.subject_name,
      assessment_name: record.assessment_name,
      percent: record.max_score > 0 ? Math.round((Number(record.score) / Number(record.max_score)) * 1000) / 10 : null,
      source: 'professor',
      terms: 8,
    })
  }
  for (const planItem of sentPlans || []) {
    const key = `${planItem.subject_code}|${planItem.topic}`.toLowerCase()
    if (!planItem.topic || seen.has(key)) continue
    seen.add(key)
    topics.push({ topic: planItem.topic, subject_code: planItem.subject_code, source: 'professor', terms: 8 })
  }
  const student = riskPayload?.students?.[0]
  for (const subject of student?.subjects || []) {
    if (!['high', 'moderate'].includes(subject.risk_level)) continue
    const key = `${subject.subject_code}|grade-focus`.toLowerCase()
    if (seen.has(key)) continue
    seen.add(key)
    topics.push({
      topic: `${subject.subject_name || subject.subject_code} fundamentals`,
      subject_code: subject.subject_code,
      subject_name: subject.subject_name,
      percent: subject.average_grade,
      source: 'grades',
      terms: 8,
    })
  }
  return { topics, student }
}

async function loadLegacyStudio() {
  const risk = await fetchMyRisk()
  const student = risk.students?.[0]
  let records = []
  let sentPlans = []
  if (student?.student_id) {
    try {
      records = (await fetchPerformanceRecords(student.student_id)).records || []
    } catch {
      records = []
    }
  }
  try {
    sentPlans = (await fetchMySentPlans()).plans || []
  } catch {
    sentPlans = []
  }
  const built = buildTopicsFromLegacy(risk, records, sentPlans)
  return {
    topics: built.topics,
    records,
    risk: built.student || student || null,
    week_plan: null,
    live_ai_configured: true,
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    try {
      const data = await fetchStudyStudio()
      studio.value = {
        ...data,
        risk: data.risk?.students?.[0] || data.risk || null,
      }
    } catch {
      studio.value = await loadLegacyStudio()
    }
    if (!selectedTopic.value && studio.value.topics?.[0]?.topic) {
      selectedTopic.value = studio.value.topics[0].topic
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Unable to load study studio.'
  } finally {
    loading.value = false
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

async function refreshFlashcards() {
  try {
    const data = await withTimeout(generateStudyFlashcards(focusTopic.value))
    const incoming = usableCards(data?.cards)
    if (incoming.length) {
      cards.value = incoming
      if (mode.value === 'match') buildMatchBoard(cards.value)
      return
    }
  } catch {
    /* keep the local set already on screen */
  }
}

async function refreshQuiz() {
  try {
    const data = await withTimeout(generateStudyQuiz(focusTopic.value))
    const incoming = usableQuestions(data?.questions)
    if (incoming.length) quiz.value = incoming
  } catch {
    /* keep the local quiz already on screen */
  }
}

async function openMode(nextMode) {
  if (!focusTopic.value) {
    error.value = 'Pick a study set / topic first.'
    return
  }
  error.value = ''
  if (nextMode === 'flashcards' || nextMode === 'learn' || nextMode === 'match') {
    cards.value = usableCards(cards.value).length ? cards.value : localFlashcards(focusTopic.value)
    cardIndex.value = 0
    flipped.value = false
    knownIds.value = []
    learningIds.value = []
    if (nextMode === 'match') buildMatchBoard(cards.value)
    mode.value = nextMode
    revealSession()
    refreshFlashcards()
    return
  }
  if (nextMode === 'test') {
    quiz.value = usableQuestions(quiz.value).length ? quiz.value : localQuiz(focusTopic.value)
    quizIndex.value = 0
    quizChoice.value = null
    quizScore.value = 0
    quizDone.value = false
    revealed.value = false
    mode.value = 'test'
    revealSession()
    refreshQuiz()
    return
  }
  if (nextMode === 'guide') {
    plan.value = localPlan(focusTopic.value)
    mode.value = 'guide'
    try {
      const data = await withTimeout(generateStudyStudioPlan(focusTopic.value))
      if (data?.plan) plan.value = data
    } catch {
      /* local guide stays */
    }
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

watch(selectedTopic, () => {
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
        <p class="eyebrow">CDM Study Studio</p>
        <h2>How do you want to study?</h2>
        <p class="lead">
          Quizlet-style practice from your weak topics — flashcards, learn, test, match, and an AI study guide.
        </p>
      </div>
      <div class="hero-meta">
        <span class="chip">{{ studio.topics?.length || 0 }} study sets</span>
        <span class="chip soft">{{ studio.live_ai_configured ? 'Live AI ready' : 'Coach mode' }}</span>
      </div>
    </header>

    <p v-if="error" class="alert" role="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading your study sets…</p>

    <template v-else>
      <div class="search-row">
        <label class="search">
          <span class="sr-only">Search topics</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" stroke-linecap="round" />
          </svg>
          <input v-model="topicQuery" type="search" placeholder="Search for a topic or subject…" />
        </label>
      </div>

      <div class="sets">
        <button
          v-for="item in filteredTopics"
          :key="`${item.subject_code}-${item.topic}`"
          type="button"
          class="set"
          :class="{ active: selectedTopic === item.topic }"
          @click="selectedTopic = item.topic"
        >
          <div>
            <strong>{{ item.topic }}</strong>
            <span>{{ item.subject_code || 'General' }} · {{ item.source === 'professor' ? 'From professor' : 'From grades' }}</span>
          </div>
          <em>{{ item.percent != null ? `${item.percent}%` : `${item.terms || 8} terms` }}</em>
        </button>
        <p v-if="!filteredTopics.length" class="muted empty">
          No study sets yet. When your professor logs quiz topics — or grades show risk — they appear here.
        </p>
      </div>

      <!-- HOME: mode picker -->
      <div v-if="mode === 'home'" class="modes" aria-label="Study modes">
        <button
          v-for="item in modes"
          :key="item.id"
          type="button"
          class="mode"
          :disabled="!focusTopic"
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
          <article class="study-card">
            <p class="card-kicker">{{ flipped ? 'Definition' : 'Term' }} · {{ cardIndex + 1 }} / {{ cards.length }}</p>
            <h3>{{ flipped ? currentCard?.back : currentCard?.front }}</h3>
            <button type="button" class="pill ok" @click="flipped = !flipped">
              {{ flipped ? 'Show term' : 'Show answer' }}
            </button>
          </article>
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
              <p>Nice work. Review misses in Flashcards or Learn, then retake.</p>
              <div class="row-actions">
                <button type="button" class="pill ok" @click="openMode('test')">Retake test</button>
                <button type="button" class="ghost" @click="openMode('flashcards')">Review flashcards</button>
              </div>
            </div>
          </template>
          <template v-else>
            <p class="progress-label">Question {{ quizIndex + 1 }} / {{ quiz.length }}</p>
            <h3 class="prompt">{{ currentQuestion?.prompt }}</h3>
            <div class="choices">
              <button
                v-for="(choice, index) in currentQuestion?.choices || []"
                :key="index"
                type="button"
                class="choice"
                :class="{
                  selected: quizChoice === index,
                  correct: revealed && index === currentQuestion.answer_index,
                  wrong: revealed && quizChoice === index && index !== currentQuestion.answer_index,
                }"
                @click="selectChoice(index)"
              >
                <span class="letter">{{ String.fromCharCode(65 + index) }}</span>
                {{ choice }}
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
  --ink: #0f1720;
  --muted: #5b6570;
  --line: #e3e8ee;
  --soft: #f4f7fb;
  --accent: #0d7856;
  --accent-deep: #065f46;
  --ok: #0f9f6e;
  --danger: #e11d48;
  --shadow: 0 14px 40px rgba(15, 23, 32, 0.08);
  animation: enter 320ms ease both;
  background:
    radial-gradient(circle at 12% 0%, rgba(13, 120, 86, 0.1), transparent 42%),
    linear-gradient(180deg, #ffffff 0%, #f7faf8 100%);
  border: 1px solid var(--line);
  border-radius: 24px;
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
  color: var(--accent);
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
.explain,
.busy {
  color: var(--muted);
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
  background: #e7f6ef;
  border-radius: 999px;
  color: var(--accent-deep);
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
  border-color: var(--accent);
  box-shadow: 0 12px 28px rgba(13, 120, 86, 0.12);
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
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
}

.mode {
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 18px;
  cursor: pointer;
  display: grid;
  gap: 0.35rem;
  justify-items: start;
  min-height: 148px;
  padding: 1rem;
  text-align: left;
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
  background: #e8f7f0;
  border-radius: 14px;
  color: var(--accent-deep);
  display: grid;
  height: 42px;
  place-items: center;
  width: 42px;
}

.mode-icon svg {
  height: 22px;
  width: 22px;
}

.mode strong {
  color: var(--ink);
  font-size: 1.05rem;
}

.mode small {
  color: var(--muted);
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
  color: var(--accent-deep);
  padding: 0.35rem 0.2rem;
}

.pill {
  background: var(--soft);
  border-radius: 999px;
  color: var(--ink);
  padding: 0.7rem 1.05rem;
}

.pill.ok {
  background: var(--accent);
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
  background: linear-gradient(160deg, #064e3b, #0d7856 58%, #059669);
  border-radius: 22px;
  box-shadow: 0 18px 40px rgba(6, 78, 59, 0.22);
  color: #fff;
  display: grid;
  gap: 0.85rem;
  justify-items: start;
  min-height: 220px;
  padding: 1.4rem 1.5rem;
  width: min(100%, 640px);
}

.study-card h3 {
  color: #fff;
  font-size: clamp(1.2rem, 2.4vw, 1.6rem);
  line-height: 1.35;
  margin: 0;
}

.card-kicker {
  letter-spacing: 0.08em;
  margin: 0;
  opacity: 0.8;
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
  cursor: pointer;
  max-width: 560px;
  perspective: 1400px;
  width: min(100%, 560px);
}

.inner {
  display: block;
  min-height: 280px;
  position: relative;
  transform-style: preserve-3d;
  transition: transform 480ms cubic-bezier(0.2, 0.8, 0.2, 1);
  width: 100%;
}

.flip-card.flipped .inner {
  transform: rotateY(180deg);
}

.face {
  align-content: center;
  backface-visibility: hidden;
  background: linear-gradient(160deg, #064e3b, #0d7856 55%, #059669);
  border-radius: 22px;
  box-shadow: 0 18px 40px rgba(6, 78, 59, 0.28);
  color: #fff;
  display: grid;
  gap: 0.65rem;
  inset: 0;
  justify-items: center;
  padding: 1.6rem;
  place-content: center;
  position: absolute;
  text-align: center;
}

.face.back {
  background: linear-gradient(160deg, #0b3b2e, #116b4d 50%, #1f8f66);
  transform: rotateY(180deg);
}

.face small,
.face em {
  font-size: 0.78rem;
  letter-spacing: 0.08em;
  opacity: 0.8;
  text-transform: uppercase;
}

.face strong {
  font-size: clamp(1.15rem, 2.6vw, 1.55rem);
  font-weight: 750;
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

.prompt {
  color: var(--ink);
  font-size: 1.2rem;
  margin: 0;
}

.choices {
  display: grid;
  gap: 0.5rem;
  width: 100%;
}

.choice {
  align-items: center;
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 14px;
  cursor: pointer;
  display: flex;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  text-align: left;
  transition: border-color 140ms ease, background 140ms ease;
}

.choice:hover {
  border-color: #9ad4bb;
}

.choice .letter {
  align-items: center;
  background: var(--soft);
  border-radius: 999px;
  display: grid;
  flex: 0 0 auto;
  font-weight: 800;
  height: 28px;
  place-items: center;
  width: 28px;
}

.choice.correct {
  background: #ecfdf5;
  border-color: var(--ok);
}

.choice.wrong {
  background: #fff1f2;
  border-color: var(--danger);
}

.scoreboard {
  display: grid;
  gap: 0.65rem;
  justify-items: start;
  padding: 0.5rem 0;
}

.scoreboard strong {
  color: var(--accent-deep);
  font-size: 2.4rem;
  line-height: 1;
}

.match-grid {
  display: grid;
  gap: 0.55rem;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  width: 100%;
}

.match-tile {
  background: #fff;
  border: 1.5px solid var(--line);
  border-radius: 14px;
  cursor: pointer;
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
