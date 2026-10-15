CDM Portal Setup Guide

This guide is for team members setting up CDM Portal on their own PC for the first time.

Requirements

CDM Portal currently uses:

Laravel 13 backend

PHP 8.3 or higher

Vue 3 + Vite frontend

MySQL / MariaDB

Laravel Sanctum authentication

Laravel Octane support

Capacitor for Android

Electron for desktop

The backend currently requires PHP ^8.3.

1. Install the required software

Install these first.

Required

Git
https://git-scm.com/downloads

Visual Studio Code
https://code.visualstudio.com/download

Node.js LTS
https://nodejs.org/en/download

PHP 8.3+
https://www.php.net/downloads.php

Composer
https://getcomposer.org/download/

XAMPP
https://www.apachefriends.org/download.html

You may use Laragon instead of XAMPP if preferred:

https://laragon.org/download/

Optional

Android Studio — for Android APK testing
https://developer.android.com/studio

WSL2 — recommended if you want to use Octane + FrankenPHP for faster backend development

2. Verify your installation

Open PowerShell or the VS Code terminal and run:

git --version
php -v
composer -V
node -v
npm -v

Make sure PHP is:

PHP 8.3 or higher

3. Clone the repository

For first-time setup:

git clone https://github.com/CeejayQuinones/CDM-Portal.git
cd CDM-Portal

If you already have the project:

git pull

For normal team development, make sure you are working from the correct branch, usually:

git switch dev
git pull origin dev

Do not work directly on main unless the team specifically agrees to do so.

Backend Setup

4. Open the backend folder

cd backend

5. Install PHP dependencies

composer install

Run this:

after first cloning the project

after composer.json or composer.lock changes

if the vendor folder is missing

It is safe to run again after pulling updates.

6. Create your backend .env

PowerShell:

Copy-Item .env.example .env

Git Bash / Linux / WSL:

cp .env.example .env

Never commit .env to GitHub.

Each member should have their own local .env.

7. Configure MySQL

Start MySQL using XAMPP or Laragon.

Apache is not required for Laravel development.

Open phpMyAdmin and create:

cdm_portal

Then configure backend/.env:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cdm_portal
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=file

If your MySQL account has a password, set:

DB_PASSWORD=your_password

8. Generate the Laravel application key

For a newly created .env:

php artisan key:generate

Then clear cached configuration:

php artisan optimize:clear

Run optimize:clear again whenever important .env values are changed.

Database Setup

9. First-time clean database

If this is a brand-new local setup and you do not need to preserve any database data:

php artisan migrate:fresh --seed

⚠️ Warning

migrate:fresh deletes all existing tables and data before rebuilding them.

Only use it when losing your local data is acceptable.

10. Apply new migrations without deleting data

After pulling new updates:

php artisan migrate

Use this when you want to preserve existing data.

Do not use migrate:fresh just because a new migration was added.

11. Run normal seeders

php artisan db:seed

Large Test Dataset

The project includes an optional large dataset for testing:

Student Records

Student Profiles

Physical Records

Cabinets

Document Requests

Appointments

History

Search

Filters

Pagination

Performance with thousands of records

Generate it with:

php artisan db:seed --class=LargeDatasetSeeder

Remove only generated staging data:

php artisan db:seed --class=LargeDatasetCleanupSeeder

Rebuild it:

php artisan db:seed --class=LargeDatasetCleanupSeeder
php artisan db:seed --class=LargeDatasetSeeder

The large dataset is optional and is not part of the normal default seeding process.

Frontend Setup

12. Open another terminal

From the project root:

cd CDM_Frontend

If you are currently inside backend:

cd ../CDM_Frontend

13. Install frontend dependencies

npm install

Run this:

during first setup

after package.json / package-lock.json changes

if node_modules is missing

14. Configure the frontend API URL

Create:

CDM_Frontend/.env

For normal same-PC Windows development:

VITE_API_BASE_URL=http://127.0.0.1:8000/api

For a phone or another device on the same Wi-Fi:

VITE_API_BASE_URL=http://YOUR_PC_IP:8000/api

Find your Windows IPv4 address with:

ipconfig

Look for:

IPv4 Address

Do not copy another member's IP address into your .env.

Every PC/network can have a different IP.

After changing the frontend .env, restart Vite.

15. Configure CORS

The Laravel backend uses an origin allowlist.

Example for local development:

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost,capacitor://localhost

For browser testing from another device, add that PC's Vite address:

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost,capacitor://localhost,http://YOUR_PC_IP:5173

Do not use:

CORS_ALLOWED_ORIGINS=*

After changing backend .env:

php artisan optimize:clear

Then restart the backend.

Running the System — Simple Setup

For most team members, this is the easiest setup.

Start MySQL first.

Terminal 1 — Laravel backend

cd backend
php artisan serve

Backend:

http://127.0.0.1:8000

For phone/LAN testing:

php artisan serve --host=0.0.0.0 --port=8000

Terminal 2 — Vue frontend

cd CDM_Frontend
npm run dev

Frontend normally runs at:

http://localhost:5173

For another device on the LAN:

npm run dev -- --host=0.0.0.0

Important Background Processes

Some CDM Portal features need additional Laravel processes.

Terminal 3 — Document AI queue

Required for asynchronous document AI analysis:

cd backend
php artisan queue:work --queue=document-analysis -v

If this is not running, the main portal can still open, but queued document analysis will not be processed.

Terminal 4 — Laravel scheduler

The new Document Request / Appointment workflow uses scheduled tasks, including automatic handling of missed appointments.

Run:

cd backend
php artisan schedule:work

For full feature testing, your development setup should therefore be:

MySQL
├── Laravel backend
├── Document AI queue
├── Laravel scheduler
└── Vue/Vite frontend

Recommended Faster Backend — WSL2 + Octane + FrankenPHP

This is optional.

The normal php artisan serve setup still works, but Laravel Octane + FrankenPHP gives much better backend performance.

Use this only if you already have WSL2/Ubuntu configured.

Inside WSL:

cd ~/projects/CDM_Portal/backend

php artisan octane:start \
  --server=frankenphp \
  --host=0.0.0.0 \
  --port=8000

Do not run:

php artisan serve

at the same time as Octane on port 8000.

Useful Octane commands:

php artisan octane:status
php artisan octane:reload
php artisan octane:stop

After backend PHP code changes, you can reload workers with:

php artisan octane:reload

WSL Development Note

If you run the backend in WSL while Chrome/Vite runs from Windows, check the WSL IP:

hostname -I

Example:

172.xx.xx.xx

Then your frontend development environment may need:

VITE_API_BASE_URL=http://YOUR_WSL_IP:8000/api

WSL IP addresses may change after rebooting Windows/WSL.

Restart Vite after changing this value.

Normal Daily Workflow

Basic development

1. Start XAMPP/Laragon MySQL
2. Start Laravel
3. Start Vite

Backend:

cd backend
php artisan serve

Frontend:

cd CDM_Frontend
npm run dev

Full feature development

Also start:

php artisan queue:work --queue=document-analysis -v

and:

php artisan schedule:work

After Pulling New Updates

From project root:

git pull

Backend:

cd backend
composer install
php artisan optimize:clear
php artisan migrate

Frontend:

cd ../CDM_Frontend
npm install
npm run dev

composer install and npm install are safe to run again.

Do not run migrate:fresh after every pull.

Quick First-Time Setup

git clone https://github.com/CeejayQuinones/CDM-Portal.git
cd CDM-Portal

cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan optimize:clear

Create the cdm_portal MySQL database and configure .env, then:

php artisan migrate:fresh --seed

Frontend:

cd ../CDM_Frontend
npm install
npm run dev

Optional large test data:

cd ../backend
php artisan db:seed --class=LargeDatasetSeeder

Offline Demo Mode

CDM Portal also has an offline demonstration mode.

Run:

cd CDM_Frontend
npm run demo

Build offline demo:

npm run demo:build

Sync the offline demo to Android:

npm run demo:android

Offline demo mode uses local browser/device storage and does not require the Laravel backend for its supported demonstration workflow.

Android Testing

Requirements:

Android Studio installed

phone and PC on the same Wi-Fi for online mode

backend exposed using 0.0.0.0

correct PC LAN IP in the frontend .env

Windows Firewall allows port 8000

Run backend:

cd backend
php artisan serve --host=0.0.0.0 --port=8000

Then:

cd ../CDM_Frontend
npm run mobile:sync
npm run mobile:open:android

Manual equivalent:

npm run build
npx cap sync android
npx cap open android

Desktop / Electron

Development:

cd CDM_Frontend
npm run desktop:dev

Build desktop app:

npm run desktop:build

Testing

Backend:

cd backend
php artisan test

Frontend:

cd CDM_Frontend
npm test

Production frontend build:

npm run build

Offline demo build:

npm run demo:build

Useful Laravel Commands

php artisan migrate:status
php artisan migrate
php artisan db:seed
php artisan optimize:clear
php artisan route:list
php artisan test

Fresh database:

php artisan migrate:fresh --seed

⚠️ Deletes existing local database tables/data.

Large dataset:

php artisan db:seed --class=LargeDatasetSeeder

Remove large dataset:

php artisan db:seed --class=LargeDatasetCleanupSeeder

Useful Frontend Commands

npm run dev
npm test
npm run build
npm run preview
npm run demo
npm run demo:build
npm run demo:android
npm run mobile:sync
npm run mobile:open:android
npm run desktop:dev
npm run desktop:build

Common Problems

Unknown database 'cdm_portal'

Create the database first in phpMyAdmin:

cdm_portal

Then verify backend/.env.

could not find driver

Check PHP database extensions.

Common required extensions include:

extension=pdo_mysql
extension=mysqli

Restart the terminal after changing php.ini.

Composer reports unsupported PHP version

Run:

php -v

CDM Portal requires:

PHP 8.3+

Frontend cannot connect to backend

Check:

MySQL is running

Laravel/Octane backend is running

VITE_API_BASE_URL is correct

backend port is 8000

for LAN/mobile, do not use 127.0.0.1

phone and PC are on the same network

Windows Firewall allows port 8000

CORS includes the correct frontend origin

After changing .env:

Backend:

php artisan optimize:clear

Frontend:

npm run dev

Appointment auto-cancellation is not running

Make sure:

php artisan schedule:work

is running during development.

AI document analysis stays pending

Make sure the queue worker is running:

php artisan queue:work --queue=document-analysis -v

Port 8000 already in use

Do not run both:

php artisan serve

and:

php artisan octane:start ...

on port 8000 at the same time.

Important Team Rules

Never commit .env.

Every member has their own local database.

Every member has their own backend/frontend .env.

Start MySQL before using Laravel database commands.

Use php artisan migrate when preserving existing data.

Use migrate:fresh --seed only for disposable local databases.

Do not manually copy vendor.

Do not manually copy node_modules.

vendor is installed by Composer.

node_modules is installed by npm.

Do not hardcode another member's IP address.

Do not commit API keys, database passwords, or other secrets.

Use feature branches and merge into dev before main.