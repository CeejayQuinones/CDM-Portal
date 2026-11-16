import test from 'node:test'
import assert from 'node:assert/strict'
import { assertInstallerPayload, inspectPeHeader } from '../scripts/verify-desktop-installer.mjs'

test('rejects a leftover NSIS stub without its application payload', () => {
  assert.throws(() => assertInstallerPayload(360_253, 97_970_494), /too small/)
  assert.doesNotThrow(() => assertInstallerPayload(100_000_000, 97_970_494))
})

test('reads the Windows certificate directory without treating it as verified trust', () => {
  const header = Buffer.alloc(512)
  header.write('MZ')
  header.writeUInt32LE(128, 0x3c)
  header.write('PE\0\0', 128)
  header.writeUInt16LE(0x10b, 152)
  const certificateDirectory = 152 + 96 + (4 * 8)
  header.writeUInt32LE(1000, certificateDirectory)
  header.writeUInt32LE(128, certificateDirectory + 4)
  assert.deepEqual(inspectPeHeader(header), { certificateOffset: 1000, certificateSize: 128 })
})
