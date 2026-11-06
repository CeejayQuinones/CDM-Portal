export const INSTITUTE_COURSE_CODES = Object.freeze({
  ICS: Object.freeze(['BSIT', 'BSCS']),
  ITE: Object.freeze(['BSED']),
  IBE: Object.freeze(['BSBA']),
})

export function instituteCourseIds(instituteCode, courses = [], requestedCourseId = '') {
  const allowedCodes = new Set(INSTITUTE_COURSE_CODES[instituteCode] || [])
  const allowedIds = courses
    .filter(course => allowedCodes.has(course.course_code))
    .map(course => String(course.id))
  const requested = String(requestedCourseId || '')
  return requested ? (allowedIds.includes(requested) ? [requested] : []) : allowedIds
}

export function mergeInstituteReviews(responses = []) {
  const summary = responses.reduce((totals, response) => ({
    pending_review: totals.pending_review + Number(response.summary?.pending_review || 0),
    approved: totals.approved + Number(response.summary?.approved || 0),
    published: totals.published + Number(response.summary?.published || 0),
  }), { pending_review: 0, approved: 0, published: 0 })
  const data = responses
    .flatMap(response => response.sheets?.data || [])
    .sort((a, b) => new Date(b.submitted_at || 0) - new Date(a.submitted_at || 0))
    .slice(0, 25)
  return {
    summary,
    sheets: {
      ...(responses[0]?.sheets || {}),
      data,
      total: responses.reduce((total, response) => total + Number(response.sheets?.total || response.sheets?.data?.length || 0), 0),
    },
  }
}
