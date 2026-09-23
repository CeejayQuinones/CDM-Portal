/**
 * Local free-form coach used when the live AI API is unavailable or returns a weak template.
 * Handles any typed message — not only suggestion chips.
 */
export function buildLocalCoachReply(question, context = {}, history = []) {
  const q = String(question || '').trim()
  const lower = q.toLowerCase()
  const name = context.studentName || 'student'
  const risk = context.riskLabel || 'unknown risk'
  const avg = context.averageGrade ?? 'n/a'
  const trend = context.trendLabel || 'steady'
  const subjects = Array.isArray(context.subjects) ? context.subjects : []
  const risky = subjects.filter((s) => ['high', 'moderate'].includes(s.risk_level))
  const focus =
    risky.map((s) => s.subject_code).filter(Boolean).join(', ') ||
    subjects.map((s) => s.subject_code).filter(Boolean).slice(0, 2).join(', ') ||
    'your heaviest subjects'

  const has = (needles) =>
    needles.some((n) => {
      const token = String(n || '').toLowerCase().trim()
      if (!token) return false
      if (token.length <= 3) {
        return new RegExp(`(?:^|\\s|[?.!,])${token}(?:$|\\s|[?.!,])`).test(lower)
      }
      return lower.includes(token)
    })

  const matchedSubject = subjects.find((s) => {
    const code = String(s.subject_code || '').toLowerCase()
    const subName = String(s.subject_name || '').toLowerCase()
    return (code && lower.includes(code)) || (subName.length >= 4 && lower.includes(subName))
  })

  let reply
  let actions = [
    `Focus study time on ${focus}.`,
    'Clarify one confusing topic with your professor this week.',
    'Do a short self-quiz before the next graded activity.',
  ]

  if (!q) {
    reply =
      `Hi ${name}! Ako ang CDM AI Help coach mo.\n\n` +
      `Standing: ${risk} (${trend}, avg ${avg}). Unahin: ${focus}.\n\n` +
      'Magtanong ka freely — hindi limited sa suggested chips.'
  } else if (has(['salamat', 'thank', 'thanks'])) {
    reply = `Walang anuman, ${name}! Message mo ulit ako anytime — kahit anong tanong.`
  } else if (has(['hello', 'hi', 'hey', 'kumusta', 'good morning', 'good evening', 'magandang'])) {
    reply =
      `Hi ${name}! Ready tumulong.\n\n` +
      `Signal: ${risk} · ${trend} · avg ${avg}. Focus: ${focus}.\n\n` +
      'Ano gusto mong ayusin — study plan, subject, o quiz prep? Type freely.'
  } else if (has(['fail', 'bagsak', 'maiiwasan', 'prevent', 'at risk'])) {
    reply =
      `Para maiwasan mag-fail this week:\n\n` +
      `1) Unahin ang ${focus} (${risk}).\n` +
      `2) 45–90 mins daily active recall.\n` +
      `3) Clarify one topic with your professor.\n` +
      `4) Track quizzes/assignments.\n\n` +
      `Avg mo: ${avg}. Sabihin ang specific subject kung gusto mo ng deeper plan.`
  } else if (has(['unahin', 'priority', 'first', 'weakest', 'pinakamahina', 'ano dapat'])) {
    const list = (risky.length ? risky : subjects).slice(0, 3)
      .map((s) => `- ${s.subject_code || 'Subject'} (${s.subject_name || ''}): avg ${s.average_grade ?? 'n/a'} · ${s.risk_label || 'watch'}`)
      .join('\n') || `- Focus on ${focus}`
    reply =
      `Dapat unahin mo muna:\n${list}\n\n` +
      `Ito ang may pinakamataas na risk base sa grades/trend (${trend}).\n` +
      'Pwede mong i-type: “paano ko irecover ang [subject]?”'
  } else if (has(['study plan', '3-day', '3 day', '5-day', 'plan', 'iskedyul', 'schedule', 'gawan'])) {
    reply =
      `3-day study plan (${risk}, focus: ${focus}):\n\n` +
      `Day 1 — Diagnose: review notes/quizzes; list 5 confusing points.\n` +
      `Day 2 — Practice: 2× 45-min blocks (problems/flashcards).\n` +
      `Day 3 — Prove: self-quiz + explain out loud; ask professor remaining gaps.\n\n` +
      'Customize? Type the subject code anytime.'
    actions = [
      `Day 1 diagnose for ${focus}`,
      'Day 2 practice blocks',
      'Day 3 self-quiz + professor question',
    ]
  } else if (has(['quiz', 'exam', 'midterm', 'final', 'prelim', 'test', 'magprepare', 'prepare'])) {
    reply =
      `Prep checklist before your next quiz/exam:\n\n` +
      `• Skim topics since last assessment.\n` +
      `• Rework mistakes from ${focus} first.\n` +
      `• Make 8–10 flashcards.\n` +
      `• Timed 20-minute practice the night before.\n` +
      `• Sleep 7+ hours.\n\n` +
      `Standing: ${risk} (avg ${avg}). Sabihin ang subject + quiz date for a tighter plan.`
  } else if (matchedSubject) {
    const code = matchedSubject.subject_code || 'that subject'
    reply =
      `Tungkol sa ${code} (${matchedSubject.subject_name || code}):\n\n` +
      `Status: ${matchedSubject.risk_label || 'watch'} · avg ${matchedSubject.average_grade ?? 'n/a'}. Overall: ${risk}.\n` +
      `1) List last 2 weak topics.\n` +
      `2) Two focused study blocks this week.\n` +
      `3) Ask your ${code} professor one clarifying question.\n` +
      `4) Short practice set before next graded work.\n\n` +
      `Tanong mo: “${q}” — type the exact lesson if you want step-by-step tutoring.`
  } else if (has(['grade', 'standing', 'risk', 'average', 'status'])) {
    reply =
      `Snapshot mo, ${name}:\n\n` +
      `• Risk: ${risk}\n• Trend: ${trend}\n• Average: ${avg}\n• Focus: ${focus}\n\n` +
      'Ask anything else freely — recovery, plans, or topic help.'
  } else if (has(['motivate', 'pagod', 'stress', 'anxious', 'overwhelm', 'ayoko na'])) {
    reply =
      `Normal yan, ${name}. Start tiny: 25 minutes today on ${focus}, then break.\n` +
      `Signal mo: ${risk} — recoverable with short consistent sessions.\n\n` +
      'Sabihin ang subject and I’ll map the next 3 steps.'
  } else {
    const continuing = Array.isArray(history) && history.length > 2 ? 'Continuing our chat: ' : ''
    reply =
      `${continuing}Got your question: “${q}”.\n\n` +
      `Practical coaching from your record (${risk}, avg ${avg}, focus ${focus}):\n` +
      `• Pick one clear outcome for today.\n` +
      `• Spend 45–90 focused minutes on ${focus} if this is about grades/study.\n` +
      `• Write what you know vs what confuses you, then attack the gaps.\n` +
      `• For a concept explained, reply with the topic name.\n\n` +
      'You can ask anything in your own words — not limited to suggested questions.'
  }

  return {
    source: 'local-coach',
    provider: 'local-coach',
    reply,
    summary: `Local coaching for ${name}`,
    advice: reply,
    actions,
    prevention_note: 'Short daily review beats cramming before exams.',
  }
}

export function isWeakCoachReply(reply, question) {
  const text = String(reply || '')
  const q = String(question || '').trim()
  if (!text) return true
  // Old template that only echoed the question
  if (text.includes('Got it — about') && text.includes('Use short daily blocks')) return true
  if (q && text === q) return true
  return false
}
