# Diagnostyka przepływu decyzji redakcyjnej — 2026-07-31

## Przyczyna

`list_ready_article_proposals()` dopuszczała wyłącznie ukończony aktywny szkic z ukończonym QC `passed=1` i co najmniej jednym rekordem `article_images`. Jednocześnie status workflow oznaczał ukończone QC z `passed=0` jako `waiting_review`, ale `proposal_url` powstawał dopiero dla silniejszego stanu `proposalReady`. Powstawała niedostępna pętla: interfejs żądał decyzji na ekranie propozycji, a query i URL ukrywały tę propozycję. Brak grafiki powodował ten sam efekt.

Podgląd na ekranie propozycji korzystał z rekordu `posts`, a nie z JSON wybranego szkicu. Nieaktywna wersja mogła więc zostać otwarta przez parametr `draft`, ale ekran nadal pokazywał inną albo pustą treść posta.

## Snapshot danych bez modyfikacji

W aktualnym `data/cms.sqlite` nie ma QC z wynikiem 82 (takiego rekordu nie ma także w `backups/cms-before-title-repair-20260731-210614.sqlite`). Widoczny obecnie przypadek spełniający wadliwy kontrakt ma: temat `649`, post `811`, aktywny szkic `268` (wersja 3), najnowszy QC `201`, zgodny ze szkicem `268`, `status=completed`, `passed=0`, `final_score=95`, `human_review_status=pending`. Blokady są niereviewable: `unsupported_title_fact` i `false_quote`. Obrazów: `0`, pobranych: `0`. Stare query pomija go zarówno przez `checks.passed = 1`, jak i przez wymaganie istnienia rekordu obrazu; stary `proposal_url` jest pusty przez niespełnione `proposalReady`.

Wartość „82%” z opisu może być zapamiętanym procentem postępu etapu `quality_check`, nie `final_score`; bieżąca baza nie pozwala przypisać jej do innego zachowanego QC. Danych nie poprawiano ręcznie.

## Nowy kontrakt

- reviewable: aktywny (lub najnowszy, gdy brak aktywnego) ukończony szkic z ukończonym QC;
- publication-ready: aktualne `passed=1`, brak aktywnych blokad i komplet gotowych grafik;
- karta tematu linkuje bezpośrednio do `admin-proposals.php?draft=<id>` dla materiału reviewable;
- ekran renderuje treść wskazanego szkicu, wynik, uzasadnienie i blokady QC;
- wyłącznie `high_risk_without_human_approval` ma decyzję człowieka z uzasadnieniem i audytem;
- blokady niereviewable kierują do poprawki i ponowienia QC;
- brak grafiki nie ukrywa szkicu, ale pozostaje blokadą publikacji.
