export const classifications = [
  { value: 'regular', label: 'Regular', description: 'Normal curriculum path. The standard subject load will be proposed during academic finalization.' },
  { value: 'irregular', label: 'Irregular', description: 'Customized subject load based on your academic history. Subject selection follows academic review.' },
  { value: 'transferee', label: 'Transferee', description: 'Requires transcript and credit evaluation before final subject assignment.' },
  { value: 'returnee', label: 'Returnee', description: 'Requires academic review and any necessary reactivation before final subject assignment.' },
]
export const label = value => String(value || '').replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase())
export const termLabel = p => `${p?.academic_year?.school_year || p?.academic_year || 'Academic year'} · ${p?.semester?.semester_name || p?.semester || 'Semester'}`
export function safeError(e) {
  const status = e?.response?.status
  if (status === 422) return Object.values(e.response.data?.errors || {}).flat().join(' ') || e.response.data?.message || 'Please check the supplied fields.'
  if ([403,404,409,429].includes(status)) return e.response.data?.message || 'This action is unavailable. Reload and try again.'
  return 'Enrollment is temporarily unavailable. Please try again.'
}
