export function createExamVisibilityGuard({
  isActive,
  onHidden,
  onReturned,
  onBlur,
  delay = 160,
  schedule = (callback, wait) => window.setTimeout(callback, wait),
  cancel = timer => window.clearTimeout(timer),
}) {
  let hiddenEpisode = false
  let blurTimer = null

  function clearBlurTimer() {
    if (blurTimer !== null) cancel(blurTimer)
    blurTimer = null
  }

  function visibilityChanged(state) {
    if (state === 'hidden') {
      clearBlurTimer()
      if (!isActive() || hiddenEpisode) return
      hiddenEpisode = true
      onHidden()
      return
    }

    if (!hiddenEpisode) return
    hiddenEpisode = false
    if (isActive()) onReturned()
  }

  function windowBlurred() {
    if (!isActive() || hiddenEpisode) return
    clearBlurTimer()
    blurTimer = schedule(() => {
      blurTimer = null
      if (isActive() && !hiddenEpisode) onBlur()
    }, delay)
  }

  function reset() {
    clearBlurTimer()
    hiddenEpisode = false
  }

  return { visibilityChanged, windowBlurred, reset }
}
