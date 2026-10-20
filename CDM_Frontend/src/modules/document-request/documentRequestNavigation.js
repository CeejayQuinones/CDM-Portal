const queryValue = (value) => (Array.isArray(value) ? value[0] : value)

const positiveId = (value) => {
  const id = Number(queryValue(value))

  return Number.isInteger(id) && id > 0 ? id : null
}

const queryText = (value) => {
  const text = String(queryValue(value) || '').trim()

  return text || undefined
}

export const documentRequestIdFromQuery = (query) => {
  const requestId = positiveId(query.request_id)
  const focusMatch = String(queryValue(query.focus) || '').match(/^request-(\d+)$/)

  return (focusMatch ? positiveId(focusMatch[1]) : null) || requestId
}

export const documentRequestSourceQuery = ({
  requestId,
  studentId,
  search,
  timeFilter,
  pendingPage,
  processingPage,
}) => ({
  from: 'document-request',
  request_id: positiveId(requestId) || undefined,
  ...(positiveId(studentId) ? { student_id: positiveId(studentId) } : {}),
  request_search: queryText(search),
  request_time_filter: queryText(timeFilter),
  request_pending_page: positiveId(pendingPage) || undefined,
  request_processing_page: positiveId(processingPage) || undefined,
})

export const documentRequestProfileQuery = documentRequestSourceQuery

export const appointmentDocumentRequestQuery = ({ requestId, appointmentId }) => ({
  from: 'appointment',
  request_id: positiveId(requestId) || undefined,
  appointment_id: positiveId(appointmentId) || undefined,
})

export const appointmentReturnContext = (query) => {
  const requestId = positiveId(query.request_id)
  const appointmentId = positiveId(query.appointment_id)

  if (queryValue(query.from) !== 'appointment' || !appointmentId) return null

  return {
    label: '← Back to Appointment',
    to: {
      name: 'registrar-document-appointments',
      query: {
        request_id: requestId || undefined,
        appointment_id: appointmentId,
        focus: `appointment-${appointmentId}`,
        open: 'completion',
      },
    },
  }
}

export const documentRequestReturnContext = (query) => {
  const requestId = positiveId(query.request_id)

  if (queryValue(query.from) !== 'document-request' || !requestId) return null

  return {
    label: '← Back to Document Request',
    to: {
      name: 'registrar-document-requests',
      query: {
        request_id: requestId,
        search: queryText(query.request_search),
        time_filter: queryText(query.request_time_filter),
        pending_page: positiveId(query.request_pending_page) || undefined,
        processing_page: positiveId(query.request_processing_page) || undefined,
      },
    },
  }
}

export const withoutDocumentRequestFocus = (query) => {
  const preservedQuery = { ...query }
  delete preservedQuery.request_id
  delete preservedQuery.focus

  return preservedQuery
}
