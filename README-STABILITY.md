# Mamona — stabilizacja subagentów

## 1. Podmień pliki

Skopiuj zawartość paczki do:

C:\Projekty\mamona

z nadpisaniem.

## 2. Ustaw PRAWIDŁOWY limit outputu OpenCode/Kilo

PowerShell:

setx OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX 65536

Sprawdź:

[Environment]::GetEnvironmentVariable("OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX","User")

Powinno zwrócić:

65536

Jeżeli wcześniej ustawiono `KILO_EXPERIMENTAL_OUTPUT_TOKEN_MAX`, nie polegaj na tej zmiennej.
Runtime Kilo jest oparty na OpenCode i limit jest odczytywany z `OPENCODE_EXPERIMENTAL_OUTPUT_TOKEN_MAX`.

## 3. Sprawdź wersję Kilo

PowerShell:

code --list-extensions --show-versions | findstr /I "kilo"

Jeżeli Kilo jest starsze niż 7.4.20, zaktualizuj rozszerzenie w VS Code.

## 4. Restart

Po `setx` zamknij WSZYSTKIE procesy VS Code i uruchom VS Code ponownie.

Następnie:

Ctrl+Shift+P
Developer: Reload Window

## 5. Nie restartuj Ollamy

Zmiany dotyczą klienta Kilo/VS Code. Ollama może pozostać uruchomiona.
