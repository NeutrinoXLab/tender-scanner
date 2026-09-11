# Acces privat — Tender Scanner

## Instalare și primul administrator

Din directorul proiectului, după configurarea bazei de date:

```sh
php artisan migrate --force
php artisan app:create-admin
```

Comanda solicită Nume, Email, Parolă și Confirmă parola. Parola se introduce ascuns,
nu prin argumente și nu prin variabile păstrate în repository. Minimum 12, maximum
72 caractere. Emailul este normalizat la litere mici. Comanda refuză o adresă deja
înregistrată și refuză crearea dacă există deja un admin activ. Nu generează conturi
cu parole implicite și nu transferă automat datele personale vechi.

Dacă sunt doar conturi vechi dezactivate, alege o adresă nouă pentru primul admin;
ulterior le poți edita/reactiva din interfață, după resetarea parolelor cunoscute/de test.
Comanda se rulează interactiv, fără `--no-interaction`. Nu este un mecanism de resetare
sau de promovare automată a unui cont existent.

Accesează `/login`, autentifică-te, apoi deschide **Utilizatori → Creează utilizator**.
Pentru ceilalți 3 utilizatori introdu numele, emailul, o parolă de minimum 12 caractere,
confirmarea, rolul `user` și bifează **Cont activ**. Parolele sunt comunicate separat de
administrator; aplicația nu trimite emailuri. Nu există o limită tehnică de 4 conturi.

## Autentificare și autorizare

Se reutilizează modelul Laravel `User`, tabelele `users` și `sessions`, guard-ul `web`
și hashing-ul standard al parolei din model (`hashed`). Nu sunt adăugate dependențe.
Login-ul regenerează sesiunea, logout-ul o invalidează și regenerează tokenul CSRF.
Maximum 5 încercări eșuate pe email+IP într-un minut, plus limită de 20 POST-uri login/IP/minut.
Mesajul de eroare nu dezvăluie dacă adresa există sau contul este inactiv.

Toate rutele aplicației, în afară de GET/POST `/login`, cer `auth` și `auth.session`.
Middleware-ul `EnsureActiveUser` verifică starea curentă înaintea verificării sesiunii.
Dezactivarea oprește accesul la următoarea cerere, inclusiv pentru o sesiune existentă.
Resetarea parolei invalidează sesiunile anterioare la următoarea cerere.
Nu există signup, resetare prin email, invitații sau verificare email.

Rolul este stocat în `users.role`, cu valori validate `admin`/`user`.
`users.is_active` este boolean. Conturile existente la migrare devin implicit inactive.
Adminul poate folosi toată aplicația și administra conturi; datele personale ale altora
nu sunt afișate nici adminului. Nu se poate dezactiva sau retrograda ultimul admin activ.
Nu există acțiune de ștergere a utilizatorilor în interfață.

Numai un admin activ, prin Gate `manage-users`, poate accesa:

- GET `/admin/users` — listă;
- GET `/admin/users/create` și POST `/admin/users` — creare;
- GET `/admin/users/{user}/edit` și PUT/PATCH `/admin/users/{user}` — editare.

Editarea include nume/email, rol, activare/dezactivare și parolă opțională.
Parola goală la editare păstrează parola existentă. Toate formularele POST/PATCH includ CSRF;
validarea este pe server, iar proprietarul marcajelor personale vine exclusiv din sesiune.

## Date personale și migrarea valorilor globale

Tabelul `user_notices` are chei externe la `users` și `notices`, unicitate pe
`(user_id, notice_id)`, plus `is_saved`, `is_ignored`, `personal_status`, `personal_notes`
și timestamps. Lipsa unei înregistrări înseamnă Nouă, nesalvată, neignorată, fără notițe.
Filtrele și pagina individuală folosesc exclusiv utilizatorul autentificat.
Salvează/Ignoră rămân reversibile și mutual exclusive. Statusul personal rămâne separat
de statusul procedurii. Notițele sunt escapate în HTML și nu sunt trimise providerului.

Coloanele personale originale din `notices` NU sunt șterse. Ele sunt o copie istorică,
nu mai sunt afișate și nu mai sunt modificate de formularele personale. Migrarea adaugă
`notices.legacy_personal_user_id` ca marcaj al transferului efectuat.

După crearea primului admin, pentru a-i atribui datele globale existente:

```sh
php artisan app:assign-legacy-personal-data admin@example.ro --dry-run
php artisan app:assign-legacy-personal-data admin@example.ro
```

Înlocuiește `admin@example.ro` cu emailul real al adminului destinatar. Folosește primul
admin numai dacă acesta este proprietarul potrivit; înainte de transfer datele rămân
neatribuite și păstrate. Comanda acceptă numai un admin activ și afișează numărul de
copieri și conflicte. Fără `--dry-run`, copierea și marcarea se fac tranzacțional.

Nu se suprascrie NICIO înregistrare personală existentă, nici măcar una cu valori implicite.
Conflictele rămân în coloanele originale, nemarcate, pentru analiză manuală; comanda
întoarce cod de ieșire 1 dacă există conflicte. Repetarea nu dublează datele deja copiate.
Pentru anunțuri vechi deja unite, transferul vizează anunțul canonic. Originalele rămân
păstrate inclusiv după copiere. Nu distribui aceleași notițe tuturor conturilor.

Migrații adăugate:

- `2026_09_11_092638_add_private_access_fields_to_users_table.php`;
- `2026_09_11_092639_create_user_notices_table.php`;
- `2026_09_11_092640_add_legacy_personal_user_id_to_notices_table.php`.

## Publicarea ulterioară

Nu a fost făcut deployment. Pentru `seap.novelions.ro`, document root trebuie să fie
folderul `public`, nu rădăcina proiectului. Folosește HTTPS și configurația de producție:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seap.novelions.ro
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Lasă `SESSION_DOMAIN` necompletat pentru cookie limitat la subdomeniu; nu îl partaja cu
alte proiecte. Păstrează un `APP_KEY` unic și protejat. După configurare:

```sh
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Meta `noindex, nofollow`, antetul `X-Robots-Tag` și `robots.txt` descurajează indexarea.
Răspunsurile aplicației au `Cache-Control: private, no-store`. Acestea nu înlocuiesc login-ul.
Ruta publică de health-check `/up` a fost eliminată. Servirea generică de fișiere private
și endpoint-ul de browser logs Boost sunt dezactivate, deoarece nu fac parte din fluxul
aplicației. Asset-urile CSS/JS/fonturi rămân publice pentru pagina de login.

## Verificare

```sh
php artisan test --compact
php vendor/bin/pint --dirty --format agent
npm run build
```

Testele acoperă rutele private, login/logout, limitarea încercărilor, CSRF, dezactivarea,
resetarea parolei, administrarea și validarea conturilor, izolarea între utilizatori,
transferul controlat și compatibilitatea importurilor/filtrelor/furnizorilor.
Testele importului folosesc răspunsuri simulate; nu este necesar acces SEAP live.

## Fișierele acestei etape

- [app/Console/Commands/AssignLegacyPersonalData.php](app/Console/Commands/AssignLegacyPersonalData.php)
- [app/Console/Commands/CreateAdmin.php](app/Console/Commands/CreateAdmin.php)
- [app/Http/Controllers/AdminUserController.php](app/Http/Controllers/AdminUserController.php)
- [app/Http/Controllers/AuthController.php](app/Http/Controllers/AuthController.php)
- [app/Http/Controllers/NoticeController.php](app/Http/Controllers/NoticeController.php)
- [app/Http/Controllers/NoticePersonalController.php](app/Http/Controllers/NoticePersonalController.php)
- [app/Http/Middleware/EnsureActiveUser.php](app/Http/Middleware/EnsureActiveUser.php)
- [app/Http/Middleware/PreventIndexing.php](app/Http/Middleware/PreventIndexing.php)
- [app/Http/Requests/AdminUserRequest.php](app/Http/Requests/AdminUserRequest.php)
- [app/Models/Notice.php](app/Models/Notice.php)
- [app/Models/User.php](app/Models/User.php)
- [app/Models/UserNotice.php](app/Models/UserNotice.php)
- [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php)
- [bootstrap/app.php](bootstrap/app.php)
- [config/boost.php](config/boost.php)
- [config/filesystems.php](config/filesystems.php)
- [database/factories/NoticeFactory.php](database/factories/NoticeFactory.php)
- [database/factories/UserFactory.php](database/factories/UserFactory.php)
- [database/factories/UserNoticeFactory.php](database/factories/UserNoticeFactory.php)
- [database/migrations/2026_09_11_092638_add_private_access_fields_to_users_table.php](database/migrations/2026_09_11_092638_add_private_access_fields_to_users_table.php)
- [database/migrations/2026_09_11_092639_create_user_notices_table.php](database/migrations/2026_09_11_092639_create_user_notices_table.php)
- [database/migrations/2026_09_11_092640_add_legacy_personal_user_id_to_notices_table.php](database/migrations/2026_09_11_092640_add_legacy_personal_user_id_to_notices_table.php)
- [database/seeders/DatabaseSeeder.php](database/seeders/DatabaseSeeder.php)
- [PRIVATE_ACCESS.md](PRIVATE_ACCESS.md)
- [public/robots.txt](public/robots.txt)
- [resources/views/admin/users/form.blade.php](resources/views/admin/users/form.blade.php)
- [resources/views/admin/users/index.blade.php](resources/views/admin/users/index.blade.php)
- [resources/views/auth/login.blade.php](resources/views/auth/login.blade.php)
- [resources/views/components/navigation.blade.php](resources/views/components/navigation.blade.php)
- [resources/views/layouts/private.blade.php](resources/views/layouts/private.blade.php)
- [resources/views/notices/index.blade.php](resources/views/notices/index.blade.php)
- [resources/views/notices/personal-panel.blade.php](resources/views/notices/personal-panel.blade.php)
- [resources/views/notices/show.blade.php](resources/views/notices/show.blade.php)
- [resources/views/notices/suppliers.blade.php](resources/views/notices/suppliers.blade.php)
- [routes/web.php](routes/web.php)
- [SUPPLIER_API.md](SUPPLIER_API.md)
- [tests/Feature/AdminSetupTest.php](tests/Feature/AdminSetupTest.php)
- [tests/Feature/AdminUsersTest.php](tests/Feature/AdminUsersTest.php)
- [tests/Feature/ExampleTest.php](tests/Feature/ExampleTest.php)
- [tests/Feature/ManualScanTest.php](tests/Feature/ManualScanTest.php)
- [tests/Feature/NoticeFundingFilterTest.php](tests/Feature/NoticeFundingFilterTest.php)
- [tests/Feature/NoticeIngestionTest.php](tests/Feature/NoticeIngestionTest.php)
- [tests/Feature/NoticePersonalTest.php](tests/Feature/NoticePersonalTest.php)
- [tests/Feature/NoticeSummaryTest.php](tests/Feature/NoticeSummaryTest.php)
- [tests/Feature/NoticeSupplierTest.php](tests/Feature/NoticeSupplierTest.php)
- [tests/Feature/PersonalIsolationTest.php](tests/Feature/PersonalIsolationTest.php)
- [tests/Feature/PrivateAccessTest.php](tests/Feature/PrivateAccessTest.php)
- [tests/Feature/SeapImportTest.php](tests/Feature/SeapImportTest.php)
- [tests/Feature/TedImportTest.php](tests/Feature/TedImportTest.php)
