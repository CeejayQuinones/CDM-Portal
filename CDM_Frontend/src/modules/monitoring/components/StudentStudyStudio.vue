<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  askAiHelp,
  fetchMyRisk,
  fetchMySentPlans,
  fetchPerformanceRecords,
  fetchStudyStudio,
  generateStudyFlashcards,
  generateStudyQuiz,
  generateStudyStudioPlan,
} from '../services/monitoringApi'

const tab = ref('flashcards')
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const studio = ref({ topics: [], records: [], week_plan: null, live_ai_configured: false, risk: null })
const selectedTopic = ref('')
const cards = ref([])
const cardIndex = ref(0)
const flipped = ref(false)
const quiz = ref([])
const quizIndex = ref(0)
const quizChoice = ref(null)
const quizScore = ref(0)
const quizDone = ref(false)
const revealed = ref(false)
const plan = ref(null)

const focusTopic = computed(() => selectedTopic.value || studio.value.topics?.[0]?.topic || 'General academic recovery')
const currentCard = computed(() => cards.value[cardIndex.value] || null)
const currentQuestion = computed(() => quiz.value[quizIndex.value] || null)
const studentId = computed(() => studio.value.risk?.student_id || studio.value.risk?.students?.[0]?.student_id || null)

function extractJson(text) {
  const match = String(text || '').match(/\{[\s\S]*\}/)
  if (!match) return null
  try {
    return JSON.parse(match[0])
  } catch {
    return null
  }
}

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
    title: `Study plan · ${topic}`,
    plan: `Focus: ${topic}\n\nDay 1: Review notes and mark confusing parts.\nDay 2: Make flashcards and practice them twice.\nDay 3: Answer a short practice quiz and check explanations.\nDay 4: Rework the weakest items from instructor feedback.\nDay 5: Teach the topic out loud and list questions for your professor.`,
    week: [],
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
    })
  }
  for (const planItem of sentPlans || []) {
    const key = `${planItem.subject_code}|${planItem.topic}`.toLowerCase()
    if (!planItem.topic || seen.has(key)) continue
    seen.add(key)
    topics.push({ topic: planItem.topic, subject_code: planItem.subject_code, source: 'professor' })
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

async function askStructured(prompt) {
  if (!studentId.value) throw new Error('No student profile linked. Run the demo seed script first.')
  const help = await askAiHelp(studentId.value, prompt)
  return help?.reply || help?.advice || ''
}

async function makeFlashcards() {
  busy.value = true
  error.value = ''
  try {
    try {
      const data = await generateStudyFlashcards(focusTopic.value)
      cards.value = data.cards || []
    } catch {
      try {
        const reply = await askStructured(
          `Create exactly 8 study flashcards as JSON only with shape {"cards":[{"front":"...","back":"..."}]} for topic: ${focusTopic.value}. No markdown.`,
        )
        const parsed = extractJson(reply)
        cards.value = Array.isArray(parsed?.cards) && parsed.cards.length ? parsed.cards : localFlashcards(focusTopic.value)
      } catch {
        cards.value = localFlashcards(focusTopic.value)
      }
    }
    cardIndex.value = 0
    flipped.value = false
    tab.value = 'flashcards'
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Unable to generate flashcards.'
  } finally {
    busy.value = false
  }
}

async function makeQuiz() {
  busy.value = true
  error.value = ''
  try {
    try {
      const data = await generateStudyQuiz(focusTopic.value)
      quiz.value = data.questions || []
    } catch {
      try {
        const reply = await askStructured(
          `Create exactly 5 multiple-choice questions as JSON only {"questions":[{"prompt":"...","choices":["A","B","C","D"],"answer_index":0,"explanation":"..."}]} for topic: ${focusTopic.value}. No markdown.`,
        )
        const parsed = extractJson(reply)
        quiz.value = Array.isArray(parsed?.questions) && parsed.questions.length ? parsed.questions : localQuiz(focusTopic.value)
      } catch {
        quiz.value = localQuiz(focusTopic.value)
      }
    }
    quizIndex.value = 0
    quizChoice.value = null
    quizScore.value = 0
    quizDone.value = false
    revealed.value = false
    tab.value = 'quiz'
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Unable to generate quiz.'
  } finally {
    busy.value = false
  }
}

async function makePlan() {
  busy.value = true
  error.value = ''
  try {
    try {
      plan.value = await generateStudyStudioPlan(focusTopic.value)
    } catch {
      try {
        const reply = await askStructured(
          `Write a friendly 5-day recovery study plan for weak topic: ${focusTopic.value}. Plain text.`,
        )
        plan.value = {
          title: `Study plan · ${focusTopic.value}`,
          plan: reply || localPlan(focusTopic.value).plan,
          week: [],
          source: 'ai-help',
        }
      } catch {
        plan.value = localPlan(focusTopic.value)
      }
    }
    tab.value = 'plan'
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Unable to generate study plan.'
  } finally {
    busy.value = false
  }
}

function nextCard(delta) {
  if (!cards.value.length) return
  cardIndex.value = (cardIndex.value + delta + cards.value.length) % cards.value.length
  flipped.value = false
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

watch(selectedTopic, () => {
  cards.value = []
  quiz.value = []
  plan.value = null
  flipped.value = false
  quizDone.value = false
})

onMounted(load)
</script>

<template>
  <section class="studio">
    <header class="studio-head">
      <div>
        <p class="eyebrow">Study Studio</p>
        <h3>Learn like Quizlet — built from your weak topics</h3>
        <p class="sub">
          AI turns instructor feedback and low areas into flashcards, practice quizzes, and a study plan.
          {{ studio.live_ai_configured ? 'Live AI is on.' : 'Coach mode is available if AI is offline.' }}
        </p>
      </div>
    </header>

    <p v-if="error" class="alert">{{ error }}</p>
    <p v-if="loading" class="muted">Loading your study topics…</p>

    <template v-else>
      <div class="topics">
        <button
          v-for="item in studio.topics"
          :key="`${item.subject_code}-${item.topic}`"
          type="button"
          class="topic"
          :class="{ active: selectedTopic === item.topic }"
          @click="selectedTopic = item.topic"
        >
          <strong>{{ item.topic }}</strong>
          <span>{{ item.subject_code || 'General' }} · {{ item.source === 'professor' ? 'From professor' : 'From grades' }}</span>
          <em v-if="item.percent != null">{{ item.percent }}%</em>
        </button>
        <p v-if="!studio.topics?.length" class="muted empty">
          No weak topics yet. When your professor logs quiz/topic results — or grades show risk — they appear here.
        </p>
      </div>

      <div class="actions">
        <button type="button" class="btn primary" :disabled="busy" @click="makeFlashcards">
          {{ busy && tab === 'flashcards' ? 'Generating…' : 'Generate flashcards' }}
        </button>
        <button type="button" class="btn" :disabled="busy" @click="makeQuiz">
          {{ busy && tab === 'quiz' ? 'Generating…' : 'Practice quiz' }}
        </button>
        <button type="button" class="btn" :disabled="busy" @click="makePlan">
          {{ busy && tab === 'plan' ? 'Generating…' : 'Build study plan' }}
        </button>
      </div>

      <nav class="tabs">
        <button type="button" :class="{ on: tab === 'flashcards' }" @click="tab = 'flashcards'">Flashcards</button>
        <button type="button" :class="{ on: tab === 'quiz' }" @click="tab = 'quiz'">Quiz</button>
        <button type="button" :class="{ on: tab === 'plan' }" @click="tab = 'plan'">Study plan</button>
      </nav>

      <div v-if="tab === 'flashcards'" class="panel">
        <p v-if="!cards.length" class="muted">Pick a topic, then generate flashcards to flip and review.</p>
        <template v-else>
          <p class="progress">Card {{ cardIndex + 1 }} / {{ cards.length }} · {{ focusTopic }}</p>
          <button type="button" class="flash" :class="{ flipped }" @click="flipped = !flipped">
            <span class="face front">{{ currentCard?.front }}</span>
            <span class="face back">{{ currentCard?.back }}</span>
          </button>
          <p class="hint">Tap card to flip</p>
          <div class="nav-row">
            <button type="button" class="btn" @click="nextCard(-1)">Previous</button>
            <button type="button" class="btn" @click="nextCard(1)">Next</button>
          </div>
        </template>
      </div>

      <div v-else-if="tab === 'quiz'" class="panel">
        <p v-if="!quiz.length" class="muted">Generate a sample quiz to practice the selected weak topic.</p>
        <template v-else-if="quizDone">
          <div class="score">
            <strong>{{ quizScore }} / {{ quiz.length }}</strong>
            <p>Nice work. Review misses with flashcards, then try again.</p>
            <button type="button" class="btn primary" @click="makeQuiz">Retake quiz</button>
          </div>
        </template>
        <template v-else>
          <p class="progress">Question {{ quizIndex + 1 }} / {{ quiz.length }}</p>
          <h4 class="q">{{ currentQuestion?.prompt }}</h4>
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
              {{ choice }}
            </button>
          </div>
          <p v-if="revealed" class="explain">{{ currentQuestion?.explanation }}</p>
          <button v-if="revealed" type="button" class="btn primary" @click="nextQuestion">
            {{ quizIndex >= quiz.length - 1 ? 'See score' : 'Next question' }}
          </button>
        </template>
      </div>

      <div v-else class="panel">
        <p v-if="!plan" class="muted">Generate a recovery study plan focused on your weak topic.</p>
        <template v-else>
          <h4>{{ plan.title }}</h4>
          <pre class="plan-body">{{ plan.plan }}</pre>
          <ul v-if="plan.week?.length" class="week">
            <li v-for="day in plan.week" :key="day.day">
              <strong>{{ day.day }}</strong>
              <span>{{ day.focus }} · {{ day.minutes || day.duration_minutes || 60 }} min</span>
            </li>
          </ul>
        </template>
      </div>
    </template>
  </section>
</template>

<style scoped>
.studio {
  --ink: #14231c;
  --muted: #5f6d66;
  --line: #d7e0da;
  --soft: #f3f7f4;
  --accent: #0f6b3c;
  --accent-2: #1f8f55;
  background:
    radial-gradient(circle at top right, rgba(31, 143, 85, 0.12), transparent 40%),
    linear-gradient(180deg, #fbfcfb, #f4f8f5);
  border: 1px solid var(--line);
  border-radius: 22px;
  padding: 1.1rem;
  display: grid;
  gap: 0.9rem;
}
.studio-head h3 {
  margin: 0.15rem 0;
  font-size: 1.35rem;
  letter-spacing: -0.02em;
  color: var(--ink);
}
.eyebrow {
  margin: 0;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-size: 0.72rem;
  font-weight: 700;
  color: var(--accent);
}
.sub,
.muted,
.hint,
.progress,
.explain {
  color: var(--muted);
  margin: 0;
}
.alert {
  margin: 0;
  padding: 0.7rem 0.85rem;
  border-radius: 12px;
  background: #fdecec;
  color: #8a1f1f;
}
.topics {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 0.55rem;
}
.topic {
  text-align: left;
  border: 1px solid var(--line);
  background: #fff;
  border-radius: 14px;
  padding: 0.75rem;
  display: grid;
  gap: 0.2rem;
  cursor: pointer;
}
.topic.active {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px rgba(15, 107, 60, 0.12);
}
.topic strong {
  color: var(--ink);
}
.topic span,
.topic em {
  font-size: 0.82rem;
  color: var(--muted);
  font-style: normal;
}
.actions,
.nav-row,
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.btn {
  border: 1px solid var(--line);
  background: #fff;
  color: var(--ink);
  border-radius: 999px;
  padding: 0.55rem 0.95rem;
  font-weight: 600;
  cursor: pointer;
}
.btn.primary,
.tabs button.on {
  background: linear-gradient(135deg, var(--accent), var(--accent-2));
  color: #fff;
  border-color: transparent;
}
.btn:disabled {
  opacity: 0.6;
  cursor: wait;
}
.tabs button {
  border: 0;
  background: transparent;
  color: var(--muted);
  font-weight: 700;
  padding: 0.45rem 0.8rem;
  border-radius: 999px;
  cursor: pointer;
}
.panel {
  background: rgba(255, 255, 255, 0.8);
  border: 1px solid var(--line);
  border-radius: 18px;
  padding: 1rem;
  display: grid;
  gap: 0.75rem;
}
.flash {
  position: relative;
  min-height: 220px;
  border: 0;
  border-radius: 18px;
  background: linear-gradient(160deg, #123525, #1d6b45);
  color: #fff;
  cursor: pointer;
  perspective: 1000px;
  overflow: hidden;
}
.face {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  padding: 1.4rem;
  text-align: center;
  font-size: 1.15rem;
  font-weight: 650;
  line-height: 1.4;
  transition: opacity 0.25s ease, transform 0.25s ease;
}
.front {
  opacity: 1;
}
.back {
  opacity: 0;
  transform: translateY(8px);
}
.flash.flipped .front {
  opacity: 0;
  transform: translateY(-8px);
}
.flash.flipped .back {
  opacity: 1;
  transform: none;
}
.q {
  margin: 0;
  color: var(--ink);
}
.choices {
  display: grid;
  gap: 0.45rem;
}
.choice {
  text-align: left;
  border: 1px solid var(--line);
  background: #fff;
  border-radius: 12px;
  padding: 0.75rem 0.9rem;
  cursor: pointer;
}
.choice.correct {
  border-color: #1f8f55;
  background: #e8f7ef;
}
.choice.wrong {
  border-color: #c44;
  background: #fdecec;
}
.score {
  display: grid;
  gap: 0.55rem;
  justify-items: start;
}
.score strong {
  font-size: 2rem;
  color: var(--accent);
}
.plan-body {
  margin: 0;
  white-space: pre-wrap;
  font-family: inherit;
  line-height: 1.5;
  color: var(--ink);
}
.week {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 0.4rem;
}
.week li {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.55rem 0.7rem;
  border-radius: 10px;
  background: var(--soft);
}
.empty {
  grid-column: 1 / -1;
}
</style>
