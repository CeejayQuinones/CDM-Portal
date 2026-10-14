const { app, BrowserWindow, shell } = require('electron')
const path = require('path')
const { pathToFileURL } = require('url')

const appEntryUrl = pathToFileURL(path.join(__dirname, '../dist/index.html')).toString()
const isAppUrl = (url) => {
  try {
    const target = new URL(url)
    const entry = new URL(appEntryUrl)
    return target.protocol === 'file:' && target.pathname === entry.pathname
  } catch {
    return false
  }
}
const openExternalHttpUrl = (url) => {
  try {
    const protocol = new URL(url).protocol
    if (protocol === 'https:' || protocol === 'http:') shell.openExternal(url)
  } catch {
    // Ignore malformed and unsupported external URLs.
  }
}

const createWindow = () => {
  const mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 960,
    minHeight: 640,
    title: 'CDM Portal',
    icon: path.join(__dirname, '../build/icon.png'),
    autoHideMenuBar: true,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
    },
  })

  mainWindow.loadURL(appEntryUrl)

  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    openExternalHttpUrl(url)
    return { action: 'deny' }
  })

  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (isAppUrl(url)) return

    event.preventDefault()
    openExternalHttpUrl(url)
  })
}

app.whenReady().then(() => {
  createWindow()

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow()
    }
  })
})

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') {
    app.quit()
  }
})
