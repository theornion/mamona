# Current Work

## Current goal

Naprawić regresję w automatycznym doborze obrazów artykułów, a następnie bezpiecznie przepuścić wszystkie wykryte wadliwe artykuły przez poprawiony pipeline obrazów.

Problem został potwierdzony wizualnie po ostatniej aktualizacji:

1. Neutralny placeholder jest wyświetlany z podpisem opisującym konkretne zdjęcie, którego faktycznie nie ma.
2. Do artykułu o neuroplastyczności został dobrany satyryczny obraz polityka-zombie jedzącego mózg (`"Big Orange Zombie Eating Brains"` / DonkeyHotey).
3. W ostatnio wygenerowanych artykułach neutralna ilustracja pojawia się nieproporcjonalnie często, co sugeruje regresję w wyszukiwaniu, rankingu, pobieraniu, walidacji albo zapisie finalnego assetu.

Zadanie obejmuje zarówno naprawę przyczyny, jak i kontrolowaną naprawę już wadliwych artykułów.

## Important scope clarification

W tym zadaniu „ponowne generowanie” oznacza wyłącznie ponowne wykonanie pipeline'u obrazów:

- wyszukanie kandydatów;
- ocenę trafności;
- walidację praw;
- pobranie i przetworzenie finalnego pliku;
- zapis spójnych metadanych;
- odświeżenie renderowanego artykułu.

Nie regenerować treści artykułów, researchu, szkiców ani kontroli jakości.

Nie zmieniać:

- tytułu;
- treści;
- sluga i publicznego URL-a;
- autora;
- źródeł merytorycznych;
- SEO;
- dat publikacji;
- statusu redakcyjnego;
- historii wersji niezwiązanej z obrazami.

## Reported examples

### Example 1 — misleading fallback caption

Artykuł dotyczący pingwina Adélie pokazuje neutralną geometryczną ilustrację zastępczą, ale podpis brzmi:

> Ilustracja redakcyjna: Zbliżenie na pingwina Adélie w naturalnym środowisku.

Finalny plik nie przedstawia pingwina. Placeholder przejął caption albo alt przygotowany dla innego, niedostępnego lub odrzuconego assetu.

### Example 2 — irrelevant and inappropriate image

Artykuł o neuroplastyczności i adaptacji układu wzrokowego otrzymał grafikę:

> "Big Orange Zombie Eating Brains" by DonkeyHotey

Obraz jest polityczną, satyryczną i makabryczną karykaturą. Dopasowanie mogło nastąpić przez pojedynczy token `brain`, mimo całkowitej niezgodności z tematem i tonem materiału.

## Existing repository paths to inspect first

Przed zmianami prześledzić aktualny kod i istniejące narzędzia, w szczególności:

- `scripts/refill-article-images.php`
- `php/admin-database.php`
- funkcję `fulfill_article_source_images(...)`
- kod providerów i centralnej walidacji praw assetu
- kod tworzący neutralny SVG
- kod rankingu kandydatów
- zapis tabeli `article_images`
- renderer publicznego `<figure>`, captionu, altu, creditu i źródła
- batch stage `images`
- synchronizację opublikowanej strony po zmianie obrazu

Istniejący `scripts/refill-article-images.php` jest punktem odniesienia, ale nie zakładać, że wystarczy bez zmian. Obecnie obsługuje pojedynczy dokładny tytuł i odmawia modyfikacji opublikowanego artykułu.

## Required investigation

Prześledzić cały przepływ od danych artykułu do finalnego HTML:

1. Jak z tytułu, kategorii, researchu i encji powstają zapytania obrazów.
2. Jakie zapytania trafiają do każdego providera.
3. Jak kandydaci są normalizowani.
4. Jak walidowane są licencja, prawa i credit.
5. Jak liczona jest trafność tematyczna.
6. Czy pojedynczy wspólny token może przeważyć nad sprzecznymi metadanymi.
7. Jak wybierany jest fallback.
8. Kiedy caption, alt, creator, credit i source URL są przypisywane do rekordu.
9. Co dzieje się, gdy download lub processing finalnego pliku nie powiedzie się.
10. Jak renderer rozpoznaje, czy metadata należą do rzeczywiście wyświetlanego pliku.
11. Dlaczego udział fallbacków wzrósł po ostatniej aktualizacji.
12. Czy regresja dotyczy wszystkich providerów, wybranej kategorii, konkretnego etapu albo konkretnego okresu.

Najpierw ustalić i zapisać konkretną przyczynę. Nie maskować problemu samym podniesieniem progu ani listą kilku zakazanych fraz.

## Functional requirements

### 1. Semantic relevance gate

Kandydat musi pasować do głównego tematu artykułu, nie tylko do pojedynczego słowa.

Ocena powinna wykorzystywać dostępne dane:

- temat i tytuł;
- kategorię;
- encje główne;
- obiekt, gatunek, proces, instytucję lub zjawisko opisane w researchu;
- opis, tytuł, tagi i kategorię assetu;
- pozytywne oraz negatywne sygnały kontekstowe.

Legalność assetu nie oznacza automatycznie przydatności redakcyjnej.

### 2. Negative-context rejection

Odrzucać albo bardzo mocno obniżać ranking kandydatów, których metadata wskazują na kontekst sprzeczny z artykułem, między innymi:

- politykę lub konkretną osobę publiczną, gdy artykuł jej nie dotyczy;
- satyrę polityczną;
- zombie, gore, makabrę, przemoc lub obraz szokujący;
- mem, karykaturę albo parodię;
- sensacyjny kontekst nieobecny w artykule;
- reklamę albo branding niezwiązany z tematem.

Nie implementować wyłącznie jednorazowego filtra na nazwisko lub dokładny tytuł wadliwego obrazu. Przypadek DonkeyHotey ma być fixture'em regresyjnym, a nie całą logiką.

### 3. Minimum relevance threshold

Jeżeli żaden legalny kandydat nie osiąga minimalnej trafności, system ma użyć neutralnego fallbacku.

Lepszy poprawnie opisany placeholder niż mylący obraz.

Próg musi być:

- deterministyczny;
- testowalny;
- udokumentowany;
- oparty na istniejących sygnałach;
- wystarczająco łagodny, aby nie zamienić większości artykułów w fallback.

### 4. Correct fallback metadata

Neutralna ilustracja zastępcza musi otrzymać własne metadata.

Nie wolno kopiować z odrzuconego albo niedostępnego kandydata:

- captionu;
- altu;
- autora;
- credit line;
- source page;
- direct file URL;
- licencji;
- opisu konkretnego obiektu lub wydarzenia.

Dopuszczalny fallback:

- caption: `Neutralna ilustracja redakcyjna.`
- alt: krótki neutralny opis grafiki zastępczej;
- brak zewnętrznego creditu;
- jawny status/typ fallbacku w danych diagnostycznych.

### 5. Asset/metadata consistency

Przed zapisem i renderowaniem zagwarantować, że:

- finalny plik istnieje i jest czytelny;
- caption i alt należą do tego samego finalnego assetu;
- creator, credit i source URL należą do tego samego finalnego assetu;
- rekord fallbacku nie dziedziczy pól kandydata;
- nieudany download lub processing nie pozostawia częściowo zapisanych metadanych;
- podmiana obrazu i jego metadanych jest atomowa albo bezpiecznie odzyskiwalna.

### 6. Diagnostics

Diagnostyka ma pozwalać ustalić:

- utworzone zapytania;
- liczbę kandydatów per provider;
- wynik trafności każdego rozważanego kandydata;
- najważniejsze sygnały dodatnie i ujemne;
- powód odrzucenia;
- powód użycia fallbacku;
- identity/fingerprint finalnego assetu;
- czy finalny plik został pobrany i przetworzony;
- czy artykuł wymaga ponownego renderu.

Nie logować kluczy API, sekretów ani pełnych prywatnych promptów.

## Repair of already affected articles

Po wdrożeniu poprawionej logiki należy przepuścić wadliwe artykuły przez nowy pipeline obrazów.

### 1. Safe audit command

Dodać albo rozszerzyć narzędzie CLI tak, aby najpierw wykonywało dry-run i wykrywało podejrzane artykuły.

Preferowana forma:

```powershell
php scripts/refill-article-images.php --dry-run --all-detected
```

Dopuszczalne jest utworzenie osobnego, lepiej nazwanego skryptu, jeżeli zachowanie istniejącego narzędzia pozostałoby kompatybilne.

Dry-run ma wypisać dla każdego kandydata:

- post ID;
- tytuł;
- `editorial_status`;
- datę utworzenia/publikacji;
- aktualną rolę obrazu;
- aktualny plik;
- aktualny caption/alt/credit;
- kod wykrytej wady;
- planowaną akcję;
- informację, czy artykuł jest publiczny.

### 2. Detection criteria

Wykrywać co najmniej:

- placeholder z captionem/alt opisującym konkretny temat;
- placeholder z zewnętrznym creatorem, creditem lub source URL;
- brak finalnego pliku przy istniejących metadanych zdjęcia;
- metadata wskazujące inny asset niż finalny plik;
- znany fixture `"Big Orange Zombie Eating Brains"`;
- kandydat odrzucony przez nową walidację semantyczną;
- rekord niekompletny lub częściowo zapisany po nieudanym downloadzie;
- artykuł oznaczony ręcznie do ponownego doboru obrazu;
- opcjonalnie artykuły z okresu regresji ustalonego na podstawie git history i lokalnych dat.

Nie uznawać każdego poprawnego fallbacku za błąd. Fallback jest wadliwy tylko wtedy, gdy jego metadata są nieprawdziwe albo powinien zostać zastąpiony po ponownym wyszukaniu.

### 3. Explicit selection options

Narzędzie naprawcze powinno obsłużyć bezpieczne, jednoznaczne selektory, np.:

```text
--post-id=<id>
--title=<exact-title>
--since=YYYY-MM-DD
--all-detected
--include-published
--dry-run
--apply
```

Nie polegać wyłącznie na dokładnym tytule.

### 4. Backup before apply

Przed masową operacją `--apply`:

1. Zatrzymać workery zapisujące do bazy.
2. Utworzyć timestampowaną kopię:
   - `data/cms.sqlite`
   - istniejących rekordów/manifestów obrazów objętych naprawą;
   - podmienianych plików z `images/posts/`;
   - publicznych stron z `pages/`, jeśli będą odświeżane.
3. Zapisać raport repair runu i listę postów.
4. Przerwać operację, jeżeli backup się nie powiedzie.

Backupów roboczych nie dodawać do Git.

### 5. Apply behavior

Dla każdego wykrytego artykułu:

1. Zachować stary rekord i plik do audytu/rollbacku.
2. Uruchomić poprawiony pipeline obrazów.
3. Zapisać tylko kompletny, zwalidowany finalny wynik.
4. Gdy nie ma trafnego zdjęcia, zapisać poprawny neutralny fallback.
5. Nie zmieniać treści ani statusu artykułu.
6. Kontynuować po błędzie pojedynczego artykułu.
7. Raportować `repaired`, `fallback`, `skipped`, `failed`.
8. Zapewnić idempotencję — ponowne uruchomienie nie może tworzyć duplikatów ani pogarszać poprawnego obrazu.

### 6. Published articles

Wadliwe artykuły opublikowane również mają zostać naprawione, ale wyłącznie przez jawną ścieżkę `--include-published`.

Dla artykułu opublikowanego:

- zachować `editorial_status=published`;
- zachować slug, URL, daty i treść;
- nie tworzyć nowej publikacji;
- atomowo odświeżyć wyłącznie stronę artykułu i wymagane pochodne wykorzystujące ten obraz;
- nie wywoływać ponownie pełnego procesu research/draft/QC;
- nie publikować żadnego szkicu;
- nie dodawać artykułu ponownie do RSS;
- nie zmieniać kolejności ani dat wpisów w feedzie;
- w razie błędu zachować działającą poprzednią stronę.

### 7. Required execution after implementation

Po przejściu testów:

1. Uruchomić dry-run na lokalnej bazie.
2. Zapisać raport kandydatów.
3. Zweryfikować, że wykryto oba zgłoszone przypadki.
4. Wykonać backup.
5. Uruchomić naprawę wszystkich wykrytych wadliwych artykułów, w tym opublikowanych, z jawną flagą.
6. Ponownie wykonać dry-run.
7. Potwierdzić, że naprawione rekordy nie są już wykrywane.
8. Otworzyć lokalnie naprawione strony i wykonać kontrolę wizualną.
9. Zapisać listę artykułów, wyniki i ewentualne błędy w tej dokumentacji.

To jest jawnie zatwierdzona operacja naprawcza dotycząca obrazów. Nie jest zgodą na automatyczną publikację nowych artykułów ani na zmianę ich treści.

## Required regression tests

Dodać albo rozszerzyć deterministyczne testy obejmujące co najmniej:

### Relevance

1. Artykuł o pingwinie:
   - trafny obraz pingwina wygrywa z ogólnym obrazem natury;
   - poprawny fallback nie twierdzi, że przedstawia pingwina.

2. Artykuł o neuroplastyczności:
   - neutralna fotografia/ilustracja naukowa mózgu może zostać zaakceptowana;
   - satyryczny polityk-zombie zostaje odrzucony;
   - sam token `brain` nie wystarcza do zaakceptowania kandydata.

3. Legalny, ale niepasujący asset:
   - przechodzi walidację praw;
   - nie przechodzi walidacji redakcyjnej;
   - nie trafia do artykułu.

4. Legalny i pasujący asset:
   - zostaje wybrany;
   - caption, alt, creator, credit i source są zachowane.

### Fallback and consistency

5. Brak trafnego kandydata:
   - powstaje neutralny fallback;
   - nie dziedziczy captionu ani creditu od odrzuconego assetu.

6. Niedostępny finalny plik:
   - renderer nie pokazuje podpisu do nieistniejącego zdjęcia;
   - pipeline kończy z bezpiecznym fallbackiem albo prawidłowym stanem błędu.

7. Nieudany processing:
   - nie pozostawia hybrydowego rekordu z plikiem fallbacku i metadanymi zdjęcia.

### Repair workflow

8. Dry-run:
   - nie zmienia bazy ani plików;
   - wykrywa wadliwe fixture'y;
   - nie oznacza poprawnego fallbacku jako błędu.

9. Apply:
   - naprawia tylko wskazane rekordy;
   - zachowuje treść i status artykułu;
   - jest idempotentne;
   - kontynuuje po błędzie jednego artykułu.

10. Published repair:
   - wymaga jawnej flagi;
   - nie zmienia daty i statusu publikacji;
   - nie tworzy duplikatu w RSS/sitemap;
   - atomowo odświeża stronę.

## Relevant existing tests

Sprawdzić i odpowiednio rozszerzyć:

- `tests/article-image-pipeline-smoke.php`
- `tests/image-rights-providers-smoke.php`
- `tests/post-renderer-smoke.php`
- `tests/generate-all-regression.php`
- `tests/generation-batch-smoke.php`
- `tests/editorial-pipeline-e2e.php`

Uruchomić również inne testy znalezione podczas śledzenia faktycznie zmienionych modułów.

Nie usuwać istniejących testów i nie osłabiać walidacji licencyjnej.

## Validation commands

Najpierw przeczytać początek każdego testu i ustawić wyłącznie udokumentowane flagi `CMS_ALLOW_*`.

Sprawdzić składnię zmienionych plików PHP:

```powershell
git diff --name-only --diff-filter=ACM |
    Where-Object { $_ -like "*.php" } |
    ForEach-Object { C:\xampp\php\php.exe -l $_ }
```

Uruchomić co najmniej testy najbliższe zmienianemu kodowi oraz:

```powershell
C:\xampp\php\php.exe tests\generate-all-regression.php
```

Pełny E2E uruchomić po przejściu testów celowanych, zgodnie z `OPERATIONS.md`, bez równoległego panelu, schedulera ani workera.

## Acceptance criteria

Zadanie jest ukończone, gdy:

- [ ] Ustalono konkretną przyczynę regresji.
- [ ] Udział fallbacków nie rośnie przez błąd techniczny w pipeline.
- [ ] Kandydat nie jest wybierany wyłącznie na podstawie przypadkowego słowa.
- [ ] Obraz polityka-zombie nie może ilustrować artykułu o neuroplastyczności.
- [ ] Obrazy osób publicznych są odrzucane, gdy osoba nie jest rzeczywistym tematem artykułu.
- [ ] Brak trafnego obrazu prowadzi do prawidłowego neutralnego fallbacku.
- [ ] Fallback ma wyłącznie własny neutralny caption i alt.
- [ ] Caption, alt, creator, credit, source i plik zawsze opisują ten sam asset.
- [ ] Nieudany download/processing nie pozostawia częściowo zapisanych metadanych.
- [ ] Walidacja praw i licencji nie została osłabiona.
- [ ] Istnieje deterministyczny dry-run wykrywający wadliwe artykuły.
- [ ] Istnieje kontrolowana, idempotentna operacja naprawcza.
- [ ] Wszystkie wykryte wadliwe artykuły zostały ponownie przepuszczone przez poprawiony pipeline.
- [ ] Naprawiono także wadliwe artykuły opublikowane bez zmiany ich treści, statusu, sluga i dat.
- [ ] Po naprawie ponowny audyt nie wykrywa tych samych usterek.
- [ ] Nowe przypadki mają testy regresji.
- [ ] Istniejące testy pipeline'u nadal przechodzą.
- [ ] Diagnostyka pokazuje powody wyboru, odrzucenia i naprawy.
- [ ] Nie wykonano niezwiązanej refaktoryzacji.

## Non-goals

Nie należy:

- regenerować treści artykułów;
- ponawiać researchu, draftu ani QC;
- zmieniać statusów redakcyjnych;
- automatycznie publikować nowych materiałów;
- wymieniać całego systemu providerów bez udowodnionej potrzeby;
- dodawać płatnego API bez zgody;
- usuwać manifestów praw;
- obniżać wymagań licencyjnych;
- nadpisywać poprawnych ręcznie dodanych obrazów;
- wykonywać masowej operacji bez dry-runu i backupu;
- dodawać lokalnej bazy, backupów ani pobranych mediów do Git.

## Current state

- Repozytorium Mamona jest dostępne lokalnie i zsynchronizowane z GitHubem.
- PHP z XAMPP jest dostępne jako `C:\xampp\php\php.exe`.
- Roo Code jest zainstalowany.
- Konfiguracja agentów i Vast.ai została dodana do repozytorium.
- Problem potwierdzono wizualnie na co najmniej dwóch artykułach.
- Istnieje skrypt `scripts/refill-article-images.php`, ale jego aktualny zakres jest zbyt wąski dla wymaganej operacji naprawczej.
- Implementacja naprawy nie została rozpoczęta.

## First actions for the agent

1. Przeczytaj `AGENTS.md`.
2. Przeczytaj relevant sections of:
   - `docs/PROJECT_CONTEXT.md`
   - `OPERATIONS.md`
   - `GEMINI-IMAGES.md`
   - `IMAGE-CREDITS.md`
3. Sprawdź `git status` i ostatnie commity, zwłaszcza zmiany poprzedzające wzrost liczby fallbacków.
4. Znajdź implementację `fulfill_article_source_images(...)`.
5. Prześledź pełny zapis i renderowanie `article_images`.
6. Odtwórz oba zgłoszone przypadki jako deterministyczne fixture'y.
7. Przed większą zmianą przedstaw krótką diagnozę root cause.
8. Napraw przyczynę najmniejszą spójną zmianą.
9. Dodaj bezpieczny dry-run i workflow naprawczy.
10. Uruchom testy.
11. Wykonaj dry-run, backup i zatwierdzoną naprawę wykrytych artykułów.
12. Przeprowadź ponowny audyt i kontrolę wizualną.
13. Zaktualizuj ten plik przed zakończeniem sesji.

## Completed

- [x] Przygotowano pliki kontekstu dla agentów.
- [x] Dodano skrypty konfiguracji Vast.ai.
- [x] Zapisano konfigurację agentów w GitHubie.
- [x] Określono pierwsze zadanie programistyczne.
- [x] Zgłoszono dwa konkretne przypadki regresji obrazów.
- [x] Zatwierdzono naprawę już wadliwych artykułów po wdrożeniu poprawki.
- [ ] Ustalono root cause.
- [ ] Dodano testy regresji.
- [ ] Naprawiono pipeline.
- [ ] Dodano bezpieczny audyt i repair workflow.
- [ ] Wykonano dry-run i backup.
- [ ] Przepuszczono wadliwe artykuły przez poprawiony pipeline.
- [ ] Zweryfikowano strony po naprawie.

## Blockers and uncertainties

- Dokładny commit albo zakres dat regresji trzeba ustalić z lokalnej historii Git i dat rekordów.
- Nie wiadomo jeszcze, czy problem powstaje podczas rankingu, fallbacku, pobierania, zapisu czy renderowania.
- Nie wiadomo jeszcze, które artykuły mają obrazy dodane ręcznie; takich obrazów nie wolno nadpisywać automatycznie.

## Validation status

Nie rozpoczęto implementacji.

## Session handoff

Na końcu sesji zapisać:

- root cause;
- zmienione pliki;
- dodane testy;
- dokładne komendy i wyniki testów;
- raport dry-run;
- lokalizację backupu;
- listę naprawionych post ID i tytułów;
- status każdego rekordu: `repaired`, `fallback`, `skipped` albo `failed`;
- wyniki ponownego audytu;
- informacje o artykułach wymagających ręcznej decyzji;
- pojedynczą najlepszą następną akcję.
