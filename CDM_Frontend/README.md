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
copy .env.example .env
npm run dev
```

Backend (separate terminal):

```bash
cd ../backend
php artisan serve --host=0.0.0.0 --port=8000
```

## AI Monitoring on every platform

| Platform | Command | Who can use AI Monitoring |
|----------|---------|---------------------------|
| Web | `npm run dev` → http://127.0.0.1:5173 | Student, Professor, Registrar, Admin |
| Desktop | `npm run desktop:dev` | Same as web |
| Mobile (phone) | `npm run mobile:sync` then Android Studio Run | **Students only** |
| Mobile (emulator) | `npm run mobile:sync:emulator` then Run | **Students only** |

Demo logins: `student1` / `Student123!`, `admin` / `Admin123!`

## Available Scripts

```bash
npm run dev
npm run build
npm run preview
npm run mobile:sync
npm run mobile:sync:emulator
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

## Mobile with Capacitor (Student app)

Student-only. API host is set in `.env.mobile` (LAN IP) or `.env.emulator` (`10.0.2.2`).

```bash
# Physical phone (same Wi-Fi as PC) — edit .env.mobile if your LAN IP changed
npm run mobile:sync
npm run mobile:open:android

# Android emulator
npm run mobile:sync:emulator
npm run mobile:open:android
```

Then Run ▶ in Android Studio. Keep backend on `0.0.0.0:8000`.

## Desktop with Electron

```bash
# Dev window (uses .env → 127.0.0.1 API)
npm run desktop:dev

# Windows installer
npm run desktop:build
```

Installer output: `release/CDM Portal Setup 0.1.0.exe`.

## Module Folders

Each campus module has its own folder under `src/modules` so developers can work independently:

- `enrollment`
- `grading`
- `monitoring`
- `registrar`
- `records`
- `events`

Shared layout, navigation, stores, and services live outside module folders.
