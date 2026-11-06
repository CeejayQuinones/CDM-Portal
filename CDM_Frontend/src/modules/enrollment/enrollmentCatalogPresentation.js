export const curriculumGroups = curriculum => {
  const groups = new Map()
  for (const subject of curriculum?.subjects || []) {
    const key = `${subject.year_level}:${subject.semester_order ?? subject.semester ?? ''}`
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        yearLevel: subject.year_level,
        semester: subject.semester || 'Semester not configured',
        subjects: [],
      })
    }
    groups.get(key).subjects.push(subject)
  }

  return [...groups.values()]
}
