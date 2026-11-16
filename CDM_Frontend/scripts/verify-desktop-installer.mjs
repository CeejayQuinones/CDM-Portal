import { createHash } from 'node:crypto'
import { createReadStream, openSync, readSync, closeSync, readdirSync, statSync } from 'node:fs'
import { readFile } from 'node:fs/promises'
import { dirname, join } from 'node:path'
import { spawnSync } from 'node:child_process'
import { fileURLToPath, pathToFileURL } from 'node:url'

const frontendDirectory = dirname(dirname(fileURLToPath(import.meta.url)))
const minimumInstallerBytes = 10 * 1024 * 1024

export function inspectPeHeader(header) {
  if (header.length < 64 || header.toString('ascii', 0, 2) !== 'MZ') {
    throw new Error('Installer is not a Windows PE executable.')
  }

  const peOffset = header.readUInt32LE(0x3c)
  if (peOffset + 24 > header.length || header.toString('ascii', peOffset, peOffset + 4) !== 'PE\0\0') {
    throw new Error('Installer has an invalid PE header.')
  }

  const optionalHeader = peOffset + 24
  const magic = header.readUInt16LE(optionalHeader)
  const directoryOffset = magic === 0x10b ? 96 : magic === 0x20b ? 112 : null
  if (directoryOffset === null || optionalHeader + directoryOffset + 40 > header.length) {
    throw new Error('Installer has an invalid PE optional header.')
  }

  const certificateDirectory = optionalHeader + directoryOffset + (4 * 8)
  return {
    certificateOffset: header.readUInt32LE(certificateDirectory),
    certificateSize: header.readUInt32LE(certificateDirectory + 4),
  }
}

export function assertInstallerPayload(installerBytes, archiveBytes = 0) {
  if (installerBytes < minimumInstallerBytes || (archiveBytes > 0 && installerBytes < archiveBytes)) {
    throw new Error('Installer is too small to contain the application payload; the build may be incomplete. Do not distribute it.')
  }
}

function validWindowsSignature(installerPath) {
  if (process.platform !== 'win32') {
    throw new Error('Authenticode trust must be verified on Windows before distribution.')
  }

  const quotedPath = installerPath.replaceAll("'", "''")
  const command = `$signature = Get-AuthenticodeSignature -LiteralPath '${quotedPath}'; if ($signature.Status -ne 'Valid') { Write-Error "Authenticode status: $($signature.Status)"; exit 1 }; Write-Output "Verified publisher: $($signature.SignerCertificate.Subject)"`
  const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], {
    encoding: 'utf8',
    maxBuffer: 1024 * 1024,
  })
  if (result.error || result.status !== 0) {
    throw new Error(result.stderr?.trim() || result.error?.message || 'Windows could not verify the installer signature.')
  }
  return result.stdout.trim()
}

async function sha256(filePath) {
  const hash = createHash('sha256')
  for await (const chunk of createReadStream(filePath)) hash.update(chunk)
  return hash.digest('hex')
}

export async function verifyDesktopInstaller({ requireSignature = false } = {}) {
  const packageJson = JSON.parse(await readFile(join(frontendDirectory, 'package.json'), 'utf8'))
  const releaseDirectory = join(frontendDirectory, packageJson.build.directories.output)
  const installerPath = join(releaseDirectory, `${packageJson.build.productName} Setup ${packageJson.version}.exe`)
  const installerBytes = statSync(installerPath).size
  const archiveBytes = readdirSync(releaseDirectory)
    .filter((name) => name.includes(packageJson.version) && name.endsWith('.nsis.7z'))
    .map((name) => statSync(join(releaseDirectory, name)).size)
    .reduce((maximum, size) => Math.max(maximum, size), 0)

  assertInstallerPayload(installerBytes, archiveBytes)

  const descriptor = openSync(installerPath, 'r')
  const header = Buffer.alloc(Math.min(installerBytes, 64 * 1024))
  try {
    readSync(descriptor, header, 0, header.length, 0)
  } finally {
    closeSync(descriptor)
  }
  const certificate = inspectPeHeader(header)
  if (certificate.certificateSize > 0 && certificate.certificateOffset + certificate.certificateSize > installerBytes) {
    throw new Error('Installer contains an invalid Authenticode certificate directory.')
  }
  if (requireSignature && certificate.certificateSize === 0) {
    throw new Error('Installer is unsigned. Sign with a trusted publisher certificate before distribution.')
  }

  const digest = await sha256(installerPath)
  process.stdout.write(`Installer: ${installerPath}\nSize: ${installerBytes} bytes\nSHA-256: ${digest}\n`)
  if (requireSignature) {
    process.stdout.write(`Installer ${validWindowsSignature(installerPath)}\n`)
    const appExecutable = join(releaseDirectory, 'win-unpacked', `${packageJson.build.productName}.exe`)
    process.stdout.write(`App ${validWindowsSignature(appExecutable)}\n`)
  } else if (certificate.certificateSize === 0) {
    process.stdout.write('WARNING: installer is unsigned and may be blocked by Windows SmartScreen. This is a build check, not distribution approval.\n')
  } else {
    process.stdout.write('Signature data is present; run with --require-signature on Windows to verify publisher trust.\n')
  }
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  verifyDesktopInstaller({ requireSignature: process.argv.includes('--require-signature') }).catch((error) => {
    process.stderr.write(`Desktop package check failed: ${error.message}\n`)
    process.exitCode = 1
  })
}
