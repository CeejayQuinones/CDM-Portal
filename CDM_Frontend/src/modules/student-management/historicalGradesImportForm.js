export function createHistoricalGradesImportForm(file) {
  const form = new FormData()
  form.append('file', file)
  return form
}
