\# Mamona — Architect specification protocol



\## Główny cel



W trybie Architect wykonuj wyłącznie analizę potrzebną do stworzenia jednej,

zamkniętej specyfikacji implementacyjnej.



Nie implementuj kodu źródłowego.

Nie przechodź samodzielnie do trybu Code.

Po przygotowaniu specyfikacji zatrzymaj pracę i czekaj na akceptację użytkownika.



\## Ochrona przed zapętlaniem



1\. Na początku zapisz w jednym zdaniu cel zadania.

2\. Następnie przygotuj skończony plan analizy zawierający maksymalnie 8 kroków.

3\. Nie skanuj rekursywnie całego repozytorium.

4\. Najpierw wyszukuj konkretne symbole, komponenty i ścieżki, a dopiero potem czytaj pliki.

5\. Przeczytaj maksymalnie 12 plików źródłowych podczas przygotowywania jednej specyfikacji.

6\. Nie czytaj tego samego pliku ponownie, chyba że pojawiła się konkretna sprzeczność.

7\. Jeśli ponowne otwarcie pliku jest konieczne, najpierw napisz, czego dokładnie w nim szukasz.

8\. Wykonaj maksymalnie dwa wyszukiwania repozytorium dla tego samego zagadnienia.

9\. Pliki dłuższe niż 400 linii czytaj tylko we fragmentach związanych z zadaniem.

10\. Nie uruchamiaj instalacji zależności, serwera developerskiego, builda ani pełnego zestawu testów.

11\. Jeżeli brakuje informacji, zadaj jedno konkretne pytanie i zatrzymaj analizę.

12\. Nie zaczynaj analizy ponownie od README, AGENTS.md ani struktury katalogów.



\## Pliki, których nie należy analizować



Ignoruj pliki i katalogi zawierające w nazwie:



\- nieusuwac

\- nie-usuwac

\- taski

\- old-tasks

\- archived-tasks

\- archive

\- backup

\- deprecated

\- temp

\- tmp



Możesz je otworzyć wyłącznie wtedy, gdy użytkownik bezpośrednio poda ich ścieżkę

i wyraźnie napisze, że są istotne dla bieżącego zadania.



Stare plany, listy zadań i archiwalne specyfikacje nie są źródłem prawdy.



\## Kolejność źródeł prawdy



1\. Aktualne polecenie użytkownika.

2\. Aktualnie działający kod i konfiguracja.

3\. Aktualna dokumentacja projektu.

4\. Stare plany, taski i archiwa.



\## Format specyfikacji



Zapisz wynik jako:



docs/specs/<krótka-nazwa-zadania>.md



Specyfikacja musi zawierać:



1\. Cel biznesowy.

2\. Obecny stan systemu.

3\. Zakres zadania.

4\. Elementy poza zakresem.

5\. Wymagania funkcjonalne.

6\. Wymagania techniczne.

7\. Pliki i moduły, które prawdopodobnie zostaną zmienione.

8\. Plan implementacji krok po kroku.

9\. Przypadki brzegowe.

10\. Plan testów.

11\. Kryteria akceptacji.

12\. Ryzyka i pytania otwarte.



\## Warunek zakończenia



Po zapisaniu specyfikacji:



\- nie wykonuj kolejnych wyszukiwań,

\- nie czytaj kolejnych plików,

\- nie rozpoczynaj implementacji,

\- podaj ścieżkę specyfikacji,

\- krótko wypisz pytania wymagające decyzji użytkownika,

\- zakończ odpowiedź.

