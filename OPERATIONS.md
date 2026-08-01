# Mamona — dokumentacja operacyjna

## Kosz tematów — retencja 10 dni

Migracje `20260731_020_topic_trash` i `20260731_021_topic_trash_snapshots` dodają stan kosza, snapshot statusu/score, trwały audyt i historię cleanup. Daty są zapisywane i porównywane w UTC; kwalifikuje się rekord z `trashed_at <= now UTC - 10 days`.

Podstawą jest jawny, idempotentny job CLI (otwarcie panelu nie jest wymagane):

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\mamona\php\cleanup-topic-trash.php
```

W Harmonogramie zadań Windows ustaw uruchomienie codziennie, np. 03:15: program `C:\xampp\php\php.exe`, argument `C:\xampp\htdocs\mamona\php\cleanup-topic-trash.php`, katalog roboczy `C:\xampp\htdocs\mamona`. Konto zadania musi mieć dostęp do `data/cms.sqlite`. Kod `0` oznacza sukces, `1` błędy pojedynczych rekordów. Podsumowanie `deleted/skipped/errors` trafia na stdout/stderr i do `topic_trash_cleanup_runs`; błąd jednego tematu nie zatrzymuje pozostałych.

„Trwałe usunięcie” tworzy nieodwracalny tombstone (`purged_at`), ponieważ FK łączą temat z feedem, batchami, research, szkicami, QC i obrazami. Nie wykonujemy cascade delete: publikacje, źródła, dane licencyjne i audyt pozostają. Koszowanie jest blokowane przy aktywnym, queued lub rate-limited elemencie batcha.

Rollback: przed migracją wykonaj kopię `data/cms.sqlite`. Bezpieczny rollback aplikacyjny przywraca poprzedni kod i pozostawia dodatkowe kolumny/tabele (starszy kod je ignoruje). Pełny rollback danych wymaga odtworzenia kopii; nie usuwaj ręcznie audytu ani tombstone'ów.

Decyzja nawigacyjna: główna „Praca redakcyjna” to `Studio / RSS → Tematy → Gotowe propozycje → Kosz`. `Generowanie / Batch` pozostaje jako `Operacje API / Diagnostyka`, a stary URL `admin-editorial-queue.php` jest drugorzędnym `Procesy / Historia` bez akcji review i publikacji.

Ten dokument opisuje uruchomienie, obsługę i odzyskiwanie procesu redakcyjnego
ukończonego w TASK-22. Specyfikacja funkcjonalna pozostaje w
`NIEUSUWAĆ-TASKI.txt`, a skrócony opis architektury w `README.md`.

## Worker pobierania RSS

Po zmianie rejestru źródeł uruchom `php tests/official-feed-replacements-smoke.php --live`,
a następnie `php php/fetch-feeds.php`. Pierwszy test sprawdza bezpieczny transport
i parsowanie oficjalnego zamiennika NIBIB; drugi raportuje każde aktywne źródło.
HTTP 403 jest trwałą odmową bez ponawiania i bez obchodzenia ochrony. Wyłączone
JPL oraz NIH News Releases zachowują rekord i czytelną przyczynę w `last_error`.

Audyt pełnych odpowiedzi bez zmiany zapisanych ETagów uruchamia
`php scripts/audit-feed-redirects.php`. Raport zawiera każdy hop, status, URL,
host, schemat, port, publiczną klasę rozwiązanych adresów IP, content type,
liczbę bajtów i kod decyzji. HTTP 304 jest poprawnym `not_modified`, a błędy
`redirect_blocked`, `redirect_loop` i `redirect_limit` są raportowane osobno.
Każdy redirect jest ponownie sprawdzany i przypinany do zweryfikowanego IP;
przekierowania do sieci lokalnych/prywatnych, downgrade HTTPS, userinfo oraz
niestandardowe porty pozostają zablokowane.

Przycisk w Studio zapisuje job w SQLite i na Windows uruchamia osobny proces PHP CLI.
W XAMPP ustaw `CMS_PHP_CLI=C:\\xampp\\php\\php.exe`. Jako zabezpieczenie po
restarcie Apache dodaj w Harmonogramie zadaĹ„ Windows wywoĹ‚anie co minutÄ™:

```text
C:\xampp\php\php.exe C:\Ĺ›cieĹĽka\do\mamona\php\content-studio-worker.php --next
```

Worker pobierze tylko oczekujÄ…cy job; blokada w SQLite nie pozwala uruchomiÄ‡
dwĂłch ingestionĂłw. Utracony heartbeat po 90 sekundach oznacza job jako
`interrupted`, po czym administrator moĹĽe bezpiecznie kliknÄ…Ä‡ ponownie. Skrypt
`php/fetch-feeds.php` pozostaje niezaleĹĽnym narzÄ™dziem CLI.

## Worker generowania batch

Zakładka `Tematy` wysyła akcje `research`, `draft`, `quality`, `images` oraz
`generate_all` do wspólnej kolejki `generation_batch_items`. Domyślnie panel
promuje wybór 10 tematów, natomiast `CMS_BATCH_MAX_TOPICS` (domyślnie 50,
maksymalnie 500) ustala twardy limit jednego requestu. Większa zaakceptowana
partia nadal jest wykonywana przez worker, nigdy równolegle w requestcie HTTP.

Studio redakcyjne uruchamia trwały batch 1–10 tematów. Na XAMPP/Windows ustaw opcjonalnie
`CMS_PHP_CLI=C:\\xampp\\php\\php.exe`; panel uruchomi worker w tle. Dla odpornego
wznowienia po restarcie dodaj w Harmonogramie zadań Windows wywołanie co minutę:

```text
C:\xampp\php\php.exe C:\ścieżka\do\mamona\php\generation-batch-worker.php --drain
```

`CMS_BATCH_WORKER_CONCURRENCY` ma wartość 1 i może wynosić maksymalnie 2. Leasing
w SQLite pilnuje limitu również przy dwóch workerach. `CMS_BATCH_LEASE_SECONDS`
steruje odzyskaniem elementu po awarii, a `CMS_BATCH_RATE_LIMIT_BACKOFF_SECONDS`
bazowym opóźnieniem po 429 lub timeout. Nagłówek `Retry-After` ma pierwszeństwo
przed tym minimum. Odświeżenie panelu nie uruchamia ponownie Gemini.
`GEMINI_API_MOCK=true` wraz z `CMS_SOURCE_IMAGE_MOCK=true` uruchamia pełny test bez sieci i kosztów.

## Legalne źródła obrazów i manifest praw

Każdy automatycznie wybrany asset musi mieć per-item manifest praw z adresem strony
oryginału, bezpośrednim plikiem, twórcą, pełnym credit line, surowym oświadczeniem
praw, migawką licencji i jej skrótem oraz flagami commercial/derivatives/attribution,
third-party, osób i znaków. Brak lub sprzeczność któregokolwiek pola odrzuca wyłącznie
kandydata; waterfall przechodzi do następnego źródła, a na końcu tworzy neutralny SVG.

Automatyczny ranking to: dokładny asset naukowy/instytucjonalny, CC0/Public Domain,
CC BY/BY-SA, Pexels, lokalna ilustracja. CC-NC, CC-ND, InC, NoC-NC, unknown,
rights-reserved, third-party oraz wyjątki ESO są niedozwolone. Unsplash i Pixabay są
providerami manual-only i worker ich nie wywołuje. Brak klucza Smithsonian, Europeana
lub Pexels nie zatrzymuje batcha. Odpowiedzi wyszukiwarek są cache'owane domyślnie
przez 24 godziny; diagnostyka pokazuje tylko tryb providera, nigdy wartość klucza.
Opcjonalne `CMS_ESO_ASSET_CATALOG_URL`, `CMS_USGS_ASSET_CATALOG_URL` i
`CMS_NCI_ASSET_CATALOG_URL` mogą wskazywać kontrolowany katalog JSON, ale każdy
zwrócony rekord i tak przechodzi centralną walidację per-item; sam host katalogu
nie stanowi dowodu licencji.

Podstawy zasad zweryfikowano 2026-08-01: Smithsonian Open Access FAQ
<https://www.si.edu/openaccess/faq>, Europeana rights statements
<https://pro.europeana.eu/page/available-rights-statements>, ESO copyright
<https://www.eso.org/public/copyright/>, NASA Images and Media
<https://www.nasa.gov/nasa-brand-center/images-and-media/>, USGS copyrights
<https://www.usgs.gov/information-policies-and-instructions/copyrights-and-credits>,
NCI Visuals Online <https://visualsonline.cancer.gov/about.cfm>, Pexels API i licencja
<https://www.pexels.com/api/documentation/> oraz <https://www.pexels.com/legal-pages/license/>,
Unsplash API Guidelines <https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines>
i Pixabay API <https://pixabay.com/api/docs/>.

## Globalny limiter i budzet Gemini

Produkcja korzysta z realnego Gemini; mock jest wylacznie izolacja testow. Wszystkie
procesy wspoldziela w SQLite limiter `GEMINI_QUOTA_PROJECT` + model: domyslnie
10 RPM, jedno wywolanie naraz, konfigurowalne TPM/RPD i osobny stan modeli z
`GEMINI_MODEL_FALLBACKS`. Ledger zapisuje cel, stage, topic/batch/item, fingerprint,
model, probe, status i tokeny, ale nie klucz ani pelny prompt.

Happy path zuzywa 3 prawdziwe requesty: research, draft i QC. Budzet trudnego tematu
wynosi 15 requestow wyslanych; cache hit i quota wait nie sa liczone. Request 15 jest
zarezerwowany dla finalnego QC. Brak dalszego budzetu uruchamia lokalny source-bounded
safe composer i prowadzi do prywatnego podgladu z notatkami, nigdy do publikacji.
Testy CLI nie moga polaczyc sie z Gemini bez `CMS_ALLOW_LIVE_GEMINI_TEST=1`.

### Pauza automatycznego dispatchera

Trwala pauza w bazie zatrzymuje scheduler, reconcile i automatyczne retry, ale nie
blokuje recznego `Wygeneruj calosc`:

```powershell
php scripts/automatic-dispatch-control.php status
php scripts/automatic-dispatch-control.php pause
php scripts/automatic-dispatch-control.php resume
```

`resume` jedynie zdejmuje blokade przyszlego dispatchu; nie kolejkuje automatycznie
kart `paused_by_operator`. Ten sam przelacznik Pauza/Wznow jest dostepny w panelu
tematow. `CMS_AUTOMATIC_DISPATCH_PAUSED=true` jest dodatkowa, wymuszajaca blokada
srodowiskowa.

## 1. Wymagania

- PHP 8.1 lub nowszy;
- rozszerzenia PHP: PDO SQLite, mbstring, DOM/XML, cURL, fileinfo i zlib;
- do miniatur: PHP GD z WebP albo Python 3 z Pillow;
- Apache lub inny serwer WWW wskazujący katalog projektu jako document root;
- prawa zapisu dla `data/`, `pages/`, `images/posts/`, `feed.xml`,
  `sitemap.xml`, `robots.txt` i `index.html`.

Projekt nie wczytuje automatycznie pliku `.env`. Zmienne z `.env.example`
należy ustawić w Apache, konfiguracji hostingu, usłudze PHP-FPM, kontenerze,
cron albo Harmonogramie zadań Windows.

## 2. Uruchomienie na czystym środowisku

1. Skopiuj projekt bez plików z sekretami i bez starej bazy.
2. Ustaw co najmniej `CMS_ENV`, `CMS_PUBLIC_URL`, nazwę serwisu i strefę czasu.
3. Zapewnij PHP prawo zapisu do katalogów wymienionych wyżej.
4. Otwórz aplikację lub wykonaj dowolny bezpieczny skrypt CLI ładujący
   `php/admin-database.php`. `data/cms.sqlite` i migracje utworzą się
   automatycznie.
5. Utwórz poświadczenia administratora przez ekran logowania/konfiguracji
   panelu. `data/admin-credentials.json` nie może trafić do repozytorium.
6. Uzupełnij prawdziwe dane wydawcy, adres kontaktowy oraz biogramy autorów.
7. Skonfiguruj źródła i wykonaj testy z sekcji 10.
8. Dopiero po poprawnym dry-run schedulera ustaw
   `CMS_AUTOMATIC_PUBLISHING=true`.

Przed startem produkcyjnym wymagane są:

```text
CMS_ENV=production
CMS_PUBLIC_URL=https://prawdziwa-domena.pl
CMS_PUBLISHER_LEGAL_NAME=...
CMS_EDITORIAL_CONTACT_EMAIL=...
CMS_PRIVACY_CONTACT_EMAIL=...
CMS_CONTACT_RETENTION_POLICY=...
```

Panel kontaktu musi zawierać prawdziwy adres, a aktywni autorzy prawdziwe
biogramy. Braki blokują planowanie i publikację w `production`.

## 3. Codzienny proces redakcyjny

1. Pobierz aktywne kanały:

   ```text
   php php/fetch-feeds.php
   ```

2. Przelicz grupowanie i punktację:

   ```text
   php php/group-topics.php
   php php/score-topics.php
   ```

3. W panelu „Tematy” sprawdź dopasowania, źródło pierwotne, ryzyko i
   uzasadnienie wyniku.
4. W panelu „Generowanie” przygotuj research, zatwierdź poprawną paczkę,
   przygotuj szkic i wykonaj kontrolę jakości.
5. Dla zaliczonego szkicu przygotuj miniaturę.
6. W edytorze sprawdź tytuł, lead, treść, autora, SEO, alt, źródła, disclosure
   AI i podgląd.
7. Zaplanuj materiał. Publikacja jest możliwa tylko dla najnowszego szkicu z
   zaliczoną kontrolą bez aktywnej twardej blokady.
8. Sprawdź kandydatów schedulera:

   ```text
   php php/publish-scheduled.php --dry-run
   ```

9. Uruchom scheduler ręcznie albo cyklicznie:

   ```text
   php php/publish-scheduled.php
   ```

Publikacja tworzy HTML artykułu i synchronizuje stronę główną, strony
kategorii, sitemapę, RSS oraz `robots.txt`. Powtórne wykonanie nie publikuje
tego samego materiału ponownie.

## 4. Tryb manual bez klucza API

Ustaw:

```text
CMS_GENERATION_MODE=manual
OPENAI_API_MOCK=false
```

Dla researchu, szkicu i kontroli jakości:

1. przygotuj operację w panelu „Generowanie”;
2. skopiuj prompt albo wyeksportuj TXT/JSON;
3. wklej prompt do ChatGPT Plus;
4. skopiuj wyłącznie wynikowy obiekt JSON;
5. wklej odpowiedź do panelu albo zaimportuj plik;
6. popraw błędy wskazane przez walidację — ręczny import nie omija schematu,
   wersjonowania ani blokad.

Dla miniatury skopiuj przygotowany prompt do generatora obrazów w ChatGPT Plus,
pobierz JPEG/PNG/WebP o rozmiarze co najmniej 1280×720 i wgraj go w panelu.
System zachowa oryginał, utworzy publiczny WebP 1280×720 i zapisze metadane.

## 5. Tryb API i lokalna atrapa

Prawdziwe API:

```text
CMS_GENERATION_MODE=api
OPENAI_API_MOCK=false
OPENAI_API_KEY=...
OPENAI_MODEL=gpt-5.6-terra
OPENAI_IMAGE_MODEL=gpt-image-2
```

Bezkosztowa atrapa pełnego procesu:

```text
CMS_GENERATION_MODE=api
OPENAI_API_MOCK=true
```

Atrapa nie wykonuje połączeń sieciowych, nie wymaga klucza i tworzy wyłącznie
techniczne dane testowe. Obsługuje research, szkic, kontrolę jakości oraz
miniaturę. Nie wolno traktować jej wyników jako materiału redakcyjnego.

Zmiana `manual` ↔ `api` dotyczy tylko nowych operacji. Istniejące operacje,
wersje szkiców, kontrole i miniatury zachowują zapisany tryb i pozostają
widoczne.

Jeżeli PHP nie ma GD z WebP:

```text
CMS_IMAGE_PROCESSOR_PYTHON=C:\pełna\ścieżka\do\python.exe
```

W tym interpreterze musi być dostępna biblioteka Pillow.

## 6. Automatyzacja i jej wyłączenie

Najbezpieczniejszy harmonogram:

1. `fetch-feeds.php`;
2. `group-topics.php`;
3. `score-topics.php`;
4. ręczne zatwierdzenia redakcyjne;
5. `publish-scheduled.php`.

Natychmiastowe wyłączenie publikacji:

```text
CMS_AUTOMATIC_PUBLISHING=false
```

Następnie wyłącz zadanie cron/Harmonogramu Windows. Tryb `--dry-run` pozostaje
dostępny. Aby zatrzymać płatne generowanie, ustaw
`CMS_GENERATION_MODE=manual`, usuń `OPENAI_API_KEY` ze środowiska procesu i
wyłącz zadania automatycznie przygotowujące operacje.

## 7. Logi i pliki robocze

- baza: `data/cms.sqlite`;
- log schedulera JSON Lines: `data/scheduled-publication.log`;
- blokada schedulera: `data/scheduled-publication.lock`;
- sesje panelu: `data/sessions/`;
- oryginały miniatur: `data/thumbnails/originals/`;
- publiczne miniatury: `images/posts/thumbnails/`;
- manifest stron feedu: `data/generated-news-pages.json`;
- archiwa operacji porządkujących: `data/archives/`;
- publiczne pliki odkrywania: `sitemap.xml`, `feed.xml`, `robots.txt`.

Błędy generowania są również zapisywane przy rekordach operacji w bazie.
Sekrety i pełny klucz API nie powinny występować w logach.

## 8. Odzyskiwanie po błędzie

### Scheduler zwraca kod 3

Inny proces trzyma blokadę. Sprawdź działające procesy PHP. Plik
`data/scheduled-publication.lock` usuń dopiero po potwierdzeniu, że żaden
scheduler nie działa.

### Jeden materiał nie został opublikowany

Scheduler kontynuuje pozostałe materiały. Odczytaj ostatni wpis `post_failed`
w logu, popraw materiał w panelu, ponów kontrolę jakości i zaplanuj go ponownie.
Nie zmieniaj statusu bezpośrednio w SQLite.

### Operacja AI ma status `failed`

Sprawdź komunikat w panelu. Przy błędzie schematu przygotuj nową operację lub
popraw import manualny. Przy błędzie sieci/limitu sprawdź CA, model, budżet i
klucz. Nie edytuj `output_json` ręcznie.

### Błąd miniatury

Poprzednia zaakceptowana wersja pozostaje aktywna. Sprawdź GD/Pillow, prawa
zapisu oraz minimalny rozmiar wejścia i przygotuj nową wersję.

### Niespójny HTML, sitemap lub RSS

Najpierw wykonaj kopię bazy i plików publicznych. Następnie zapisz ponownie
poprawny opublikowany materiał albo uruchom synchronizację przez panel.
Zweryfikuj, że szkice nie pojawiły się w `pages/`, `feed.xml` ani
`sitemap.xml`.

### Uszkodzona baza

Zatrzymaj zapis aplikacji i zadania cykliczne. Zachowaj uszkodzony plik do
analizy, odtwórz ostatnią zweryfikowaną kopię `data/cms.sqlite`, uruchom
migracje i wykonaj testy publikacji przed ponownym włączeniem schedulera.

## 9. Kopie zapasowe

Przed migracją, wdrożeniem lub większą operacją zbiorczą wykonaj spójną kopię:

- `data/cms.sqlite` po zatrzymaniu procesów zapisujących;
- `pages/`, `index.html`, `feed.xml`, `sitemap.xml`, `robots.txt`;
- `images/posts/` i `data/thumbnails/`;
- konfiguracji serwera bez eksportowania sekretów do repozytorium.

Odtworzenie należy najpierw sprawdzić poza produkcją.

## 10. Test odbiorowy TASK-22

Test pełnego procesu modyfikuje lokalną bazę, ale usuwa własne dane i przywraca
źródła, tryb, logi oraz publiczne pliki. Nie uruchamiaj go równolegle z panelem
ani schedulerem.

PowerShell:

```powershell
$env:CMS_ALLOW_PIPELINE_E2E='1'
$env:CMS_IMAGE_PROCESSOR_PYTHON='C:\pełna\ścieżka\do\python.exe'
C:\xampp\php\php.exe tests\editorial-pipeline-e2e.php
```

Linux/macOS:

```bash
CMS_ALLOW_PIPELINE_E2E=1 \
CMS_IMAGE_PROCESSOR_PYTHON=/pełna/ścieżka/do/python \
php tests/editorial-pipeline-e2e.php
```

Oczekiwany status: `EDITORIAL_PIPELINE_E2E_OK`, osobno dla `manual` i `api`.
Test sprawdza RSS, deduplikację, utworzenie i scoring tematu, research, szkic,
QA, miniaturę, edycję, planowanie, publikację, brak duplikatu oraz obecność
publikacji w HTML, sitemapie i RSS.

Kontrola składni wszystkich plików PHP w PowerShell:

```powershell
Get-ChildItem -Recurse -Filter *.php |
    ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
```

Testy smoke wymagają odpowiadających im flag `CMS_ALLOW_*` opisanych na początku
każdego pliku w `tests/`. Testy modyfikujące bazę używają
`CMS_SKIP_PUBLIC_SYNC=1` i sprzątają własne rekordy.

## 11. Koszty i limity API

Tryb `manual` i `OPENAI_API_MOCK=true` nie generują kosztów API. W prawdziwym
trybie `api` koszt zależy od aktualnego cennika wybranych modeli, liczby tokenów
wejścia/wyjścia, liczby wygenerowanych obrazów, jakości i rozmiaru.

Limity techniczne aplikacji:

- `OPENAI_MODEL` i `OPENAI_IMAGE_MODEL` wybierają modele;
- `OPENAI_TIMEOUT_SECONDS` ustawia timeout;
- `OPENAI_MAX_ATTEMPTS` ogranicza liczbę prób do zakresu 1–4;
- błąd `insufficient_quota` nie jest kosztownie ponawiany;
- użycie zwrócone przez API jest zapisywane przy operacji;
- `CMS_DAILY_PUBLICATION_LIMIT` ogranicza publikacje, ale nie wydatki API.

Aplikacja nie egzekwuje własnego limitu kwotowego. Twardy budżet, alerty użycia
i uprawnienia klucza należy ustawić w projekcie/organizacji u dostawcy API.
Klucz powinien należeć do osobnego projektu z minimalnymi uprawnieniami. Przed
włączeniem produkcji sprawdź aktualny cennik i budżet dostawcy — nie wpisuj
stałych cen do konfiguracji aplikacji.

## 12. Automatyczny wybór tematów i pełny batch

Bezpieczny podgląd nie zapisuje runu, rezerwacji ani batcha i nie wywołuje API:

```text
php php/full-auto-run.php --dry-run
```

Wynik JSON zawiera każdego kandydata oraz dokładny kod wyboru lub pominięcia.
Rzeczywisty run wymaga `FULL_AUTO_ENABLED=true`, trybu generowania `api` oraz
klucza dostawcy albo jawnego lokalnego mocka:

```text
php php/full-auto-run.php
```

Ten sam command działa w cron i Harmonogramie zadań Windows. Kod `0` oznacza
sukces, `1` błąd co najmniej jednego tematu, `2` błędne argumenty, a `4`
wyłączoną flagę. Jeden błąd nie zatrzymuje pozostałych tematów. Audyt jest
zapisany w `full_auto_runs` i `full_auto_reservations`; zawiera kandydatów,
powody, batch IDs i błędy, lecz nie sekrety.

Selektor wymaga kwalifikacji scoringu, progu, świeżości, dozwolonej kategorii
i ryzyka, minimalnej liczby niezależnych źródeł oraz opcjonalnie źródła
pierwotnego. Pomija odrzucone/przetworzone/zdublowane tematy, aktywne batche i
wcześniejsze rezerwacje. Limity runu i dnia są egzekwowane atomowo. Batch
wykonuje research, szkic, QC i obrazy, może skończyć w `waiting_review`, ale
nigdy nie publikuje.

Awaryjne wyłączenie: ustaw `FULL_AUTO_ENABLED=false` i zatrzymaj wpis schedulera.
Istniejące batche zachowują audyt i można je obsłużyć w panelu; wyłączenie nie
usuwa danych ani nie uruchamia publikacji.

## 13. Checklista produkcyjna

- [ ] prawdziwy `CMS_PUBLIC_URL`;
- [ ] dane wydawcy, kontakty, retencja i biogramy;
- [ ] HTTPS i aktualny magazyn CA;
- [ ] kopia bazy i mediów została odtworzona testowo;
- [ ] E2E manual i mock API przechodzą;
- [ ] wszystkie pliki PHP przechodzą lint;
- [ ] dry-run schedulera pokazuje oczekiwane materiały;
- [ ] sekretów nie ma w repozytorium ani logach;
- [ ] ustawiono budżet i alerty po stronie dostawcy API;
- [ ] Lighthouse mobile i dane terenowe potwierdzają LCP/INP/CLS po wdrożeniu.
