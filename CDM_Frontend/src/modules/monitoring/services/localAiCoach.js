/**
 * Local free-form coach used when the live AI API is unavailable or returns a canned script.
 * Answers the typed message and recent turns. Grade context stays in the background.
 */

const SKIP = new Set([
  'a', 'an', 'the', 'and', 'or', 'but', 'if', 'to', 'of', 'for', 'in', 'on', 'at', 'by', 'with', 'from', 'about',
  'is', 'are', 'was', 'were', 'be', 'been', 'being', 'do', 'does', 'did', 'can', 'could', 'should', 'would', 'will',
  'i', 'me', 'my', 'you', 'your', 'we', 'our', 'it', 'this', 'that', 'these', 'those', 'what', 'how', 'why', 'when',
  'where', 'who', 'which', 'please', 'help', 'just', 'also', 'more', 'some', 'any', 'into', 'than', 'then', 'them',
  'they', 'their', 'there', 'here', 'tell', 'give', 'make', 'explain', 'need', 'want', 'like', 'know', 'think',
  'really', 'very', 'much', 'something', 'anything', 'not', 'dont', 'have', 'has', 'had', 'let', 'get', 'got',
  'only', 'even', 'still', 'out', 'off', 'too', 'its', 'im', 'youre', 'us', 'before', 'after', 'between',
  'ako', 'ang', 'mga', 'ano', 'paano', 'pano', 'pwede', 'puede', 'gusto', 'kasi', 'para', 'kung', 'may', 'wala',
  'hindi', 'huwag', 'tayo', 'natin', 'tulungan', 'tulong', 'paki', 'pakiusap', 'kahit', 'suggestions', 'suggested',
  'question', 'questions', 'example', 'examples', 'halimbawa', 'short', 'again', 'detail', 'details', 'show',
  'tagalog', 'english', 'ulit', 'sige', 'okay', 'sample', 'yung', 'yun', 'iyan', 'ito', 'lang', 'naman',
  'po', 'opo', 'din', 'rin', 'nya', 'niyo', 'nyo',
])

const clip = (text, limit = 140) => {
  const clean = String(text || '').replace(/\s+/g, ' ').trim()
  return clean.length <= limit ? clean : `${clean.slice(0, limit - 1)}…`
}

const hasWord = (haystack, word) =>
  new RegExp(`(?:^|\\s|[?.!,])${word}(?:$|\\s|[?.!,])`, 'i').test(haystack)

const matchesAny = (haystack, needles) =>
  needles.some((needle) => {
    const token = String(needle || '').toLowerCase().trim()
    if (!token) return false
    if (token.length <= 3 || token.includes(' ')) {
      if (token.includes(' ')) return haystack.includes(token)
      return hasWord(haystack, token)
    }
    return haystack.includes(token)
  })

const contentTerms = (text) => {
  const clean = String(text || '')
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s-]/gu, ' ')
  const seen = new Set()
  const terms = []
  for (const raw of clean.split(/\s+/)) {
    const word = raw.replace(/^-+|-+$/g, '')
    if (word.length < 3 || SKIP.has(word) || seen.has(word)) continue
    seen.add(word)
    terms.push(word)
    if (terms.length === 8) break
  }
  return terms
}

const topicPhrase = (terms, fallback) => (terms.length ? terms.slice(0, 5).join(' ') : fallback)

const previousTurns = (history, question) => {
  const turns = (Array.isArray(history) ? history : [])
    .map((message) => ({
      role: message?.role === 'assistant' ? 'assistant' : 'user',
      content: String(message?.content || '').trim(),
    }))
    .filter((message) => message.content && message.content !== 'Thinking…')

  while (turns.length && turns[turns.length - 1].role === 'user' && turns[turns.length - 1].content === question) {
    turns.pop()
  }

  let priorUser = ''
  let priorAssistant = ''
  for (let i = turns.length - 1; i >= 0; i -= 1) {
    if (!priorAssistant && turns[i].role === 'assistant') priorAssistant = turns[i].content
    if (!priorUser && turns[i].role === 'user') priorUser = turns[i].content
    if (priorUser && priorAssistant) break
  }
  return { priorUser, priorAssistant }
}

const isFollowUp = (question, priorUser) => {
  if (!priorUser) return false
  const terms = contentTerms(question)
  if (!terms.length) return true
  const prior = new Set(contentTerms(priorUser))
  return terms.every((term) => prior.has(term))
}

const prefersFilipino = (text) => {
  const lower = String(text || '').toLowerCase()
  return [
    'ako', 'ang', 'mga', 'paano', 'pano', 'ano', 'po', 'naman', 'pwede', 'puede', 'aral',
    'yung', 'tulong', 'tulungan', 'maiiwasan', 'unahin', 'gawan', 'magprepare', 'salamat',
    'kumusta', 'kamusta', 'lang', 'hindi', 'gusto',
  ].some((marker) => hasWord(lower, marker))
}

const isGreetingOnly = (question) => {
  const stripped = String(question || '')
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim()
  return /^(hi|hello|hey|kumusta|kamusta|good morning|good evening|good afternoon|magandang umaga|magandang gabi|magandang hapon|yo|sup)( (po|there|cdm|coach))?$/.test(stripped)
}

const classifyIntent = (question, combinedLower) => {
  const current = question.toLowerCase()
  if (isGreetingOnly(question)) return 'greet'
  if (matchesAny(current, ['salamat', 'thank', 'thanks'])) return 'thanks'
  if (matchesAny(combinedLower, ['example', 'halimbawa', 'sample'])) return 'example'
  if (matchesAny(combinedLower, ['difference', 'compare', 'versus', 'kaysa']) || combinedLower.includes(' vs ')) return 'compare'
  if (matchesAny(combinedLower, ['sleep', 'tulog', 'puyat', 'insomnia'])) return 'sleep'
  if (matchesAny(combinedLower, ['study plan', '3-day', '3 day', '5-day', '5 day', 'iskedyul', 'schedule'])) return 'plan'
  if (matchesAny(combinedLower, ['gawan']) && matchesAny(combinedLower, ['plan', 'aral', 'review'])) return 'plan'
  if (hasWord(combinedLower, 'plan') && matchesAny(combinedLower, ['study', 'aral', 'week', 'araw', 'review'])) return 'plan'
  if (
    matchesAny(combinedLower, ['quiz', 'exam', 'midterm', 'finals', 'prelim', 'magprepare', 'prepare'])
    || hasWord(combinedLower, 'test')
    || hasWord(combinedLower, 'final')
  ) return 'prep'
  if (matchesAny(combinedLower, ['fail', 'bagsak', 'maiiwasan', 'prevent'])) return 'fail'
  if (matchesAny(combinedLower, ['unahin', 'priority', 'weakest', 'pinakamahina'])) return 'priority'
  if (matchesAny(combinedLower, ['motivate', 'pagod', 'stress', 'anxious', 'overwhelm', 'ayoko'])) return 'motivate'
  if (matchesAny(combinedLower, ['grade', 'grades', 'standing', 'average']) || hasWord(combinedLower, 'risk')) return 'status'
  if (matchesAny(combinedLower, ['explain', 'define', 'meaning', 'ibig sabihin', 'what is', 'ano ang', 'how does', 'paano gumagana', 'teach'])) return 'explain'
  return 'general'
}

const pickVariant = (seed, options) => {
  let hash = 0
  for (let i = 0; i < seed.length; i += 1) hash = (hash * 33 + seed.charCodeAt(i)) >>> 0
  return options[hash % options.length]
}

const findSubject = (lower, subjects) =>
  (Array.isArray(subjects) ? subjects : []).find((subject) => {
    const code = String(subject.subject_code || '').toLowerCase()
    const name = String(subject.subject_name || '').toLowerCase()
    return (code && lower.includes(code)) || (name.length >= 4 && lower.includes(name))
  })

const opening = (question, topic, followUp, priorUser, priorAssistant) => {
  if (followUp && priorUser) {
    const earlier = priorAssistant ? ` Earlier: ${clip(priorAssistant, 140)}` : ''
    return `Still on ${topic}, following “${clip(priorUser, 110)}”.${earlier}`
  }
  return pickVariant(question, [
    `About ${topic}.`,
    `Here is a direct answer on ${topic}.`,
    `Let’s answer ${topic} directly.`,
    `Working from your message on ${topic}.`,
  ])
}

const priorityBody = (topic, asked, filipino, focus, subjects) => {
  const risky = (Array.isArray(subjects) ? subjects : []).filter((subject) =>
    ['high', 'moderate'].includes(subject.risk_level),
  )
  const lines = (risky.length ? risky : []).slice(0, 3).map(
    (subject) => `- ${subject.subject_code || 'Subject'} (${subject.subject_name || ''}): avg ${subject.average_grade ?? 'n/a'} · ${subject.risk_label || 'watch'}`,
  )
  const list = lines.length ? lines.join('\n') : `- ${focus}`
  if (filipino) {
    return `Sa tanong mo tungkol sa ${topic}, unahin ito:\n${list}\n\nAng unang session, doon lang sa pinakataas.\n“${asked}”.`
  }
  return `For ${topic}, start here:\n${list}\n\nUse the next session only for the top item, then say which part is unclear.\n“${asked}”.`
}

const replyBody = (intent, topic, asked, filipino, name, focus, subjects, risk, trend, avg) => {
  if (intent === 'priority') return priorityBody(topic, asked, filipino, focus, subjects)

  if (filipino) {
    const filipinoBodies = {
      thanks: `Walang anuman, ${name}. “${asked}” — i-type ang susunod na topic, correction, o example.`,
      greet: `Hi ${name}. “${asked}” — tanong ka ng topic o subject. Gagamitin ko ang grades lang kapag yan ang tanong.`,
      example: `Example para sa ${topic}:\n\nSetup — isang maliit na input na gumagamit ng ${topic}.\nMiddle — i-apply ang rule nang isang beses.\nCheck — ikumpara sa ibig sabihin ng ${topic}.\n\nI-paste ang attempt mo at ituturo ko ang unang maling step.\n“${asked}”.`,
      compare: `Paghambing sa ${topic}:\n\nDalawang column mula sa “${asked}”. Sa bawat idea: para saan, isang example, at kailan mali itong gamitin.`,
      sleep: `Kasama sa aral ang tulog para sa ${topic}.\n\nMga 7 oras kung kaya. I-review ang ${topic} nang mas maaga, hindi all-nighter. Itigil ang screen mga 30 minuto bago matulog.\n“${asked}”.`,
      plan: `Plan para sa ${topic}:\n\nDay 1 — listahan ng alam mo at 3 gaps sa ${topic}.\nDay 2 — dalawang 40-minute practice sa gaps lang.\nDay 3 — i-explain ang ${topic} nang walang notes, tapos ayusin ang nalaktawan.\n“${asked}”.`,
      prep: `Prep para sa ${topic}:\n\nIlista ang pwedeng itanong tungkol sa ${topic}. Ulitin ang isang maling item at isulat ang rule na nakalimutan. Gabi bago: 20 minutong recall, tapos tulog.\n“${asked}”.`,
      fail: `Para hindi mag-fail, ilagay ang susunod na study blocks sa ${topic}, hindi sa lahat ng subject nang sabay.\n\n1. Isang session sa pinakamahinang parte ng ${topic}.\n2. Isang tanong na pwedeng itanong sa klase tungkol sa ${topic}.\n3. Tingnan ang susunod na graded date para hindi masurpresa sa ${topic}.\n“${asked}”.`,
      motivate: `Hindi kailangan perfect session, ${name}. 25 minutes lang sa ${topic}, tapos tigil.\n“${asked}”.`,
      status: `Standing para sa “${asked}”:\n\nRisk: ${risk}. Trend: ${trend}. Average: ${avg}. Watch: ${focus}.\n\nMagtanong tungkol sa isang subject kung recovery step ang gusto mo.`,
      explain: `Simpleng paliwanag ng ${topic}:\n\n1. Isang sentence: ano ang ${topic}?\n2. I-trace ang isang example ng ${topic} at bakit ganun ang bawat step.\n3. Ulitin nang walang notes. Ang nakalimutan, yun ang susunod.\n“${asked}”.`,
    }
    return filipinoBodies[intent] || `Direktang sagot sa ${topic}.\n\n1. Ano ang “tapos” ngayon para sa ${topic} — isang paliwanag, isang problem, o isang desisyon.\n2. Gawin nang isang beses at isulat ang exact na stuck point.\n3. I-send ang stuck point. Sasagutin ko yun, hindi ang buong grade record.\nTanong mo: “${asked}”.`
  }

  const english = {
    thanks: `You're welcome, ${name}. “${asked}” — send the next topic, a correction, or say example.`,
    greet: `Hi ${name}. You said “${asked}”. Ask a topic, a subject, or a follow-up in your own words.`,
    example: `Example for ${topic}:\n\nSetup — one small input that uses ${topic}.\nMiddle — apply the rule once, slowly.\nCheck — compare the result with what ${topic} means.\n\nPaste your attempt and I will mark the first wrong step.\n“${asked}”.`,
    compare: `Comparison for ${topic}:\n\nMake two columns from “${asked}”. For each idea, write what it is for, one example, and when it is the wrong tool.`,
    sleep: `Sleep is part of studying ${topic}.\n\nAim for about 7 hours when you can. Review ${topic} earlier in the evening instead of staying up. Stop screens about 30 minutes before bed.\n“${asked}”.`,
    plan: `Plan for ${topic}:\n\nDay 1 — list what you know and three gaps in ${topic}.\nDay 2 — two 40-minute practice blocks on those gaps only.\nDay 3 — explain ${topic} out loud, then fix the part you skipped.\n“${asked}”.`,
    prep: `Prep for ${topic}:\n\nList what a quiz on ${topic} can ask. Redo one missed problem and write the rule you forgot. The night before, 20 minutes of recall, then sleep.\n“${asked}”.`,
    fail: `To avoid failing, put the next study blocks on ${topic}, not on every subject at once.\n\n1. One focused session on the weakest part of ${topic}.\n2. Write one precise question you can ask in class about ${topic}.\n3. Check the next graded date so a missed ${topic} item is not a surprise.\n“${asked}”.`,
    motivate: `You do not need a perfect session, ${name}. Set a 25-minute timer for ${topic} and stop when it rings.\n“${asked}”.`,
    status: `Standing that answers “${asked}”:\n\nRisk: ${risk}. Trend: ${trend}. Average: ${avg}. Watch: ${focus}.\n\nAsk about one subject if you want a recovery step for that class only.`,
    explain: `Plain version of ${topic}:\n\n1. Define ${topic} in one sentence you could tell a classmate.\n2. Walk one example of ${topic} and note why each step exists.\n3. Close your notes and redo ${topic}. The step you forget is the next thing to ask.\n“${asked}”.`,
  }
  return english[intent] || pickVariant(asked, [
    `Direct take on ${topic}.\n\n1. Say what “done” looks like today for ${topic} — one explanation, one problem, or one decision.\n2. Do that piece once and write the exact stuck point.\n3. Send the stuck point. I will answer that, not a general study speech.\nYou asked: “${asked}”.`,
    `On ${topic}, skip the overview.\n\nStart from a concrete piece of ${topic}: a number, a line of notes, or an example. Say what you already get, then the one gap.\nYou asked: “${asked}”.`,
  ])
}

const contextAside = (intent, matched, risk, trend, avg) => {
  if (matched && ['fail', 'priority', 'status', 'plan', 'prep', 'general'].includes(intent)) {
    const code = matched.subject_code || 'that subject'
    return `Grade background: ${code} is ${matched.risk_label || 'on watch'} (avg ${matched.average_grade ?? 'n/a'}). I will bring this up again only if you ask.`
  }
  if (['fail', 'priority', 'status'].includes(intent)) {
    return `Background: ${risk}, trend ${trend}, average ${avg}.`
  }
  return ''
}

const actionsFor = (intent, topic) => {
  if (intent === 'plan') return [`Day 1: list gaps in ${topic}`, `Day 2: practice ${topic}`, `Day 3: explain ${topic} without notes`]
  if (intent === 'prep') return [`List likely questions on ${topic}`, `Redo one missed ${topic} item`, `Short recall the night before`]
  if (intent === 'fail' || intent === 'priority') return [`One block today on ${topic}`, `One question for the professor about ${topic}`]
  return []
}

export function buildLocalCoachReply(question, context = {}, history = []) {
  const q = String(question || '').trim()
  const name = context.studentName || 'student'
  const risk = context.riskLabel || 'unknown risk'
  const avg = context.averageGrade ?? 'n/a'
  const trend = context.trendLabel || 'steady'
  const subjects = Array.isArray(context.subjects) ? context.subjects : []
  const risky = subjects.filter((subject) => ['high', 'moderate'].includes(subject.risk_level))
  const focus =
    risky.map((subject) => subject.subject_code).filter(Boolean).join(', ') ||
    subjects.map((subject) => subject.subject_code).filter(Boolean).slice(0, 2).join(', ') ||
    'your heaviest subjects'

  if (!q) {
    const reply =
      `Hi ${name}! Ako ang CDM AI Help coach mo.\n\n` +
      `Ask anything in your own words. I will use your record (${risk}, ${trend}, avg ${avg}) only when the question is about grades or what to study first.`
    return {
      source: 'local-coach',
      provider: 'local-coach',
      reply,
      summary: `Local coaching for ${name}`,
      advice: reply,
      actions: [],
      prevention_note: '',
    }
  }

  const { priorUser, priorAssistant } = previousTurns(history, q)
  const followUp = isFollowUp(q, priorUser)
  const anchor = followUp && priorUser ? priorUser : q
  const topic = topicPhrase(contentTerms(anchor), clip(anchor, 90))
  const combined = `${q}\n${anchor}`.toLowerCase()
  const intent = classifyIntent(q, combined)
  const filipino = prefersFilipino(q) || (followUp && prefersFilipino(anchor))
  const matched = findSubject(combined, subjects)
  const asked = clip(q, 160)
  const reply = [
    intent === 'greet' || intent === 'thanks' ? '' : opening(q, topic, followUp, priorUser, priorAssistant),
    replyBody(intent, topic, asked, filipino, name, focus, subjects, risk, trend, avg),
    contextAside(intent, matched, risk, trend, avg),
  ].filter(Boolean).join('\n\n')

  return {
    source: 'local-coach',
    provider: 'local-coach',
    reply,
    summary: `Local coaching for ${name}`,
    advice: reply,
    actions: actionsFor(intent, topic),
    prevention_note: ['fail', 'prep', 'plan'].includes(intent) ? 'Short review and sleep beat an all-night cram.' : '',
  }
}

export function isWeakCoachReply(reply, question) {
  const text = String(reply || '').trim()
  const q = String(question || '').trim()
  if (!text) return true
  if (q && text === q) return true
  const lower = text.toLowerCase()
  const cannedScript =
    (text.includes('Got it — about') && text.includes('Use short daily blocks')) ||
    (text.includes('Got your question:') && lower.includes('practical coaching')) ||
    lower.includes('not limited to suggested questions') ||
    lower.includes('free-form chat is fully supported')
  return cannedScript
}
