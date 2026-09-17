# Tender Scanner v1

Aplicație privată Laravel pentru oportunități SEAP și TED. PHP 8.3, Laravel 13; versiunile exacte sunt în fișierele lock. Accesul și administratorul inițial: [PRIVATE_ACCESS.md](PRIVATE_ACCESS.md). Integrarea pentru furnizori: [SUPPLIER_API.md](SUPPLIER_API.md).

## Acoperirea SEAP

API JSON public, bază `https://e-licitatie.ro/api-pub/`; fără scraping HTML:

| Familie | Listare POST | Identificator / tip |
| --- | --- | --- |
| PI, intenție de achiziție | `PUBLICPINotice/GetAll/` | `piNoticeId`, tip 1 |
| CN, participare | `PUBLICCNotice/GetAll/` | `cNoticeID`, tip 2 |
| SCN, participare simplificată | `PublicRFQInvitation/GetAll/` | `rfqInvitationId`, filtru `sysNoticeType: {id: 17}` |
| ADV, publicitate | `AdvNoticeCommon/GetAdvNoticeList/` | `advNoticeId`, `noticeNo`; date `publicationDateStart` / `publicationDateEnd`, pagini de 100 |

Listările PI/CN/SCN primesc `pageIndex`, `pageSize`, `publicationDateFrom`, `publicationDateTo`. Importul normal folosește zile întregi în Europe/Bucharest și pagini de 18. PI: detalii GET `PUBLICPINotice/getView/{id}`. CN/SCN: GET `ComboPub/getNoticeGeneralInfo/`, `NoticeCommon/GetSection1View/`, `NoticeCommon/GetSection21View/`, `NoticeCommon/GetSection4View/`, cu identificatorul și tipul familiei. Detaliile sunt cerute secvențial, pentru fiecare anunț.

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

- Limita 100 este politica noastră inițială, **nu o limită confirmată de SEAP**. Poate fi schimbată la 50 sau altă valoare pozitivă. Se aplică totalului PI + CN + SCN + ADV, nu fiecărei familii. Numără și anunțurile verificate din cache sau încercate cu eroare. Nu limitează TED.
- Există și un buget de cereri de listare egal cu mărimea lotului: un interval lung/gol sau plin de duplicate nu poate genera pagini nelimitate într-o execuție. Un anunț CN/SCN poate necesita patru cereri de detalii; 100 de anunțuri nu înseamnă 100 de cereri HTTP. Retry-urile HTTP existente pentru timeout/5xx rămân limitate la trei încercări.
- Checkpoint-ul salvează familia, zilele încheiate, pagina, răspunsul paginii curente, ID-urile procesate și statisticile. Paginile au 18 elemente pentru PI/CN/SCN și 100 pentru ADV, independent de mărimea lotului. Elementele rămase sunt procesate din răspunsul salvat înainte de a cere pagina următoare; zilele/familiile complete sunt sărite.
- Checkpoint-urile vechi, fără cursor de pagină, sunt compatibile: prima reluare relistează doar ziua incompletă și sare ID-urile deja procesate. După aceea folosește cursorul nou. Nu sunt necesare migrări noi peste schema existentă cu `checkpoint`.
- Oprirea normală la limita lotului este afișată „Lot încheiat — de continuat”, nu „Finalizată”. Statisticile per familie sunt cumulative; „încercate în acest lot” descrie execuția curentă.
- La orice 403/429, SEAP se oprește imediat, păstrează datele/checkpoint-ul și nu mai apelează alte familii în acea execuție. Următoarea invocare cron poate continua după pauza configurată, implicit 60 minute. Nu există autorelansare în buclă, proxy sau bypass. Dacă SEAP continuă să restricționeze accesul, opriți cronul și verificați accesul legitim.
- Un run incomplet este continuat cu **același ID și interval**, până sunt complete familiile înregistrate în checkpoint (PI/CN/SCN/ADV pentru cicluri noi). După terminare se creează un nou ciclu: de exemplu, ultima zi completă 15 septembrie + rulare pe 17 septembrie înseamnă intervalul **15–17 septembrie**. Ultima zi verificată este suprapunerea; nu se recitesc 30 de zile. O familie SEAP fără reper pornește de ieri, nu cu 30 de zile; un interval istoric se cere explicit. Reperul este separat pe familie. Perioada implicită TED rămâne cea existentă.
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
0 */3 * * * cd /CALE/ABSOLUTA/TENDER-SCANNER && /CALE/PHP artisan seap:tick --no-interaction >> storage/logs/seap-cron.log 2>&1
```

Recomandarea delta actuală: cron la trei ore, dar **nu pornește un nou lot înainte de cele 60 minute de pauză** și nu dublează un run activ. Astfel continuarea are loc la prima verificare după pauză. Pentru alt ritm schimbați `SEAP_BATCH_INTERVAL_MINUTES`; evitați frecvențele agresive. Nu este necesară o intrare Laravel Scheduler în plus față de acest cron direct.

Nu programați în cron `seap:scan` fără limite: aceea rămâne comanda internă/diagnostică de import, iar apelul CLI direct nu are contextul persistent al unui `ScanRun`. Pentru recuperare periodică folosiți `seap:tick`; pentru testul diagnostic mic există opțiunile de mai jos. Nu rulați simultan importuri SEAP diagnostice și workerul de loturi.

După modificarea configurației sau actualizarea codului, administratorul trebuie să refacă cache-ul de configurație și să restarteze workerul, conform secțiunii Deployment. Aceste operațiuni și cronul nu au fost executate în producție în această etapă.

Butonul „Testează PI/CN/SCN/ADV fără import” face câte o cerere de listare fără detalii. Test real limitat din CLI (nu apelează TED):

```sh
php artisan seap:scan --from=2026-08-03 --to=2026-09-11 --limit=1 --report --no-interaction
```

Limita este per familie, maximum 18, și se aplică local chiar dacă API-ul întoarce mai multe elemente. Se citește o singură pagină per familie. Limita 1 înseamnă cel mult 4 cereri logice de listare și 10 de detalii; erorile temporare pot produce încercări HTTP suplimentare. Endpointul manual acceptă `mode=sample`, prin același job, cu limita fixă 1 per familie și fără TED. Testele limitate nu sunt sincronizări complete.

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

Catalog CKAN: `https://data.gov.ro/api/3/action/package_show?id=achizitii-publice-2026`, publicat de Autoritatea pentru Digitalizarea României. Inițial au fost integrate resursele de inițiere și contracte. Extensia DA/DAN de mai jos adaugă achizițiile directe și notificările lor exclusiv ca istoric; atribuirile separate de proceduri și modificările de contract rămân neimportate.

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
0 */3 * * * cd /CALE/TENDER-SCANNER && /CALE/PHP artisan seap:tick --no-interaction >> storage/logs/seap-cron.log 2>&1
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

## Audit istoric: achiziții directe DA/DAN — 16.09.2026

> Concluziile despre blocarea ADV de mai jos descriu auditul din 16 septembrie. Integrarea verificată ulterior este documentată în secțiunea „Delta live și ADV — 17.09.2026”.

**ADV active nu sunt încă importate.** La audit, accesarea publică `https://e-licitatie.ro/pub` a primit pagina oficială „Accesul de la adresa dumneavoastră IP a fost restricționat”, care menționează volumul de trafic automat. Cererile către SEAP au fost oprite. Nu s-a confirmat un endpoint JSON ADV, schema răspunsului, enumerația stărilor sau câmpul deadline. Nu s-au inventat endpointuri și nu s-au încercat proxy-uri, alte identități sau alte metode de ocolire. Pentru finalizarea ADV este necesar acces oficial permis și verificarea listării/detaliilor reale, inclusiv termenul exact și referințele documentelor. Această etapă livrează partea verificabilă DA/DAN, nu un import ADV pretins funcțional.

### Exporturi oficiale verificate

Toate sunt din [datasetul ADR Achiziții publice 2026](https://data.gov.ro/dataset/achizitii-publice-2026), descoperite prin API-ul CKAN existent. Au fost citite integral, fără import în baza locală a aplicației:

| Resursă | ID oficial și legătură | Format real | Rânduri de date |
| --- | --- | --- | ---: |
| Achiziții directe TI | [5bcff70e-a97d-494d-a028-ac031b431dae](https://data.gov.ro/dataset/e8d22de4-f8ce-4b42-8561-98d2481ddef9/resource/5bcff70e-a97d-494d-a028-ac031b431dae) | XLSX | 476.597 |
| Achiziții directe TII | [5ea63810-6fbd-489c-aa41-0b0852a2902e](https://data.gov.ro/dataset/e8d22de4-f8ce-4b42-8561-98d2481ddef9/resource/5ea63810-6fbd-489c-aa41-0b0852a2902e) | ODS | 588.644 |
| Notificări de atribuire directă TI | [728c1bb4-c417-40b7-a4fa-e52a94281ee6](https://data.gov.ro/dataset/e8d22de4-f8ce-4b42-8561-98d2481ddef9/resource/728c1bb4-c417-40b7-a4fa-e52a94281ee6) | XLSX | 72.140 |
| Notificări de atribuire directă TII | [510c84d6-e240-4782-a003-aa6e5e132e27](https://data.gov.ro/dataset/e8d22de4-f8ce-4b42-8561-98d2481ddef9/resource/510c84d6-e240-4782-a003-aa6e5e132e27) | ODS | 75.273 |

Total: 1.212.654 rânduri înainte de deduplicare. Toate numerele au prefixul real DA sau DAN, niciunul ADV. Memoria maximă PHP la citirea integrală a fost aproximativ 8 MB; cel mai lent fișier a durat aproximativ 66 s local. Aceasta verifică cititorul, nu reprezintă un benchmark al importului SQL pe KabyNode. TII este etichetat XLSX în catalog, dar arhiva este ODS. Fișierele descărcate au aproximativ 60 MB / 103 MB pentru DA și 9 MB / 12 MB pentru DAN; indexurile locale și fișierele temporare necesită spațiu suplimentar pe disc.

| Informație | DA / achiziții directe | DAN / notificări |
| --- | --- | --- |
| Identitate | `Numar achizitie directa`, DA… | `Numar notificare`, DAN… |
| Obiect | `Denumire achizitie` | `Obiectul achizitiei` |
| Cumpărător și furnizor | Autoritate, CUI autoritate, ofertant câștigător, CUI câștigător | Aceleași câmpuri |
| Clasificare | Cod CPV, denumire CPV, tip contract | Aceleași câmpuri |
| Date | Data publicare, Data finalizare, inclusiv ora | Aceleași câmpuri |
| Valoare | `Valoare achizitie (RON)` | Același câmp |
| Fonduri UE | Indicator și denumire program | Indicator și tip proiect/program |
| Termen ofertare, stare curentă, valoare estimată, cantitate structurată, județ/localitate, documente, legătură ADV | Nu există coloane dedicate | Nu există coloane dedicate |

Datele DA reprezintă achiziții, iar DAN notificări de atribuire, cu furnizor și valoare raportată. Data finalizării **nu** este deadline. Nu inferăm cantitatea din titlu și nu echivalăm valoarea achiziției cu o valoare estimată. Moneda este RON conform antetului, nu este dedusă din alt câmp. Valorile sunt păstrate la două zecimale, iar rândul original integral rămâne în `payload`. Datele XLSX seriale și datele ISO ODS sunt normalizate fără pierderea orei, în timpul local furnizat de export, fără inventarea unui fus orar absent din sursă.

### Integrare și identitate

`seap:bulk-sync` descoperă acum și familiile de resurse `direct_purchases` și `direct_awards`. Folosește același cititor streaming, lock, registru de resurse, versiune, index local și checkpoint de rând/octet. Nu există un al doilea sistem de scanare. Niciun rând DA/DAN nu ajunge în `notices`, nici prin filtrul de istoric al licitațiilor. Nu se modifică `RunManualScan`, PI/CN/SCN, TED sau datele personale.

Identitatea pentru istoric este numărul oficial complet DA/DAN, independent de titlu, valoare, trimestru și furnizor. Corectarea furnizorului sau valorii actualizează același rând. CUI furnizor este normalizat; în lipsa sa se folosește cheia existentă bazată pe nume. DA și DAN rămân distincte: exporturile nu oferă o legătură sigură între ele sau cu ADV, deci nu sunt reunite prin asemănare. Numărul contractului nu este inventat; coloanele vechi fără corespondent rămân goale.

Singurul rând găsit fără dată de finalizare este DA40195337, rândul 74755 din DA TII. Acesta este omis din istoricul finalizat. Checkpoint-ul avansează, iar registrul păstrează `skipped_rows` și `last_skipped_row`, cu avertizare în log. Rezultatul comenzii include `skipped`, cumulativ pentru versiunea resursei; `processed` reprezintă rândurile parcurse în apel, inclusiv cele omise. Schimbarea versiunii resetează checkpoint-ul și contoarele și permite reanalizarea rândului. Identificatorii invalizi, antetele lipsă sau valorile numerice invalide opresc resursa cu eroare, păstrând ultimul rând valid și toate datele importate. Nu sunt înghițite erorile de format sau de bază de date.

HTTP 403/429 la catalog/descărcare oprește apelul fără retry în aceeași rulare; reluarea folosește cronul existent. O versiune nouă reface indexul și execută upsert, fără ștergerea istoricului absent din noul fișier. Importul rămâne limitat la numărul configurat de rânduri și bugetul de procesare existent de 45 s; descărcarea și pregătirea inițială au separat limitele existente de 90 s, respectiv patru minute. Shared hostingul trebuie să permită această pregătire în PHP CLI; nu presupunem că timpul local se reproduce pe server.

### Migrare, afișare și operare

Migrarea `2026_09_16_155700_extend_supplier_history_for_direct_purchases.php` adaugă:

- `supplier_history.history_type` (implicit `contracts`, păstrează rândurile vechi), `official_number` unic și nullable, `title`, `published_at`, `completed_at`;
- `seap_bulk_resources.skipped_rows`, `last_skipped_row`.

Nu modifică schema `Notice`, autentificarea sau tabelele personale. Migrarea nu a fost aplicată bazei locale reale sau producției; testele folosesc SQLite temporar în memorie.

În pagina Licitații, filtrul minim „Tip SEAP” distinge ADV / PI / CN / SCN după metadata existentă și se combină cu filtrele personale și de căutare. La alegerea TED, linkul elimină filtrul de familie SEAP. Interfața precizează că ADV active nu sunt încă importate. Testele cu înregistrări ADV sintetice verifică exclusiv filtrul și regula existentă de vizibilitate: stări inactive, deadline necunoscut sau expirat nu apar ca active; înregistrările rămân accesibile în istoric/Salvate. Aceste teste **nu** validează o integrare ADV live.

Istoricul DA/DAN este căutat în pagina existentă de furnizori după CPV identic/categorie de cinci cifre, iar căutarea fără CPV folosește și titlul achiziției. Tipul fiecărei înregistrări și numărul oficial sunt afișate explicit, cu referință la export și rând. Numărătoarea reprezintă înregistrări documentate distincte, nu calitate, venit sau un număr garantat de tranzacții economice unice între DA/DAN. Nu există o legătură de identitate ADV–istoric; o viitoare oportunitate ADV cu CPV poate folosi căutarea generică existentă. Nu este implementat calculul atribuit/estimat: aceste exporturi nu furnizează valoarea estimată necesară unei perechi comparabile.

După revizuire, sunt suficiente migrarea și cronul bulk existente, fără servicii sau worker nou:

```sh
php artisan migrate --force --no-interaction
php artisan seap:bulk-sync --year=2026 --limit=1000 --no-interaction
```

```cron
*/5 * * * * cd /CALE/TENDER-SCANNER && /CALE/PHP artisan seap:bulk-sync --year=2026 --limit=1000 --no-interaction >> storage/logs/seap-bulk-cron.log 2>&1
```

Înlocuiește cronul bulk existent dacă este deja configurat; nu îl dubla. Cronurile `seap:tick` și worker/TED rămân cele documentate anterior. Nu există comandă sau cron ADV activ funcțional în această etapă. Nu s-a modificat KabyNode, nu s-a pornit o scanare SEAP suplimentară și nu s-a făcut commit/push/deployment.

### Verificări și fișierele acestei extensii

187 teste / 1.177 aserțiuni au trecut în suita completă. Testele adăugate acoperă DA și DAN, upsert inclusiv corectarea furnizorului, neatingerea Notice/datelor personale, conversia datelor XLSX, checkpoint fără redescărcare, versiuni noi, rânduri/antete invalide, omisiuni fără dată de finalizare, oprire 403/429, căutarea și escaparea istoricului, filtrarea familiilor și excluderea ADV sintetice inactive. Testarea importului ADV live, a documentelor și a normalizării răspunsului său rămâne blocată de lipsa unei surse verificate. Pint, build Vite și `git diff --check` au trecut; build-ul semnalează doar dependența opțională `fontaine` absentă, fără eșec.

Fișiere modificate (8):

- `README.md`
- `app/Console/Commands/SyncSeapBulk.php`
- `app/Http/Controllers/NoticeController.php`
- `app/Services/SeapBulkSync.php`
- `app/Services/SupplierHistorySearch.php`
- `resources/views/notices/index.blade.php`
- `resources/views/notices/suppliers.blade.php`
- `tests/Feature/NoticeSourceFilterTest.php`

Fișiere noi (2):

- `database/migrations/2026_09_16_155700_extend_supplier_history_for_direct_purchases.php`
- `tests/Feature/DirectPurchaseHistoryTest.php`

Stare la raportarea din 16 septembrie: `master`, ahead 1 față de `origin/master` din commitul anterior `1e8d615`, opt fișiere modificate și două noi, nimic staged. Exporturile descărcate și fișierele generate sunt ignorate de Git. Mesaj propus pentru acea etapă: `Add official direct purchase history and SEAP family filtering`.

## Delta live și ADV — 17.09.2026

### Contract oficial verificat

Auditul limitat din mediul local a primit HTTP 200 de la pagina oficială [Publicitate anunțuri](https://e-licitatie.ro/pub/notices/adv-notices/), bundle-ul JavaScript public `app-pub` și serviciul oficial [adv-notice/service.min.js](https://e-licitatie.ro/views/pub/notices/adv-notice/service.min.js). Acestea confirmă listarea POST `https://e-licitatie.ro/api-pub/AdvNoticeCommon/GetAdvNoticeList/` și detaliul GET `https://e-licitatie.ro/api-pub/PUBLICAdvNotice/getForView/{id}`. Nu se extrag anunțuri din HTML.

Payload-ul minimal verificat pentru listare:

```json
{"pageSize":100,"pageIndex":0,"publicationDateStart":"2026-09-16T00:00:00+03:00","publicationDateEnd":"2026-09-16T23:59:59+03:00"}
```

`pageIndex` începe la zero. Interfața oficială enumeră dimensiunile 5, 10, 25, 50, 100. Răspunsul conține `total`, `items`, `searchTooLong`. Codul public confirmă și filtrele `noticeNo`, `contractObject`, `sysAdvertisingNoticeId`, `sysAcquisitionContractTypeId`, `cpvCodeId`, `contractingAuthorityId`, `sysNoticeStateId`, intervalele valorii estimate și `createDateStart` / `createDateEnd`; scannerul nu are nevoie de acestea pentru delta. **Nu a fost confirmat un filtru pentru data modificării.** `createDate` nu este folosit ca dată de modificare.

Au fost făcute numai două POST-uri de listare și un GET de detaliu, plus cele trei citiri ale aplicației publice. Primul POST, pagina de 5 pentru 16 septembrie, a raportat **212 ADV**, cu 5 rânduri. Al doilea, pagina de 100, cu începutul la ora 18:00, a returnat tot totalul 212 și rânduri anterioare orei 18:00. Nu considerăm orele filtre fiabile; folosim zile întregi în Europe/Bucharest. Nu s-au parcurs toate paginile și nu s-a făcut import în baza locală sau în producție. Numărul 212 este o observație pentru acea zi, nu o medie zilnică. Pagina de detaliu verificată: [ADV1548284](https://e-licitatie.ro/pub/notices/adv-notices/view/100734971).

| Informație | Câmp oficial și disponibilitate |
| --- | --- |
| Identitate | Listare `advNoticeId`, `noticeNo`; detaliu `advertisingNoticeId` |
| Titlu / autoritate | Listare `contractObject`, `contractingAuthority` (textul combinat este păstrat, fără separare CUI presupusă) |
| CPV | Listare `cpvCode`, forma `35125300-2 - ...`; detaliu `cpvCode.localeKey` / `text` |
| Valoare | `estimatedValue`, `minEstimatedValue`, `maxEstimatedValue`; intervalul se păstrează în metadata |
| Publicare / termen exact | `publicationDate`, `tenderReceiptDeadline`, cu oră și offset |
| Stare / tip | `sysNoticeState.id` / `text`, `sysAdvertisingNotice`, `sysAcquisitionContractType` |
| CUI / monedă / localizare | Numai când se preiau detalii: `noticeEntityAddress.nationalIDNumber`, `currency.text`, `noticeEntityAddress.county.text` / `city` |
| Descriere / condiții | Detaliu `contractDescription`, `participationConditions`, `contractRelatedConditions`, `additionalInformation`, `awardCriteria` |
| Documentație | Detaliu `documentID`, `documentName`, `documentUrl`, `documentUniqueIdentificationCode`; numai referințe, fără descărcare |
| Versiune / părinte | Detaliu `versionPublicationDate`, `parentAdvertisingNoticeId`; păstrate, fără a presupune semantica completă a versiunilor |

Lista nu furnizează monedă structurată în eșantion; nu se atribuie automat RON. Enumerația integrală a stărilor nu este confirmată. Starea 2 / Publicat a fost observată; alte stări nu sunt considerate active. Tipurile observate sunt Achizitii directe, Cumparari directe și Anexa 2. Un tip necunoscut este păstrat ca istoric, nu promovat automat ca oportunitate.

### Integrare și corectitudine

ADV folosește `SeapClient` → `SeapImporter` → `SeapNormalizer` → `NoticeIngestor`, prin același `ScanDispatcher`, job și checkpoint. Identitatea sursei este `ADV:{advNoticeId}`, familia este `seap_metadata.family=ADV`, iar numărul oficial ADV folosește cheia unică existentă `seap_official_number`. Același număr oficial și aceeași familie actualizează același Notice, inclusiv când identificatorul intern se schimbă. PI/CN/SCN rămân separate chiar dacă ID-urile numerice coincid. Nu există reunire ADV–TED sau ADV–DA/DAN prin asemănare.

Activarea cere termen ISO complet și valid, strict în viitor conform regulii existente, stare Publicat confirmată și tip de publicitate cunoscut. Termenul este exclusiv `tenderReceiptDeadline`, normalizat la ora Bucureștiului; data finalizării DA/DAN nu este consultată. Un termen devenit necunoscut este șters din valoarea curentă, iar anunțul nu rămâne activ cu termenul vechi. Istoricul de revizii și datele personale sunt păstrate; nu se șterg anunțuri.

Implicit, ADV folosește **doar listarea**. `SEAP_FETCH_ADV_DETAILS=false` este valoarea implicită; `true` permite GET-ul de detaliu pentru completare. Modul diagnostic `--limit=1` cere și detaliul unui ADV. Testul fără import cere pagina minimă ADV confirmată, de 5 elemente, iar sample procesează numai limita cerută din acea pagină. Cache-ul existent evită repetarea detaliilor complete recente când listarea este identică. Referințele documentelor se păstrează dacă se preiau detalii; în modul implicit fără detalii nu promitem că toate anunțurile vor avea documentația identificată. Nu există download/analiză PDF.

Ciclurile noi înregistrează `_families` și `_ranges` în `scan_runs.checkpoint`. Fiecare familie începe de la ultima zi finalizată pentru ea, inclusiv acea zi (overlap). Fără reper, începe de ieri. Zilele și paginile sunt parcurse până la `total`, în loturi de maximum `SEAP_BATCH_SIZE` anunțuri, implicit 100 în total; pagina curentă rămâne în checkpoint. Un răspuns gol înainte de total, invalid sau `searchTooLong=true` produce eroare, nu avansează ziua. Nu încercăm subîmpărțire orară nedovedită. O zi trunchiată necesită investigare oficială, nu un succes fals.

Checkpoint-urile existente fără `_families` continuă cele trei familii originale până la finalizare; ADV intră în următorul ciclu, cu propriul reper. Nu se resetează o coadă veche și nu se marchează ADV sincronizat doar pentru că PI/CN/SCN sunt complete. Datele cerute manual se aplică tuturor familiilor noului ciclu. UI afișează ADV și intervalul efectiv per familie; perioada TED rămâne separată. Un checkpoint incomplet are prioritate față de un interval manual nou, ca înainte.

PI păstrează detaliul necesar normalizării existente. CN/SCN păstrează implicit listările fără cele patru detalii per anunț. Reducerea nouă comună este bootstrap-ul scurt și reperul separat, fără reluarea implicită a 30 de zile. Bulk `data.gov.ro` rămâne responsabil de volum/istoric și de verificarea limitată a unei zile bulk neconfirmate, conform implementării anterioare. ADV nu este sărit din cauza acoperirii bulk CN/SCN.

403 **și 429** opresc imediat toate apelurile SEAP din execuție, inclusiv familiile rămase. Nu există retry în aceeași rulare pentru aceste coduri; datele și pagina sunt păstrate. Retry-ul limitat pentru timeout/5xx rămâne. Reluarea periodică respectă pauza existentă. TED rulează independent, nu este limitat de lotul SEAP.

### Cost, ritm și limite

Pentru fiecare zi efectiv interogată, fără erori/retry: `L(N,P) = max(1, ceil(N/P))`. O zi goală necesită tot o listare. Costul unui ciclu este suma zilelor, inclusiv overlap; costul zilnic real este suma ciclurilor pornite/continuate în acea zi. Continuarea paginii salvate nu face o listare nouă.

| Familie | Listări pe zi interogată | Detalii implicite | Documente |
| --- | --- | --- | --- |
| ADV | `L(N_ADV,100)` | 0; opțional maximum 1 per anunț care necesită completare | 0 |
| PI | `L(N_PI,18)` | 1 per anunț nou/schimbat sau cu detalii expirate/lipsă | 0 |
| CN | `L(N_CN,18)` | 0; opțional 4 per anunț care necesită completare | 0 |
| SCN | `L(N_SCN,18)` | 0; opțional 4 per anunț care necesită completare | 0 |

Cele 212 ADV observate ar necesita **3 listări, 0 detalii, 0 documente** pentru o traversare completă a zilei, distribuită pe loturi. Nu pretindem că toate trei au fost executate. Pentru PI/CN/SCN nu s-a făcut o măsurare live nouă. Ziua bulk suplimentară, retry-urile timeout/5xx și overlap-ul se adaugă formulei.

Recomandare inițială conservatoare: înlocuirea cronului SEAP anterior cu `0 */3 * * * ... php artisan seap:tick --no-interaction` (o verificare la trei ore), folosind **workerul tender existent**, unic. Nu adăugați un al doilea cron sau worker. Pauza minimă configurată de 60 minute rămâne în vigoare. Nu s-a schimbat cronul real. La 100/lot, opt rulări permit cel mult 800 verificări/zi, incluzând overlap; volumul, duratele și restricțiile pot impune revizuirea ritmului. Dacă intrările depășesc capacitatea, progresul este păstrat, dar nu promitem actualizare în aceeași zi. Bulk își păstrează cronul și responsabilitatea istorică; nu este substitut pentru deadline-ul live.

Aplicația poate descoperi publicările recente fără scanare masivă și fără detalii per ADV. **Nu garantează toate modificările anunțurilor publicate înainte de overlap**, deoarece nu avem un filtru oficial „modified since”. Nici accesul local 200 nu dovedește că IP-ul KabyNode este deblocat. Nu există blocaj local de acces în acest audit; accesul curent KabyNode nu a fost testat și nu s-a făcut deployment.

Dacă hostingul rămâne restricționat, varianta minimă ulterioară este un worker SEAP dintr-un mediu autorizat de operator: același client/normalizator, export JSON semnat al lotului și checkpoint-ului, transmis printr-un canal autentificat; aplicația de pe hosting ar valida și aplica lotul prin `NoticeIngestor`, apoi ar confirma recepția înainte de avansarea checkpoint-ului. Necesită implementare/revizuire separată a transportului, autentificării și protecției la replay; **nu există acum un endpoint de upload sau infrastructură externă**. Mutarea traficului între IP-uri pentru evitarea unei restricții nu este o soluție implementată sau recomandată.

Testele ADV folosesc date sintetice cu structura oficială verificată, fără contacte personale sau documente descărcate. Acoperă paginare, loturi, checkpoint, overlap per familie, 403/429 și reluare, identitate/upsert, deadline invalid/necunoscut/expirat și schimbat, stare, date personale, referințe documente și fluxul manual pentru toate cele patru familii. Validarea sintetică nu înlocuiește validarea endpointului live limitată descrisă mai sus. Nu sunt necesare migrări noi.

### Verificare finală și fișiere

Suita completă: **208 teste / 1.299 aserțiuni**, toate trecute. `php vendor/bin/pint --dirty --format agent`, `npm run build` și `git diff --check` au trecut. Build-ul păstrează avertismentul existent despre pachetul opțional `fontaine`, fără eroare. Datele de audit și artefactele build sunt ignorate de Git; nu intră în modificările versionate.

Fișiere modificate (23):

```text
.env.example
README.md
app/Console/Commands/ScanSeapNotices.php
app/Jobs/RunManualScan.php
app/Models/Notice.php
app/Models/ScanRun.php
app/Services/NoticeIngestor.php
app/Services/ScanDispatcher.php
app/Services/SeapClient.php
app/Services/SeapImporter.php
app/Services/SeapNormalizer.php
config/services.php
resources/js/app.js
resources/views/notices/index.blade.php
resources/views/notices/scan-family-results.blade.php
resources/views/notices/scan-panel.blade.php
tests/Feature/ManualScanFamiliesTest.php
tests/Feature/NoticeSourceFilterTest.php
tests/Feature/SeapBatchTest.php
tests/Feature/SeapBulkTest.php
tests/Feature/SeapClientTest.php
tests/Feature/SeapImportTest.php
tests/Feature/SeapStabilizationTest.php
```

Fișier nou: `tests/Feature/AdvDeltaTest.php` (21 cazuri de test, incluzând variantele parametrizate).

Stare finală: `master`, ahead 2 față de `origin/master` (commituri preexistente), 23 fișiere modificate și unul nou, nimic staged. Fără commit, push, deployment, scanare completă live sau modificări KabyNode. Mesaj propus: `Add verified ADV daily delta with resumable SEAP checkpoints`.
