// Keep validation precedence and fallback wording consistent across request forms.
export function documentRequestErrorMessage(error) {
  const validationMessage = Object.values(error.response?.data?.errors || {}).flat()[0]

  return validationMessage || error.response?.data?.message || 'The request could not be completed.'
}
