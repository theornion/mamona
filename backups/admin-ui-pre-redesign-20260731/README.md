# Backup panelu admina przed redesignem (2026-07-31)

Snapshot obejmuje wspólny layout i nawigację admina, ekran logowania, wszystkie widoki `admin-*.php`, główny arkusz CSS oraz wszystkie skrypty ładowane przez layout panelu. Nie zawiera bazy danych, plików sesji ani konfiguracji/secrets. `manifest.json` zapisuje przeznaczenie, rozmiar, czas modyfikacji i SHA-256 każdego pliku.

## Bezpieczne przywrócenie samego wyglądu

1. Zatrzymaj aplikację i wykonaj osobny backup aktualnych plików.
2. Przywróć `assets/css/main.css`, pliki `assets/js/admin-*.js`, `php/admin-ui.php`, `php/admin-nav.php` i `php/admin-login.php` do odpowiadających im ścieżek w katalogu głównym repozytorium.
3. Nie kopiuj automatycznie pozostałych widoków `php/admin-*.php`: są dołączone jako referencja kompletnego wyglądu, ale mogą też zawierać nowszą logikę tasków 1–3. Przywracaj je wyłącznie po porównaniu zmian warstwy prezentacji.
4. Usuń nowy, adminowy arkusz/skrypt dodany przez redesign, jeśli jest nadal podpięty w layoucie.
5. Zweryfikuj sumy poleceniem `Get-FileHash -Algorithm SHA256 <plik>` i porównaj je z `manifest.json`.
6. Uruchom testy smoke panelu i sprawdź logowanie, Studio, Posty, Galerie oraz widok mobilny.

Ten rollback nie wymaga cofania danych ani resetowania repozytorium. Screenshoty referencyjne znajdują się w `screenshots/before/`.
