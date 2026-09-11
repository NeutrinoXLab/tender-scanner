# Integrarea viitoare a furnizorilor prin HTTP

Tender Scanner are un singur spațiu local, fără conturi separate. Salvarea, ignorarea,
statusul personal și notițele se păstrează pe licitație, separat de datele importate.
Rezultatele furnizorilor nu sunt persistate. Nu există o legătură la baza Price Scanner.

## Activare

Interfața `App\Services\SupplierSearchProvider` poate fi implementată de orice sursă
comercială autorizată. Legătura curentă din `AppServiceProvider` folosește
`PriceScannerSupplierProvider`, un adaptor HTTP pregătit în Tender Scanner.

**Nu a fost verificat sau creat niciun endpoint în Price Scanner.** Contractul de mai
jos este o propunere de integrare, care trebuie implementată/agreată de sursa viitoare.

În `.env`, după ce acel endpoint există și accesul este autorizat:

```dotenv
PRICE_SCANNER_SEARCH_URL=https://HOST/CALEA-AGREATA
PRICE_SCANNER_TOKEN=
```

`PRICE_SCANNER_SEARCH_URL` este URL-ul complet; adaptorul nu adaugă o cale presupusă.
Folosiți HTTPS pentru servicii externe. Un URL HTTP poate fi folosit pentru o integrare
locală. Tokenul opțional se trimite exclusiv server-la-server ca `Authorization: Bearer`.
Nu îl includeți în browser, URL, codul versionat sau răspunsurile API.
După modificarea configurației, eliminați cache-ul configurației Laravel dacă este activ.
Configurația implicită este dezactivată: nu se trimit cereri, nu există rezultate demo.

## Contractul așteptat

O cerere POST JSON, cu `Accept: application/json`, doar la apăsarea butonului „Caută”.
Deschiderea paginii sau selectarea unui termen nu trimite cereri comerciale.
Exemplu de format, cu valori ilustrative:

```json
{
  "query": "Laptopuri",
  "context": {
    "notice_id": 123,
    "cpv": "30200000-1",
    "ean": null,
    "quantity": {"value": 12, "unit": "buc"},
    "specifications": "Laptopuri, 12 buc, memorie 16 GB"
  }
}
```

- `query`: text editabil, 1–240 caractere.
- `notice_id`: identificatorul local Tender Scanner, fără semnificație în baza sursei.
- `cpv`: valoarea deja stocată, sau null.
- `ean`: numai EAN-8/EAN-13 explicit, cu cifră de control validă; altfel null.
- `quantity`: numai cantitate explicită în fragmentul selectat, sau null.
- `specifications`: fragmentul sursă selectat, maximum 2.000 caractere, sau null.
- Nu se trimit notițele personale, statusul personal sau toate datele brute ale licitației.
- După editarea manuală a query-ului, EAN/cantitatea din vechea propunere nu sunt reutilizate;
  se extrag din query-ul nou doar dacă sunt precizate explicit.

Răspuns HTTP 200 JSON (acest exemplu ilustrează schema, nu rezultate reale):

```json
{
  "items": [
    {
      "supplier": "Denumirea furnizorului",
      "product": "Denumirea produsului",
      "price": "1234.50",
      "currency": "RON",
      "availability": "Text furnizat de sursă",
      "match_score": 0.9,
      "url": "https://furnizor.example/produs"
    }
  ]
}
```

`items` este obligatoriu, listă de maximum 100 rezultate. `items: []` înseamnă căutare
reușită fără rezultate. Câmpurile unui rezultat pot lipsi sau fi null; UI afișează „—”.
`price` este numeric nenegativ (număr sau șir numeric), în moneda `currency` (trei litere
majuscule). Furnizorul trebuie să clarifice TVA și unitatea de vânzare în propriul produs;
aplicația nu presupune că acestea sunt comparabile și nu calculează totaluri.
`match_score` este opțional, între 0 și 1, primit de la sursă, fără scoring local/AI.
Linkurile acceptate sunt HTTP/HTTPS; alte scheme sunt eliminate.

Nu se inventează câmpuri lipsă. HTTP non-2xx, timeout, erorile de conexiune și schema
nevalidă sunt afișate ca indisponibilitate/eroare, fără rezultate fictive. Conectare:
maximum 3 secunde; cerere: maximum 10 secunde. Nu se urmăresc redirecturi și nu există
retry automat al cererii comerciale. Tokenurile și răspunsurile interne de eroare nu sunt
expuse utilizatorului.

## Propuneri de căutare

`NoticeSupplierQueries` folosește titlul, obiectul, CPV și descrierile/loturile locale PI,
CN/SCN și TED. Elimină formule administrative și numele exact al autorității. Propune
termeni de produse numai când apar în date, inclusiv în liste explicite, fără a deduce
produse din codul CPV. Se afișează maximum 12 propuneri distincte, editabile separat.
Extracția este deterministă, orientativă, fără AI plătit; nu garantează inventarul complet
al produselor din documentația de atribuire. Pentru cazuri ambigue se folosește editarea
manuală. Nu se descarcă documente și nu se contactează SEAP pentru această funcție.

## Testare și organizare

Testele folosesc `Http::fake`, `Http::preventStrayRequests` și mock-uri ale contractului.
Răspunsurile de test sunt exclusiv în teste, nu în UI. Rezultatele nu sunt persistate;
repetarea căutării nu creează înregistrări sau duplicate locale.

„Salvează” elimină marcajul „Ignoră” și invers. Anularea fiecărei acțiuni revine la starea
neutră. Statusurile personale sunt Nouă, Analizez, Cer ofertă, Particip, Renunț, independente
de marcaj și de statusul SEAP/TED. Listele Salvate/Ignorate includ și proceduri SEAP
expirate; filtrele explicite ale utilizatorului se păstrează.
