# Mamona Agent Performance V3.1

Wnioski z P2:
- największy koszt to model turns na dużym kontekście;
- duże subtaski P2-B traciły raport;
- mały dispatch-integration zakończył się szybko;
- tester 27B znalazł realny off-by-one;
- 9B/no-think dobrze działał do mechaniki.

V3.1:
1. atomy 1–2 pliki / ~100–150 linii / 1 integration point;
2. DIRECT_TARGET_MODE;
3. `PARTIAL_COMPLETE`;
4. Orchestrator bez edycji kodu;
5. tester: istniejące testy najpierw;
6. 9B/no-think dla quick/checkpoint/handoff;
7. auto-compaction Orchestratora przy 65% zamiast automatycznej rotacji sesji;
8. brak ponownego pełnego diffu po COMPLETE;
9. jeden targeted recovery;
10. małe tool-call arguments.

## Auto-compaction

Przy 65% Kilo:
- kondensuje starszą historię;
- zachowuje recent tail;
- prunuje stare duże tool outputs;
- używa `qwen3.5-no-think` do summary;
- kontynuuje w tej samej sesji.

Handoff do pliku jest checkpointem/fallbackiem, nie standardową reakcją na 65%.
