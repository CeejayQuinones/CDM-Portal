import assert from 'node:assert/strict'
import test from 'node:test'
import { ROLES } from '../src/config/accessControl.js'
import {
  APP_SHELLS,
  DESKTOP_SHELL_MESSAGE,
  MOBILE_SHELL_MESSAGE,
  detectAppShell,
  shellBlockMessage,
} from '../src/config/appShell.js'

test('browser runtime stays the web app', () => {
  assert.equal(detectAppShell({}), APP_SHELLS.WEB)
})

test('Capacitor native runtime is the mobile app', () => {
  assert.equal(detectAppShell({ native: true }), APP_SHELLS.MOBILE)
})

test('Electron runtime is the desktop app', () => {
  assert.equal(detectAppShell({ electron: true }), APP_SHELLS.DESKTOP)
  assert.equal(detectAppShell({ shell: 'desktop' }), APP_SHELLS.DESKTOP)
})

test('web app allows every portal role', () => {
  for (const role of Object.values(ROLES)) {
    assert.equal(shellBlockMessage(APP_SHELLS.WEB, role), '')
  }
})

test('mobile app allows students and blocks staff roles', () => {
  assert.equal(shellBlockMessage(APP_SHELLS.MOBILE, ROLES.STUDENT), '')
  for (const role of [ROLES.PROFESSOR, ROLES.ADMIN, ROLES.REGISTRAR_STAFF, ROLES.GUEST]) {
    assert.equal(shellBlockMessage(APP_SHELLS.MOBILE, role), MOBILE_SHELL_MESSAGE)
  }
})

test('desktop app allows staff roles and blocks students', () => {
  for (const role of [ROLES.PROFESSOR, ROLES.ADMIN, ROLES.REGISTRAR_STAFF]) {
    assert.equal(shellBlockMessage(APP_SHELLS.DESKTOP, role), '')
  }
  assert.equal(shellBlockMessage(APP_SHELLS.DESKTOP, ROLES.STUDENT), DESKTOP_SHELL_MESSAGE)
  assert.equal(shellBlockMessage(APP_SHELLS.DESKTOP, ROLES.GUEST), DESKTOP_SHELL_MESSAGE)
})
