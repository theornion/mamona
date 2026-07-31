# Audyt płynności scrollowania i renderingu Mamony

Data pomiaru: 2026-07-31. Środowisko: lokalny PHP 8.2 na Windows, Chromium w przeglądarce aplikacji, DPR 1.0. Test wykonywał liniowy automatyczny scroll od początku do końca aktywnego kontenera i z powrotem (2 × 1800 ms), po 500 ms stabilizacji. Te same cele i wymiary zastosowano przed i po zmianach.

## Diagnoza

Głównym kosztem publicznej strony nie była sama przezroczystość. `parallax.js` zmieniał `--bg-parallax-y` niemal w każdej klatce scrolla, poruszając stałą warstwę o wysokości 260 vh z `will-change: transform`. Na długim artykule 1366 px wariant bez paralaksy obniżył p95 czasu klatki z 49,9 ms do 33,6 ms i wyzerował mutacje stylu tła. Samo wyłączenie `backdrop-filter: blur(1px)` nie poprawiło wyniku w próbie izolacyjnej, więc blur pozostaje w pełnym wariancie i jest usuwany tylko w trybie lekkim.

Drugim problemem był model przewijania. `snap.js` (około 77 KB) przechwytuje wheel i touch, steruje dwoma kontenerami oraz uruchamia własne animacje rAF. W syntetycznym programatycznym scrollu jego wyłączenie nie zmieniło p95, ale mechanizm blokuje natywny scroll przy realnych gestach. Dlatego pełny wariant zachowuje go jako efekt, a reduced-motion, urządzenia dotykowe/słabsze i ręczny tryb wydajności używają natywnego dokumentu bez snap-hijackingu oraz niestandardowego scrollbara.

Panel admina ładował 9,30 MB `digital_rain.png`, zbędne publiczne tło NASA 1,64 MB i uruchamiał publiczną paralaksę mimo że jej warstwa była ukryta przez CSS. Dawało to 11,63 MB transferu badanego ekranu i 357 mutacji tła w teście. Publiczna paralaksa została usunięta z admina, a tło matrix przekodowane do WebP: 472 KB desktop i 188 KB mobile.

Obrazy długiego artykułu były pobierane jako pliki źródłowe 1024–1920 px. Pipeline tworzy teraz warianty 768/1280 WebP i emituje `srcset`/`sizes`; hero pozostaje eager/high, a obrazy dalsze są lazy/async.

## Wyniki porównawcze

`>33 ms` oznacza liczbę klatek wolniejszych niż około 30 FPS w całym przejeździe. Wyniki są pojedynczym lokalnym przebiegiem, a nie deklaracją stałych 60 FPS.

| Widok | Wariant | p95 klatki | >33 ms | >50 ms | CLS | Transfer |
|---|---:|---:|---:|---:|---:|---:|
| Home 375 | przed | 18,0 ms | 0 | 0 | 0,0179 | 1,32 MB |
| Home 375 | po, pełny | 16,8 ms | 1 | 0 | 0,0178 | 1,33 MB |
| Home 375 | po, lekki | 16,8 ms | 0 | 0 | 0,0179 | 1,33 MB |
| Home 1366 | przed | 18,2 ms | 6 | 1 | 0,0184 | 2,41 MB |
| Home 1366 | po, pełny | 16,9 ms | 0 | 0 | 0,0184 | 2,41 MB |
| Home 1366 | po, lekki | 16,9 ms | 0 | 0 | 0,0184 | 2,41 MB |
| Home 1920 | przed | 35,4 ms | 23 | 0 | 0,0065 | 2,41 MB |
| Home 1920 | po, pełny | 33,4 ms | 18 | 1 | 0,0065 | 2,41 MB |
| Home 1920 | po, lekki | 16,8 ms | 0 | 0 | 0,0065 | 2,41 MB |
| Artykuł 375 | przed | 18,1 ms | 0 | 0 | 0,0063 | 4,50 MB |
| Artykuł 375 | po, pełny | 16,8 ms | 0 | 0 | 0,0060 | 1,82 MB |
| Artykuł 375 | po, lekki | 16,8 ms | 0 | 0 | 0,0063 | 1,82 MB |
| Artykuł 1366 | przed | 36,0 ms | 57 | 6 | 0,0064 | 5,59 MB |
| Artykuł 1366 | po, pełny | 33,4 ms | 20 | 1 | 0,0018 | 3,75 MB |
| Artykuł 1366 | po, lekki | 16,8 ms | 0 | 0 | 0,0165 | 3,74 MB |

768 px również sprawdzono: home przed 18,1 ms p95 / 2 klatki >33 ms, po w trybie lekkim 16,8 ms / 0 klatek >33 ms. Artykuł po zmianie przesyła około 0,50 MB danych obrazów na 375 px i 1,34 MB na 1366 px zamiast około 3,19 MB.

Admin przed zmianą miał p95 33,2 ms, jedną długą taskę 66 ms, 357 mutacji tła i 11,63 MB transferu. Po zmianie pełny wariant ma 0 mutacji tła i 1,10 MB transferu, a lekki 0 mutacji i 0,82 MB. Lista tematów zmieniła się równolegle między przebiegami (zakres scrolla nie jest ten sam), dlatego nie traktujemy porównania klatek/LCP admina jako ścisłego A/B; redukcja zasobów i pracy paralaksy jest bezpośrednio porównywalna.

## Ograniczenia pomiaru

Udostępnione narzędzie przeglądarkowe nie wystawiało Chrome DevTools CPU throttling, GPU layer tree ani eksportu natywnego Performance trace. Nie oznaczamy trybu lekkiego jako „CPU throttled” i nie wyciągamy wniosków o czasie GPU/composite. JSON-y w `qa/performance` są śladami własnego, deterministycznego testu opartego na rAF i PerformanceObserver (frame intervals, long tasks, LCP, CLS, event duration, heap i zasoby). LCP z lokalnego serwera i ciepłego cache jest orientacyjne; INP wymaga realnej interakcji użytkownika i danych terenowych.

## Tryb wydajności

Przycisk `Ogranicz efekty` jest przyklejony w prawym dolnym rogu strony publicznej i admina. Wybór zapisuje się jako `mamona-effects-mode` w localStorage. Po włączeniu strona zachowuje treść i matrixowe tło, ale używa statycznej warstwy tła, natywnego scrolla, bez dużego blur, snapowania, niestandardowego scrollbara i dekoracyjnych animacji. Przycisk `Pełne efekty` przywraca pełny wariant. Bez zapisanego wyboru tryb lekki włącza się automatycznie dla reduced-motion, coarse/touch, do 4 logicznych rdzeni lub do 4 GB Device Memory; Device Memory jest tylko sygnałem pomocniczym.

## Artefakty QA

- Baseline: `qa/performance/before/metrics.json`, `admin-metrics.json`, `isolation-metrics.json`.
- Po zmianie, pełne efekty: `qa/performance/after-full/metrics.json`, `admin-metrics.json`.
- Po zmianie, tryb lekki: `qa/performance/after-reduced/metrics.json`, `admin-metrics.json`.
- Screenshoty przed/po znajdują się w odpowiadających katalogach `before`, `after-full` i `after-reduced`.
- Powtarzalny runner: `qa/performance/runner.php` i `qa/performance/scroll-probe.js` (tylko do lokalnego QA publicznych plików HTML; nie zawiera bypassu logowania admina).

