# Audyt mobilny i Core Web Vitals — TASK-21

Data audytu: 24 lipca 2026 r.

## Zakres i metoda

Sprawdzono wygenerowaną stronę główną, artykuł, szablon publiczny, panel
administracyjny oraz zasoby CSS/JS. Powtarzalne pomiary wykonuje:

```powershell
C:\xampp\php\php.exe scripts/mobile-cwv-audit.php
C:\xampp\php\php.exe tests/mobile-performance-smoke.php
```

Izolowana przeglądarka audytowa nie mogła połączyć się z lokalnym serwerem
hosta. Dlatego poniższe wartości są pomiarami HTML i budżetów zasobów, a nie
wynikami Lighthouse. Nie należy przedstawiać ich jako zmierzonych LCP, INP lub
CLS. Te trzy wskaźniki trzeba potwierdzić po wdrożeniu na publicznym adresie,
na reprezentatywnym urządzeniu i następnie danymi terenowymi z CrUX/Search
Console.

## Wynik przed i po

| Kontrola | Przed | Po |
| --- | ---: | ---: |
| JavaScript strony głównej | 15 plików / 233 307 B | 14 plików / 159 284 B |
| JavaScript artykułu | 15 plików / 233 307 B | 13 plików / 152 332 B |
| Skrypty z `defer` — główna / artykuł | 0 / 0 | 14 / 13 |
| Niepotrzebny `snap.js` na głównej i w artykule | tak | nie |
| Font-face z `font-display` | 0 z 11 | 11 z 11 |
| Obrazy artykułu z wymiarami | 0 z 3 | 3 z 3 |
| Priorytet najważniejszego obrazu | nie | tak |
| Ponowne zastępowanie kanału SSR przez JS | tak | nie |
| Zoom użytkownika | zablokowany | dozwolony |
| Osobna osłona CSS dla 320–360 px | nie | tak |
| Minimalne cele dotykowe 44 px | brak reguły | publiczne i panel |
| Kompresja/cache w konfiguracji Apache | brak | dodane |

Redukcja JavaScriptu wynosi około 31,7% na stronie głównej i 34,7% w artykule.
Wzrost CSS o około 3 KB wynika z reguł celów dotykowych, focusu, czytelności i
osłony 320 px.

## Zmiany pod docelowe wskaźniki

- **LCP < 2,5 s:** usunięto wspólne ładowanie pakietu `snap.js`, odroczono
  skrypty, włączono kompresję i cache, a pierwszy rzeczywisty obraz otrzymuje
  `loading="eager"` i `fetchpriority="high"`.
- **INP < 200 ms:** kanał renderowany na serwerze nie jest ponownie pobierany
  i przebudowywany przez JavaScript; elementy dotykowe mają co najmniej 44 px,
  a standardowe kontrolki używają `touch-action: manipulation`.
- **CLS < 0,1:** obrazy mają `width`, `height` i rezerwowany `aspect-ratio`;
  kanał SSR pozostaje w DOM, a fonty używają `font-display: swap`.

Są to zabezpieczenia implementacyjne wspierające cele, nie gwarancja wyniku
terenowego. Po publikacji należy wykonać Lighthouse mobile dla strony głównej
i reprezentatywnego artykułu oraz monitorować 75. percentyl LCP/INP/CLS.

## Kontrola akceptacyjna

- Reguły obejmują szerokość 320 px i znoszą globalne `min-width: 320px`.
- Kontenery, tekst i karty mogą się zawijać bez wymuszania szerokości.
- Publiczne menu mobilne, jego zamknięcie i linki mają cele co najmniej 44 px.
- Pola oraz przyciski panelu mają na telefonie co najmniej 44 px i tekst 16 px.
- Focus klawiatury ma kontrastowy obrys 3 px.
- Zoom nie jest blokowany.
- `snap.js` pozostał dostępny tylko tam, gdzie obsługuje właściwe galerie.
