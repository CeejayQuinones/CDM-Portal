export const loginErrorMessage = (error) => {
  const backendMessage = error.response?.data?.message
  if (backendMessage) return backendMessage

  if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') {
    return 'The CDM server did not respond in time. Confirm that the server is running and try again.'
  }

  return 'Unable to reach the CDM server. Confirm that the server is running and try again.'
}

export const runLoginRequest = async ({ credentials, login, redirect, onError, setLoading }) => {
  setLoading(true)

  try {
    const user = await login(credentials)
    await redirect(user)
    return user
  } catch (error) {
    onError(error, loginErrorMessage(error))
    return null
  } finally {
    setLoading(false)
  }
}
