// Build-only fallback for Linux hosts without Wine. electron-builder already
// uses this NSIS uninstaller extractor on macOS Catalina; reuse it only for
// the temporary uninstaller-generation executable, leaving other calls alone.
const path = require('node:path')
const { WineVmManager } = require('app-builder-lib/out/vm/WineVm')
const { UninstallerReader } = require('app-builder-lib/out/targets/nsis/nsisUtil')

const originalExec = WineVmManager.prototype.exec

WineVmManager.prototype.exec = function exec(file, args, options, ...rest) {
  if (
    process.platform === 'linux' &&
    Array.isArray(args) &&
    args.length === 0 &&
    options?.env?.__COMPAT_LAYER === 'RunAsInvoker' &&
    file.toLowerCase().endsWith('.exe')
  ) {
    const uninstaller = path.join(
      path.dirname(file),
      `${path.basename(file, 'exe')}__uninstaller.exe`,
    )
    return UninstallerReader.exec(file, uninstaller)
  }

  return originalExec.call(this, file, args, options, ...rest)
}
