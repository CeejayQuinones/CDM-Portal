const { contextBridge } = require('electron')

contextBridge.exposeInMainWorld('cdmShell', 'desktop')
