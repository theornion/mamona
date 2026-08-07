# V3.1.1 — checkpoint-writer write permission fix

Poprawka względem V3.1:
- `checkpoint-writer` ma teraz natywne uprawnienie `edit` dla plików Markdown w `docs/`;
- nie może pisać przez bash/PowerShell/php ani inne obejścia;
- po udanym zapisie kończy markerem sukcesu;
- jeśli natywne edit/write mimo konfiguracji nie jest dostępne, zwraca `BLOCKED_CHECKPOINT_WRITE_TOOL_UNAVAILABLE`.

Pozostałe ustawienia V3.1, w tym auto-compaction przy 65%, pozostają bez zmian.
