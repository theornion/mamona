# Neutralna warstwa miejsc reklamowych

Warstwa przygotowuje layout Mamona do przyszłej integracji, ale nie zawiera
SDK, identyfikatora wydawcy, trackera ani zewnętrznego skryptu reklamowego.
Domyślna konfiguracja `CMS_ADS_ENABLED=false` nie renderuje slotów ani ich
odstępów.

## Mapa placementów

| Widok | Desktop / tablet | Mobile |
| --- | --- | --- |
| Strona główna i kategoria | `page-top` przed feedem; `feed-inline` po trzeciej z pięciu kart | Te same pozycje, formaty 320×100; bez sticky i overlay |
| Artykuł | `page-top` przed artykułem; do 1/2/3 `article-inline` przy ok. 2000/3000/4000 znaków; `post-article` po artykule | Te same naturalne granice; inline 300×250, pozostałe 320×100 |
| Sidebar | Niewdrożony — aktualny layout nie ma naturalnej kolumny bocznej | Ukryty |

Planner operuje na tablicy semantycznych bloków artykułu. Nie tnie surowego
HTML. Nie wybiera granicy `heading → paragraph`, nie wchodzi do `section`,
listy, cytatu, galerii ani figury, więc obraz pozostaje razem z podpisem
i atrybucją. Domyślna przerwa wynosi co najmniej dwa pełne bloki.

## Konfiguracja

- `CMS_ADS_ENABLED` — globalna flaga; `false` usuwa sloty i przestrzeń.
- `CMS_ADS_PREVIEW` — placeholdery wyłącznie poza `production`.
- `CMS_ADS_ALLOWED_PLACEMENTS` — allowlista placementów.
- `CMS_ADS_MAX_SLOTS_PER_PAGE` — limit wszystkich slotów (domyślnie 5).
- `CMS_ADS_MAX_INLINE_SLOTS` — limit śródtekstowy (maksymalnie 3).
- `CMS_ADS_MIN_BLOCK_GAP` — minimalny odstęp liczony pełnymi blokami.

Placeholder pokazuje nazwę placementu, format i warianty wymiarów. Aktywny
slot rezerwuje kontrolowaną przestrzeń przez `aspect-ratio` i `min-height`.
Sloty poniżej pierwszego ekranu mają `data-ad-lazy="true"`.

## Przyszła integracja dostawcy i CMP

1. Zaimplementować `AdProviderAdapter` poza neutralnym rendererem.
2. Podłączyć wynik CMP do `consent_state`; stan `unknown` nie aktywuje adaptera.
3. Rozróżnić zgodę na reklamy spersonalizowane i niespersonalizowane zgodnie
   z wymaganiami wybranego dostawcy.
4. Ładować SDK asynchronicznie i leniwie wyłącznie dla slotów, które mają się
   aktywować.
5. Uzupełnić politykę prywatności, listę partnerów, okresy przechowywania
   i mechanizm wycofania zgody.
6. Zweryfikować wymagania prawne i polityki platformy po wyborze dostawcy.

Sama warstwa layoutu nie stanowi deklaracji zgodności prawnej.
