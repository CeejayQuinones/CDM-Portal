export const RAIL_SELECTION_DURATION_MS = 380

const transform = (y, scale) => `translate3d(0, ${y}px, 0) scaleY(${scale})`

export function railSelectionKeyframes(startY, targetY, height, startScale = 1) {
  const distance = targetY - startY
  const stretch = Math.min(0.12, Math.max(0.04, Math.abs(distance) / 900))
  const compressedScale = 0.96
  const leadingOffset = distance < 0 ? -Math.min(stretch * height, Math.abs(distance) * 0.2) : 0

  return [
    { transform: transform(startY, startScale), offset: 0 },
    { transform: transform(startY + distance * 0.32 + leadingOffset, 1 + stretch), offset: 0.28 },
    { transform: transform(targetY - Math.sign(distance) * (1 - compressedScale) * height / 2, compressedScale), offset: 0.82 },
    { transform: transform(targetY, 1), offset: 1 },
  ]
}
