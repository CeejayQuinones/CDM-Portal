# CDM Portal

Frontend scaffold for a Vue 3 campus management system.

## Tech Stack

- Vue 3
- Vite
- Vue Router
- Pinia
- vite-plugin-pwa
- Capacitor
- Electron

## Project Setup

```bash
npm install
npm run dev
```

## Available Scripts

```bash
npm run dev
npm run build
npm run preview
npm run mobile:sync
npm run mobile:open:android
npm run desktop:dev
npm run desktop:build
```

## PWA

Run a production build to generate the web app manifest and service worker:

```bash
npm run build
```

The generated installable PWA files are in `dist/`, including `manifest.webmanifest`
and `sw.js`. In supported browsers, the navbar shows an `Install App` button when
the install prompt is available.

## Mobile with Capacitor

The Capacitor config uses `dist/` as the native web directory.

```bash
npm run mobile:sync
npm run mobile:open:android
```

The Android native project lives in `android/`. Build APK/AAB files from Android
Studio after syncing. iOS support is scaffolded through scripts, but iOS builds
must be done on macOS with Xcode.

## Desktop with Electron

Build a Windows installer with:

```bash
npm run desktop:build
```

The installer is generated in `release/`, for example
`release/CDM Portal Setup 0.1.0.exe`.

## Module Folders

Each campus module has its own folder under `src/modules` so developers can work independently:

- `enrollment`
- `grading`
- `monitoring`
- `registrar`
- `records`
- `events`

Shared layout, navigation, stores, and services live outside module folders.
