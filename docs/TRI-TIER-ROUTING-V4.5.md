# Mamona V4.5 — Tri-Tier Parallel Routing

```text
                  CODEX / SOL LOW
                    coordinator
                         |
          +--------------+--------------+
          |                             |
   HEAVY_EXCLUSIVE                 FAST_PARALLEL
          |                             |
 Qwen3-Coder 30B               Qwen3 14B || Qwen3.5 9B
      128K, solo                   64K    ||    64K
```

## Heavy
30B ma 30.5B parametrów i architekturę MoE; wersja Qwen3-Coder 30B aktywuje ok. 3.3B parametrów na token. Używamy go do trudnego coding/repo-level work.

## Fast parallel
14B i 9B mogą być aktywne równolegle tylko jeśli oba mieszczą się w VRAM i `ollama ps` pokazuje oczekiwane GPU residency. Scheduler nie zakłada sukcesu sprzętowego w ciemno: init wykonuje smoke/VRAM check.

## 14B context note
Qwen3-14B ma natywne 32K; 64K jest rozszerzonym użyciem. Ustawienie V4.5 celowo daje 64K zgodnie z profilem projektu. Jeżeli jakość long-context 14B spadnie, pierwszy fallback to obniżenie 14B do 32K, bez zmiany routingu.

## Parallel rule
Równoległość oznacza dwa niezależne Task calls w tym samym kroku coordinatora, a nie sekwencyjne delegowanie.
