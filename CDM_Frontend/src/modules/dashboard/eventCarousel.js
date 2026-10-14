export function moveCarouselIndex(currentIndex, itemCount, direction) {
  if (itemCount <= 1) return 0

  return (currentIndex + direction + itemCount) % itemCount
}

export function carouselCardState(itemIndex, activeIndex, itemCount) {
  if (itemCount <= 0 || itemIndex < 0 || itemIndex >= itemCount) return 'hidden-right'
  if (itemIndex === activeIndex) return 'active'

  const forwardDistance = (itemIndex - activeIndex + itemCount) % itemCount
  const backwardDistance = (activeIndex - itemIndex + itemCount) % itemCount
  if (forwardDistance === 1) return 'next'
  if (backwardDistance === 1) return 'previous'

  return forwardDistance < backwardDistance ? 'hidden-right' : 'hidden-left'
}

export function horizontalSwipeDirection(start, end, minimumDistance = 40) {
  if (!start || !end) return 0
  const horizontalDistance = end.x - start.x
  const verticalDistance = end.y - start.y
  if (Math.abs(horizontalDistance) < minimumDistance || Math.abs(horizontalDistance) <= Math.abs(verticalDistance)) return 0

  return horizontalDistance < 0 ? 1 : -1
}
