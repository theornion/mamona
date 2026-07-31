# Audyt i QA redesignu panelu admina

## Problemy stanu wejściowego

- workspace ograniczony przez stary shell i duże marginesy;
- płaska nawigacja z 13 pozycjami, bez grup workflow i stałego kontekstu;
- nakładające się bloki admin CSS we wspólnym `main.css`;
- zbyt wysokie karty i powtarzane obramowania na listach operacyjnych;
- brak wspólnego breadcrumba i wskazania następnego kroku;
- mobilna nawigacja zależna od publicznego panelu menu.

## Nowa architektura informacji

1. Praca redakcyjna: Studio/RSS → Tematy → Generowanie/Batch → Gotowe propozycje → Kolejka.
2. Treści: Posty i kategorie, Galerie.
3. Źródła i automatyzacja: Źródła techniczne.
4. Ustawienia i administracja: Wiadomości, wygląd, kontakt, social media, profil, kosz.

Desktop korzysta ze stałego sidebaru i pełnej szerokości workspace. Mobile ma wysuwane menu z backdropem, przyciskiem zamknięcia, `aria-expanded` i obsługą Escape. Każdy ekran ma breadcrumb; główny workflow dodatkowo pokazuje następny krok.

## Wyniki QA

- 27/30 plików testowych przeszło w pierwszym pełnym przebiegu.
- Pipeline E2E i thumbnail przeszły po wskazaniu dostępnego lokalnego Python/Pillow.
- Popular science profile pozostaje zablokowany przez stan fixture: brakuje 8 aktywnych źródeł profilu; błąd nie dotyczy UI.
- Prawdziwy Gemini smoke pominięty (zewnętrzne API).
- Desktop 1440: workspace 1382 px, sidebar 280 px, content 1072 px, brak globalnego overflow.
- Mobile 375: brak globalnego overflow; menu 279 px; otwieranie, backdrop, zamknięcie i Escape zweryfikowane.
- Viewporty: 375, 768, 1024, 1440 i 1920 px.
- Statycznie zweryfikowano reduced motion, focus-visible, izolację publicznego UI, komplet nawigacji i backup.

Screenshoty przed: `backups/admin-ui-pre-redesign-20260731/screenshots/before/`.

Screenshoty po: `qa/admin-ui-redesign/after/`.
