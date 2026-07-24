# TASK-01 — audyt repozytorium i plan zmian

Data audytu: 2026-07-24  
Zakres: `C:\Projekty\mamona`  
Charakter zadania: analiza bez zmian funkcjonalnych

## 1. Podsumowanie

Projekt jest lekkim CMS-em napisanym proceduralnie w PHP, korzystającym z
SQLite oraz częściowo statycznie generowanych stron HTML. Fundament nadaje się
do rozbudowy, ale przed dodaniem kolejki redakcyjnej i automatyzacji trzeba
rozdzielić trzy obecnie silnie sprzężone odpowiedzialności:

1. zapis danych artykułu,
2. decyzję, czy artykuł jest publiczny,
3. generowanie oraz synchronizację plików publicznych.

Najważniejsza wykryta blokada: nieopublikowany wpis nadal otrzymuje publiczny
plik HTML. `update_post()` zawsze wywołuje `write_post_page()`, a
`sync_public_navigation()` regeneruje strony wszystkich nieusuniętych wpisów,
również tych z `is_published = 0`. Taki wpis nie pojawia się w publicznym API,
ale jego bezpośredni adres może istnieć i być dostępny.

Drugim istotnym ograniczeniem jest skupienie schematu, migracji, repozytoriów,
renderowania stron, obsługi kosza i synchronizacji nawigacji w jednym pliku
`php/admin-database.php`. Kolejne funkcje należy dodawać modułowo, bez
jednorazowego przepisywania całego CMS-a.

## 2. Obecna architektura

### 2.1. Warstwa danych

Główny plik:

- `php/admin-database.php`

Baza:

- `data/cms.sqlite`
- połączenie PDO jest tworzone leniwie przez `bueno_database()`;
- baza i brakujące tabele są tworzone przy pierwszym użyciu;
- PDO korzysta z wyjątków oraz domyślnego zwracania tablic asocjacyjnych.

Najważniejsze istniejące tabele:

- `posts`,
- `post_categories`,
- `galleries`,
- `gallery_items`,
- `messages`,
- `cats`,
- `contact_settings`,
- `social_media`,
- `site_style_settings`,
- `cms_meta`.

Tabela `posts` posiada obecnie między innymi:

- `category_id`,
- `title`,
- `excerpt`,
- `content`,
- `image_path`,
- `slug`,
- `is_published`,
- `created_at`,
- `updated_at`,
- `deleted_at`,
- później dodawane pola obrazów, galerii i kadrowania.

Relacje `FOREIGN KEY` są deklarowane w schemacie, ale podczas audytu nie
znaleziono jawnego `PRAGMA foreign_keys = ON`. Nie należy zakładać, że SQLite
egzekwuje wszystkie zadeklarowane relacje w obecnym środowisku.

### 2.2. Sposób migracji

Projekt nie posiada numerowanych migracji. Aktualizacje wykonywane są przy
otwarciu bazy przez funkcje `ensure_*`, które:

1. odczytują `PRAGMA table_info(...)`,
2. sprawdzają obecność kolumny,
3. wykonują `ALTER TABLE ... ADD COLUMN`, jeśli jej brakuje,
4. czasami uzupełniają starsze dane.

Przykładem jest `ensure_post_extra_columns()`, która dodaje pola obrazów i
migruje starszy pojedynczy obraz treści do kolekcji JSON.

Ten wzorzec jest wystarczający dla prostych zmian addytywnych, ale będzie
trudny do utrzymania przy większej liczbie tabel redakcyjnych.

### 2.3. Cykl życia artykułu

Panel edycji:

- `php/admin-posts.php` — lista kategorii i wejście do sekcji postów;
- `php/admin-post-category-editor.php` — posty w kategorii;
- `php/admin-post-editor.php` — tworzenie, edycja, obrazy i publikacja;
- `php/admin-trash.php` — kosz;
- `php/admin-trash-preview.php` — podgląd elementu w koszu.

Główne funkcje domenowe w `php/admin-database.php`:

- `list_posts()`,
- `find_post()`,
- `create_post()`,
- `update_post()`,
- `delete_post()`,
- `restore_post()`,
- `permanently_delete_post()`,
- odpowiedniki dla kategorii.

Obecny przebieg utworzenia wpisu:

1. formularz przechodzi walidację i kontrolę CSRF;
2. obrazy są zapisywane do `images/posts/`;
3. `create_post()` tworzy rekord z domyślnym `is_published = 1`;
4. `create_post()` od razu zapisuje stronę HTML i synchronizuje strony;
5. formularz wywołuje `update_post()` z właściwą wartością checkboxa publikacji;
6. `update_post()` ponownie zapisuje stronę i synchronizuje strony.

Konsekwencje:

- nowy wpis jest zapisywany i renderowany dwukrotnie;
- wpis, który miał być szkicem, jest chwilowo tworzony jako opublikowany;
- po zmianie na szkic jego statyczny plik nadal istnieje;
- `created_at` pełni jednocześnie rolę daty utworzenia i publicznej daty wpisu.

### 2.4. Generowanie statycznych stron

Generator:

- `write_post_page()` w `php/admin-database.php`.

Szablon źródłowy:

- `pages/index.html`.

Wynik:

- `pages/post-{slug}.html`.

Generator:

- wczytuje `pages/index.html`,
- podmienia klasę elementu `body`,
- koduje tytuł, skrót i treść,
- zamienia znaczniki `[[Z1]]`, `[[Z2]]` itd. na obrazy,
- opcjonalnie dodaje podpiętą galerię,
- zamienia sekcję feedu na element `<article>`,
- zapisuje wynik przez `file_put_contents(..., LOCK_EX)`.

`sync_public_navigation()` regeneruje:

- przegląd galerii,
- wszystkie strony artykułów,
- wszystkie strony galerii,
- nawigację we wszystkich wygenerowanych stronach,
- główny `index.html` w katalogu projektu.

Obecny mechanizm jest prosty, ale koszt synchronizacji rośnie liniowo z liczbą
artykułów. Zmiana pojedynczej kategorii lub ustawienia może ponownie zapisać
wszystkie strony.

### 2.5. Strona główna

Pliki:

- `index.html`,
- `pages/index.html`,
- `assets/js/news-feed.js`,
- `php/posts.php`.

Przebieg:

1. HTML zawiera pustą sekcję z atrybutem `data-news-source`;
2. `news-feed.js` odpytuje `php/posts.php`;
3. endpoint pobiera opublikowane wpisy przez `list_posts(..., true)`;
4. JavaScript buduje karty oraz paginację po stronie przeglądarki.

Skutek: bez JavaScriptu strona główna nie zawiera listy artykułów. Paginacja
również odbywa się po pobraniu całej listy wpisów, ponieważ endpoint nie
obsługuje jeszcze limitu ani przesunięcia.

### 2.6. Obrazy artykułów

Obsługa serwerowa znajduje się głównie w:

- `php/admin-post-editor.php`,
- `php/admin-database.php`.

Obsługa interfejsu:

- `assets/js/admin-post-editor.js`.

Aktualne zasady:

- formaty JPG, PNG i WebP;
- limit 8 MB;
- kontrola typu przez `getimagesize()`;
- losowe nazwy plików;
- zapis do `images/posts/`;
- jeden obraz główny i wiele obrazów treści;
- kadrowanie przechowywane jako JSON;
- obrazy treści umieszczane za pomocą znaczników `[[Z<n>]]`.

Ograniczenia:

- brak pola `alt` dla obrazu głównego i obrazów treści;
- renderowane obrazy otrzymują pusty `alt`;
- brak generowania wariantów fizycznych, np. 1280×720 WebP;
- kadrowanie jest realizowane przez CSS, nie przez utworzenie osobnego pliku;
- brak kontroli minimalnej rozdzielczości dla Discover;
- logika uploadu jest umieszczona bezpośrednio w kontrolerze formularza.

### 2.7. Autoryzacja panelu

Plik:

- `php/admin-auth.php`.

Panel korzysta z:

- sesji zapisanych w `data/sessions/`,
- osobnego pliku poświadczeń,
- cookie `HttpOnly` i `SameSite=Lax`,
- tokenów CSRF,
- `require_admin_login()` na stronach administracyjnych.

Nowe ekrany i operacje mutujące powinny użyć istniejących mechanizmów, zamiast
tworzyć osobny system logowania.

### 2.8. Zależność publicznych endpointów od pliku administracyjnego

Publiczne endpointy, między innymi:

- `php/posts.php`,
- `php/galleries.php`,
- `php/gallery-items.php`,
- `php/site-theme.php`,
- `php/contact-settings.php`,

ładują `php/admin-database.php`. Nazwa pliku sugeruje zależność wyłącznie
administracyjną, choć w praktyce jest to wspólny bootstrap całej aplikacji.
Przenoszenie funkcji trzeba wykonać stopniowo i pozostawić warstwę
kompatybilności, aby nie zerwać publicznych endpointów.

## 3. Mapa zależności

### Publikacja artykułu

`admin-post-editor.php`
→ `admin-auth.php`
→ `admin-database.php`
→ zapis obrazów
→ `create_post()` / `update_post()`
→ `write_post_page()`
→ `sync_public_navigation()`
→ `pages/*.html`
→ `index.html`

### Wyświetlenie strony głównej

`index.html`
→ `assets/js/news-feed.js`
→ `php/posts.php`
→ `admin-database.php`
→ `list_posts(..., publishedOnly: true)`
→ `data/cms.sqlite`

### Wyświetlenie strony artykułu

`pages/post-{slug}.html`
→ statyczny HTML wygenerowany z `pages/index.html`
→ wspólne CSS i JavaScript
→ opcjonalnie `php/gallery-items.php` dla podpiętej galerii

### Zmiana kategorii lub nawigacji

funkcja administracyjna
→ `sync_public_navigation()`
→ ponowne generowanie wszystkich artykułów i galerii
→ podmiana nawigacji
→ odtworzenie głównego `index.html`

## 4. Ryzyka przed rozpoczęciem kolejnych tasków

### Krytyczne

1. Szkice posiadają publiczne pliki HTML.
2. Nowy wpis jest początkowo tworzony jako opublikowany.
3. Generator nie dodaje `noindex` ani nie usuwa pliku po wycofaniu publikacji.

### Wysokie

1. Brak rozdzielenia daty utworzenia od daty pierwszej publikacji.
2. `sync_public_navigation()` regeneruje wszystkie nieusunięte posty.
3. `pages/index.html` jest jednocześnie stroną publiczną i szablonem generatora.
4. Brak jednego miejsca określającego bazowy publiczny URL.
5. Brak metadanych per artykuł, canonicala, Open Graph i JSON-LD.
6. Brak jawnie włączonego egzekwowania kluczy obcych SQLite.

### Średnie

1. Publiczne API zwraca pełną treść wszystkich wpisów naraz.
2. Paginacja jest wyłącznie kliencka.
3. Obrazy nie mają opisów alternatywnych.
4. Upload, walidacja formularza i logika publikacji są w jednym kontrolerze.
5. Operacje plikowe używają blokady, ale nie wszędzie stosują zapis przez plik
   tymczasowy i atomową podmianę.
6. Brak automatycznych testów migracji i publikacji.

## 5. Bezpieczny sposób migracji istniejącej bazy

### Zalecenie

Nie przebudowywać od razu tabeli `posts`. Następny task powinien wykonać
migrację addytywną:

1. przed zmianami utworzyć kopię `data/cms.sqlite`;
2. dodać numerowanie migracji przez `PRAGMA user_version` albo tabelę
   `schema_migrations`;
3. dodać nowe kolumny przez osobną funkcję migracyjną;
4. utworzyć nowe tabele relacyjne przez `CREATE TABLE IF NOT EXISTS`;
5. utworzyć indeksy przez `CREATE INDEX IF NOT EXISTS`;
6. wykonać backfill w transakcji;
7. dopiero po poprawnym backfillu podnieść numer wersji schematu;
8. zachować stare pola `is_published`, `created_at` i `updated_at` w okresie
   przejściowym;
9. po migracji zweryfikować liczbę rekordów i integralność odwołań;
10. nie usuwać starych kolumn w Sprincie 1.

### Proponowany backfill statusów

- `deleted_at IS NOT NULL` → wpis pozostaje w obecnym koszu;
- `deleted_at IS NULL AND is_published = 1` → `status = published`;
- `deleted_at IS NULL AND is_published = 0` → `status = draft`;
- dla istniejących publikacji `published_at = created_at`;
- `content_updated_at = updated_at`;
- `scheduled_at = NULL`;
- brakujące dane autora i SEO pozostają puste i są oznaczane w panelu.

### Nowe tabele zamiast kolejnych pól JSON

Rekomendowane oddzielne tabele:

- `authors`,
- `post_sources`,
- `post_status_history`,
- `post_generation_runs`,
- później `source_feeds`, `source_items` i `story_clusters`.

Źródeł, historii statusu i wykonań automatyzacji nie należy zapisywać w jednej
kolumnie JSON, ponieważ będą filtrowane, audytowane i deduplikowane.

## 6. Proponowany podział nowych modułów

Podział należy wprowadzać stopniowo. `admin-database.php` może tymczasowo
ładować nowe pliki, aby zachować istniejące wywołania.

### Konfiguracja i bootstrap

- `php/app-config.php`  
  Publiczny URL, język, strefa, wydawca i ustawienia środowiska.

- `php/database.php`  
  Połączenie PDO, ustawienia SQLite i uruchamianie migracji.

- `php/migrations.php`  
  Numerowane, idempotentne migracje i backfill.

### Domena artykułów

- `php/posts-repository.php`  
  Zapytania SQL dotyczące artykułów i kategorii.

- `php/editorial-repository.php`  
  Autorzy, źródła, statusy, historia i wykonania automatyzacji.

- `php/publication-service.php`  
  Jedno miejsce zmiany statusu, publikacji, wycofania i planowania.

### Renderowanie

- `php/post-renderer.php`  
  Budowa HTML artykułu i metadanych.

- `php/publication-files.php`  
  Atomowy zapis lub usuwanie publicznego pliku artykułu.

- `php/navigation-renderer.php`  
  Generowanie nawigacji bez mieszania jej z repozytorium danych.

- `php/sitemap-generator.php` i `php/rss-generator.php`  
  Dodane w późniejszym tasku.

### Media

- `php/post-media.php`  
  Walidacja uploadu, bezpieczne ścieżki, warianty obrazów, alt i usuwanie.

### Kontrolery

Istniejące pliki `admin-*.php` powinny pozostać cienkimi kontrolerami:

1. autoryzacja,
2. odczyt danych wejściowych,
3. walidacja,
4. wywołanie serwisu,
5. przekierowanie albo renderowanie formularza.

## 7. Pliki wymagające zmian w kolejnych taskach

### Bezpośrednio w TASK-02 i TASK-03

- `php/admin-database.php`
- nowy `php/app-config.php`
- nowy moduł migracji
- `.gitignore`
- przykładowy plik konfiguracji środowiskowej

### Publikacja, SEO i dane strukturalne

- `php/admin-database.php` lub wydzielony `php/post-renderer.php`
- `pages/index.html`
- `index.html` jako wynik generatora
- `php/admin-post-editor.php`
- `php/posts.php`

### Kolejka redakcyjna i statusy

- `php/admin-posts.php`
- `php/admin-post-category-editor.php`
- `php/admin-post-editor.php`
- `php/admin-ui.php`
- `php/admin-nav.php`
- `assets/css/public-theme.css`
- `assets/js/admin-post-editor.js`

### Strona główna

- `index.html`
- `pages/index.html`
- `assets/js/news-feed.js`
- `php/posts.php`
- prawdopodobnie nowy publiczny kontroler PHP albo generator HTML listy.

### Obrazy

- `php/admin-post-editor.php`
- `php/admin-database.php` lub nowy `php/post-media.php`
- `assets/js/admin-post-editor.js`
- `assets/css/public-theme.css`

### Sitemap i RSS

- `robots.txt`
- `sitemap.xml`
- nowy generator sitemapy
- nowy generator RSS
- serwis publikacji jako punkt wywołania generatorów.

## 8. Zalecana kolejność implementacji po TASK-01

1. TASK-02 — centralna konfiguracja i absolutne URL-e.
2. TASK-03 — wersjonowane migracje, statusy i dane redakcyjne.
3. Przed TASK-04 poprawić cykl publikacji:
   - `create_post()` nie może publikować domyślnie,
   - szkic nie może mieć publicznego pliku,
   - wycofanie publikacji musi usuwać publiczny plik,
   - generator musi pobierać tylko status `published`.
4. TASK-04 i TASK-05 — renderer artykułu, SEO i JSON-LD.
5. TASK-06 — sitemap i RSS uruchamiane z serwisu publikacji.
6. TASK-07 — HTML strony głównej renderowany po stronie serwera.
7. Dopiero potem kolejka, scheduler, RSS-y źródłowe i OpenAI.

## 9. Decyzje architektoniczne dla kolejnych tasków

1. `status` powinien być źródłem prawdy. `is_published` należy zachować
   przejściowo dla kompatybilności, ale nie rozbudowywać wokół niego nowej
   automatyzacji.
2. Publikacja powinna odbywać się przez jeden serwis, nie przez bezpośrednie
   kombinowanie `create_post()` i `update_post()` w formularzu.
3. Wygenerowany HTML powinien powstawać tylko dla `published`.
4. Szablon artykułu powinien zostać oddzielony od publicznej strony listy.
5. Zapisy publicznych plików powinny używać pliku tymczasowego i atomowej
   podmiany.
6. Nowe moduły powinny zachować proceduralny styl projektu i `strict_types`,
   bez wprowadzania frameworka w trakcie Sprintu 1.

## 10. Weryfikacja kryteriów TASK-01

- [x] Zidentyfikowano sposób tworzenia i aktualizowania tabel.
- [x] Zidentyfikowano funkcje tworzenia, aktualizacji i publikacji artykułów.
- [x] Zidentyfikowano sposób generowania statycznych stron.
- [x] Zidentyfikowano sposób wyświetlania strony głównej.
- [x] Zidentyfikowano obsługę obrazów.
- [x] Przygotowano mapę zależności.
- [x] Przygotowano listę plików wymagających zmian.
- [x] Określono bezpieczny sposób migracji istniejącej bazy.
- [x] Zaproponowano podział nowych modułów.
- [x] Nie zmieniono zachowania aplikacji.
