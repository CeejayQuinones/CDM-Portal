// Vite bundles every renderer dependency into dist, while the Electron main
// process imports only Node/Electron built-ins. Tell electron-builder there
// are no external runtime node_modules to rebuild or copy into the package.
module.exports = async () => false
