# TASK-23 — plan testów Full Auto

## Zakres i bezpieczniki

Harness jest niezależny od usług produkcyjnych. Testuje kontrakt: ingestion/temat → scoring/kwalifikacja → research → draft → QC → obrazy. Nie zawiera operacji publikacji, a każda asercja końcowa wymaga `published=false`. Testy lokalne nie używają sieci ani klucza API.

Live canary używa wyłącznie dwóch legalnych, lokalnie zapisanych fragmentów testowych. Jest domyślnie pomijany i wymaga jednocześnie flagi `--live` oraz `CMS_ALLOW_FULL_AUTO_GEMINI_CANARY=1`. Klucz jest pobierany tylko z `GEMINI_API_KEY`; skrypt nie wypisuje go ani pełnych odpowiedzi. Raport zawiera wyłącznie rozmiar, hash, nazwy pól, liczniki i kategorie błędów. Trafia do `logs/full-auto/`, który jest ignorowany przez Git.

## Windows / PowerShell

Testy bez API:

```powershell
php tests/full-auto-deterministic.php
php tests/full-auto-fault-injection.php
php tests/full-auto-statistics.php --trials=3
php tests/full-auto-salvage-matrix.php
php tests/full-auto-product-contract.php
$env:CMS_ALLOW_GENERATE_ALL_REGRESSION = '1'
php tests/generate-all-regression.php
Remove-Item Env:CMS_ALLOW_GENERATE_ALL_REGRESSION
php -l tests/full-auto-harness.php
php -l tests/full-auto-deterministic.php
php -l tests/full-auto-fault-injection.php
php -l tests/full-auto-statistics.php
php -l scripts/full-auto-gemini-canary.php
```

Jawny live canary (kosztuje quota):

```powershell
$env:GEMINI_API_KEY = '<klucz ustawiony tylko w procesie>'
$env:CMS_ALLOW_FULL_AUTO_GEMINI_CANARY = '1'
php scripts/full-auto-gemini-canary.php --live --trials=3 --deadline=180
Remove-Item Env:GEMINI_API_KEY
Remove-Item Env:CMS_ALLOW_FULL_AUTO_GEMINI_CANARY
```

Bez obu zgód skrypt kończy się komunikatem `SKIP` i kodem 2, bez wywołania sieciowego. `--trials` przyjmuje 1–5 (domyślnie 3), deadline 30–300 sekund. Canary wykonuje najwyżej trzy wywołania na próbę (research, draft, QC), czyli maksymalnie 15; nie ponawia automatycznie błędów 429/timeout. Koszt zależy od aktualnego modelu i cennika dostawcy, dlatego przed uruchomieniem należy sprawdzić limit projektu.

## Oczekiwane wyniki i raport

- Deterministyczny E2E: wszystkie sześć etapów w wersji 1, powtórzenie tego samego operation ID bez nowej wersji, restart bez zmiany stanu, brak publikacji.
- Fault injection: wykryte `source_id`, `unknown_id`, `exact-evidence`, `quality` i `transport`; schema jest pokrywana przez wspólny klasyfikator/statystyki. Po timeout/429, pustym szkicu, restarcie i dwóch błędach QC stan wraca do ostatniego bezpiecznego etapu, bez duplikatów.
- Statystyki: osobne liczniki i współczynniki `first_pass_success` oraz `success_after_repair` dla research/draft/QC, zawsze sześć kluczy kategorii: `schema`, `source_id`, `unknown_id`, `exact-evidence`, `quality`, `transport`.
- Live: raport `logs/full-auto/canary-*.json`; `published` musi pozostać `false`, `calls <= max_calls`, a każdy błąd ma jedną kategorię. `success_after_repair` oznacza sukces końcowy w ograniczonej próbie; canary nie wykonuje ukrytych retry, więc błąd obniża ten licznik i wymaga świadomego kolejnego uruchomienia.
- Obowiązkowa macierz `generate_all`: siedem stanów wejściowych wybiera dokładnie pierwszy nieukończony etap, zachowuje ID ukończonych wyników, nie zwiększa licznika operacji dla etapów wcześniejszych i nie wywołuje transportu ponownie. Bieżący znany wynik opisuje `GENERATE-ALL-REGRESSION.md`.
- Macierz salvage: błędy jakości nie kończą `generate_all` jako `waiting_review` ani `auto_rejected`. Pipeline kolejno wykorzystuje kandydatów tytułu, enrichment B, strukturę A-B-A-B-A, zweryfikowane C, image-search, neutralny lokalny fallback i deterministic safe composer. Wynikiem jest kompletny, niepubliczny `ready_with_notes`; tylko awaria infrastruktury przechodzi do `auto_retry_scheduled`.
- `full-auto-product-contract.php` jest oczekiwanym czerwonym kontraktem do czasu wdrożenia finalnej drabiny w usługach. Jego aktualną diagnozę zawiera `SALVAGE-CONTRACT-STATUS.md`.

## Kryterium gotowości

1. 100% testów deterministycznych przechodzi i PHP lint nie zgłasza błędów.
2. Świadomie uruchomiony live pipeline kończy research → draft → QC poprawnie w kontrolowanej paczce.
3. Żaden przebieg nie publikuje i nie tworzy operacji publikacji.
4. Każdy błąd w raporcie ma dozwoloną kategorię, a suma wywołań nie przekracza limitu.
5. Hard-block QC zatrzymuje przebieg; naprawa nigdy go nie obchodzi.
6. Macierz `generate_all` przechodzi wszystkie siedem przypadków, w tym nieaktywny pusty placeholder i podwójne kliknięcie.
7. Każda porażka jakości kończy się kompletnym preview package lub dalszym krokiem salvage; nigdy terminalnym odrzuceniem.
8. Raport preview zawiera decyzje, źródła B/C, claims usunięte jako niewspierane i pochodzenie obrazów.
9. 429/timeout daje `auto_retry_scheduled`, a reconcile wznawia stare `generate_all` zatrzymane na `waiting_review`.

Spełnienie tych warunków kwalifikuje TASK-23 jako bazę pomiarową. Nie jest zgodą na automatyczną publikację.
