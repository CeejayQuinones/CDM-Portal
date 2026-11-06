import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const read = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('Buddy schedules one contextual greeting per module entry and cleans up stale timers', async () => {
  const [buddy, proactive] = await Promise.all([
    read('src/components/CDMBuddy.vue'),
    read('src/composables/useBuddyProactive.js'),
  ])
  assert.match(proactive, /BUDDY_GREETING_DELAY = 750/)
  assert.match(proactive, /BUDDY_MODULE_COOLDOWN = 5000/)
  assert.match(buddy, /AUTO_MINIMIZE_DELAY = 10000/)
  assert.match(buddy, /cdm-buddy-seen:/)
  assert.match(buddy, /buddyMuteStorageKey/)
  assert.match(proactive, /pointermove/)
  assert.match(proactive, /transitionGeneration/)
  assert.match(proactive, /watch\(moduleKey/)
  assert.match(proactive, /window\.clearTimeout\(routeTimer\)/)
  assert.match(proactive, /unref\(muted\)/)
  assert.match(buddy, /pageIsBusy\(\)/)
  assert.match(buddy, /input, textarea, select, \[contenteditable=/)
  assert.match(buddy, /form:focus-within/)
  assert.match(buddy, /importantUnseen/)
  assert.match(buddy, /announceModule/)
  assert.match(buddy, /noticeLoadGeneration/)
  assert.match(buddy, /accountKey\.value !== requestedAccount/)
  assert.match(buddy, /loadedForAccount = null/)
  assert.match(buddy, /if \(loadedForAccount === null\) void loadNotices\(\)/)
  assert.match(proactive, /removeInteractionListeners/)
  assert.match(buddy, /onBeforeUnmount/)
})

test('Buddy pauses routine dismissal and handles accessibility and priority persistence', async () => {
  const buddy = await read('src/components/CDMBuddy.vue')
  assert.match(buddy, /@mouseenter="pauseMinimize"/)
  assert.match(buddy, /@focusin="pauseMinimize"/)
  assert.match(buddy, /@pointerdown="pauseMinimize"/)
  assert.match(buddy, /visibilitychange/)
  assert.match(buddy, /priority === 'urgent'/)
  assert.match(buddy, /acknowledgement\?\.required/)
  assert.match(buddy, /aria-live="polite"/)
  assert.match(buddy, /event\.key === 'Escape'/)
  assert.match(buddy, /prefers-reduced-motion/)
  assert.match(buddy, /@media \(max-width: 520px\)/)
})

test('staff notice console is routed to desktop roles and supports the publication lifecycle', async () => {
  const [view, service, router, access] = await Promise.all([
    read('src/modules/campus-notices/CampusNoticesView.vue'),
    read('src/services/campusNoticeService.js'),
    read('src/router/index.js'),
    read('src/config/accessControl.js'),
  ])
  assert.match(router, /name: 'campus-notices'/)
  assert.match(access, /'campus-notices': \[ROLES\.REGISTRAR_STAFF, ROLES\.ADMIN\]/)
  assert.match(service, /post\(`\/campus-notices\/\$\{id\}\/publish`\)/)
  assert.match(service, /post\(`\/campus-notices\/\$\{id\}\/archive`\)/)
  assert.match(view, /Live preview/)
  assert.match(view, /Weekly repeat/)
  assert.match(view, /notice-command-card/)
  assert.match(view, /publishCampusNotice\(saved\.id\)/)
  assert.match(view, /Asia\/Manila/)
  assert.match(view, /Weather forecasts cannot publish suspensions/)
})

test('weather remains optional and the normalized Buddy API stays a single request', async () => {
  const [buddyService, weather] = await Promise.all([
    read('src/services/portalNoticeService.js'),
    read('../backend/app/Services/CampusWeatherService.php'),
  ])
  assert.match(buddyService, /get\('\/portal-notices'\)/)
  assert.match(buddyService, /if \(noticesRequest\) return noticesRequest/)
  assert.match(buddyService, /if \(noticesRequest === request\) noticesRequest = null/)
  assert.match(weather, /campus_weather\.enabled/)
  assert.match(weather, /Cache::put/)
  assert.match(weather, /catch \(Throwable\)/)
})
