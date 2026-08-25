# V3.3 — Kilo 7.4.x permission compatibility

Root cause potwierdzony w eksporcie P3-A:
- child `mamona-tester` miał effective `edit: deny`;
- `write` call kończył się `unavailable tool`;
- dostępne toolsy nie zawierały edit/write/apply_patch.

Zmiany:
- usunięte `permission.write` i `permission.apply_patch`;
- writing agents używają `permission.edit: allow`;
- tester/coder zapisują result files;
- reviewer/scout pozostają read-only;
- Orchestrator pozostaje edit-denied;
- dodany PRECHECK-V3.3-PERMISSIONS.txt.
