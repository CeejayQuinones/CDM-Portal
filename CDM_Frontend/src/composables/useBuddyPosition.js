import { computed, nextTick, onBeforeUnmount, onMounted, ref, unref, watch } from 'vue'
import { WEBSITE_SIDEBAR_LAYOUT_EVENT } from './useWebsiteSidebar.js'
import { WEBSITE_NAVIGATION_LAYOUT_EVENT } from './useWebsiteNavigation.js'

export const BUDDY_DRAG_THRESHOLD = 7
export const BUDDY_SAFE_MARGIN = 14

export function buddyPositionStorageKey(account) {
  return `cdm-buddy-position:${account || 'session'}`
}

export function clampBuddyPoint(point, viewport, size, margin = BUDDY_SAFE_MARGIN) {
  const minX = viewport.offsetLeft + margin
  const minY = viewport.offsetTop + margin
  const maxX = Math.max(minX, viewport.offsetLeft + viewport.width - size.width - margin)
  const maxY = Math.max(minY, viewport.offsetTop + viewport.height - size.height - margin)
  return {
    x: Math.min(maxX, Math.max(minX, Number(point.x) || minX)),
    y: Math.min(maxY, Math.max(minY, Number(point.y) || minY)),
  }
}

export function isBuddyDrag(start, current, threshold = BUDDY_DRAG_THRESHOLD) {
  return Math.hypot(current.x - start.x, current.y - start.y) >= threshold
}

export function nearestBuddyDock(point, viewport, size, margin = BUDDY_SAFE_MARGIN) {
  const center = point.x + (size.width / 2)
  const side = center < viewport.offsetLeft + (viewport.width / 2) ? 'left' : 'right'
  return {
    side,
    point: clampBuddyPoint({
      x: side === 'left' ? viewport.offsetLeft + margin : viewport.offsetLeft + viewport.width - size.width - margin,
      y: point.y,
    }, viewport, size, margin),
  }
}

export function useBuddyPosition({ accountKey, triggerRef, enabled, onDragStart }) {
  const position = ref({ x: 0, y: 0 })
  const dockSide = ref('right')
  const dragging = ref(false)
  const peeking = ref(false)
  const initialized = ref(false)
  const suppressClick = ref(false)
  let pointerStart
  let positionStart
  let activePointerId
  let layoutTimer

  const viewport = () => {
    const visual = window.visualViewport
    const view = {
      width: visual?.width || window.innerWidth,
      height: visual?.height || window.innerHeight,
      offsetLeft: visual?.offsetLeft || 0,
      offsetTop: visual?.offsetTop || 0,
    }
    const right = view.offsetLeft + view.width
    const bottom = view.offsetTop + view.height
    const navigationRects = Array.from(document.querySelectorAll('[data-website-navigation-boundary="true"]'))
      .map(element => element.getBoundingClientRect())
      .filter(rect => rect.width > 0 && rect.right > view.offsetLeft && rect.right < right)
    const sidebarRect = document.querySelector('.website-desktop-sidebar .sidebar')?.getBoundingClientRect()
    const topbarRect = document.querySelector('[data-website-topbar="true"]')?.getBoundingClientRect()
    const navbarRect = document.querySelector('.website-academic-shell .navbar')?.getBoundingClientRect()
    const navigationRight = Math.max(view.offsetLeft, sidebarRect?.right || 0, ...navigationRects.map(rect => rect.right))
    const offsetLeft = navigationRight < right ? navigationRight : view.offsetLeft
    const topbarBottom = topbarRect?.bottom || navbarRect?.bottom
    const offsetTop = topbarBottom > view.offsetTop && topbarBottom < bottom ? topbarBottom : view.offsetTop

    return {
      width: Math.max(0, right - offsetLeft),
      height: Math.max(0, bottom - offsetTop),
      offsetLeft,
      offsetTop,
    }
  }

  const triggerSize = () => {
    const rect = triggerRef.value?.getBoundingClientRect()
    return { width: rect?.width || 82, height: rect?.height || 98 }
  }

  const style = computed(() => initialized.value ? {
    left: `${position.value.x}px`,
    top: `${position.value.y}px`,
  } : {})

  function avoidObstacles(point, view, size) {
    let safe = clampBuddyPoint(point, view, size)
    const obstacles = document.querySelectorAll('[data-buddy-obstacle="true"]')
    for (const obstacle of obstacles) {
      const rect = obstacle.getBoundingClientRect()
      const overlaps = safe.x < rect.right + BUDDY_SAFE_MARGIN
        && safe.x + size.width > rect.left - BUDDY_SAFE_MARGIN
        && safe.y < rect.bottom + BUDDY_SAFE_MARGIN
        && safe.y + size.height > rect.top - BUDDY_SAFE_MARGIN
      if (!overlaps) continue

      const below = rect.bottom + BUDDY_SAFE_MARGIN
      const above = rect.top - size.height - BUDDY_SAFE_MARGIN
      safe.y = below + size.height <= view.offsetTop + view.height - BUDDY_SAFE_MARGIN
        ? below
        : above
      safe = clampBuddyPoint(safe, view, size)
    }
    return safe
  }

  const safePoint = point => {
    const view = viewport()
    return avoidObstacles(point, view, triggerSize())
  }

  function save() {
    if (!initialized.value) return
    localStorage.setItem(buddyPositionStorageKey(unref(accountKey)), JSON.stringify({
      x: Math.round(position.value.x), y: Math.round(position.value.y), dockSide: dockSide.value,
    }))
  }

  function resetPosition(savePreference = true) {
    const view = viewport()
    const size = triggerSize()
    position.value = safePoint({
      x: view.offsetLeft + view.width - size.width - BUDDY_SAFE_MARGIN,
      y: view.offsetTop + view.height - size.height - BUDDY_SAFE_MARGIN,
    })
    dockSide.value = 'right'
    peeking.value = false
    initialized.value = true
    if (savePreference) save()
  }

  function restore() {
    peeking.value = false
    try {
      const stored = JSON.parse(localStorage.getItem(buddyPositionStorageKey(unref(accountKey))) || 'null')
      if (stored && Number.isFinite(stored.x) && Number.isFinite(stored.y)) {
        position.value = safePoint(stored)
        dockSide.value = stored.dockSide === 'left' ? 'left' : 'right'
        initialized.value = true
        return
      }
    } catch {
      localStorage.removeItem(buddyPositionStorageKey(unref(accountKey)))
    }
    resetPosition(false)
  }

  function recalculate() {
    if (!initialized.value) return
    const view = viewport()
    const size = triggerSize()
    const docked = nearestBuddyDock(position.value, view, size)
    position.value = avoidObstacles(docked.point, view, size)
    dockSide.value = docked.side
    save()
  }

  function onPointerDown(event) {
    if (!unref(enabled) || event.button > 0 || event.isPrimary === false) return
    activePointerId = event.pointerId
    pointerStart = { x: event.clientX, y: event.clientY }
    positionStart = { ...position.value }
    dragging.value = false
    peeking.value = false
    event.currentTarget.setPointerCapture?.(event.pointerId)
  }

  function onPointerMove(event) {
    if (event.pointerId !== activePointerId || !pointerStart) return
    const current = { x: event.clientX, y: event.clientY }
    if (!dragging.value && isBuddyDrag(pointerStart, current)) {
      dragging.value = true
      suppressClick.value = true
      onDragStart?.()
    }
    if (!dragging.value) return
    event.preventDefault()
    position.value = safePoint({
      x: positionStart.x + current.x - pointerStart.x,
      y: positionStart.y + current.y - pointerStart.y,
    })
  }

  function onPointerUp(event) {
    if (event.pointerId !== activePointerId) return
    event.currentTarget.releasePointerCapture?.(event.pointerId)
    if (dragging.value) {
      const view = viewport()
      const size = triggerSize()
      const docked = nearestBuddyDock(position.value, view, size)
      position.value = avoidObstacles(docked.point, view, size)
      dockSide.value = docked.side
      save()
    }
    activePointerId = undefined
    pointerStart = undefined
    positionStart = undefined
    dragging.value = false
  }

  function allowClick() {
    if (!suppressClick.value) return true
    suppressClick.value = false
    return false
  }

  function nudge(event) {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return false
    event.preventDefault()
    const step = event.shiftKey ? 32 : 12
    const delta = {
      ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step],
    }[event.key]
    position.value = safePoint({ x: position.value.x + delta[0], y: position.value.y + delta[1] })
    dockSide.value = nearestBuddyDock(position.value, viewport(), triggerSize()).side
    peeking.value = false
    save()
    return true
  }

  function setPeek(value = true) {
    peeking.value = Boolean(value)
  }

  function onViewportChange() {
    window.requestAnimationFrame(recalculate)
  }

  function onSidebarLayoutChange() {
    onViewportChange()
    window.clearTimeout(layoutTimer)
    layoutTimer = window.setTimeout(onViewportChange, 240)
  }

  onMounted(() => {
    nextTick(restore)
    window.addEventListener('resize', onViewportChange)
    window.addEventListener('orientationchange', onViewportChange)
    window.visualViewport?.addEventListener('resize', onViewportChange)
    window.visualViewport?.addEventListener('scroll', onViewportChange)
    window.addEventListener(WEBSITE_SIDEBAR_LAYOUT_EVENT, onSidebarLayoutChange)
    window.addEventListener(WEBSITE_NAVIGATION_LAYOUT_EVENT, onSidebarLayoutChange)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('resize', onViewportChange)
    window.removeEventListener('orientationchange', onViewportChange)
    window.visualViewport?.removeEventListener('resize', onViewportChange)
    window.visualViewport?.removeEventListener('scroll', onViewportChange)
    window.removeEventListener(WEBSITE_SIDEBAR_LAYOUT_EVENT, onSidebarLayoutChange)
    window.removeEventListener(WEBSITE_NAVIGATION_LAYOUT_EVENT, onSidebarLayoutChange)
    window.clearTimeout(layoutTimer)
  })
  watch(accountKey, () => nextTick(restore))

  return {
    position, dockSide, dragging, peeking, initialized, style,
    onPointerDown, onPointerMove, onPointerUp, allowClick, nudge,
    resetPosition, recalculate, setPeek, restore,
  }
}
