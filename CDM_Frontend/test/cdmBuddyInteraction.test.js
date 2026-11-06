import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

import { BUDDY_GREETING_DELAY, createSingleTrigger } from '../src/composables/useBuddyProactive.js'
import {
  buddyPositionStorageKey,
  clampBuddyPoint,
  isBuddyDrag,
  nearestBuddyDock,
} from '../src/composables/useBuddyPosition.js'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('early greeting single-trigger guard cannot fire twice', () => {
  let calls = 0
  const trigger = createSingleTrigger(() => { calls += 1 })

  assert.equal(BUDDY_GREETING_DELAY, 750)
  assert.equal(trigger.fire('movement'), true)
  assert.equal(trigger.fire('timer'), false)
  assert.equal(calls, 1)
})

test('tap and Android-like pointer movement are distinguished', () => {
  const start = { x: 200, y: 500 }
  assert.equal(isBuddyDrag(start, { x: 204, y: 503 }), false)
  assert.equal(isBuddyDrag(start, { x: 220, y: 530 }), true)
})

test('dragging clamps to the visual viewport and docks to the nearest safe edge', () => {
  const viewport = { width: 360, height: 640, offsetLeft: 0, offsetTop: 0 }
  const size = { width: 70, height: 86 }

  assert.deepEqual(clampBuddyPoint({ x: -100, y: 900 }, viewport, size), { x: 14, y: 540 })
  assert.deepEqual(nearestBuddyDock({ x: 180, y: 300 }, viewport, size), {
    side: 'right', point: { x: 276, y: 300 },
  })
  assert.deepEqual(nearestBuddyDock({ x: 20, y: 300 }, viewport, size), {
    side: 'left', point: { x: 14, y: 300 },
  })
})

test('saved positions are account scoped and cannot leak between users', () => {
  assert.equal(buddyPositionStorageKey(12), 'cdm-buddy-position:12')
  assert.equal(buddyPositionStorageKey(19), 'cdm-buddy-position:19')
  assert.notEqual(buddyPositionStorageKey(12), buddyPositionStorageKey(19))
})

test('component keeps automatic docking, pointer capture, reduced motion, and global exclusions', async () => {
  const [buddy, position, layout] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/composables/useBuddyPosition.js'),
    read('src/layouts/DashboardLayout.vue'),
  ])

  assert.doesNotMatch(buddy, /Reset position|Dock at edge|Dismiss this session/)
  assert.match(buddy, /Mute automatic CDM Buddy messages/)
  assert.match(buddy, /Show next CDM Buddy notice/)
  assert.match(buddy, /Close CDM Buddy message/)
  assert.match(buddy, /peeking\.dock-right/)
  assert.match(buddy, /prefers-reduced-motion: reduce/)
  assert.match(buddy, /student-admission-exam/)
  assert.match(buddy, /excludedRoutes = new Set\(\['settings'/)
  assert.match(position, /setPointerCapture/)
  assert.match(position, /visualViewport/)
  assert.match(position, /orientationchange/)
  assert.match(buddy, /touch-action: none/)
  assert.match(buddy, /background: transparent/)
  assert.match(layout, /<\/main>\s*<CDMBuddy \/>/)
})
