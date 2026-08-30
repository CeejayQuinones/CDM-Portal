CDM Portal Setup Guide

simple setup guide para sa mga member na magse-set up ng CDM Portal sa sariling PC.

Important: ang project backend natin ay Laravel 13 at kailangan ng PHP 8.3 or higher.

1. install these first

required

Git
https://git-scm.com/downloads

Visual Studio Code
https://code.visualstudio.com/download

Node.js LTS
https://nodejs.org/en/download

PHP 8.3+
https://www.php.net/downloads.php
Windows PHP guide: https://www.php.net/manual/en/install.windows.php

Composer
https://getcomposer.org/download/

XAMPP
https://www.apachefriends.org/download.html

or

Laragon
https://laragon.org/download/

optional

Android Studio — kung gusto mag-test ng Android app
https://developer.android.com/studio

2. check kung installed nang tama

open PowerShell or VS Code terminal then run:

git --version
php -v
composer -V
node -v
npm -v

make sure PHP is 8.3 or higher.

3. clone the repository

kung first time mo pa lang kukunin yung project:

git clone https://github.com/CeejayQuinones/CDM-Portal.git
cd CDM-Portal

kung meron ka nang existing copy, hindi na kailangan mag-clone ulit.

latest update lang:

git pull

backend setup

4. punta sa backend

cd backend

5. install backend dependencies

composer install

usually kailangan ito:

first setup

kapag may bagong package sa composer.json / composer.lock

kapag may kulang sa vendor

safe lang din i-run ulit after pull.

6. gumawa ng .env

PowerShell:

Copy-Item .env.example .env

pwede rin:

cp .env.example .env

ang .env ay local configuration mo. wag itong i-push sa GitHub.

7. configure MySQL

start muna ang MySQL sa XAMPP or Laragon.

gumawa ng database sa phpMyAdmin:

cdm_portal

then sa backend/.env, make sure ganito:

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cdm_portal
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=file

kung may password ang MySQL mo, ilagay sa DB_PASSWORD.

8. generate Laravel app key

php artisan key:generate

first setup lang ito after gumawa ng bagong .env.

9. clear old config

php artisan optimize:clear

useful ito especially kapag may binago sa .env.

database

10. first-time database setup

kung bagong setup at okay lang mabura ang existing local database:

php artisan migrate:fresh --seed

ito ay:

magde-delete ng existing tables

gagawa ulit ng lahat ng tables

magra-run ng normal/default seeders

warning: mabubura lahat ng existing local database data kapag gumamit ng migrate:fresh.

11. kapag may bagong migration lang after git pull

kung gusto mong i-keep ang existing data:

php artisan migrate

hindi nito dine-delete ang existing tables/data.

12. normal seed data

kung kailangan lang i-run ang regular seeders:

php artisan db:seed

large / staging dataset

may optional large dataset tayo para ma-test ang:

Student Records

Student Profiles

Physical Records / Cabinets

Document Requests

Appointments

History

Search

Filters

Pagination

performance with thousands of records

13. generate large dataset

php artisan db:seed --class=LargeDatasetSeeder

ito ay test/staging data lang at hindi kasama automatically sa normal DatabaseSeeder.

14. remove large generated dataset only

php artisan db:seed --class=LargeDatasetCleanupSeeder

ito ang gamitin kapag gusto mong alisin yung generated staging data nang hindi dine-delete yung normal seeded accounts.

15. rebuild large dataset

kung gusto mo i-refresh yung generated data:

php artisan db:seed --class=LargeDatasetCleanupSeeder
php artisan db:seed --class=LargeDatasetSeeder

run backend

16. start Laravel

for normal web testing sa same PC:

php artisan serve

for testing sa phone / another device sa same Wi-Fi:

php artisan serve --host=0.0.0.0

default backend:

http://127.0.0.1:8000

kapag --host=0.0.0.0, ibang device can connect gamit ang local IPv4 address ng PC mo.

check your IPv4:

ipconfig

hanapin yung:

IPv4 Address

use the IPv4 address reported by `ipconfig`; it may change when you join a different network.

backend CORS origins are configured in `backend/.env` as a comma-separated allowlist:

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost,capacitor://localhost

the first two origins support same-PC Vite development. the last two support the bundled Capacitor app. for browser testing from another device, append the exact Vite origin:

CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost,capacitor://localhost,http://YOUR_PC_IP:5173

do not set `CORS_ALLOWED_ORIGINS=*`. production must set this variable to the exact deployed frontend origin or origins.

after changing backend `.env`, clear Laravel's cached configuration and restart the server:

php artisan optimize:clear

frontend setup

17. open another terminal

from project root:

cd CDM_Frontend

kung nasa backend folder ka:

cd ../CDM_Frontend

18. install frontend packages

npm install

usually kailangan:

first setup

kapag may bagong package sa package.json / lock file

kapag missing ang node_modules

19. frontend .env

gumawa ng:

CDM_Frontend/.env

same PC browser testing

VITE_API_BASE_URL=http://127.0.0.1:8000/api

Capacitor phone / LAN API testing

VITE_API_BASE_URL=http://YOUR_PC_IP:8000/api

for browser testing from another device, also expose Vite on the LAN:

npm run dev -- --host=0.0.0.0

palitan ang IP depende sa PC/network mo.

iba-iba ang IP ng bawat member, kaya wag i-hardcode ang IP ng ibang member.

after baguhin ang frontend .env, restart:

npm run dev

20. start frontend

npm run dev

normally Vite will show something like:

http://localhost:5173

normal daily workflow

usually dalawang terminal lang kailangan.

terminal 1 — backend

cd backend
php artisan serve

or for mobile/LAN:

php artisan serve --host=0.0.0.0

terminal 2 — frontend

cd CDM_Frontend
npm run dev

habang nagde-develop, usually parehong naka-run ang backend at frontend.

after pulling new updates

kapag existing na yung project sa laptop at nag-pull lang ng latest code:

git pull

then backend:

cd backend
composer install
php artisan optimize:clear
php artisan migrate

then frontend:

cd ../CDM_Frontend
npm install
npm run dev

composer install at npm install are safe to run again. kung walang bagong dependencies, mabilis lang sila matatapos.

fresh setup quick commands

kung bagong clone at gusto mo ng clean local database:

git clone https://github.com/CeejayQuinones/CDM-Portal.git
cd CDM-Portal/backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan config:clear
php artisan migrate:fresh --seed

then:

cd ../CDM_Frontend
npm install
npm run dev

optional large staging data:

cd ../backend
php artisan db:seed --class=LargeDatasetSeeder

android testing (optional)

make sure:

phone and PC are on the same Wi-Fi

frontend .env uses your PC IPv4 address

Laravel is running with --host=0.0.0.0

Windows Firewall allows the connection

example:

cd backend
php artisan serve --host=0.0.0.0

frontend:

cd ../CDM_Frontend
npm run mobile:sync
npm run mobile:open:android

mobile:sync already builds the frontend before syncing the Capacitor Android project.

if needed manually:

npm run build
npx cap sync android
npx cap open android

desktop / electron (optional)

development:

cd CDM_Frontend
npm run desktop:dev

build:

npm run desktop:build

useful Laravel commands

check migrations:

php artisan migrate:status

run new migrations:

php artisan migrate

fresh database:

php artisan migrate:fresh --seed

normal seeders:

php artisan db:seed

large staging dataset:

php artisan db:seed --class=LargeDatasetSeeder

remove large staging data:

php artisan db:seed --class=LargeDatasetCleanupSeeder

clear Laravel caches/config:

php artisan optimize:clear

check API routes:

php artisan route:list

run tests:

php artisan test

useful frontend commands

start development:

npm run dev

production build:

npm run build

preview build:

npm run preview

Android sync:

npm run mobile:sync

open Android Studio:

npm run mobile:open:android

common problems

Unknown database 'cdm_portal'

create muna yung database sa phpMyAdmin:

cdm_portal

then check .env.

could not find driver

usually PHP database extension issue.

check kung enabled ang:

extension=pdo_mysql
extension=mysqli

then restart terminal/XAMPP.

mysqli extension is missing

enable this sa php.ini:

extension=mysqli

then restart Apache.

Composer says PHP version is not supported

check:

php -v

this project needs:

PHP 8.3+

make sure yung PHP na ginagamit ng terminal ay yung correct version.

frontend cannot connect to backend

check:

Laravel is running.

correct VITE_API_BASE_URL.

if phone/LAN, use PC IPv4 instead of 127.0.0.1.

same Wi-Fi.

Windows Firewall is not blocking port 8000.

after changing .env

backend:

php artisan optimize:clear

frontend:

stop and restart:

npm run dev

important notes

wag i-push ang .env.

bawat member may sariling local database.

bawat member may sariling backend/frontend .env.

start MySQL before migrations or Laravel database operations.

use php artisan migrate when you want to preserve existing data.

use php artisan migrate:fresh --seed only when okay lang mabura ang local database.

large generated data is optional.

use LargeDatasetCleanupSeeder before rebuilding the large dataset.

vendor comes from Composer.

node_modules comes from npm.

do not manually copy vendor or node_modules between members.
