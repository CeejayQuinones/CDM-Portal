import assert from 'node:assert/strict'
import test from 'node:test'
import { carouselCardState, horizontalSwipeDirection, moveCarouselIndex } from '../src/modules/dashboard/eventCarousel.js'

test('Event carousel moves forward and backward with wrap-around', () => {
  assert.equal(moveCarouselIndex(0, 3, 1), 1)
  assert.equal(moveCarouselIndex(1, 3, -1), 0)
  assert.equal(moveCarouselIndex(2, 3, 1), 0)
  assert.equal(moveCarouselIndex(0, 3, -1), 2)
})

test('single or empty Event sets stay at the only safe index', () => {
  assert.equal(moveCarouselIndex(0, 1, 1), 0)
  assert.equal(moveCarouselIndex(0, 1, -1), 0)
  assert.equal(moveCarouselIndex(0, 0, 1), 0)
})

test('stacked cards assign active, adjacent, and directional hidden states', () => {
  assert.equal(carouselCardState(2, 2, 5), 'active')
  assert.equal(carouselCardState(1, 2, 5), 'previous')
  assert.equal(carouselCardState(3, 2, 5), 'next')
  assert.equal(carouselCardState(0, 2, 5), 'hidden-left')
  assert.equal(carouselCardState(4, 2, 5), 'hidden-right')
  assert.equal(carouselCardState(0, 0, 1), 'active')
  assert.equal(carouselCardState(0, 0, 0), 'hidden-right')
})

test('horizontal swipes navigate while short and vertical gestures are ignored', () => {
  assert.equal(horizontalSwipeDirection({ x: 100, y: 20 }, { x: 30, y: 25 }), 1)
  assert.equal(horizontalSwipeDirection({ x: 30, y: 20 }, { x: 100, y: 25 }), -1)
  assert.equal(horizontalSwipeDirection({ x: 100, y: 20 }, { x: 80, y: 22 }), 0)
  assert.equal(horizontalSwipeDirection({ x: 100, y: 20 }, { x: 50, y: 100 }), 0)
  assert.equal(horizontalSwipeDirection(null, { x: 50, y: 20 }), 0)
})
