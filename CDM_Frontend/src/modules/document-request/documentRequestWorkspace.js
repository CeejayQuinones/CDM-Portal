export function groupRequestStatus(requests = []) {
  if (!requests.length) return 'No requests'
  const statuses = new Set(requests.map((request) => request.status))
  if (statuses.size !== 1) return 'Mixed statuses'
  const status = statuses.values().next().value
  return {
    pending: 'Pending',
    approved: 'Approved',
    completed: 'Completed',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
  }[status] || 'Status unavailable'
}

export function retrievalPageSummary(tasks = []) {
  return tasks.reduce((summary, task) => {
    if (task.status === 'awaiting') {
      summary.awaiting += 1
      const slot = task.student?.physical_record_location?.cabinet_slot
      if (!slot?.slot_code || !slot.cabinet?.cabinet_code) summary.missing += 1
    } else if (task.status === 'retrieved') {
      summary.retrieved += 1
    }
    return summary
  }, { awaiting: 0, retrieved: 0, missing: 0 })
}

export function groupStudentSubmissions(requests = []) {
  const groups = new Map()
  for (const request of requests) {
    const key = request.document_request_batch_id
      ? `batch-${request.document_request_batch_id}`
      : `single-${request.id}`
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        reference: request.batch?.group_reference || request.request_reference || `REQ-${String(request.id).padStart(6, '0')}`,
        date: request.batch?.created_at || request.created_at || request.request_date,
        requests: [],
      })
    }
    groups.get(key).requests.push(request)
  }
  return [...groups.values()]
}
