export const DOCUMENT_REQUEST_PURPOSES = Object.freeze(['Scholarship', 'Evaluation', 'Others'])

export const documentRequestPurpose = (request) => {
  const category = String(request?.purpose_category || '').trim()
  const details = String(request?.purpose_details || '').trim()
  const legacy = String(request?.purpose || '').trim()

  if (category === 'Others') return details ? `Others — ${details}` : legacy || 'Others'
  return category || legacy
}

export const purposePayload = (category, details = '') => ({
  purpose_category: String(category || '').trim(),
  purpose_details: category === 'Others' ? String(details || '').trim() : null,
})
