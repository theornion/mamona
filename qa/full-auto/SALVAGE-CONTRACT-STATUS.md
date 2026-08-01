# Ostateczny kontrakt salvage — status implementacji

## Zielone kontrakty TASK-23

`tests/full-auto-salvage-matrix.php` wykonawczo pokrywa pełną drabinę: pięć tytułów i bezpieczny wybór; enrichment B przed użyciem i strukturę A-B-A-B-A; zweryfikowane C oraz image-search; neutralny lokalny fallback; dwa nieudane repair i deterministic safe composer; factual pass, `ready_with_notes`; retry 429/timeout; reconcile starego `waiting_review`; kompletny raport decyzji. Fixture usuwa niewsparty claim zamiast przepuszczać go dla zielonego wyniku.

## Oczekiwany czerwony kontrakt implementacyjny

`tests/full-auto-product-contract.php` jest czerwony na bieżącej implementacji. Diagnozuje brak jawnych elementów finalnego kontraktu oraz obecność terminalnych ścieżek jakości `auto_rejected`/`waiting_review` w `generate_all`.

Implementator powinien:

1. zastąpić jakościowe terminale kontynuacją drabiny salvage aż do kompletnego niepublicznego `ready_with_notes`;
2. pozostawić `auto_retry_scheduled` wyłącznie dla awarii infrastruktury, w tym 429 i timeout;
3. dodać deterministic safe composer po dwóch nieudanych naprawach modelowych, bez osłabienia walidacji evidence/source IDs;
4. wznowić stare elementy `generate_all` zatrzymane jako `waiting_review` przez reconcile;
5. materializować raport preview z decyzjami, źródłami B/C, usuniętymi claims i pochodzeniem każdego obrazu;
6. zapewnić neutralny lokalny asset, gdy legalny obraz zewnętrzny nie jest dostępny.

Test czerwony ma stać się zielony dopiero po rzeczywistym wdrożeniu tych mechanizmów. Samo dodanie nazw statusów lub markerów bez zachowania wykonawczego nie spełnia kontraktu.
