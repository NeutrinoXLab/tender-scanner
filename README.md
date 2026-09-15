# Tender Scanner v1

Aplicație privată Laravel pentru oportunități SEAP și TED. PHP 8.3, Laravel 13; versiunile exacte sunt în fișierele lock. Accesul și administratorul inițial: [PRIVATE_ACCESS.md](PRIVATE_ACCESS.md). Integrarea pentru furnizori: [SUPPLIER_API.md](SUPPLIER_API.md).

## Acoperirea SEAP

API JSON public, bază `https://e-licitatie.ro/api-pub/`; fără scraping HTML:

| Familie | Listare POST | Identificator / tip |
| --- | --- | --- |
| PI, intenție de achiziție | `PUBLICPINotice/GetAll/` | `piNoticeId`, tip 1 |
| CN, participare | `PUBLICCNotice/GetAll/` | `cNoticeID`, tip 2 |
| SCN, participare simplificată | `PublicRFQInvitation/GetAll/` | `rfqInvitationId`, filtru `sysNoticeType: {id: 17}` |

Listările primesc `pageIndex`, `pageSize`, `publicationDateFrom`, `publicationDateTo`. Importul normal folosește zile întregi în Europe/Bucharest și pagini de 18. PI: detalii GET `PUBLICPINotice/getView/{id}`. CN/SCN: GET `ComboPub/getNoticeGeneralInfo/`, `NoticeCommon/GetSection1View/`, `NoticeCommon/GetSection21View/`, `NoticeCommon/GetSection4View/`, cu identificatorul și tipul familiei. Detaliile sunt cerute secvențial, pentru fiecare anunț.

Criteriul de relevanță folosește familia structurată și `sysAcquisitionContractType.id`: 1 = furnizare, 2 = servicii, 3 = lucrări. Tipurile explicite incompatibile sunt omise și raportate ca eroare, fără ștergerea datelor existente. Absența unui câmp opțional nu dovedește că anunțul este irelevant. Metadatele păstrează familia, tipul, procedura, starea, caracterul sectorial și legislația când sunt furnizate.

PI sunt **achiziții planificate**, nu dovada unei proceduri deja deschise. CN și SCN sunt oportunități de participare. Lista principală exclude stările inactive și termenele expirate; istoricul și colecțiile personale păstrează accesul la înregistrările existente. Achizițiile sectoriale, acordurile-cadru și sistemele dinamice de achiziții nu sunt excluse automat.

Nu importăm atribuiri (`ca-notices`), rezultate sau alte tipuri RFQ decât SCN 17. Alte familii, precum invitații istorice și concursuri de soluții, nu sunt activate fără validarea relevanței și a normalizării. Nu importăm cataloage de produse sau vânzări de bunuri. Referințele TED sunt păstrate în `seap_metadata.ted_references`; noile familii SEAP nu sunt unite automat cu TED.

## Scanare incrementală și reluare

„Scanează acum” → `ScanController` → `RunManualScan` → `seap:scan` / `ted:scan`. SEAP apelează explicit PI, CN și SCN. O familie lipsă împiedică raportarea unei sincronizări complete.

- Fără date alese, prima scanare acoperă ultimele 30 de zile. Ulterior începe cu 3 zile înainte de data finală a ultimei sincronizări SEAP care confirmă toate familiile. Scanările limitate sau incomplete nu avansează acest reper. O pauză lungă între scanări poate necesita recuperarea unui interval mai mare.
- Scanarea implicită adaugă o singură zi mai veche cu oportunități încă active, prin rotație: zilele niciodată reverificate, apoi cea reverificată cel mai demult. Aceeași zi este transmisă tuturor familiilor și afișată separat în statistici.
- O listare identică și detalii complete preluate în ultimele 3 zile permit reutilizarea detaliilor. O listare schimbată sau expirarea perioadei determină recitirea lor. Cache-ul nu se prelungește doar prin recitirea listării.
- API-ul filtrează după **publicare**, nu după o dată de modificare verificată. Rotația permite reverificarea procedurilor vechi, dar nu garantează detectarea imediată: o rotație completă necesită câte o scanare pentru fiecare zi eligibilă. Un interval explicit permite recuperarea completă a unei perioade istorice.
- Cererile sunt secvențiale, cu pauză implicită de 1 secundă înainte de trimitere. Conectare: 10 secunde; răspuns: 30 secunde. Maximum 3 încercări pentru timeout, 429 sau 5xx. Orice 403 oprește imediat cererile SEAP rămase, indiferent de textul răspunsului; fără retry sau bypass.
- `scan_runs.checkpoint` salvează zilele complete, identificatorii și rezultatele după fiecare anunț. „Reia scanarea întreruptă” reia același run eșuat/parțial, cu intervalul original. Zilele complete și detaliile deja procesate sunt sărite. Ziua întreruptă este relistată de la prima pagină pentru a evita omisiunile provocate de deplasarea elementelor între pagini. TED deja finalizat nu este reluat.
- După 403, verifică/restabilește accesul legitim înainte de reluare. Reluarea unei scanări vechi nu înlocuiește o sincronizare incrementală nouă.

Butonul „Testează PI/CN/SCN fără import” face câte o cerere de listare fără detalii. Test real limitat din CLI (nu apelează TED):

```sh
php artisan seap:scan --from=2026-08-03 --to=2026-09-11 --limit=1 --report --no-interaction
```

Limita este per familie, maximum 18, și se aplică local chiar dacă API-ul întoarce mai multe elemente. Se citește o singură pagină per familie. Limita 1 înseamnă cel mult 3 cereri logice de listare și 9 de detalii; erorile temporare pot produce încercări HTTP suplimentare. Endpointul manual acceptă `mode=sample`, prin același job, cu limita fixă 1 per familie și fără TED. Testele limitate nu sunt sincronizări complete.

## Deployment

Document root: `public/`, HTTPS activ, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` corect, `SESSION_SECURE_COOKIE=true`. Păstrează `.env`, `APP_KEY`, baza și `storage/` între release-uri. Nu regenera cheia unei instalări existente. PHP și workerul au nevoie de acces de scriere în `storage/` și `bootstrap/cache/`. Web, CLI și worker trebuie să folosească aceeași bază și același cache persistent; `array` nu poate transmite restartul workerului.

Înainte de actualizare: backup al bazei; lasă scanarea activă să se termine și oprește workerul prin managerul de procese. În fereastra de mentenanță execută din directorul proiectului:

```sh
php artisan down --no-interaction
# Actualizează checkout-ul/release-ul aprobat.
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan optimize:clear --no-interaction
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan queue:restart --no-interaction
# Pornește/repornește workerul prin managerul de procese.
php artisan up --no-interaction
```

`queue:restart` trebuie executat **după** curățarea cache-ului. Cere ieșirea workerilor după jobul curent; nu pornește un proces nou. Managerul de procese trebuie să îi repornească. Workerul PHP păstrează clasele încărcate: actualizarea codului și refresh-ul browserului nu sunt suficiente. Un worker pornit înaintea implementării CN/SCN a produs vechiul raport cu numai 26 PI.

Workerul acestui proiect:

```sh
php artisan queue:work tender --queue=tender-scans --sleep=3 --tries=1 --timeout=21600 --memory=256 --no-interaction
```

Conexiunea `tender` folosește tabela `jobs`, cu `retry_after=21720`, mai mare decât timeout-ul jobului. Rulează un singur worker pentru această coadă, cu restart automat, fără retry agresiv. Exemplu Supervisor pentru Linux (adaptează calea și utilizatorul):

```ini
[program:seap-scanner]
command=/usr/bin/php /var/www/seap-scanner/artisan queue:work tender --queue=tender-scans --sleep=3 --tries=1 --timeout=21600 --memory=256 --no-interaction
directory=/var/www/seap-scanner
user=www-data
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=21730
redirect_stderr=true
stdout_logfile=/var/www/seap-scanner/storage/logs/worker.log
```

După modificarea configurației: `supervisorctl reread`, `supervisorctl update`, `supervisorctl restart seap-scanner`. În Windows folosește un serviciu/task administrat, cu directorul de lucru al proiectului și restart automat. Timeout-ul global al workerului necesită PCNTL, indisponibil în Windows; timeouts HTTP rămân active. Un proces lansat manual este potrivit pentru dezvoltare, nu asigură repornirea după reboot.

Nu există taskuri programate în `routes/console.php`; `php artisan schedule:list` confirmă. **Nu este necesar cron/scheduler pentru v1**. Nu adăuga implicit scanări SEAP periodice.

Un proces omorât fără rularea hook-ului de eșec poate lăsa scanarea „În curs”. Confirmă întâi că procesul vechi nu mai rulează. După `retry_after`, un worker cu `--tries=1` marchează jobul rezervat eșuat și eliberează scanarea pentru reluarea din UI. Nu forța `queue:retry` pentru un run activ. Păstrează în backup inclusiv `scan_runs`, `scan_run_items`, `notice_sources` și datele personale.

După deploy: verifică ora/versiunea workerului, `php artisan migrate:status`, login-ul și accesul privat. Un test PI/CN/SCN fără import poate confirma funcționarea; nu lansa automat importul mare.

## Verificări de release

```sh
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm run build
```

Testele folosesc baze în memorie și HTTP simulat. Acoperă familiile, normalizarea, paginarea, deduplicarea, actualizările, erorile HTTP, timeout/retry, limitele, orchestrarea, reluarea și rotația. Verifică separat accesul privat, rolurile, dezactivarea utilizatorilor, CSRF, noindex/nofollow, izolarea notițelor/statusurilor/colecțiilor și filtrele esențiale. Nu a fost schimbată arhitectura autentificării.

## Rezultatul testului live din 12.09.2026

Interval 03.08–11.09.2026, run-uri 7 și 8, prin controllerul real și un worker proaspăt. Importul mare nu a fost lansat.

| Familie | Raportate API | Procesate în run 7 | Noi | Actualizate | Run 8 identic |
| --- | ---: | ---: | ---: | ---: | ---: |
| PI | 26 | 1 | 0 | 1 | 1 neschimbat |
| CN | 1302 | 1 | 0 | 1 | 1 neschimbat |
| SCN | 1506 | 1 | 1 | 0 | 1 neschimbat |

Fără erori de listare/detalii, fără 403, fără duplicate interne. Actualizările din run 7 reprezintă îmbogățirea metadatelor existente; nu dovedesc o schimbare publicată între timp de SEAP. Actualizarea efectivă a datelor și revizia sunt verificate automat cu răspunsuri API schimbate. Total local SEAP: 404 → 405. Cele 2834 raportate de API nu au fost importate integral.

Metadate reale: PI1004600 = servicii, Legea 98/2016, planificare pentru 14.09.2026; CN1095062 = servicii, licitație restrânsă, în desfășurare; SCN1179721 = furnizare, tip 17, procedură simplificată, în desfășurare. Referința TED `538493-2026` a CN a rămas separată. Cele 404 înregistrări anterioare cuprindeau 26 PI și 378 CN, inclusiv 71 achiziții sectoriale. Auditul nu a justificat ștergerea vreunui anunț ca străin achizițiilor.

Furnizorul comercial extern pentru firme nu este configurat implicit. Funcționarea offline existentă nu a fost extinsă în această etapă.

## Închiderea verificărilor din 15.09.2026

145 de teste, 847 de assertions, toate trecute. Pint executat și build Vite reușit. Build-ul menționează opțional lipsa `fontaine` pentru optimizarea fonturilor; nu împiedică generarea asseturilor și nu am adăugat dependențe. Toate migrările sunt aplicate local.

La verificarea finală exista run-ul 9, `full`, în așteptare din 12.09.2026, pentru 13.08–12.09.2026. Workerul local a rămas oprit pentru a nu declanșa automat acel import mare. Jobul și datele sunt păstrate. Acest aspect operațional trebuie rezolvat înainte de folosirea butonului: decide dacă se execută scanarea aflată în coadă, apoi pornește workerul conform pașilor de mai sus.

## Fișierele etapei de stabilizare

- `README.md`
- `app/Console/Commands/ScanSeapNotices.php`
- `app/Http/Controllers/NoticeController.php`
- `app/Http/Controllers/ScanController.php`
- `app/Jobs/RunManualScan.php`
- `app/Models/ScanRun.php`
- `app/Services/NoticeIngestor.php`
- `app/Services/ScanContext.php`
- `app/Services/SeapClient.php`
- `app/Services/SeapImporter.php`
- `app/Services/SeapNormalizer.php`
- `database/migrations/2026_09_11_164842_add_mode_to_scan_runs_table.php`
- `database/migrations/2026_09_11_225808_add_checkpoint_to_scan_runs_table.php`
- `resources/js/app.js`
- `resources/views/notices/scan-panel.blade.php`
- `resources/views/notices/scan-family-results.blade.php`
- `tests/Feature/ManualScanTest.php`
- `tests/Feature/NoticeIngestionTest.php`
- `tests/Feature/SeapClientTest.php`
- `tests/Feature/SeapImportTest.php`
- `tests/Feature/ManualScanFamiliesTest.php`
- `tests/Feature/SeapStabilizationTest.php`
