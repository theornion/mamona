# V3.1 — Auto-compaction przed P3

Zmiana względem V3:
- usunięto automatyczny handoff/rotację Orchestratora przy 65%;
- włączono natywne Kilo Context Condensing przy 65%;
- compaction wykonuje `ollama/qwen3.5-no-think`;
- `prune=true`;
- zachowane są 2 najnowsze turny;
- recent tail ma budżet do 8000 tokenów;
- formalny handoff pozostaje checkpointem/fallbackiem.

Bez zmian:
- atomowe subtaski;
- DIRECT_TARGET_MODE;
- PARTIAL_COMPLETE;
- Orchestrator bez produkcyjnego `edit`;
- tester 27B;
- mechanical agents 9B/no-think;
- jeden targeted recovery;
- timeout=false;
- chunkTimeout=1800000.
