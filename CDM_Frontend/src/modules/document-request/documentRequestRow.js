const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)

const mergeObjects = (values) => Object.assign({}, ...values.filter(isObject))

export function mergeDocumentRequestRow(...requests) {
  const sources = requests.filter(isObject)
  if (!sources.length) return null

  const merged = mergeObjects(sources)
  const documentTypes = sources.map((request) => request.document_type).filter(isObject)
  const students = sources.map((request) => request.student).filter(isObject)

  if (documentTypes.length) merged.document_type = mergeObjects(documentTypes)

  if (students.length) {
    const student = mergeObjects(students)
    const users = students.map((value) => value.user).filter(isObject)
    const directProfiles = students.map((value) => value.user_profile).filter(isObject)

    if (users.length) {
      const user = mergeObjects(users)
      const userProfiles = users.map((value) => value.profile).filter(isObject)

      if (userProfiles.length) user.profile = mergeObjects(userProfiles)
      student.user = user
    }

    if (directProfiles.length) student.user_profile = mergeObjects(directProfiles)
    merged.student = student
  }

  return merged
}

export const requestDocumentName = (request) => request?.document_type?.document_name || 'Document'

export const requestStudentNumber = (request) => request?.student?.student_number || 'Student number unavailable'
