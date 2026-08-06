# Current Work â€” TASK-23: image selection and rendering regression

## ACTIVE

```text
MAMONA-23-P0 â€” INITIAL INDEXED MAP
```

Wykonaj tylko aktywnÄ… fazÄ™. Nie przechodÅº automatycznie do nastÄ™pnej.

## GÅ‚Ã³wny cel

NaprawiÄ‡ automatyczny dobÃ³r oraz renderowanie obrazÃ³w w artykuÅ‚ach.

Po ostatniej aktualizacji wystÄ™pujÄ… dwie klasy regresji:

1. Neutralny fallback wyÅ›wietla caption lub alt opisujÄ…cy konkretny obraz, ktÃ³rego finalnie nie ma.
2. Legalny, ale semantycznie albo redakcyjnie niepasujÄ…cy obraz moÅ¼e wygraÄ‡ przez przypadkowe dopasowanie pojedynczego sÅ‚owa.

Do zakoÅ„czenia zadania nie publikowaÄ‡ automatycznie kolejnych materiaÅ‚Ã³w z niesprawdzonymi obrazami.

## PrzykÅ‚ady referencyjne

### R1 â€” faÅ‚szywy caption fallbacku

ArtykuÅ‚ o pingwinie AdÃ©lie pokazuje neutralnÄ… grafikÄ™ geometrycznÄ…, ale caption twierdzi, Å¼e przedstawia pingwina w naturalnym Å›rodowisku.

Oczekiwane: fallback ma wÅ‚asny neutralny caption, alt i brak zewnÄ™trznego creditu.

### R2 â€” legalny, ale niedopuszczalny obraz

ArtykuÅ‚ o neuroplastycznoÅ›ci otrzymuje satyryczny obraz polityka-zombie jedzÄ…cego mÃ³zg, poniewaÅ¼ metadane zawierajÄ… token `brain`.

Oczekiwane: legalnoÅ›Ä‡ przechodzi osobnÄ… walidacjÄ™, ale kandydat odpada na bramce semantycznej/redakcyjnej.

## Zasady wykonania

- UÅ¼ywaj semantycznego indeksu przed rÄ™cznym otwieraniem plikÃ³w.
- Maksymalnie 12 plikÃ³w na jeden subtask eksploracyjny.
- Nie czytaj ponownie pliku bez nazwania konkretnego brakujÄ…cego pytania.
- BrakujÄ…cego pliku nie otwieraj drugi raz.
- Nie implementuj przed zapisaniem root cause i zaakceptowaniem specyfikacji.
- Nie uruchamiaj realnych providerÃ³w, pÅ‚atnych API ani publikacji.
- Nie osÅ‚abiaj walidacji praw i licencji.
- Nie opieraj filtra wyÅ‚Ä…cznie na liÅ›cie nazwisk albo pojedynczych sÅ‚owach.
- Po kaÅ¼dej fazie zatrzymaj siÄ™ na checkpoint.

# Kolejka faz

## MAMONA-23-P0 â€” INITIAL INDEXED MAP â€” ACTIVE

**Agent nadrzÄ™dny:** `mamona-orchestrator`  
**Subagenci:** 2 Ã— `repo-scout`  
**Modele:** scout `qwen3.5:9b/fast`, synteza `qwen3.6:27b/deep`  
**Edycja kodu:** zabroniona

### Cel

ZbudowaÄ‡ potwierdzonÄ… mapÄ™ istniejÄ…cego przepÅ‚ywu obrazÃ³w przy minimalnym odczycie plikÃ³w.

### Zadania rÃ³wnolegÅ‚e

#### P0-A â€” selection pipeline

ZnajdÅº:

1. dane artykuÅ‚u/researchu/kategorii uÅ¼ywane do zapytaÅ„;
2. generowanie query;
3. providerÃ³w i pobieranie kandydatÃ³w;
4. prawa/licencje;
5. ranking semantyczny i redakcyjny;
6. wybÃ³r zwyciÄ™zcy.

#### P0-B â€” metadata and rendering pipeline

ZnajdÅº:

1. finalny plik i identyfikator assetu;
2. source page i direct file;
3. creator i credit;
4. caption i alt;
5. rights manifest;
6. flagÄ™/typ fallbacku;
7. fallback creation;
8. publiczny renderer HTML;
9. zachowanie przy niedostÄ™pnym pliku.

### Wyniki

- uzupeÅ‚nione `docs/ARCHITECTURE.md`;
- uzupeÅ‚nione `docs/IMAGE_PIPELINE_MAP.md`;
- lista maksymalnie 16 najwaÅ¼niejszych plikÃ³w Å‚Ä…cznie;
- lista luk, ktÃ³rych indeks/kod nie rozstrzyga.

### Kryterium zakoÅ„czenia

Mapa zawiera potwierdzonÄ… kolejnoÅ›Ä‡ funkcji i pola przenoszone miÄ™dzy etapami. Brak implementacji i testÃ³w.

---

## MAMONA-23-P1 â€” ROOT CAUSE AND SPEC â€” BLOCKED BY P0

**Agent:** `mamona-architect`  
**Model:** `qwen3.6:27b/deep`  
**Edycja:** tylko `docs/`

### Cel

UstaliÄ‡ konkretnÄ… przyczynÄ™ obu regresji i zapisaÄ‡ specyfikacjÄ™:

```text
docs/specs/TASK-23-image-selection-rendering-regression.md
```

### ObowiÄ…zkowe ustalenia

- gdzie finalny asset moÅ¼e rozminÄ…Ä‡ siÄ™ z captionem/alt/credit/source;
- czy fallback dziedziczy metadane kandydata;
- co dzieje siÄ™, gdy plik nie istnieje albo processing koÅ„czy siÄ™ bÅ‚Ä™dem;
- jak powstaje relevance score;
- czy pojedynczy token moÅ¼e zdominowaÄ‡ ranking;
- gdzie legalnoÅ›Ä‡ jest mylona z uÅ¼ytecznoÅ›ciÄ… redakcyjnÄ…;
- jakie negatywne sygnaÅ‚y sÄ… dostÄ™pne w metadanych;
- czy naprawa wymaga migracji istniejÄ…cych rekordÃ³w.

### Checkpoint

Po specyfikacji zatrzymaj siÄ™ i poproÅ› o akceptacjÄ™. Nie implementuj.

---

## MAMONA-23-P2 â€” FINAL ASSET AND FALLBACK CONSISTENCY â€” BLOCKED BY APPROVAL

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/balanced`

### Cel

ZapewniÄ‡, Å¼e finalnie wyÅ›wietlany plik, caption, alt, credit, source i rights manifest naleÅ¼Ä… do jednego finalnego assetu.

### Wymagania

- fallback ma wÅ‚asne neutralne metadane;
- fallback nie dziedziczy danych odrzuconego lub niedostÄ™pnego kandydata;
- niedostÄ™pny finalny plik nie renderuje podpisu/creditu ÅºrÃ³dÅ‚a;
- renderer uÅ¼ywa wyÅ‚Ä…cznie zweryfikowanego finalnego rekordu;
- istniejÄ…ce poprawne assety zachowujÄ… dotychczasowe dane.

### Minimalna walidacja

- test pingwina/fallbacku;
- test niedostÄ™pnego pliku;
- test poprawnego legalnego obrazu.

---

## MAMONA-23-P3 â€” SEMANTIC AND EDITORIAL RELEVANCE GATE â€” BLOCKED BY P2

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/deep` dla projektu reguÅ‚, potem `balanced` dla implementacji

### Cel

OddzieliÄ‡:

```text
rights validation
```

od:

```text
semantic and editorial suitability
```

### SygnaÅ‚y pozytywne

- gÅ‚Ã³wny temat;
- tytuÅ‚;
- kategoria;
- kluczowe encje;
- gatunki, obiekty, procesy i instytucje z researchu;
- title, description i tags assetu.

### SygnaÅ‚y negatywne

- inny gÅ‚Ã³wny kontekst niÅ¼ artykuÅ‚;
- osoby publiczne niebÄ™dÄ…ce tematem;
- polityczna satyra;
- zombie, gore, makabra, przemoc;
- memy i karykatury;
- szokujÄ…cy albo sensacyjny przekaz nieobecny w artykule.

RozwiÄ…zanie musi byÄ‡ ogÃ³lne i oparte na dostÄ™pnych metadanych. Nie moÅ¼e bazowaÄ‡ wyÅ‚Ä…cznie na jednej liÅ›cie nazwisk lub sÅ‚Ã³w.

JeÅ¼eli Å¼aden kandydat nie speÅ‚nia minimum, wybierz neutralny fallback.

---

## MAMONA-23-P4 â€” DIAGNOSTICS â€” BLOCKED BY P3

**Agent:** `mamona-coder`  
**Model:** `qwen3.6:27b/balanced`

Zapisuj bez sekretÃ³w:

- query dla kaÅ¼dego providera;
- liczbÄ™ kandydatÃ³w;
- przyczynÄ™ odrzucenia;
- relevance score;
- najwaÅ¼niejsze sygnaÅ‚y pozytywne i negatywne;
- przyczynÄ™ fallbacku;
- identyfikator finalnego assetu.

Diagnostyka ma byÄ‡ wystarczajÄ…ca do odtworzenia decyzji, ale nie moÅ¼e logowaÄ‡ kluczy API ani peÅ‚nych sekretÃ³w.

---

## MAMONA-23-P5 â€” REGRESSION TESTS AND VALIDATION â€” BLOCKED BY P2â€“P4

**Agent:** `mamona-tester`  
**Model:** `qwen3.6:27b/balanced`  
**Review:** `mamona-reviewer/deep`

### Test matrix

1. Pingwin:
   - trafny obraz pingwina wygrywa z ogÃ³lnÄ… naturÄ…;
   - fallback nie twierdzi, Å¼e przedstawia pingwina.

2. NeuroplastycznoÅ›Ä‡:
   - naukowy obraz mÃ³zgu moÅ¼e przejÅ›Ä‡;
   - polityk-zombie odpada;
   - sam token `brain` nie wystarcza.

3. Brak odpowiedniego obrazu:
   - fallback;
   - brak starego captionu;
   - brak faÅ‚szywego creditu i ÅºrÃ³dÅ‚a.

4. NiedostÄ™pny plik:
   - brak podpisu do nieistniejÄ…cego zdjÄ™cia;
   - bezpieczny fallback albo pominiÄ™cie figury zgodnie z architekturÄ….

5. Legalny, ale niepasujÄ…cy:
   - prawa przechodzÄ…;
   - redakcja odrzuca;
   - kandydat nie trafia do artykuÅ‚u.

6. PasujÄ…cy i legalny:
   - nadal wygrywa;
   - caption, alt, credit i source pozostajÄ… spÃ³jne.

### IstniejÄ…ce testy do sprawdzenia

- `tests/article-image-pipeline-smoke.php`
- `tests/image-rights-providers-smoke.php`
- `tests/full-auto-selector-smoke.php`
- `tests/post-renderer-smoke.php`
- `tests/generate-all-regression.php`
- `tests/editorial-pipeline-e2e.php`

Nie usuwaj istniejÄ…cych testÃ³w i nie osÅ‚abiaj walidacji licencji.

### Minimalne komendy walidacyjne

```powershell
C:\xampp\php\php.exe tests\article-image-pipeline-smoke.php
C:\xampp\php\php.exe tests\image-rights-providers-smoke.php
C:\xampp\php\php.exe tests\full-auto-selector-smoke.php
C:\xampp\php\php.exe tests\post-renderer-smoke.php
C:\xampp\php\php.exe tests\generate-all-regression.php
```

PeÅ‚ny `editorial-pipeline-e2e.php` uruchom dopiero po sprawdzeniu wymaganych flag i tylko wtedy, gdy zakres faktycznie dotyka peÅ‚nego pipeline'u.

# Stan wykonania

## ZakoÅ„czone

- [ ] P0 â€” initial indexed map
- [ ] P1 â€” root cause and spec
- [ ] P2 â€” final asset/fallback consistency
- [ ] P3 â€” semantic/editorial gate
- [ ] P4 â€” diagnostics
- [ ] P5 â€” tests, validation and review

## Sprawdzone pliki

UzupeÅ‚niaj listÄ™ zamiast otwieraÄ‡ pliki ponownie bez powodu:

```text
â€” brak; P0 jeszcze nie wykonano
```

## Decyzje i pytania

```text
â€” brak; P0 jeszcze nie wykonano
```

## Validation log

| Data | Faza | Komenda/test | Wynik | Uwagi |
|---|---|---|---|---|
| â€” | â€” | â€” | â€” | â€” |

## Format raportu po fazie

1. ZakoÅ„czona faza.
2. Potwierdzone ustalenia.
3. Zmienione pliki.
4. Walidacja.
5. Ryzyka.
6. Jedna nastÄ™pna faza.
7. Czy wymagana jest akceptacja uÅ¼ytkownika.


