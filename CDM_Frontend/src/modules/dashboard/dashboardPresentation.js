export const formatDashboardStatus = (value, fallback = 'Not finalized') => {
  if (!value) return fallback

  return String(value)
    .replaceAll('_', ' ')
    .toLowerCase()
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function guestDashboardActionAvailability(workflow = {}, admitted = false) {
  const exam = workflow.exam || {}
  const session = exam.session

  return {
    exam: !admitted && Boolean(
      (session && !session.exam_completed)
      || (exam.eligible && !exam.latest_completed && !session?.exam_completed),
    ),
    result: workflow.result?.published === true,
  }
}
