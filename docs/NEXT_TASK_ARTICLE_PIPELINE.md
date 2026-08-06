# MAMONA-24 — Article Generation & Visual Narrative Pipeline V2

> Gotowy prompt dla agenta `mamona-orchestrator` w Kilo. Jeżeli numer MAMONA-24 jest już zajęty w repozytorium, wybierz najbliższy wolny numer i zaktualizuj wszystkie odwołania.

## Rola

Jesteś agentem `mamona-orchestrator`. Zarządzasz zadaniem, budżetem kontekstu i delegacją. Nie implementujesz bezpośrednio kodu źródłowego. Korzystasz z lokalnych modeli i agentów skonfigurowanych w Kilo.

Najpierw zweryfikuj aktualny stan repozytorium. `docs/CURRENT_WORK.md` dostarczony w paczce opisuje wcześniejsze TASK-23 i nie jest automatycznie źródłem prawdy dla tego nowego zadania. Zachowaj historię TASK-23, ale utwórz świeży stan pracy dla bieżącego zadania.

## Cel produktu

Przebuduj pipeline generowania artykułów tak, aby tworzył spójny, angażujący i niemonotonny artykuł mieszczący się w wymaganiach danego typu tekstu, z trafnymi grafikami oraz deterministycznym limitem maksymalnie 20 odpowiedzi Gemini API na jeden przebieg artykułu. Każdy artykuł uznany za ukończony lub gotowy do publikacji musi zawierać wymaganą liczbę rzeczywistych, dostępnych, legalnych i redakcyjnie trafnych grafik; artykuł bez grafik albo z grafiką zastępczą nie jest wynikiem poprawnym.

System ma działać rekurencyjnie, ale zbieżnie: poprawia tylko element, który nie przeszedł kontroli, zachowuje zaakceptowane fragmenty i kończy bez zapętlenia. Nie wolno obniżać kryteriów jakości, wstawiać niepowiązanych grafik ani używać placeholderów jako sposobu zaliczenia kontroli.

## Nienaruszalne wymagania

1. **Długość tekstu**
   - Odszukaj wszystkie typy tekstów i ich aktualne limity.
   - Zachowaj istniejące minima.
   - Zwiększ maksimum każdego typu tekstu dokładnie o 2000 znaków.
   - Ustal jedną kanoniczną metodę liczenia znaków i stosuj ją w generatorze, QC, logach i testach.
   - Finalny tekst musi mieścić się między minimum i nowym maksimum danego typu.

2. **Budżet Gemini**
   - Maksymalnie 20 odpowiedzi Gemini API na pojedynczy przebieg generowania artykułu.
   - Licz centralnie wszystkie wywołania, które mogą zwrócić odpowiedź Gemini: research, plan, draft, QC, poprawki, plan obrazów, analiza obrazów i generowanie obrazów, jeżeli dany etap używa Gemini.
   - Retry, regeneracja i alternatywny prompt także zużywają budżet. Nie może istnieć boczna ścieżka omijająca licznik.
   - Od odpowiedzi numer 16 włącz `convergence mode`: zamroź zaakceptowane elementy, zabroń pełnych rewrite'ów i wykonuj wyłącznie najmniejsze poprawki usuwające konkretne blokery.
   - Nie obniżaj progów QC po 16. odpowiedzi.
   - Po wyczerpaniu 20 odpowiedzi zachowaj najlepszy audytowalny stan i skieruj materiał do `manual_review`; nie publikuj niezgodnego artykułu.

3. **Grafiki — brak fallbacków w finalnym artykule**
   - Maksymalnie 5 grafik łącznie z hero.
   - Docelowa minimalna liczba slotów wizualnych wynika z planowanej długości tekstu: jedna grafika na każde rozpoczęte 1200 znaków, z limitem 5 i z wliczeniem hero. Każdy niepusty artykuł wymaga co najmniej jednej grafiki.
   - Zamroź liczbę wymaganych slotów po zaakceptowaniu planu narracyjnego i docelowej długości. Dodatkowy moduł B lub C ma wypełnić brakujący slot, a nie uruchamiać nieskończone zwiększanie wymagań.
   - Hero musi odpowiadać głównemu tematowi A.
   - Każda grafika inline musi być przypisana do konkretnego `section_id` lub `topic_id` i wynikać z faktycznej treści tej części.
   - Grafika legalna, lecz semantycznie lub redakcyjnie nietrafna, nie przechodzi. Przypadek satyrycznej grafiki Donalda Trumpa lub polityka-zombie jedzącego mózg w artykule o neuroplastyczności jest obowiązkowym fixture'em regresyjnym: taki obraz musi zostać odrzucony mimo zgodności pojedynczego tokenu `brain`.
   - **Żaden placeholder, neutralna plansza, grafika redakcyjna zastępcza, techniczny fallback ani przypadkowy obraz nie może znaleźć się w finalnym artykule ani zaliczać minimalnej liczby grafik.** Decyzja ta zastępuje wcześniejszą koncepcję neutralnego fallbacku z TASK-23.
   - Fallback może istnieć wyłącznie jako wewnętrzny sygnał niepowodzenia procesu, nigdy jako renderowany asset finalnego artykułu. Renderer i publikacja muszą odrzucać rekord zawierający fallback lub brak rzeczywistego finalnego assetu.
   - Jeżeli dla tematu A nie da się znaleźć wystarczającej liczby trafnych grafik, nie edytuj zaakceptowanego A. Zaplanuj krótki, wartościowy i okołotematowy moduł B, a w razie potrzeby C, dla którego istnieje trafna grafika. Moduł ma pogłębiać temat, budować ciekawość albo utrzymywać uwagę, a nie być sztucznym wypełniaczem.
   - Jeżeli po B/C nadal nie ma wymaganej liczby trafnych grafik, zakończ jako `manual_review`, nie twórz finalnego renderu i zablokuj publikację. Nie zastępuj brakującej grafiki fallbackiem.
   - Artykuł może otrzymać status ukończony/publikowalny dopiero wtedy, gdy każdy wymagany slot ma istniejący plik, spójne metadane, prawa/licencję oraz zaliczoną bramkę semantyczną i redakcyjną.

4. **Narracja i struktura**
   - Po researchu, przed pisaniem treści, wygeneruj i zatwierdź osobny `NarrativePlan`.
   - Plan ma określać: obietnicę dla czytelnika, główną tezę, łuk narracyjny, kolejność sekcji, przejścia, rytm, planowane sloty wizualne, zakończenie oraz opcjonalne gałęzie B/C.
   - Nie narzucaj każdemu artykułowi schematu: hero → opis → obraz → dalszy opis → trzy fakty → wniosek.
   - Struktura ma wynikać z tematu i materiału. Dopuszczalne są m.in. chronologia, problem–rozwiązanie, pytanie prowadzące, scena–analiza, porównanie, mit–dowody, case study albo inny uzasadniony układ.
   - Nie wybieraj archetypu losowo dla samej różnorodności. Zapisz uzasadnienie wyboru w planie.
   - Generator ma pisać spójną narrację z przejściami, a nie sklejać niezależne fragmenty.

5. **Rekurencyjna poprawa bez niszczenia dobrych elementów**
   - Każdy artefakt ma status co najmniej: `planned`, `generated`, `accepted`, `rejected`, `frozen` albo `manual_review`.
   - Tekst A zaakceptowany pod względem długości, tematu i jakości staje się `frozen`.
   - Brak grafik nie może wywołać pełnego rewrite'u zaakceptowanego tekstu A.
   - QC zwraca ustrukturyzowane blokery i wskazuje minimalny zakres naprawy: cały plan, konkretna sekcja, przejście, grafika, podpis, moduł B/C albo metadane.
   - Po każdej iteracji orkiestrator przelicza pozostały budżet i wybiera najmniejszą operację mogącą usunąć blokery.
   - Zdefiniuj jawne warunki zakończenia, aby pętla nie mogła działać bez końca.

6. **Quality Control**
   - Rozdziel bramki twarde od miękkich.
   - Twarde obejmują co najmniej: zakres znaków, limit 20 odpowiedzi, maksymalnie 5 grafik, wymaganą liczbę trafnych slotów, istnienie assetów, prawa/licencje, spójność metadanych i bezpieczeństwo publikacji.
   - Miękkie obejmują co najmniej: narrację, przejścia, redundancję, rytm, użyteczność, zaangażowanie i brak monotonnej matrycy.
   - QC nie może zmieniać własnych progów, aby dopasować je do nieudanego wyniku.
   - Finalna kontrola ma oceniać kompletny artykuł, ale naprawa ma dotykać tylko wskazanego, niezaakceptowanego zakresu.

7. **Kodowanie**
   - Wszystkie zmieniane pliki tekstowe zapisuj w UTF-8.
   - Zachowuj polskie znaki. Nie konwertuj treści przez Windows-1250/1252 i nie usuwaj znaków diakrytycznych.
   - PHP i pozostałe pliki kodu zapisuj bez BOM.
   - Przed zakończeniem skanuj zmienione pliki pod kątem `�` oraz typowych śladów mojibake: `Ã`, `Â`, `Ä`, `Å`, `â€`.
   - Dodaj test regresji kodowania, jeżeli pipeline zapisuje prompty, artykuły, dokumentację lub JSON automatycznie.

8. **Naprawa istniejących wadliwych artykułów bez Gemini**
   - Po naprawie i zatwierdzeniu generatora wykonaj deterministyczny audyt istniejących artykułów. Audyt oraz reset nie mogą wywoływać Gemini, żadnego innego modelu, providerów obrazów ani publikacji.
   - Do zbioru naprawczego zakwalifikuj co najmniej:
     1. artykuł z referencyjną, semantycznie błędną grafiką polityka-zombie/Donalda Trumpa jedzącego mózg;
     2. każdy artykuł zawierający grafikę redakcyjną zastępczą, placeholder, neutralny fallback albo techniczny asset zastępczy;
     3. każdy artykuł bez hero, bez żadnej grafiki albo z liczbą prawidłowych grafik mniejszą od wymaganego minimum;
     4. artykuł z brakującym plikiem assetu, niespójnym finalnym rekordem lub grafiką odrzuconą przez nową bramkę semantyczną/redakcyjną.
   - Identyfikacja nie może opierać się wyłącznie na tekście captionu albo jednej liście słów. Użyj potwierdzonych pól statusu/fallbacku, identyfikatorów i hashy assetów, relacji artykuł–obraz, istnienia pliku, rights manifestu oraz wyniku nowej walidacji. Nazwa i opis referencyjnego obrazu mogą być dodatkowym sygnałem, nie jedynym kryterium.
   - Najpierw przygotuj idempotentne narzędzie z trybami `--dry-run` i `--apply`. `--dry-run` ma wygenerować manifest: `article_id`, tytuł/seed, powód kwalifikacji, obecny status, assety, planowane pola do wyczyszczenia i pola zachowywane.
   - Przed modyfikacją utwórz lokalny backup/eksport dotkniętych rekordów i zapisz ścieżkę oraz sumę kontrolną w logu implementacji. Nie dodawaj backupu ani danych produkcyjnych do Git.
   - „Wyzerowanie” oznacza cofnięcie artykułu do bezpiecznego stanu oczekującego na pełną ponowną generację, a nie usunięcie rekordu. Zachowaj co najmniej: `article_id`, pierwotny temat/seed/brief, typ tekstu, kategorię, język, ustawienia wejściowe oraz audyt historii. Wyczyść lub unieważnij wszystkie artefakty pochodne potwierdzone przez architekturę: wygenerowany tytuł/body/excerpt, research i plan narracyjny, QC, plan i przypisania grafik, caption/alt/credit/source, wynik renderowania, publiczny plik, hashe i statusy zakończonych przebiegów.
   - Jeżeli wadliwy artykuł jest publiczny, najpierw atomowo zdejmij go z publikacji zgodnie z `editorial_status`, zachowując historię zdarzenia. Żaden wadliwy artykuł nie może pozostać widoczny publicznie.
   - Reset nie uruchamia automatycznej regeneracji. Po `--apply` artykuły pozostają jako `pending_generation`/równoważny potwierdzony stan, aby użytkownik mógł ręcznie przetestować na nich poprawiony generator treści i grafik.
   - Nie resetuj poprawnych artykułów. Operacja musi być idempotentna: ponowne uruchomienie nie może usuwać dodatkowych danych ani rozszerzać zbioru bez nowego uzasadnienia.

## Obowiązkowa delegacja agentów i poziomy rozumowania

### P0 — rozpoznanie repozytorium

Agent nadrzędny: `mamona-orchestrator`  
Model orkiestratora: `qwen3.6:27b/balanced`; `deep` tylko do końcowej syntezy sprzecznych ustaleń.  
Edycja kodu: zabroniona.

Uruchom maksymalnie trzy niezależne subtaski `repo-scout` na `qwen3.5:9b/fast`, tylko do odczytu. Każdy ma otworzyć maksymalnie 12 plików i zwrócić ścieżki, symbole, kontrakty oraz nierozstrzygnięte pytania:

- **P0-A — text types and limits:** typy artykułów, minima/maksima znaków, sposób liczenia długości, prompty i obecna struktura tekstu.
- **P0-B — Gemini call graph:** wszystkie miejsca wywołań Gemini, retry, pętle korekt, liczniki, logowanie, warunki końca i publikacja.
- **P0-C — narrative and image flow:** research → outline/plan → draft → QC → plan grafik → selekcja/generowanie → rendering, wraz z istniejącymi kontraktami i testami.
- **P0-D — existing invalid article inventory:** schemat danych artykułów i assetów, statusy publikacji/generacji, flagi placeholder/fallback, znane assety zastępcze, sposób wykrycia artykułu z politykiem-zombie oraz bezpieczne pola resetu. Tylko odczyt; bez Gemini, providerów i zmian danych.

Równoległość jest dozwolona wyłącznie dla odczytu. Subagenci nie mogą edytować tych samych ani żadnych plików.

### P1 — architektura i specyfikacja

Agent: `mamona-architect`  
Model: `qwen3.6:27b/deep`  
Edycja: wyłącznie dokumentacja.

Na podstawie potwierdzonego kodu przygotuj:

- aktualny diagram/call graph pipeline'u;
- root cause monotonnej struktury i niekontrolowanych iteracji;
- kontrakty `NarrativePlan`, `GenerationState`, `GeminiBudget`, `QcReport`, `VisualSlot` i `SupplementalTopic`;
- maszynę stanów z warunkami przejścia oraz zakończenia;
- strategię migracji i zgodności wstecznej;
- listę konkretnych plików do zmiany;
- plan testów i fixture'ów bez realnych płatnych wywołań.
- specyfikację kryteriów audytu istniejących artykułów oraz dokładny kontrakt resetu: pola zachowywane, czyszczone, status po resecie, obsługa artykułów publicznych, backup, idempotencja i brak wywołań Gemini;
- decyzję, jak usunąć możliwość renderowania placeholderów bez łamania kompatybilności historycznych rekordów.

Zapisz specyfikację w `docs/specs/<TASK-ID>-article-generation-visual-narrative-v2.md`. Zaktualizuj `docs/CURRENT_WORK.md`, ale wcześniej zachowaj wcześniejszy TASK-23 jako historię, jeżeli nie ma go jeszcze w archiwum. Po P1 uruchom `mamona-reviewer` na `qwen3.6:27b/deep` do przeglądu samej specyfikacji. Następnie zatrzymaj implementację i przedstaw użytkownikowi checkpoint: potwierdzone pliki, root cause, kontrakty, ryzyka i proponowany diff.

### P2 — implementacja po akceptacji checkpointu

Agent: `mamona-coder`  
Model domyślny: `qwen3.6:27b/balanced`; użyj `deep` wyłącznie dla centralnej maszyny stanów, budżetu i trudnych zmian kontraktów.

Podziel implementację na sekwencyjne, małe subtaski. Nie uruchamiaj równolegle edycji plików współdzielonych:

- **P2-A:** centralny `GeminiBudget`, licznik, convergence mode, audyt i warunki końca;
- **P2-B:** `NarrativePlan`, zróżnicowana struktura oraz generowanie spójnego draftu;
- **P2-C:** ustrukturyzowany QC i naprawy zakresowe z zamrażaniem zaakceptowanych elementów;
- **P2-D:** `VisualSlot`, trafność sekcji, limit 5, hero oraz moduły B/C;
- **P2-E:** aktualizacja konfiguracji wszystkich typów tekstu — maksimum +2000 znaków;
- **P2-F:** diagnostyka i bezpieczny `manual_review` po wyczerpaniu budżetu.
- **P2-G:** deterministyczne narzędzie audytu/resetu istniejących wadliwych artykułów z `--dry-run` i `--apply`, manifestem, backupem, ochroną publikacji, idempotencją i bez jakichkolwiek wywołań Gemini/providerów. Na tym etapie zaimplementuj narzędzie, ale nie wykonuj jeszcze `--apply` na rzeczywistych danych.

Po każdym subtasku agent ma podać zmienione pliki, test lokalny, wpływ na kontrakty i aktualny stan dokumentacji.

### P3 — testy

Agent: `mamona-tester`  
Model: `qwen3.6:27b/balanced`; `deep` tylko do debugowania niejednoznacznej regresji.  
Realne płatne API i publikacja: zabronione.

Dodaj deterministyczne mocki/fixture'y i sprawdź co najmniej:

1. Każdy typ tekstu zachowuje minimum i ma maksimum większe dokładnie o 2000 znaków.
2. Jedna kanoniczna funkcja liczy długość identycznie w generatorze i QC.
3. 20. odpowiedź jest twardym limitem, a retry także go zużywa.
4. Od 16. odpowiedzi system wykonuje tylko poprawki zakresowe i nie obniża QC.
5. Zaakceptowany tekst A nie zmienia się, kiedy brakuje wyłącznie grafik.
6. Hero odpowiada A; grafiki inline odpowiadają przypisanym sekcjom.
7. Długości w okolicach progów 1200/2400/3600/4800 dają prawidłową liczbę slotów, nigdy więcej niż 5.
8. Brak trafnego obrazu uruchamia B, potem C, ale nie placeholder ani obraz przypadkowy.
9. Brak rozwiązania po B/C prowadzi do `manual_review` i blokuje publikację.
10. Nie ma obowiązkowej jednej matrycy sekcji dla wszystkich tematów.
11. Finalny artykuł przechodzi twarde i miękkie QC bez pełnych rewrite'ów zaakceptowanych części.
12. Polskie znaki przechodzą przez prompty, JSON, bazę, renderer i dokumentację bez mojibake.
13. Finalny artykuł bez hero albo bez wymaganej liczby prawidłowych grafik nie może otrzymać statusu ukończonego ani zostać opublikowany.
14. Placeholder, neutralny fallback i grafika redakcyjna zastępcza są odrzucane przez finalny QC i renderer; nie istnieje ścieżka, która publikuje je jako asset.
15. Fixture polityka-zombie/Donalda Trumpa jedzącego mózg odpada w artykule o neuroplastyczności mimo tokenu `brain`.
16. Audyt `--dry-run` wykrywa: znany błędny obraz, placeholder/fallback, zero grafik, za mało prawidłowych grafik i brak pliku; nie kwalifikuje poprawnego artykułu.
17. Reset nie wykonuje żadnego wywołania Gemini/providerów, zachowuje seed i historię, czyści artefakty pochodne oraz ustawia bezpieczny stan oczekujący na ręczną regenerację.
18. Reset jest idempotentny, tworzy manifest i backup, a publiczny wadliwy artykuł jest bezpiecznie zdejmowany z publikacji przed wyczyszczeniem artefaktów.


### P4 — review

Agent: `mamona-reviewer`  
Model: `qwen3.6:27b/deep`.

Sprawdź:

- czy wszystkie ścieżki Gemini używają jednego budżetu;
- czy nie istnieje ukryte obejście limitu 20;
- czy akceptowane elementy są rzeczywiście zamrażane;
- czy nie osłabiono QC;
- czy grafiki są powiązane z sekcjami i prawami;
- czy B/C nie są sztucznym fillerem;
- czy publikacja jest blokowana przy `manual_review`;
- czy diff nie narusza innych typów treści ani istniejących poprawnych artykułów;
- czy dokumentacja odpowiada finalnemu kodowi;
- czy wszystkie pliki są poprawnym UTF-8 i zachowują polskie znaki.
- czy nie istnieje finalna ścieżka renderowania/publikacji placeholderu, fallbacku albo artykułu bez wymaganych grafik;
- czy narzędzie resetu ma prawidłowy `--dry-run`, nie korzysta z Gemini/providerów, zachowuje dane wejściowe i historię, nie obejmuje poprawnych artykułów oraz jest idempotentne;
- czy kryteria wykrywania wadliwych artykułów są oparte na danych i walidacji, a nie wyłącznie na nazwisku lub pojedynczym słowie.

### P5 — kontrolowane wyzerowanie wadliwych artykułów po naprawie generatora

Agent nadrzędny: `mamona-orchestrator` na `qwen3.6:27b/balanced`. Sam nie modyfikuje danych; deleguje i pilnuje checkpointów.

- **P5-A — audyt danych:** `mamona-tester` na `qwen3.6:27b/balanced` uruchamia wyłącznie narzędzie z `--dry-run`, bez Gemini, providerów obrazów i publikacji. Powstaje manifest wszystkich kandydatów wraz z jednoznacznym powodem kwalifikacji.
- **P5-B — review manifestu:** `mamona-reviewer` na `qwen3.6:27b/deep` sprawdza, czy lista obejmuje znany artykuł z politykiem-zombie/Donaldem Trumpem jedzącym mózg, wszystkie artykuły z grafiką redakcyjną zastępczą/fallbackiem, artykuły bez grafik lub z brakującym minimum oraz czy nie obejmuje poprawnych rekordów.
- **Checkpoint przed mutacją:** pokaż użytkownikowi liczbę i identyfikatory artykułów, powody, zakres czyszczonych pól, statusy publiczne oraz lokalizację backupu. Nie wykonuj `--apply` bez akceptacji manifestu.
- **P5-C — reset:** po akceptacji `mamona-coder` na `qwen3.6:27b/balanced` uruchamia `--apply`. Operacja nie generuje treści ani grafik. Wadliwe rekordy zostają bezpiecznie wyzerowane do stanu oczekującego na ręczny test poprawionego generatora.
- **P5-D — kontrola po resecie:** `mamona-tester` na `qwen3.6:27b/balanced` potwierdza brak publicznych wadliwych artykułów, brak powiązań do placeholderów oraz zachowanie seedów i historii. Nie uruchamia regeneracji.

Zapisz raport w `docs/remediation/<TASK-ID>-invalid-article-reset.md`, bez kopiowania danych wrażliwych lub pełnej bazy do Git.

### P6 — dokumentacja i handoff

Odpowiedzialność: każdy agent dokumentuje własny etap; końcową normalizację wykonuje `quick-maintainer` na `qwen3.5:9b/reasoned`, wyłącznie dla małych zmian dokumentacyjnych i linków.

Obowiązkowo utrzymuj:

- `docs/CURRENT_WORK.md` — aktywna faza, checkpointy, wykonane testy, otwarte ryzyka i pozostały zakres;
- `docs/ARCHITECTURE.md` — wyłącznie potwierdzony finalny call graph, moduły i kontrakty;
- `docs/DECISIONS.md` — decyzje, alternatywy i uzasadnienie;
- `docs/CONTEXT_INDEX.md` — aktualny punkt wejścia dla następnej instancji Kilo lub Codex;
- `docs/specs/<TASK-ID>-article-generation-visual-narrative-v2.md` — kompletna specyfikacja;
- `docs/implementation/<TASK-ID>-implementation-log.md` — zmienione pliki, migracje, testy, wyniki, ograniczenia i znane problemy.
- `docs/remediation/<TASK-ID>-invalid-article-reset.md` — kryteria audytu, manifest dry-run, backup, zaakceptowany zakres, wynik resetu i walidacja bez wywołań Gemini.

Dokumentacja musi pozwolić następnej instancji Kilo albo Codex rozpocząć pracę bez ponownego pełnego reverse engineeringu. Nie zapisuj hipotez jako faktów. Każde trwałe ustalenie ma wskazywać plik, symbol, test albo konfigurację będącą dowodem.

## Zasady wykonania

- Zacznij od `git status --short` i nie nadpisuj niezacommitowanych zmian użytkownika.
- Najpierw semantic search, potem celowany grep i odczyt symboli.
- Nie skanuj całego repo rekursywnie.
- Nie uruchamiaj realnych providerów, Gemini ani publikacji podczas rozpoznania i testów.
- Audyt i reset istniejących wadliwych artykułów muszą działać całkowicie bez Gemini i providerów. Nie regeneruj ich automatycznie po resecie; użytkownik uruchomi testy ręcznie.
- Nie uznawaj placeholderu ani fallbacku za prawidłową grafikę nawet dla historycznych rekordów. Nowa decyzja produktu nadpisuje wcześniejsze dopuszczenie neutralnego fallbacku w TASK-23.
- Nie umieszczaj sekretów w logach ani dokumentacji.
- Nie commituj i nie pushuj bez wyraźnej prośby użytkownika.
- Nie implementuj przed zakończeniem P1 i zaakceptowaniem checkpointu.

## Raport po każdym etapie

Podaj krótko:

1. agent/subagent i użyty model/wariant rozumowania;
2. wykonany zakres;
3. zmienione lub przeczytane kluczowe pliki;
4. potwierdzone ustalenia i dowody;
5. testy i wyniki;
6. wykorzystanie budżetu, ryzyka i następny krok.
