import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { buildLocalCoachReply, isWeakCoachReply } from '../src/modules/monitoring/services/localAiCoach.js'

const context = {
  studentName: 'Ana',
  riskLabel: 'Moderate risk',
  averageGrade: 78,
  trendLabel: 'declining',
  subjects: [{
    subject_code: 'CS101',
    subject_name: 'Programming',
    average_grade: 72,
    risk_level: 'high',
    risk_label: 'High risk',
  }],
}

test('different questions do not share one fallback reply', () => {
  const programming = buildLocalCoachReply('How do I avoid fail this semester in programming?', context)
  const calculus = buildLocalCoachReply('What should I do so I do not fail calculus?', context)

  assert.notEqual(programming.reply, calculus.reply)
  assert.match(programming.reply.toLowerCase(), /programming/)
  assert.match(calculus.reply.toLowerCase(), /calculus/)
  assert.doesNotMatch(programming.reply.toLowerCase(), /calculus/)
  assert.doesNotMatch(programming.reply, /Practical coaching from your record/)
  assert.doesNotMatch(programming.reply, /Not limited to suggested questions/)
})

test('the same follow-up changes when recent messages change', () => {
  const question = 'Can you give a short example?'
  const loops = buildLocalCoachReply(question, context, [
    { role: 'user', content: 'Explain nested loops' },
    { role: 'assistant', content: 'A nested loop is a loop inside another loop.' },
  ])
  const search = buildLocalCoachReply(question, context, [
    { role: 'user', content: 'What is binary search?' },
    { role: 'assistant', content: 'Binary search halves a sorted list.' },
  ])

  assert.notEqual(loops.reply, search.reply)
  assert.match(loops.reply.toLowerCase(), /nested loop/)
  assert.match(search.reply.toLowerCase(), /binary search/)
  assert.match(loops.reply, /Earlier:/)
})

test('a specific coach answer is kept and a canned script is replaced', () => {
  const specific = 'A for-loop repeats a known count. A while-loop repeats until a condition fails. Trace both with the number 3.'
  assert.equal(isWeakCoachReply(specific, 'What is the difference between a for loop and a while loop?'), false)
  assert.equal(
    isWeakCoachReply('Got your question: “hi”.\n\nPractical coaching from your record (moderate, avg 78).', 'hi'),
    true,
  )
  assert.equal(isWeakCoachReply('', 'hello'), true)
})

test('chat sends prior turns and does not treat the welcome line as history', async () => {
  const vue = await readFile(new URL('../src/modules/monitoring/components/AiHelpChatbot.vue', import.meta.url), 'utf8')
  assert.match(vue, /askAiHelp\(props\.studentId, question, prior\)/)
  assert.match(vue, /startsWith\('sys-'\)/)
  assert.match(vue, /isWeakCoachReply/)
})
