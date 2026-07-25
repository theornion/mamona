# Szablon CMS

Neutralna baza lekkiego CMS-a w PHP i SQLite. Treści, galerie, wiadomości,
dane kontaktowe, profile społecznościowe oraz wygląd strony są zarządzane
z panelu administratora w katalogu `php/`.

Baza danych tworzy się automatycznie w `data/cms.sqlite`. Czysta instalacja
nie zawiera przykładowych wpisów, galerii, wiadomości ani mediów.

## Konfiguracja

Wspólna konfiguracja aplikacji znajduje się w `php/app-config.php` i pobiera
wartości ze zmiennych środowiskowych. Lista obsługiwanych ustawień jest
dostępna w `.env.example`.

Plik `.env.example` jest wyłącznie dokumentacją — aplikacja nie wczytuje
automatycznie pliku `.env`. Zmienne należy ustawić w konfiguracji PHP, Apache,
hostingu albo procesu uruchamiającego zadania cykliczne.

W produkcji wymagane jest ustawienie:

```text
CMS_ENV=production
CMS_PUBLIC_URL=https://twoja-domena.pl
```

Bez `CMS_PUBLIC_URL` żądania wykonywane przez przeglądarkę mogą tymczasowo
wykryć adres z bieżącego hosta, ale procesy CLI nie będą mogły zbudować
absolutnych URL-i. Panel administratora pokazuje wtedy ostrzeżenie.

Sekretów, w tym `OPENAI_API_KEY`, danych pocztowych i poświadczeń
administratora, nie należy zapisywać w repozytorium.

## Migracje bazy

Starsze, proste aktualizacje schematu nadal korzystają z funkcji `ensure_*`.
Nowe migracje są wersjonowane w tabeli `schema_migrations` i uruchamiane
automatycznie podczas otwierania bazy. Każda migracja jest wykonywana w
transakcji i po sukcesie zapisuje swój unikalny identyfikator.

Pierwsza wersjonowana migracja dodaje model redakcyjny: statusy artykułów,
autorów, źródła, historię zmian i rejestr operacji generowania.

## Prywatność szkiców

Status redakcyjny jest źródłem prawdy dla publiczności artykułu. Tylko wpis ze
statusem `published`, który nie znajduje się w koszu, może posiadać publiczny
plik HTML i pojawić się w publicznym API. Pole `is_published` jest utrzymywane
wyłącznie jako zgodna kopia statusu dla starszego kodu.

Szkice można oglądać przez uwierzytelniony `admin-post-preview.php`. Podgląd
nie tworzy publicznego pliku, nie jest cache’owany i wysyła reguły `noindex`.

## Kontekst sprintu automatyzacji publikacji

Pełna specyfikacja i kryteria akceptacji znajdują się w
`NIEUSUWAĆ-TASKI.txt`. Ten rozdział jest skróconym handoffem dla kolejnej
instancji Codexa. Zadania należy wykonywać kolejno, zachowując prywatność
szkiców, status `published` jako jedyne źródło publiczności oraz atomowy zapis
generowanych plików.

### Stan wykonania

- **TASK-01 — audyt:** wykonany. Wyniki są w `AUDYT-TASK-01.md`.
- **TASK-02 — konfiguracja:** wykonany w `php/app-config.php`,
  `.env.example` i `.gitignore`. Produkcja wymaga prawdziwego `CMS_PUBLIC_URL`.
- **TASK-03 — model redakcyjny:** wykonany w `php/editorial-schema.php` oraz
  `php/editorial-repository.php`. Obejmuje statusy, autorów, źródła, historię
  statusów i rejestr generowania.
- **TASK-03A — prywatność publikacji:** wykonany. Szkice nie mają publicznego
  HTML-a, podgląd jest uwierzytelniony i `noindex`, a zapis jest atomowy.
- **TASK-04 — strony artykułów:** wykonany. Artykuły mają indywidualne
  metadata, canonical, Open Graph, autora, daty, źródła, alty, disclosure AI
  i materiały powiązane.
- **TASK-05 — dane strukturalne:** wykonany. Publiczne artykuły zawierają
  bezpiecznie kodowany JSON-LD `NewsArticle`, zgodny z widoczną treścią.
- **TASK-06 — odkrywanie:** wykonany w `php/discovery-service.php`.
  Sitemap, RSS i wpis w `robots.txt` powstają atomowo po synchronizacji.
- **TASK-07 — SSR strony głównej:** wykonany. Feed, paginacja i strony
  kategorii są obecne w HTML; JavaScript jest progresywnym ulepszeniem.

### Testy regresji

Testy mutujące bazę wymagają zmiennych `CMS_ALLOW_*` i sprzątają dane:

- `tests/editorial-schema-smoke.php`,
- `tests/publication-lifecycle-smoke.php`,
- `tests/post-renderer-smoke.php`,
- `tests/discovery-files-smoke.php`,
- `tests/server-rendered-feed-smoke.php`,
- `tests/editorial-queue-smoke.php`,
- `tests/editorial-editor-smoke.php`,
- `tests/scheduled-publication-smoke.php`,
- `tests/technical-sources-smoke.php`,
- `tests/feed-ingestion-smoke.php`,
- `tests/topic-grouping-smoke.php`,
- `tests/topic-scoring-smoke.php`,
- `tests/generation-service-smoke.php`,
- `tests/popular-science-profile-smoke.php`,
- `tests/research-package-smoke.php`,
- `tests/article-draft-smoke.php`,
- `tests/quality-check-smoke.php`,
- `tests/thumbnail-smoke.php`,
- `tests/trust-pages-smoke.php`,
- `tests/mobile-performance-smoke.php`,
- `tests/editorial-pipeline-e2e.php`.

W testach używane są `CMS_PUBLIC_URL=https://example.test` i
`CMS_SKIP_PUBLIC_SYNC=1`. Druga zmienna jest wyłącznie bezpiecznikiem testowym
zapobiegającym przebudowie prawdziwych plików na współdzielonej bazie. Nie
ustawiać jej w produkcji.

### Kolejne zadania

- **TASK-08 — kolejka redakcyjna:** wykonany w
  `php/admin-editorial-queue.php`. Widok obsługuje filtry statusów, metadane
  materiału, błędy automatyzacji, zmianę statusu, odrzucanie z przyczyną oraz
  historię. Mutacje wymagają logowania i CSRF, a logika przejść znajduje się
  w `change_post_editorial_status()`.
- **TASK-09 — edytor redakcyjny:** wykonany. Edytor obsługuje autora, daty,
  SEO, alt obrazu, źródła, komponenty AI, wynik jakości, ostrzeżenia oraz
  osobne akcje: szkic, review, planowanie, publikacja i odrzucenie. Zwykły
  zapis nie publikuje materiału. Walidacja i zapis metadanych znajdują się w
  `php/editorial-editor-service.php`.
- **TASK-10 — scheduler publikacji:** wykonany. Bezpieczny proces CLI publikuje
  należne materiały `scheduled`, respektuje limit dobowy, korzysta z blokady,
  kontynuuje po błędzie pojedynczego wpisu i zapisuje log.
- **TASK-11 — rejestr źródeł:** wykonany. Tabela `technical_sources`, panel
  `php/admin-technical-sources.php` i repozytorium
  `php/technical-source-repository.php` obsługują oficjalne kanały RSS/API,
  wiarygodność, źródła pierwotne, aktywację oraz stan ostatniej kontroli.
  Walidacja wymaga HTTPS i odrzuca URL-e zawierające dane logowania lub
  parametry wyglądające jak klucze i tokeny.
- **TASK-12 — pobieranie RSS/Atom:** wykonany. Moduł
  `php/feed-ingestion-service.php` pobiera aktywne kanały z limitami czasu i
  rozmiaru, blokuje adresy lokalne/prywatne, normalizuje krótkie metadane oraz
  zapisuje idempotentnie wpisy jako niepubliczne pomysły. Nie pobiera stron
  artykułów ani nie zapisuje pełnego pola `content` z kanałów Atom.
- **TASK-13 — grupowanie wydarzeń:** wykonany. Tabele tematów, członkostwa,
  sugestii i historii decyzji rozdzielają wpis źródłowy od pomysłu
  redakcyjnego. Deterministyczny algorytm uwzględnia znormalizowane tytuły,
  wspólne słowa, firmy/produkty, identyfikatory modeli oraz okno 72 godzin.
  Wysoka pewność może połączyć różne źródła automatycznie; niepewne wyniki
  trafiają do ręcznej decyzji w `php/admin-editorial-topics.php`. Każde
  połączenie można cofnąć bez utraty wpisu źródłowego.
- **TASK-14 — punktacja tematów:** wykonany. Deterministyczny scoring 0–100
  zapisuje wynik, ryzyko, kwalifikację do automatyzacji i pełne uzasadnienie
  każdej składowej. Uwzględnia świeżość, źródło pierwotne, niezależne
  potwierdzenia, preferowane kategorie, znaczenie dla polskiego odbiorcy,
  wartość własną, potencjał grafiki, podobieństwo do publikacji, ryzyko oraz
  sensacyjność. Panel „Tematy” jest sortowany od najwyższego wyniku.
- **TASK-15 — generowanie manualne i API:** wykonany. Wspólna warstwa
  `php/generation-service.php` zapisuje kompletny prompt, wejście, schemat
  odpowiedzi, sposób wykonania i zwalidowany wynik. Panel
  `php/admin-generation.php` pozwala centralnie przełączać `manual`/`api`,
  kopiować i eksportować prompt, importować odpowiedź ChatGPT albo uruchomić
  klienta OpenAI API. Klucz jest czytany wyłącznie ze środowiska, a lokalna
  atrapa umożliwia test bez kosztów.
- **TASK-15A — profil popularnonaukowy:** wykonany. Pięć źródeł
  deweloperskich pozostaje w rejestrze jako wyłączone, a 11 aktywnych kanałów
  NASA, JPL, ESA, USGS, CERN, MIT, Caltech, Quanta i Science News zasila osiem
  kategorii nowego profilu. Scoring premiuje mechanizm, odkrycie, znaczenie,
  narrację `problem_discovery_return` i potencjał grafiki, a obniża changelogi,
  marketing, komunikaty instytucjonalne i sensacyjne twierdzenia. Domyślny
  widok „Tematy” pokazuje wyłącznie aktywne materiały.
- **TASK-16 — paczka researchowa:** wykonany. Dla zgrupowanego tematu powstaje
  osobny, wersjonowany rekord researchu z ponumerowanymi źródłami, wspólnym
  schematem JSON dla trybu manualnego i API oraz dodatkową walidacją cytatów.
  System wymaga źródła dla każdego twierdzenia, sprawdza dosłowne fragmenty
  materiału, odróżnia sprzeczności od pewników i pozwala odrzucić temat przy
  niewystarczającym pokryciu. Wynik nie jest szkicem artykułu.
- **TASK-17 — generowanie szkicu:** wykonany według zmienionej specyfikacji.
  Szkic powstaje wyłącznie z jawnie zatwierdzonego researchu i jest zapisywany
  jako osobna, niepubliczna wersja. Tryb `informational` nie jest sztucznie
  rozciągany, a `problem_discovery_return` wymaga podstawy dla dygresji i
  zapisuje siedem etapów narracji. Manual i API używają tego samego kontraktu,
  a regeneracja nigdy nie nadpisuje wcześniejszej wersji.
- **TASK-18 — kontrola jakości:** wykonany. Każde sprawdzenie szkicu jest
  wersjonowane, ma wynik do 100 punktów, uzasadnienie, tryb wykonania i
  niezależny raport deterministyczny. Aplikacja blokuje planowanie oraz
  publikację najnowszego szkicu bez zaliczonej kontroli albo z aktywną twardą
  blokadą. Ręczna akceptacja dotyczy wyłącznie treści wysokiego ryzyka i zawsze
  wymaga zapisanego uzasadnienia; fałszywego cytatu, braku źródeł czy
  podobieństwa nie można w ten sposób ukryć.
- **TASK-19 — miniatury:** wykonany. Zaliczone szkice mogą otrzymać
  wersjonowaną miniaturę w trybie manualnym lub przez OpenAI Images API.
  Oryginał jest zachowywany, a publiczny wariant powstaje jako centralnie
  kadrowany WebP 1280×720. Każda wersja zapisuje prompt, alt, model, tryb,
  datę, identyfikator odpowiedzi i użycie API. Odrzucenie przywraca poprzednią
  zaakceptowaną wersję, a błąd generowania jej nie usuwa.
- **TASK-20 — zaufanie i transparentność:** wykonany. Generator tworzy strony
  „O serwisie”, „Autorzy”, „Polityka redakcyjna”, „Jak używamy AI”,
  „Korekty i aktualizacje”, „Kontakt” i „Polityka prywatności” oraz profile
  aktywnych autorów. Wszystkie są podlinkowane w stopce i trafiają do sitemapy.
  Artykuł prowadzi do lokalnego profilu autora i polityki redakcyjnej.
  Braki prawdziwej tożsamości wydawcy, danych kontaktowych, zasad retencji lub
  biogramów są widoczne w panelu; w `CMS_ENV=production` blokują zaplanowanie
  i publikację. Uzupełnij `CMS_PUBLISHER_LEGAL_NAME`,
  `CMS_EDITORIAL_CONTACT_EMAIL`, `CMS_PRIVACY_CONTACT_EMAIL`,
  `CMS_CONTACT_RETENTION_POLICY`, adres kontaktowy oraz prawdziwe opisy autorów.
  Politykę prywatności trzeba ponownie zweryfikować po wyborze hostingu,
  ochrony formularza, analityki lub reklam.
- **TASK-21 — mobile i Core Web Vitals:** wykonany. Widoki mają osłonę dla
  320 px, cele dotykowe 44 px, czytelny focus i dozwolony zoom. Obrazy
  rezerwują miejsce i nadają priorytet pierwszemu istotnemu obrazowi, fonty
  używają `font-display: swap`, a skrypty są odroczone. Kanał SSR nie jest
  ponownie przebudowywany w przeglądarce; ciężki `snap.js` pozostał wyłącznie
  na stronach galerii. Apache otrzymał kompresję oraz cache zasobów.
  Wyniki przed/po i ograniczenia pomiaru są w `AUDYT-TASK-21.md`; rzeczywiste
  LCP/INP/CLS trzeba potwierdzić po wdrożeniu na publicznym adresie.
- **TASK-22 — test E2E i dokumentacja operacyjna:** wykonany. Test
  `tests/editorial-pipeline-e2e.php` przechodzi pełny proces w trybie `manual`
  oraz `api` z lokalną atrapą, weryfikuje brak duplikatu publikacji i obecność
  artykułu w HTML, sitemapie oraz RSS, a następnie sprząta własne dane i
  przywraca pliki publiczne. Runbook konfiguracji, obsługi, odzyskiwania,
  wyłączania automatyzacji, testów oraz kosztów znajduje się w
  `OPERATIONS.md`.

Nie wdrażać automatycznej publikacji bez kontroli człowieka ani reklam przed
ukończeniem odpowiednich zadań i zebraniem danych z pierwszych 25–40
wartościowych publikacji.

### Uruchamianie schedulera

Podgląd bez zmian w bazie:

```text
php php/publish-scheduled.php --dry-run
```

Rzeczywista publikacja wymaga ustawienia
`CMS_AUTOMATIC_PUBLISHING=true` i prawidłowego `CMS_PUBLIC_URL`:

```text
php php/publish-scheduled.php
```

Skrypt jest dostępny wyłącznie z CLI. Można wywoływać go cyklicznie przez cron
lub Harmonogram zadań Windows. Blokada znajduje się w
`data/scheduled-publication.lock`, a log JSON Lines w
`data/scheduled-publication.log`. Kod wyjścia `0` oznacza sukces, `1` błąd
co najmniej jednego materiału, `2` błędne argumenty, a `3` aktywny równoległy
proces.

### Pobieranie kanałów RSS/Atom

Jednorazowe pobranie wszystkich aktywnych źródeł RSS:

```text
php php/fetch-feeds.php
```

Skrypt jest dostępny wyłącznie z CLI i zwraca podsumowanie JSON. Kod wyjścia
`1` oznacza błąd co najmniej jednego źródła; pozostałe źródła są mimo tego
przetwarzane. Proces zapisuje najwyżej 50 wpisów z kanału, przyjmuje odpowiedź
do 2 MB i kończy żądanie po 12 sekundach. Ponowne uruchomienie nie tworzy
duplikatów. Znaleziska trafiają do kolejki ze statusem `idea` i nie są
publikowane, dodawane do RSS ani sitemapy.

### Grupowanie tematów

Przeliczenie tematów można uruchomić w panelu „Tematy” albo z CLI:

```text
php php/group-topics.php
```

Algorytm automatycznie łączy wyłącznie dopasowania o pewności co najmniej 74%
i nie łączy wpisów tylko dlatego, że dotyczą tej samej firmy. Wyniki od 50%
do progu automatycznego są sugestiami administratora. Panel pozwala je
zaakceptować, odrzucić, ręcznie połączyć całe tematy oraz rozdzielić dowolny
wpis. Ręczna decyzja blokuje ponowne automatyczne sklejenie tematu.

### Punktacja tematów

Punktację można przeliczyć w panelu „Tematy” albo z CLI:

```text
php php/score-topics.php
```

Preferowane kategorie konfiguruje
`CMS_PREFERRED_TOPIC_CATEGORIES`. Temat wysokiego ryzyka lub bez źródła
pierwotnego otrzymuje `automatic_eligible=0`; scheduler pomija taki temat.
Brak źródła pierwotnego i wszystkie dodatnie oraz ujemne składowe są widoczne
w uzasadnieniu wyniku.

### Generowanie manualne i OpenAI API

Domyślny, bezkosztowy tryb `manual` nie wymaga klucza API. W panelu
„Generowanie” należy przygotować operację, skopiować prompt do ChatGPT Plus,
a otrzymany obiekt JSON wkleić albo zaimportować z pliku. ChatGPT Plus i OpenAI
API są osobnymi usługami — abonament Plus nie wykonuje automatycznych wywołań
API.

Tryb dla nowych operacji można zmienić centralnie w panelu. Istniejące
operacje zachowują swój zapisany tryb i dane. Dla automatyzacji należy ustawić:

```text
CMS_GENERATION_MODE=api
OPENAI_API_KEY=...
OPENAI_MODEL=gpt-5.6-terra
```

Klucza nie wolno wpisywać do `.env.example`, bazy ani logów. Limity czasu
i prób są konfigurowane przez `OPENAI_TIMEOUT_SECONDS` oraz
`OPENAI_MAX_ATTEMPTS`. `OPENAI_API_MOCK=true` uruchamia lokalną atrapę zgodną
z tym samym schematem odpowiedzi i nie wysyła żądania sieciowego.

### Paczki researchowe

W panelu „Generowanie” należy wybrać aktywny temat i użyć akcji „Przygotuj
research”. Operacja zawiera komplet ponumerowanych materiałów `S1`, `S2`, …
oraz ścisły schemat odpowiedzi. W trybie `manual` prompt można skopiować lub
wyeksportować do TXT/JSON, a odpowiedź z ChatGPT Plus wkleić albo zaimportować
z pliku. W trybie `api` ten sam kontrakt jest przekazywany automatycznie.

Po poprawnej walidacji wynik trafia zarówno do audytowalnego rejestru operacji,
jak i do osobnej tabeli `research_packages`. Import jest odrzucany, jeśli
zawiera nieznane źródło, pusty ważny fakt, cytat nieobecny w przekazanym
materiale, wspólny fakt oparty na mniej niż dwóch źródłach albo rekomendację
kontynuacji bez udokumentowanych twierdzeń. Niepoprawny import manualny
pozostaje w stanie `prepared`; niepoprawna odpowiedź API otrzymuje stan
`failed`.

### Wersjonowane szkice artykułów

Ukończona paczka z rekomendacją `continue` wymaga w panelu jawnej akcji
„Zatwierdź research do szkicu”. Dopiero wtedy pojawia się na liście podstaw
nowego szkicu. Administrator wybiera tryb `informational` albo
`problem_discovery_return`. Jeżeli research nie zawiera porównania ani co
najmniej dwóch udokumentowanych twierdzeń, żądanie narracyjne jest bezpiecznie
sprowadzane do `informational`.

Treść główna szkicu `informational` musi mieć 2000–4000 znaków. Wariant
`problem_discovery_return`, używany do tematów wymagających szerszego
wyjaśnienia i uzupełniającego tematu B, musi mieć 3000–4000 znaków. Dla tego
wariantu 3000 jest wyłącznie dolną granicą: gdy research zawiera dość
wartościowego materiału, tekst powinien naturalnie ją przekraczać. Do pomiaru
wchodzą pola treści artykułu, ale nie tytuł, SEO, kategoria, alt ani metadane.
Prompt i kontrola jakości zabraniają osiągania zakresu przez powtórzenia, lanie
wody lub sztuczne rozwlekanie.

Każda próba generowania tworzy rekord w `article_draft_versions` z numerem
wersji, trybem kompozycji i sposobem wykonania `manual`/`api`. Szkic zachowuje
identyfikatory twierdzeń i źródeł, wskazania niewiadomych oraz komplet pól
redakcyjnych i SEO. Panel pozwala zestawić zakończoną wersję z jej zatwierdzoną
paczką faktów. Ten proces nie zapisuje szkicu do treści posta, nie zmienia
statusu redakcyjnego i nigdy sam nie publikuje artykułu.

### Kontrola jakości i blokady publikacji

W panelu „Generowanie” można przygotować dowolną liczbę kontroli dla każdej
ukończonej wersji szkicu. Tryb `manual` eksportuje wspólny prompt zawierający
szkic, zatwierdzony research, źródła i ścisły schemat odpowiedzi. Tryb `api`
wykonuje ten sam kontrakt automatycznie. Wynik modelowy ocenia zgodność faktów,
kompletność, źródło pierwotne, wartość własną, oryginalność, tytuł, język,
SEO oraz obsługę ryzyka. Próg zaliczenia wynosi 75/100.

Niezależnie od odpowiedzi modelu aplikacja ponownie sprawdza źródła, podstawę
tytułu, nieudokumentowane cytaty i deklaracje własnych testów, długie wspólne
fragmenty ze źródłami lub opublikowanymi tekstami, clickbait, kompletność SEO
oraz treści wysokiego ryzyka. Dodatkowo niezależnie mierzy długość głównej
treści i blokuje wynik poza zakresem właściwym dla trybu. Najnowszy ukończony szkic musi mieć zaliczoną
najnowszą ukończoną kontrolę. W przeciwnym razie przejście do `scheduled` lub
`published` kończy się błędem również w schedulerze.

Ryzyko prawne, finansowe, medyczne lub bezpieczeństwa może zostać
zaakceptowane przez człowieka wyłącznie z uzasadnieniem długości 10–1000
znaków. Ta decyzja nie usuwa pozostałych blokad. Każde ponowienie kontroli
tworzy kolejny rekord w `quality_check_runs`; wcześniejsze wyniki pozostają
dostępne do audytu.

### Miniatury manualne i OpenAI Images API

Panel „Generowanie” pokazuje do wyboru wyłącznie szkice, których najnowsza
kontrola jakości jest zaliczona i nie zawiera aktywnej blokady. Prompt obrazu
powstaje deterministycznie z tytułu i zatwierdzonego researchu. Wymusza
kompozycję 16:9 z ważnym elementem w bezpiecznym centrum oraz zakazuje tekstu,
znaków wodnych, mylących logotypów, fałszywych interfejsów, zbędnych wizerunków
prawdziwych osób i wizualizacji niepotwierdzonego produktu.

W trybie `manual` administrator kopiuje prompt do generatora w ChatGPT Plus,
pobiera wynik i wgrywa JPEG, PNG lub WebP do 25 MB. W trybie `api` aplikacja
wywołuje `/v1/images/generations`, domyślnie z modelem `gpt-image-2`, rozmiarem
`2048x1152`, jakością `medium` i odpowiedzią WebP. Model można zmienić przez:

```text
OPENAI_IMAGE_MODEL=gpt-image-2
```

Oba tryby przechodzą przez ten sam lokalny procesor: weryfikację typu i
minimalnego rozmiaru wejścia, centralne kadrowanie, skalowanie do 1280×720,
usunięcie metadanych oraz zapis WebP. Preferowane jest PHP GD z WebP. Jeżeli
serwer go nie ma, należy wskazać Python z biblioteką Pillow:

```text
CMS_IMAGE_PROCESSOR_PYTHON=/pełna/ścieżka/do/python
```

Oryginały trafiają do `data/thumbnails/originals`, a warianty publiczne do
`images/posts/thumbnails`. Nowa wersja nie usuwa starszych plików. Po
odrzuceniu system przywraca poprzednią aktywną miniaturę i zapisuje przyczynę.
Alt jest pobierany ze zwalidowanego szkicu i nie może zaczynać się od formuły
„obraz przedstawia”.

### Profil popularnonaukowy

Kanoniczne kategorie profilu są zapisane w `editorial_profile_categories`:
`new-technologies`, `how-it-works`, `space`, `earth-nature`,
`energy-climate`, `robotics-transport`, `materials-inventions` oraz
`human-technology`. Kategoria źródła ma pierwszeństwo przed niespójnymi
etykietami pochodzącymi z RSS.

Panel „Tematy” zawiera podgląd i audytowalną operację odrzucenia
nieprzetworzonych pomysłów starego profilu. W trakcie TASK-15A użytkownik
wydał osobne, jawne polecenie trwałego usunięcia wcześniejszych 110 rekordów.
Przed usunięciem zapisano odzyskiwalny eksport:
`data/archives/developer-feeds-before-purge-20260724-184303.json`.
Źródła deweloperskie nie zostały usunięte i można je ponownie włączyć.

Klient RSS korzysta z natywnego magazynu zaufanych certyfikatów Windows,
jeżeli obsługuje go cURL. Na innym serwerze można wskazać aktualny pakiet CA
przez `CMS_FEED_CA_BUNDLE`.

### Rewizja jakości po TASK-07

Przed TASK-08 wykonano ponowną kontrolę wcześniejszych zmian. Poprawiono
sortowanie publicznego feedu według `published_at`, dodano manifest
`data/generated-news-pages.json` do bezpiecznego usuwania nieaktualnych stron
paginacji/kategorii oraz włączono `PRAGMA foreign_keys = ON` dla SQLite.
Usunięto 84 osierocone, wyłącznie testowe rekordy historii statusów. Testy
mutujące bazę muszą nadal używać `CMS_SKIP_PUBLIC_SYNC=1`, aby nie przepisywać
istniejących publicznych plików domeną `example.test`.
