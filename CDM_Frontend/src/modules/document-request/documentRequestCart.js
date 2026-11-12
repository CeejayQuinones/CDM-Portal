import { DOCUMENT_REQUEST_PURPOSES, purposePayload } from './documentRequestPurpose.js'

export function addCartDocument(cart, type) {
  if (!type || cart.some((item) => item.document_type_id === type.id)) return cart
  return [...cart, { document_type_id: type.id, type, quantity: 1,
    overridePurpose: false, purposeCategory: '', purposeDetails: '' }]
}

export function cartIsComplete(cart, sharedCategory, sharedDetails) {
  if (!cart.length || !DOCUMENT_REQUEST_PURPOSES.includes(sharedCategory)) return false
  if (sharedCategory === 'Others' && (!sharedDetails.trim() || sharedDetails.trim().length > 500)) return false
  return cart.every((item) => {
    const category = item.overridePurpose ? item.purposeCategory : sharedCategory
    const details = item.overridePurpose ? item.purposeDetails : sharedDetails
    return Number.isInteger(Number(item.quantity)) && Number(item.quantity) >= 1 && Number(item.quantity) <= 10 &&
      DOCUMENT_REQUEST_PURPOSES.includes(category) &&
      (category !== 'Others' || (Boolean(details.trim()) && details.trim().length <= 500))
  })
}

export function cartSubmissionPayload(cart, sharedCategory, sharedDetails, key) {
  return {
    idempotency_key: key,
    items: cart.map((item) => ({
      document_type_id: item.document_type_id,
      quantity: Number(item.quantity),
      ...purposePayload(item.overridePurpose ? item.purposeCategory : sharedCategory,
        item.overridePurpose ? item.purposeDetails : sharedDetails),
    })),
  }
}
