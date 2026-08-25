# CDM Portal Setup Guide


cd backend
php artisan db:seed --class=LargeDatasetCleanupSeeder
php artisan db:seed --class=LargeDatasetSeeder



## install these first

- Git
- VS Code
- Node.js LTS
- PHP 8.2+
- Composer
- XAMPP or Laragon
- Android Studio (optional kung gusto mag test sa android)

---

## clone the repository

```bash
git clone <repository-url>
```

i-download nito yung latest project galing sa GitHub.

---

## backend (laravel)

mag open muna ng bagong terminal.

```
Ctrl + Shift + `
```

punta sa backend folder.

```bash
cd backend
```

install lahat ng kailangan ni laravel.

```bash
composer install
```

first setup lang to o kaya kapag may bagong package na na-pull sa github.

---

gumawa ng `.env`.

```bash
cp .env.example .env
```

ito yung local configuration ng backend. dito nakalagay yung database settings at iba pang kailangan ni laravel.

---

generate yung app key.

```bash
php artisan key:generate
```

required ito bago gumana yung laravel project.

---

create yung database.

```bash
php artisan migrate:fresh --seed
```

gagawa nito lahat ng tables tapos maglalagay ng default data.

**make sure naka-start muna yung MySQL sa XAMPP o Laragon.**

 mabubura lahat ng existing data kapag ginamit ito.

---

start yung backend.

```bash
php artisan serve --host=0.0.0.0
```

ito yung backend server. kapag hindi ito naka-run, hindi gagana yung login, register, student records, at lahat ng requests papunta sa database.

---

## frontend (vue)

punta sa frontend.

```bash
cd CDM_Frontend
```

install lahat ng packages.

```bash
npm install
```

first setup lang din ito o kapag may bagong package na na-pull.

---

gumawa ng `.env`.

```env
VITE_API_BASE_URL=http://YOUR_IP:8000/api
```

example:

```env
VITE_API_BASE_URL=http://192.168.100.34:8000/api
```

ito yung address ng backend.

palitan lang yung `YOUR_IP` ng IP address ng sarili mong PC.

iba-iba ang IP ng bawat member kaya kailangan baguhin ito.

---

start yung frontend.

```bash
npm run dev
```

ito naman yung nagpapaandar ng website.

---

## android (optional)

kapag gusto mo mag test sa phone.

build muna.

```bash
npm run build
```

katapos i-sync sa android project.

```bash
npm run mobile:sync
```

tapos buksan sa android studio.

```bash
npm run mobile:open:android
```

kapag may binago sa frontend, ulitin lang yung build at sync bago mag-run sa phone.

---

## araw-araw na gagawin

backend

```bash
cd backend
php artisan serve --host=0.0.0.0
```

frontend

```bash
cd CDM_Frontend
npm run dev
```

habang nagco-code, pareho dapat naka-run yung backend at frontend.

---

## kapag first time mag setup

```bash
git clone <repository-url>

cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed

cd ../CDM_Frontend
npm install
```

---

## notes

- start muna yung MySQL bago mag migrate.
- wag i-push yung `.env` sa github.
- bawat member may sariling `.env` at sariling IP address.
- kapag may bagong package galing sa github, gawin ulit:

```bash
composer install
npm install
```

- kung android yung ite-test at may binago sa frontend:

```bash
npm run build
npm run mobile:sync
```

para makita yung latest changes sa phone.