let pendingRequestId = null

const positiveId = (value) => {
  const id = Number(value)

  return Number.isInteger(id) && id > 0 ? id : null
}

export function rememberDocumentRequestFocus(requestId) {
  pendingRequestId = positiveId(requestId)
}

export function consumeDocumentRequestFocus() {
  const requestId = pendingRequestId
  pendingRequestId = null

  return requestId
}
