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

## Loturi SEAP, checkpoint și incremental

Fluxul manual și cronul reutilizează același sistem: `ScanDispatcher` → `RunManualScan` → `SeapImporter`, cu `scan_runs`, `scan_run_items` și `scan_runs.checkpoint`. Nu există o a doua bază de progres. Controllerul validează cererea UI și folosește același dispatcher ca noua comandă `seap:tick`.

Problema din producție: importatorul nu avea o limită totală pe execuție și parcurgea toate zilele/paginile. CN/SCN folosesc patru cereri de detalii per anunț; 178 CN înseamnă aproximativ 712 cereri de detalii, plus listări și PI. Un Referer corect permite accesul, dar nu garantează că SEAP nu va aplica ulterior o restricție. Pragul real SEAP nu este cunoscut.

Configurație în `.env`:

```dotenv
SEAP_BATCH_SIZE=100
SEAP_BATCH_INTERVAL_MINUTES=60
SEAP_REQUEST_DELAY_MS=1000
```

- Limita 100 este politica noastră inițială, **nu o limită confirmată de SEAP**. Poate fi schimbată la 50 sau altă valoare pozitivă. Se aplică totalului PI + CN + SCN, nu fiecărei familii. Numără și anunțurile verificate din cache sau încercate cu eroare. Nu limitează TED.
- Există și un buget de cereri de listare egal cu mărimea lotului: un interval lung/gol sau plin de duplicate nu poate genera pagini nelimitate într-o execuție. Un anunț CN/SCN poate necesita patru cereri de detalii; 100 de anunțuri nu înseamnă 100 de cereri HTTP. Retry-urile HTTP existente pentru timeout/429/5xx rămân limitate la trei încercări.
- Checkpoint-ul salvează familia, zilele încheiate, pagina, răspunsul paginii curente, ID-urile procesate și statisticile. Pagina are în continuare 18 elemente, indiferent de mărimea lotului. Elementele rămase sunt procesate din răspunsul salvat înainte de a cere pagina următoare; zilele/familiile complete sunt sărite.
- Checkpoint-urile vechi, fără cursor de pagină, sunt compatibile: prima reluare relistează doar ziua incompletă și sare ID-urile deja procesate. După aceea folosește cursorul nou. Nu sunt necesare migrări noi peste schema existentă cu `checkpoint`.
- Oprirea normală la limita lotului este afișată „Lot încheiat — de continuat”, nu „Finalizată”. Statisticile per familie sunt cumulative; „încercate în acest lot” descrie execuția curentă.
- La orice 403, SEAP se oprește imediat, păstrează datele/checkpoint-ul și nu mai apelează alte familii în acea execuție. Următoarea invocare cron poate continua după pauza configurată, implicit 60 minute. Nu există autorelansare în buclă, proxy sau bypass. Dacă SEAP continuă să restricționeze accesul, opriți cronul și verificați accesul legitim.
- Un run incomplet este continuat cu **același ID și interval**, până sunt complete PI/CN/SCN. După terminare se creează un nou ciclu: de exemplu, ultima zi completă 15 septembrie + rulare pe 17 septembrie înseamnă intervalul **15–17 septembrie**. Ultima zi verificată este suprapunerea; nu se recitesc 30 de zile. Prima sincronizare fără reper pornește implicit cu 30 de zile.
- Ciclurile noi nu mai adaugă rotația zilelor istorice. Un checkpoint vechi care are deja o zi suplimentară o păstrează până termină acel ciclu. API-ul filtrează după publicare; suprapunerea nu garantează detectarea imediată a modificărilor unor proceduri publicate mult mai demult. După terminarea ciclului curent se poate cere explicit un interval istoric.
- Cache-ul existent rămâne: listare identică + detalii complete mai recente de 3 zile evită cererile de detalii. Listările schimbate sau detaliile expirate sunt recitite. Deduplicarea și păstrarea datelor istorice sunt neschimbate; nu există ștergere automată.
- În UI, TED rulează primul, fără limita SEAP. TED deja finalizat în acel run nu este repetat la reluare. `seap:tick` apelează numai SEAP; importul TED existent poate fi rulat independent cu `ted:scan`, fără a aștepta recuperarea istoricului SEAP. Loturile SEAP nu planifică automat alte importuri TED.
- Dispatcherul serializează pornirile UI/cron și reutilizează run-ul activ; un cron suprapus nu adaugă un job identic. Un interval manual diferit nu înlocuiește un checkpoint incomplet. Scanările anulate nu sunt reluate.

### Cron KabyNode

Comanda de planificare, din rădăcina aplicației:

```sh
php artisan seap:tick --no-interaction
```

Aceasta **pune în coadă cel mult un lot**, nu îl execută în procesul cron. Necesită workerul `tender`, descris mai jos. Exemplu cron (înlocuiți calea proiectului și binarul PHP cu cele reale de pe hosting):

```cron
*/15 * * * * cd /CALE/ABSOLUTA/TENDER-SCANNER && /CALE/PHP artisan seap:tick --no-interaction >> storage/logs/seap-cron.log 2>&1
```

Cronul verifică la 15 minute, dar **nu pornește un nou lot înainte de cele 60 minute de pauză** și nu dublează un run activ. Astfel continuarea are loc la prima verificare după pauză. Pentru alt ritm schimbați `SEAP_BATCH_INTERVAL_MINUTES`; evitați frecvențele agresive. Nu este necesară o intrare Laravel Scheduler în plus față de acest cron direct.

Nu programați în cron `seap:scan` fără limite: aceea rămâne comanda internă/diagnostică de import, iar apelul CLI direct nu are contextul persistent al unui `ScanRun`. Pentru recuperare periodică folosiți `seap:tick`; pentru testul diagnostic mic există opțiunile de mai jos. Nu rulați simultan importuri SEAP diagnostice și workerul de loturi.

După modificarea configurației sau actualizarea codului, administratorul trebuie să refacă cache-ul de configurație și să restarteze workerul, conform secțiunii Deployment. Aceste operațiuni și cronul nu au fost executate în producție în această etapă.

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

Nu există taskuri programate în `routes/console.php`. Pentru noua procesare periodică SEAP se configurează cronul direct `seap:tick` de mai sus; nu se configurează în paralel și o programare Laravel pentru aceeași comandă.

Un proces omorât fără rularea hook-ului de eșec poate lăsa scanarea „În curs”. Confirmă întâi că procesul vechi nu mai rulează. După `retry_after`, un worker cu `--tries=1` marchează jobul rezervat eșuat și eliberează scanarea pentru reluarea din UI. Nu forța `queue:retry` pentru un run activ. Păstrează în backup inclusiv `scan_runs`, `scan_run_items`, `notice_sources` și datele personale.

După deploy: verifică ora/versiunea workerului, `php artisan migrate:status`, login-ul și accesul privat. Un test PI/CN/SCN fără import poate confirma funcționarea; nu lansa automat importul mare.

## Verificări de release

```sh
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm run build
```

Testele folosesc baze în memorie și HTTP simulat. Acoperă familiile, normalizarea, paginarea, deduplicarea, actualizările, erorile HTTP, timeout/retry, limitele, orchestrarea, loturile, reluarea după 403 și tranziția incrementală. Verifică separat accesul privat, rolurile, dezactivarea utilizatorilor, CSRF, noindex/nofollow, izolarea notițelor/statusurilor/colecțiilor și filtrele esențiale. Nu a fost schimbată arhitectura autentificării.

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

Scanarea veche 9 (`full`, 13.08–12.09.2026) a fost anulată la cererea utilizatorului pe 15.09.2026, înainte de execuție. Exclusiv jobul ei a fost eliminat din coadă; înregistrarea scanării este păstrată ca „Anulată”, iar anunțurile nu au fost modificate. Coada `tender-scans` era goală după anulare. Workerul a rămas oprit; acel job nu mai poate porni la repornire.

Pagina Licitații oferă „Toate | SEAP | TED”, după câmpul `source` al înregistrării din catalog. Schimbarea sursei păstrează celelalte filtre și revine la prima pagină. O referință TED atașată unui anunț SEAP nu mută acel anunț în lista TED. Importul și legăturile dintre surse nu au fost modificate.

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

## Colectare bulk data.gov.ro și istoric furnizori — 16.09.2026

Această secțiune descrie strategia curentă și înlocuiește recomandările anterioare privind preluarea automată a tuturor detaliilor CN/SCN.

### Surse confirmate

Catalog CKAN: `https://data.gov.ro/api/3/action/package_show?id=achizitii-publice-2026`, publicat de Autoritatea pentru Digitalizarea României. Sunt descoperite numai resursele de inițiere și contracte, nu achizițiile directe, atribuirile separate sau modificările de contract.

| Resursă | ID oficial | Format real | Rânduri de date verificate |
| --- | --- | --- | ---: |
| Anunțuri de inițiere TI 2026 | 5720192a-dc9a-428f-9ac6-bd055a06da95 | XLSX | 4.934 |
| Anunțuri de inițiere TII 2026 | 80a3d434-29c7-42eb-8d25-eb1457175101 | ODS | 5.816 |
| Contracte TI 2026 | f78b2b07-8f7c-4367-9a96-cd7318572f20 | XLSX | 229.089 |
| Contracte TII 2026 | 2c9237d1-b9c0-4fc3-ad8a-959a7973cc2c | ODS | 190.394 |

Catalogul etichetează resursele TII ca XLSX, însă arhivele conțin ODS. Cititorul identifică structura internă. TI conține 2.418 CN și 2.454 SCN; TII conține 2.727 CN și 3.014 SCN. PC/RFQ/DC sunt omise explicit: acoperirea lor și eligibilitatea pentru participare nu au fost validate în această etapă. Total CN/SCN în cele două exporturi: 10.613 rânduri, înainte de deduplicare.

Exporturile sunt trimestriale, nu un flux curent. Metadatele verificate aveau ultima actualizare la 13.07.2026; nu exista export TIII. Fișierele de inițiere NU au deadline sau ID-ul numeric intern al API-ului. Câmpul JOUE este Da/Nu, nu numărul anunțului TED. Nu îl folosim pentru deduplicare TED.

### Import și reluare

```sh
php artisan migrate --force --no-interaction
php artisan seap:bulk-sync --year=2026 --limit=1000 --no-interaction
```

Migrarea adaugă `seap_bulk_resources`, `supplier_history` și extinde titlul licitației la TEXT (exporturile reale au titluri de peste 400 caractere). Nu șterge anunțuri. Comanda este destinată CLI/cron; nu rulează în requestul web și nu necesită daemon sau Redis. Necesită extensiile PHP ZIP, XMLReader, DOM și spațiu în `storage/app/private` pentru arhive plus indexul text decomprimat.

Fiecare execuție verifică metadatele CKAN și importă cel mult un lot dintr-o resursă, prioritar anunțurile. Fișierul este descărcat o singură dată pentru fiecare versiune, apoi transformat streaming într-un index local. Pregătirea inițială trebuie să termine un fișier; întreruperea ei reconstruiește numai indexul temporar, fără schimbări în date. Pentru contractele TI citirea completă locală a durat aproximativ 32 secunde, cu circa 8 MB memorie; pe shared hosting poate dura mai mult. Downloadul are timeout de 90 secunde. Rulările ulterioare sar direct la poziția salvată în index, fără recitirea rândurilor procesate. Prelucrarea lotului se oprește și după aproximativ 45 secunde.

Checkpoint-ul (rând și poziție în fișier) este salvat în aceeași tranzacție cu datele fiecărui rând. Erorile HTTP/schema/date sunt raportate, păstrând progresul; nu există retry agresiv. Modificarea versiunii oficiale (URL/data modificării/dimensiune/hash publicat) reia fișierul prin upsert. Versiunile vechi și istoricul nu sunt șterse automat. Contorul `processed` reprezintă rânduri parcurse în lot, inclusiv familiile omise, nu numărul de licitații noi. Starea resurselor și ultima eroare se găsesc în `seap_bulk_resources`; finalizările și erorile sunt și în jurnal.

Anunțurile sunt legate după numărul oficial exact CN/SCN, cu familia verificată. ID-ul temporar `BULK:CN...` este înlocuit de ID-ul API când acesta devine disponibil; înregistrarea și datele personale rămân aceleași. Exportul nu suprascrie câmpurile curente ale unei înregistrări deja verificate prin API. Snapshot-ul bulk rămâne în metadata.

### Oportunități și rolul API-ului

Lista principală SEAP cere status compatibil și deadline neexpirat. PI poate apărea cu dată viitoare de inițiere când nu are deadline. Datele necunoscute, atribuirile, anulările și expirările nu sunt prezentate ca oportunități active. Filtrul de istoric, Salvate și Ignorate păstrează accesul la date. TED și separarea Toate / SEAP / TED rămân independente.

`seap:tick` și butonul existent folosesc în continuare limita de lot, checkpoint și oprirea imediată la 403. În mod implicit, CN/SCN folosesc listările pentru starea curentă și termen, fără cele patru cereri suplimentare pentru fiecare anunț. PI păstrează integrarea existentă. Un export finalizat acoperă istoricul trimestrului; zilele fără oportunități bulk de reverificat nu sunt cerute din nou API-ului. O singură zi cu oportunități bulk neconfirmate este adăugată unui nou ciclu incremental. Dacă nu sunt găsite în listare, rămân neconfirmate și ziua poate fi verificată din nou după șapte zile. Nu presupunem că „În desfășurare” dintr-un export vechi înseamnă participare încă posibilă.

Pentru diagnosticul controlat cu detalii există în continuare modul sample; `SEAP_FETCH_PROCEDURE_DETAILS=true` permite explicit vechiul comportament de enrichment în scanare. Implicit este `false`; reactivarea crește numărul cererilor și nu este recomandarea pentru cron. Datele detaliate deja existente sunt păstrate. Actualizarea unei proceduri vechi deja confirmate depinde în continuare de intervalul scanat; sistemul nu promite detectarea instantanee a tuturor anulărilor/modificărilor SEAP.

### Caută furnizori

Pagina existentă afișează separat experiența publică documentată din `supplier_history`, chiar fără sursa comercială configurată. Se caută CPV identic sau aceeași categorie (primele cinci cifre), cu eticheta potrivirii; fără CPV valid se folosesc cuvintele din căutare în denumirea CPV/titlurile procedurilor locale. Se afișează cel mult 20 firme după recență și câte cinci înregistrări contract/lot. Numărul contractelor este distinct, astfel încât loturile nu îl umflă.

Identitatea unei înregistrări combină autoritatea, anunțul de atribuire, lotul, numărul/data/tipul contractului și câștigătorul. CUI este normalizat, iar asocierile de câștigători sunt păstrate separat. Valorile afișate pot fi comune asociaților; nu sunt însumate ca venit al firmei. Linkul duce la exportul oficial și indică rândul, deoarece exportul nu furnizează linkul intern al contractului. Denumirea procedurii este disponibilă numai dacă avem și anunțul în catalog. Nu se generează scor de calitate și nu se modifică integrarea Price Scanner.

### Cron KabyNode

Exemple de configurat după revizuire, cu căile reale și PHP 8.3:

```cron
*/5 * * * * cd /CALE/TENDER-SCANNER && /CALE/PHP artisan seap:bulk-sync --year=2026 --limit=1000 --no-interaction >> storage/logs/seap-bulk-cron.log 2>&1
*/15 * * * * cd /CALE/TENDER-SCANNER && /CALE/PHP artisan seap:tick --no-interaction >> storage/logs/seap-cron.log 2>&1
*/5 * * * * cd /CALE/TENDER-SCANNER && /CALE/PHP artisan queue:work tender --queue=tender-scans --stop-when-empty --max-jobs=1 --tries=1 --no-interaction >> storage/logs/worker-cron.log 2>&1
```

Configurează **o singură execuție de worker la un moment dat**, folosind facilitatea de blocare oferită de hosting; nu instala acest cron peste un worker deja administrat. `--max-jobs=1` oprește workerul după job, nu întrerupe jobul în curs. TED poate dura mai mult și nu este limitat de politica SEAP. Lock-ul bulk împiedică două sincronizări bulk simultane. Frecvența de verificare a catalogului poate fi redusă după bootstrap, păstrând reluarea periodică. Nu este necesar `schedule:run` pentru aceste cronuri directe.

Nu au fost executate comenzi de deploy sau cron pe KabyNode în această etapă. Fișierele oficiale au fost verificate local fără import masiv în baza aplicației și fără scanare SEAP live. Migrările și importurile sunt testate în baze temporare.

Fișierele acestei etape (29):

- `.env.example`
- `README.md`
- `app/Console/Commands/SyncSeapBulk.php` (nou)
- `app/Http/Controllers/NoticeSupplierController.php`
- `app/Models/Notice.php`
- `app/Services/BulkSpreadsheetReader.php` (nou)
- `app/Services/SeapBulkSync.php` (nou)
- `app/Services/SupplierHistorySearch.php` (nou)
- `app/Services/NoticeIngestor.php`
- `app/Services/NoticeSupplierQueries.php`
- `app/Services/ScanDispatcher.php`
- `app/Services/SeapImporter.php`
- `app/Services/SeapNormalizer.php`
- `config/services.php`
- `database/factories/NoticeFactory.php`
- `database/migrations/2026_09_16_122312_create_seap_bulk_tables.php` (nou)
- `resources/js/app.js`
- `resources/views/notices/index.blade.php`
- `resources/views/notices/scan-family-results.blade.php`
- `resources/views/notices/show.blade.php`
- `resources/views/notices/suppliers.blade.php`
- `tests/Feature/NoticeFundingFilterTest.php`
- `tests/Feature/NoticeIngestionTest.php`
- `tests/Feature/NoticeSummaryTest.php`
- `tests/Feature/SeapBatchTest.php`
- `tests/Feature/SeapBulkTest.php` (nou)
- `tests/Feature/SeapImportTest.php`
- `tests/Feature/SeapStabilizationTest.php`
- `tests/Feature/TedImportTest.php`

Migrarea adaugă și `notices.seap_official_number`, unic și completat numai pentru identități CN/SCN neambigue. Protejează inclusiv împotriva inserărilor concurente bulk/API; nu unește automat duplicate istorice ambigue. La rollback titlul rămâne TEXT, pentru a nu trunchia titlurile importate. Pregătirea indexului are o limită proprie de patru minute, sub durata lock-ului bulk.
