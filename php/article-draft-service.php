<?php

declare(strict_types=1);

const ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION = 3;
const ARTICLE_DRAFT_QC_REPAIR_CONTRACT_VERSION = 3;

final class ArticleTitleRepairException extends InvalidArgumentException
{
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct((string) ($diagnostics['message'] ?? 'Tytuł wymaga poprawy.'));
    }
}

const ARTICLE_COMPOSITION_MODES = ['informational', 'problem_discovery_return'];
const ARTICLE_MAIN_CONTENT_MIN_LENGTH = 5000;
const ARTICLE_COMPLEX_MAIN_CONTENT_MIN_LENGTH = 5000;
const ARTICLE_MAIN_CONTENT_MAX_LENGTH = 10000;

function article_draft_length_policy(string $compositionMode): array
{
    if (!in_array($compositionMode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Nieprawidłowy tryb kompozycji szkicu.');
    }
    $complex = $compositionMode === 'problem_discovery_return';

    return [
        'scope' => 'main_content',
        'complex' => $complex,
        'minimum_characters' => $complex
            ? ARTICLE_COMPLEX_MAIN_CONTENT_MIN_LENGTH
            : ARTICLE_MAIN_CONTENT_MIN_LENGTH,
        'maximum_characters' => ARTICLE_MAIN_CONTENT_MAX_LENGTH,
    ];
}

/**
 * Length contract shared by initial drafts and QC repairs.  The aggregate is
 * deliberately repeated in the repair input: Gemini otherwise sees only the
 * field-level QC feedback and can make a valid section-level edit that pushes
 * the assembled article past the deterministic hard limit.
 */
function article_draft_repair_length_requirements(string $compositionMode): array
{
    $policy = article_draft_length_policy($compositionMode);

    return [
        ...$policy,
        'target_characters' => '6000–8500',
        'measurement' => 'Liczba znaków tekstu głównego: lead, znaczenie, fakty, kontekst porównawczy, niewiadome, wniosek praktyczny i — jeśli używana — narracja. Bez tytułu, briefu, SEO, kategorii, altu i metadanych.',
        'final_check' => 'Przed zwróceniem JSON zsumuj długości wszystkich pól tekstu głównego. Wynik musi mieścić się w zakresie minimum_characters–maximum_characters; skróć merytorycznie nadmiar albo rozwiń zbyt krótkie sekcje. Nie zwracaj szkicu poza tym zakresem.',
    ];
}

function article_draft_main_content_texts(array $draft): array
{
    $texts = [];
    $append = static function (mixed $value) use (&$texts): void {
        $text = trim(strip_tags((string) $value));
        if ($text !== '') {
            $texts[] = $text;
        }
    };
    if (isset($draft['sections']) && is_array($draft['sections'])) {
        foreach ($draft['sections'] as $section) $append($section['body'] ?? '');
        return $texts;
    }
    $append($draft['lead']['text'] ?? '');
    $append($draft['why_important']['text'] ?? '');
    foreach ((array) ($draft['key_facts'] ?? []) as $fact) {
        $append($fact['text'] ?? '');
    }
    $append($draft['comparison_context']['text'] ?? '');
    foreach ((array) ($draft['unknowns'] ?? []) as $unknown) {
        $append($unknown['text'] ?? '');
    }
    $append($draft['practical_takeaway']['text'] ?? '');
    foreach ((array) ($draft['narrative'] ?? []) as $section) {
        $append($section['text'] ?? '');
    }

    return $texts;
}

function article_draft_v2_schema(array $sourceIds, array $claimIds, string $compositionMode, array $plannedSections): array
{
    $sectionIds = array_values(array_filter(array_map(static fn (array $section): string => (string) ($section['section_id'] ?? ''), $plannedSections)));
    $section = [
        'type' => 'object',
        'properties' => [
            'section_id' => ['type'=>'string', 'minLength'=>2, 'maxLength'=>81, ...($sectionIds === [] ? [] : ['enum'=>$sectionIds])],
            'topic_role' => ['type'=>'string','enum'=>['A','B','C']],
            'content_type' => ['type'=>'string','enum'=>['prose','explainer','curiosity','history','comparison','short_callout','unknowns','takeaway']],
            'heading' => ['type'=>'string','maxLength'=>180],
            'body' => ['type'=>'string','minLength'=>1,'maxLength'=>4000],
            'visual_slot_id' => ['type'=>'string','maxLength'=>100],
            'claim_ids' => ['type'=>'array','items'=>['type'=>'string','enum'=>$claimIds],'minItems'=>1],
            'source_ids' => ['type'=>'array','items'=>['type'=>'string','enum'=>$sourceIds],'minItems'=>1],
        ],
        'required' => ['section_id','topic_role','content_type','heading','body','claim_ids','source_ids'],
        'additionalProperties' => false,
    ];
    $legacy = article_draft_schema($sourceIds, $claimIds, $compositionMode);
    foreach (['lead','why_important','key_facts','comparison_context','unknowns','practical_takeaway','narrative'] as $field) unset($legacy['properties'][$field]);
    $legacy['properties']['sections'] = ['type'=>'array','items'=>$section,'minItems'=>3,'maxItems'=>12];
    $legacy['required'] = array_values(array_diff($legacy['required'], ['lead','why_important','key_facts','comparison_context','unknowns','practical_takeaway','narrative']));
    $legacy['required'][] = 'sections';
    return $legacy;
}

/** Normalize the non-editorial SEO metadata before the provider-output schema gate. */
function article_draft_normalize_seo_description(array &$draft): ?array
{
    $seo = trim((string) ($draft['seo_description'] ?? ''));
    if ($seo === '') {
        return null;
    }
    $length = mb_strlen($seo);
    if ($length > 200) {
        $draft['seo_description'] = rtrim(mb_substr($seo, 0, 200));
        return ['code' => 'seo_description_shortened', 'original_length' => $length, 'final_length' => mb_strlen($draft['seo_description'])];
    }
    if ($length < 70) {
        $draft['seo_description'] = $seo;
        return ['code' => 'seo_description_short', 'length' => $length];
    }
    $draft['seo_description'] = $seo;
    return null;
}

/** Upgrade only the SEO field of persisted article-draft operation schemas. */
function article_draft_apply_seo_description_schema(array &$schema): void
{
    $seo =& $schema['properties']['seo_description'];
    if (!is_array($seo)) {
        return;
    }
    $seo['minLength'] = 1;
    $seo['maxLength'] = 200;
}

function article_draft_main_content_length(array $draft): int
{
    return mb_strlen(implode("\n\n", article_draft_main_content_texts($draft)));
}

function article_draft_claim_grounded_text(array $draft, array $knownClaims): string
{
    $texts = [];
    $collect = static function (array $section) use (&$texts, $knownClaims): void {
        $ids = array_values((array)($section['claim_ids'] ?? []));
        if ($ids !== [] && array_diff($ids, array_keys($knownClaims)) === []) $texts[] = (string)($section['text'] ?? '');
    };
    if (isset($draft['sections']) && is_array($draft['sections'])) {
        foreach ($draft['sections'] as $section) $collect(['text'=>$section['body'] ?? '', 'claim_ids'=>$section['claim_ids'] ?? []]);
        return implode(' ', $texts);
    }
    foreach (['lead','why_important','comparison_context','practical_takeaway'] as $field) $collect((array)($draft[$field] ?? []));
    foreach ((array)($draft['key_facts'] ?? []) as $section) $collect((array)$section);
    foreach ((array)($draft['narrative'] ?? []) as $section) $collect((array)$section);
    return implode(' ', $texts);
}

function article_draft_assert_polish_language(array $draft): void
{
    $text = mb_strtolower(implode(' ', article_draft_main_content_texts($draft)));
    $markers = [
        'że',
        'się',
        'jest',
        'oraz',
        'który',
        'która',
        'które',
        'nie',
        'dla',
        'przez',
        'ponieważ',
        'jednak',
        'może',
        'są',
        'aby',
        'także',
    ];
    $matched = 0;
    foreach ($markers as $marker) {
        if (preg_match('/(?<![\p{L}\p{N}_])' . preg_quote($marker, '/') . '(?![\p{L}\p{N}_])/u', $text) === 1) {
            $matched++;
        }
    }
    $diacriticCount = preg_match_all('/[ąćęłńóśźż]/u', $text) ?: 0;
    if ($matched < 2 || ($matched < 4 && $diacriticCount < 3)) {
        throw new InvalidArgumentException(
            'Treść szkicu nie została rozpoznana jako język polski (wymagany pl-PL).'
        );
    }
}

function article_draft_repeated_sentence(array $draft): ?string
{
    $seen = [];
    foreach (article_draft_main_content_texts($draft) as $text) {
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($sentences as $sentence) {
            $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence));
            if (mb_strlen($normalized) < 40) {
                continue;
            }
            if (isset($seen[$normalized])) {
                return $sentence;
            }
            $seen[$normalized] = true;
        }
    }

    return null;
}

function article_title_normalized_tokens(string $value): array
{
    $value = mb_strtolower(strip_tags($value));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $parts = preg_split(
        '/[^a-z0-9]+/',
        strtolower(is_string($ascii) ? $ascii : $value),
        -1,
        PREG_SPLIT_NO_EMPTY
    ) ?: [];
    $stop = [
        'oraz', 'ktory', 'ktora', 'ktore', 'tego', 'tych', 'jest', 'jako',
        'przez', 'dla', 'nad', 'pod', 'bez', 'czy', 'jak', 'dlaczego',
        'wlasnie', 'moze', 'warto', 'nowe', 'wynika', 'pokazuje', 'ujawnia',
        'sprawia', 'oznacza', 'zmienia', 'najwazniejsze',
    ];
    $tokens = [];
    foreach ($parts as $part) {
        if (strlen($part) < 4 || in_array($part, $stop, true)) {
            continue;
        }
        foreach (['owego', 'owej', 'ami', 'ach', 'ego', 'emu', 'owie', 'ie', 'em', 'om', 'ow', 'y', 'a', 'u', 'e'] as $ending) {
            if (str_ends_with($part, $ending) && strlen($part) - strlen($ending) >= 4) {
                $part = substr($part, 0, -strlen($ending));
                break;
            }
        }
        $tokens[$part] = true;
    }

    return array_keys($tokens);
}

function article_title_surface_error(string $title): ?string
{
    $title = trim(preg_replace('/\s+/u', ' ', strip_tags($title)) ?? '');
    $length = mb_strlen($title);
    if ($length < 35 || $length > 100) {
        return 'Tytuł musi mieć od 35 do 100 znaków.';
    }
    if (preg_match('/(?:^|[^\p{L}])(?:nie uwierzysz|musisz to zobaczyć|internet oszalał|szok|pilne|zmieni wszystko|koniec świata|przełom stulecia|tego ci nie powiedzą)(?:[^\p{L}]|$)/iu', $title) === 1) {
        return 'Tytuł zawiera zakazaną pustą formułę clickbaitową.';
    }
    if (preg_match('/[!?]{2,}|\.{3,}/u', $title) === 1
        || substr_count($title, '!') > 1
        || substr_count($title, '?') > 1) {
        return 'Tytuł zawiera nadmiarową interpunkcję.';
    }
    if (preg_match('/[:,;–—-]\s*$/u', $title) === 1) {
        return 'Tytuł jest urwany znakiem zapowiadającym brakującą część.';
    }
    $letters = preg_replace('/[^\p{L}]+/u', '', $title) ?? '';
    if (mb_strlen($letters) >= 8
        && $letters === mb_strtoupper($letters)
        && $letters !== mb_strtolower($letters)) {
        return 'Tytuł nie może być zapisany wersalikami.';
    }
    if (preg_match('/\b(?:the|this|these|why|scientists|study|researchers)\b/i', $title) === 1) {
        return 'Tytuł nie jest napisany naturalnym językiem polskim.';
    }

    return null;
}

function validate_article_title_strategy(array $draft, array $knownClaims): array
{
    $title = trim((string) ($draft['title'] ?? ''));
    $surfaceError = article_title_surface_error($title);
    if ($surfaceError !== null) {
        throw new InvalidArgumentException($surfaceError);
    }
    $variants = (array) ($draft['title_variants'] ?? []);
    if (count($variants) < 5 || count($variants) > 8) {
        throw new InvalidArgumentException('Szkic musi zawierać od 5 do 8 wariantów tytułu.');
    }
    $seen = [];
    $selected = [];
    $highestScore = -1;
    $scoreFields = [
        'relevance_score',
        'specificity_score',
        'curiosity_score',
        'naturalness_score',
        'click_potential_score',
    ];
    foreach ($variants as $index => $variant) {
        $candidateTitle = trim((string) ($variant['title'] ?? ''));
        $candidateError = article_title_surface_error($candidateTitle);
        if ($candidateError !== null) {
            throw new InvalidArgumentException("$.title_variants[{$index}]: {$candidateError}");
        }
        $key = mb_strtolower($candidateTitle);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('Warianty tytułu muszą być unikalne.');
        }
        $seen[$key] = true;
        $calculatedTotal = 0;
        foreach ($scoreFields as $scoreField) {
            $score = $variant[$scoreField] ?? null;
            if (!is_int($score) || $score < 0 || $score > 10) {
                throw new InvalidArgumentException("$.title_variants[{$index}].{$scoreField} musi mieć wartość 0–10.");
            }
            $calculatedTotal += $score;
        }
        if ((int) ($variant['total_score'] ?? -1) !== $calculatedTotal) {
            throw new InvalidArgumentException("$.title_variants[{$index}].total_score nie jest sumą ocen.");
        }
        if (mb_strlen(trim((string) ($variant['rationale'] ?? ''))) < 15) {
            throw new InvalidArgumentException("$.title_variants[{$index}].rationale jest zbyt krótkie.");
        }
        $highestScore = max($highestScore, $calculatedTotal);
        if (($variant['selected'] ?? false) === true) {
            $selected[] = ['title' => $candidateTitle, 'score' => $calculatedTotal];
        }
    }
    if (count($selected) !== 1 || $selected[0]['title'] !== $title) {
        throw new InvalidArgumentException('Dokładnie jeden wariant musi być wybrany i identyczny z polem title.');
    }
    if ($selected[0]['score'] < $highestScore) {
        throw new InvalidArgumentException('Wybrany tytuł nie jest najmocniejszym ocenionym wariantem.');
    }
    if (mb_strlen(trim((string) ($draft['title_selection_reason'] ?? ''))) < 30) {
        throw new InvalidArgumentException('Uzasadnienie wyboru tytułu jest zbyt krótkie.');
    }

    $claimText = implode(' ', array_map(
        static fn (array $claim): string => (string) ($claim['claim'] ?? ''),
        array_values($knownClaims)
    ));
    $claimGroundedText = article_draft_claim_grounded_text($draft, $knownClaims);
    $contentText = implode(' ', article_draft_main_content_texts($draft));
    $titleTokens = article_title_normalized_tokens($title);
    // Claims and article may use different languages. Ground lexical support in
    // Polish passages that explicitly cite valid claim_ids, not in unattributed copy.
    $claimTokens = article_title_normalized_tokens($claimText . ' ' . $claimGroundedText);
    $contentTokens = article_title_normalized_tokens($contentText);
    $supportedByClaims = array_values(array_intersect($titleTokens, $claimTokens));
    $supportedByContent = array_values(array_intersect($titleTokens, $contentTokens));
    $allowedClaims = array_values(array_map(static fn (array $claim): array => [
        'claim_id' => (string) ($claim['claim_id'] ?? ''),
        'claim' => (string) ($claim['claim'] ?? ''),
        'source_ids' => array_values((array) ($claim['source_ids'] ?? [])),
    ], $knownClaims));
    $requiredMatches = max(2, (int) ceil(count($titleTokens) * 0.45));
    if ($titleTokens === []
        || count($supportedByClaims) < min(2, $requiredMatches)
        || count($supportedByContent) < $requiredMatches) {
        throw new ArticleTitleRepairException([
            'code' => 'unsupported_title_promise',
            'repair_scope' => 'titles',
            'message' => 'Wybrany tytuł obiecuje więcej, niż uzasadniają fakty i treść artykułu.',
            'old_title' => $title,
            'unsupported_elements' => array_values(array_diff($titleTokens, array_unique([...$supportedByClaims, ...$supportedByContent]))),
            'supported_title_tokens' => $supportedByContent,
            'allowed_claims' => $allowedClaims,
            'allowed_claim_ids' => array_values(array_filter(array_column($allowedClaims, 'claim_id'))),
            'preserved_fields' => array_values(array_diff(array_keys($draft), [
                'title', 'title_variants', 'title_selection_reason', 'seo_title', 'seo_description',
            ])),
        ]);
    }
    foreach ($variants as $index => $variant) {
        $variantTokens = article_title_normalized_tokens((string)($variant['title'] ?? ''));
        $variantRequired = max(2, (int)ceil(count($variantTokens) * 0.30));
        if ($variantTokens === [] || count(array_intersect($variantTokens, $claimTokens)) < min(2, $variantRequired) || count(array_intersect($variantTokens, $contentTokens)) < $variantRequired) {
            throw new ArticleTitleRepairException([
                'code'=>'unsupported_title_promise','repair_scope'=>'titles',
                'message'=>"Wariant tytułu #" . ($index + 1) . ' obiecuje więcej, niż uzasadniają fakty i treść artykułu.',
                'old_title'=>$title,'unsupported_elements'=>array_values(array_diff($variantTokens, $claimTokens, $contentTokens)),
                'offending_variant_index'=>$index,'allowed_claims'=>$allowedClaims,'allowed_claim_ids'=>array_values(array_filter(array_column($allowedClaims,'claim_id'))),
                'preserved_fields'=>array_values(array_diff(array_keys($draft), ['title','title_variants','title_selection_reason','seo_title','seo_description'])),
            ]);
        }
    }
    $leadText = (string) (($draft['lead'] ?? [])['text'] ?? '');
    if ($leadText === '' && isset($draft['sections'][0]) && is_array($draft['sections'][0])) {
        $leadText = (string) ($draft['sections'][0]['body'] ?? '');
    }
    $leadTokens = article_title_normalized_tokens($leadText);
    if (array_intersect($titleTokens, $leadTokens) === []) {
        throw new InvalidArgumentException('Lead nie wspiera głównej obietnicy wybranego tytułu.');
    }
    $researchNormalized = mb_strtolower($claimText);
    foreach (['udowadnia', 'dowodzi', 'gwarantuje', 'na pewno', 'bez wątpienia', 'zawsze', 'nigdy', 'każdego człowieka'] as $strongClaim) {
        if (str_contains(mb_strtolower($title), $strongClaim)
            && !str_contains($researchNormalized, $strongClaim)) {
            throw new InvalidArgumentException('Tytuł zawiera twierdzenie mocniejsze niż źródła.');
        }
        foreach ($variants as $index => $variant) {
            if (str_contains(mb_strtolower((string)($variant['title'] ?? '')), $strongClaim) && !str_contains($researchNormalized, $strongClaim)) {
                throw new ArticleTitleRepairException(['code'=>'unsupported_title_promise','repair_scope'=>'titles','message'=>'Wariant tytułu #' . ($index + 1) . ' zawiera twierdzenie mocniejsze niż źródła.','old_title'=>$title,'unsupported_elements'=>[$strongClaim],'offending_variant_index'=>$index,'allowed_claims'=>$allowedClaims,'allowed_claim_ids'=>array_values(array_filter(array_column($allowedClaims,'claim_id'))),'preserved_fields'=>array_values(array_diff(array_keys($draft), ['title','title_variants','title_selection_reason','seo_title','seo_description']))]);
            }
        }
    }
    $normalizedTitle = mb_strtolower(rtrim($title, ".!? \t\n\r\0\x0B"));
    foreach (article_draft_main_content_texts($draft) as $sectionText) {
        $sentences = preg_split('/(?<=[.!?])\s+|\R+/u', $sectionText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($sentences as $sentence) {
            if (mb_strtolower(rtrim(trim($sentence), ".!? \t\n\r\0\x0B")) === $normalizedTitle) {
                throw new InvalidArgumentException('Lead i treść nie mogą mechanicznie powtarzać tytułu.');
            }
        }
    }
    return [
        'variant_count' => count($variants),
        'selected_score' => $selected[0]['score'],
        'highest_score' => $highestScore,
        'supported_title_tokens' => count($supportedByContent),
    ];
}

function article_title_repair_schema(): array
{
    return ['type' => 'object', 'additionalProperties' => false, 'properties' => [
        'title' => ['type' => 'string'],
        'title_variants' => ['type' => 'array', 'minItems' => 5, 'maxItems' => 8, 'items' => article_title_variant_schema()],
        'title_selection_reason' => ['type' => 'string'],
        'seo_title' => ['type' => 'string'],
        'seo_description' => ['type' => 'string'],
    ], 'required' => ['title', 'title_variants', 'title_selection_reason', 'seo_title', 'seo_description']];
}

function article_title_repair_input(array $operation, array $draft, array $diagnostics, int $attempt): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    return [
        'parent_operation_id' => (int) $operation['id'],
        'repair_scope' => 'titles',
        'attempt' => $attempt,
        'exact_rejection_reason' => (string) $diagnostics['message'],
        'current_title' => (string) ($draft['title'] ?? ''),
        'verified_claims' => $diagnostics['allowed_claims'] ?? [],
        'numbered_sources' => $input['numbered_sources'] ?? [],
        'actual_article_summary' => implode("\n", array_slice(article_draft_main_content_texts($draft), 0, 8)),
        'forbidden_unsupported_elements' => $diagnostics['unsupported_elements'] ?? [],
        'previous_feedback' => $diagnostics,
        'instructions' => [
            'Zmień wyłącznie tytuł, warianty, ich punktację i rationale, title_selection_reason oraz SEO tylko gdy powtarza tę samą niepopartą obietnicę.',
            'Nie dodawaj żadnego faktu spoza verified_claims. Zachowaj język polski i modalność: hipoteza ani korelacja nie mogą stać się dowodem.',
            'Tytuł ma być atrakcyjny, naturalny i budzić ciekawość, ale nie może sugerować niewykazanego skutku ani bez podstaw używać: przełom, rewolucja, zmienia wszystko, koniec problemu.',
            'Nie zwracaj leadu, sekcji, researchu ani planu ilustracji.',
        ],
    ];
}

function merge_article_title_repair(array $draft, array $repair): array
{
    $merged = $draft;
    foreach (['title', 'title_variants', 'title_selection_reason'] as $field) $merged[$field] = $repair[$field];
    $oldPromise = article_title_normalized_tokens((string) ($draft['title'] ?? ''));
    foreach (['seo_title', 'seo_description'] as $field) {
        $current = (string) ($draft[$field] ?? '');
        if (array_intersect($oldPromise, article_title_normalized_tokens($current)) !== []) {
            $merged[$field] = (string) ($repair[$field] ?? $current);
        }
    }
    return $merged;
}

function article_title_deterministic_fallback(array $draft, array $knownClaims): array
{
    $claim = trim((string) (($knownClaims[array_key_first($knownClaims)] ?? [])['claim'] ?? ''));
    $claim = preg_replace('/\s+/u', ' ', $claim) ?: 'Co naprawdę wynika ze zweryfikowanych danych w tym temacie';
    $base = mb_substr(rtrim($claim, '.!?'), 0, 96);
    if (mb_strlen($base) < 35) $base .= ': co naprawdę wiadomo z dostępnych źródeł';
    $titles = [$base, 'Co naprawdę pokazują dane? ' . $base, 'Nowe ustalenia pod lupą: ' . $base, 'Bez nadinterpretacji: ' . $base, 'Najważniejszy trop w badaniu: ' . $base];
    $titles = array_map(static fn(string $t): string => mb_substr($t, 0, 100), $titles);
    $variants = [];
    foreach ($titles as $i => $title) $variants[] = ['title'=>$title,'relevance_score'=>10-$i,'specificity_score'=>9-$i,'curiosity_score'=>8-$i,'naturalness_score'=>9,'click_potential_score'=>8-$i,'total_score'=>44-(4*$i),'selected'=>$i===0,'rationale'=>'Wariant opiera obietnicę wyłącznie na zweryfikowanym twierdzeniu i zachowuje jego modalność.'];
    return ['title'=>$titles[0],'title_variants'=>$variants,'title_selection_reason'=>'Bezpieczny wariant deterministyczny opiera się wyłącznie na pierwszym zweryfikowanym twierdzeniu.','seo_title'=>$titles[0],'seo_description'=>(string)($draft['seo_description'] ?? '')];
}

function build_article_title_strategy_fixture(string $selectedTitle): array
{
    $titles = [
        $selectedTitle,
        'Kontrolowany pomiar: co dokładnie wynika z dostępnych danych',
        'Laboratorium opisuje wynik, który porządkuje ograniczenia pomiaru',
        'Co kontrolowany pomiar mówi o znaczeniu zastosowanej metody?',
        'Wynik kontrolowanego pomiaru zmienia ocenę dostępnych danych',
    ];
    $totals = [46, 42, 40, 39, 38];
    $variants = [];
    foreach ($titles as $index => $title) {
        $total = $totals[$index];
        $scores = $index === 0
            ? [10, 9, 9, 9, 9]
            : [9, 8, 8, 8, $total - 33];
        $variants[] = [
            'title' => $title,
            'relevance_score' => $scores[0],
            'specificity_score' => $scores[1],
            'curiosity_score' => $scores[2],
            'naturalness_score' => $scores[3],
            'click_potential_score' => $scores[4],
            'total_score' => array_sum($scores),
            'selected' => $index === 0,
            'rationale' => $index === 0
                ? 'Najtrafniej łączy konkretny temat, stawkę i pokrycie w treści.'
                : 'Bezpieczny wariant alternatywny oparty na tym samym materiale.',
        ];
    }

    return [
        'title_variants' => $variants,
        'title_selection_reason' => 'Wybrany wariant jest konkretny, naturalny i najlepiej oddaje udokumentowaną obietnicę artykułu.',
    ];
}

function article_draft_reference_schema(array $sourceIds, array $claimIds): array
{
    return [
        'type' => 'object',
        'properties' => [
            'text' => ['type' => 'string'],
            'claim_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $claimIds],
            ],
            'source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
        ],
        'required' => ['text', 'claim_ids', 'source_ids'],
        'additionalProperties' => false,
    ];
}

function article_title_variant_schema(): array
{
    $score = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10];

    return [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'relevance_score' => $score,
            'specificity_score' => $score,
            'curiosity_score' => $score,
            'naturalness_score' => $score,
            'click_potential_score' => $score,
            'total_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50],
            'selected' => ['type' => 'boolean'],
            'rationale' => ['type' => 'string'],
        ],
        'required' => [
            'title',
            'relevance_score',
            'specificity_score',
            'curiosity_score',
            'naturalness_score',
            'click_potential_score',
            'total_score',
            'selected',
            'rationale',
        ],
        'additionalProperties' => false,
    ];
}

function article_draft_schema(array $sourceIds, array $claimIds, string $compositionMode, array $unknownIds = []): array
{
    $section = article_draft_reference_schema($sourceIds, $claimIds);
    $requiredSection = $section;
    $requiredSection['properties']['text']['minLength'] = 1;
    $requiredSection['properties']['text']['maxLength'] = 10000;
    $requiredInlineIllustrations = $compositionMode === 'problem_discovery_return' ? 4 : 3;
    $narrativeProperties = [];
    foreach ([
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ] as $key) {
        $narrativeProperties[$key] = $section;
    }

    return [
        'type' => 'object',
        'properties' => [
            'composition_mode' => ['type' => 'string', 'enum' => [$compositionMode]],
            'title' => ['type' => 'string'],
            'title_variants' => [
                'type' => 'array',
                'items' => article_title_variant_schema(),
                'minItems' => 5,
                'maxItems' => 8,
            ],
            'title_selection_reason' => ['type' => 'string'],
            'brief' => ['type' => 'string', 'minLength' => 80, 'maxLength' => 220],
            'lead' => $requiredSection,
            'why_important' => $requiredSection,
            'key_facts' => [
                'type' => 'array',
                'items' => $requiredSection,
                'minItems' => 3,
                'maxItems' => 3,
            ],
            'comparison_context' => $section,
            'unknowns' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'research_unknown_indexes' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'integer',
                                ...($unknownIds === [] ? [] : ['enum' => array_values($unknownIds)]),
                            ],
                        ],
                    ],
                    'required' => ['text', 'research_unknown_indexes'],
                    'additionalProperties' => false,
                ],
            ],
            'practical_takeaway' => $requiredSection,
            'seo_description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'category' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
            'image_alt' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 250],
            'illustration_plan' => article_illustration_plan_schema(
                $requiredInlineIllustrations,
                array_slice(
                    ARTICLE_IMAGE_ALWAYS_AVAILABLE_SECTION_IDS,
                    0,
                    $requiredInlineIllustrations
                )
            ),
            'used_source_ids' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $sourceIds],
            ],
            'narrative' => [
                'type' => 'object',
                'properties' => $narrativeProperties,
                'required' => array_keys($narrativeProperties),
                'additionalProperties' => false,
            ],
        ],
        'required' => [
            'composition_mode',
            'title',
            'title_variants',
            'title_selection_reason',
            'brief',
            'lead',
            'why_important',
            'key_facts',
            'comparison_context',
            'unknowns',
            'practical_takeaway',
            'seo_description',
            'category',
            'image_alt',
            'illustration_plan',
            'used_source_ids',
            'narrative',
        ],
        'additionalProperties' => false,
    ];
}

/**
 * The NarrativePlan is the source of truth for image acquisition.  Keep the
 * provider response schema as strict as the later projection assertion, so a
 * draft that changes an image brief is rejected before it can consume a
 * transport response or reach the local persistence path.
 */
function article_draft_schema_lock_narrative_visual_projection(array $schema, array $narrativePlan): array
{
    $contract = narrative_plan_draft_illustration_contract($narrativePlan);
    $expectedPlan = (array) ($contract['illustration_plan'] ?? []);
    $hero = (array) ($expectedPlan['hero'] ?? []);
    $inline = array_values((array) ($expectedPlan['inline'] ?? []));
    $properties =& $schema['properties']['illustration_plan']['properties'];

    $lockSlot = static function (array &$slotSchema, array $expectedSlots, bool $required): void {
        if ($expectedSlots === []) {
            if ($required) {
                throw new RuntimeException('VisualPlan nie zawiera wymaganego slotu do zablokowania w schemacie szkicu.');
            }
            return;
        }
        foreach ([
            'role', 'section_id', 'visual_intent', 'expected_content', 'alt', 'caption', 'layout', 'status',
        ] as $field) {
            $values = array_values(array_unique(array_map(
                static fn (array $slot): string => (string) ($slot[$field] ?? ''),
                $expectedSlots
            )));
            $slotSchema['properties'][$field]['enum'] = $values;
        }
        $queries = [];
        foreach ($expectedSlots as $expectedSlot) {
            foreach ((array) ($expectedSlot['search_queries'] ?? []) as $query) {
                $query = (string) $query;
                if ($query !== '') {
                    $queries[$query] = true;
                }
            }
        }
        if ($queries === []) {
            throw new RuntimeException('VisualPlan slot nie zawiera zapytań do zablokowania w schemacie szkicu.');
        }
        $slotSchema['properties']['search_queries']['items']['enum'] = array_keys($queries);
    };

    $lockSlot($properties['hero'], [$hero], true);
    $lockSlot($properties['inline']['items'], $inline, false);

    return $schema;
}

function find_research_package(int $packageId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.*, operations.input_json AS research_input_json
         FROM research_packages AS packages
         INNER JOIN generation_operations AS operations
            ON operations.id = packages.generation_operation_id
         WHERE packages.id = :id'
    );
    $statement->execute([':id' => $packageId]);
    $package = $statement->fetch();

    return is_array($package) ? $package : null;
}

function list_approved_research_packages(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT packages.*, topics.title AS topic_title
         FROM research_packages AS packages
         INNER JOIN editorial_topics AS topics ON topics.id = packages.topic_id
         WHERE packages.status = "approved"
         ORDER BY packages.approved_at DESC, packages.id DESC
         LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function approve_research_package(int $packageId, string $actor = 'system', string $reason = 'Policy accepted'): void
{
    $package = find_research_package($packageId);
    if ($package === null || $package['status'] !== 'completed') {
        throw new RuntimeException('Do zatwierdzenia wymagana jest ukończona paczka researchowa.');
    }
    $validation = json_decode((string) $package['validation_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = json_decode((string) $package['package_json'], true, 128, JSON_THROW_ON_ERROR);
    if (($validation['valid'] ?? false) !== true || ($research['recommendation']['decision'] ?? '') !== 'continue') {
        throw new RuntimeException('Nie można zatwierdzić researchu odrzuconego lub niepoprawnego.');
    }
    bueno_database()->prepare(
        'UPDATE research_packages
         SET status = "approved", approved_at = CURRENT_TIMESTAMP,
             approval_actor = :actor, approval_reason = :reason,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id AND status = "completed"'
    )->execute([':id' => $packageId, ':actor' => mb_substr($actor, 0, 100), ':reason' => mb_substr($reason, 0, 1000)]);
    $policy = json_decode((string) ($package['policy_json'] ?? '{}'), true) ?: [];
    bueno_database()->prepare('INSERT INTO research_policy_audit (topic_id,research_package_id,decision,reason,policy_json,actor) VALUES (:topic,:package,"approved",:reason,:policy,:actor)')
        ->execute([':topic'=>(int)$package['topic_id'], ':package'=>$packageId, ':reason'=>$reason, ':policy'=>generation_json($policy), ':actor'=>$actor]);
}

function resolve_article_composition_mode(array $research, string $requestedMode): string
{
    if (!in_array($requestedMode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Nieprawidłowy tryb kompozycji szkicu.');
    }
    if (
        $requestedMode === 'problem_discovery_return'
        && (array) ($research['comparisons'] ?? []) === []
        && count((array) ($research['claims'] ?? [])) < 2
    ) {
        return 'informational';
    }

    return $requestedMode;
}

function prepare_article_draft_operation(int $researchPackageId, string $compositionMode, ?array $narrativePlan = null): int
{
    $package = find_research_package($researchPackageId);
    if ($package === null || $package['status'] !== 'approved') {
        throw new RuntimeException('Szkic można utworzyć wyłącznie z zatwierdzonej paczki researchowej.');
    }
    $research = json_decode((string) $package['package_json'], true, 128, JSON_THROW_ON_ERROR);
    $researchInput = json_decode((string) $package['research_input_json'], true, 128, JSON_THROW_ON_ERROR);
    if (($research['recommendation']['decision'] ?? '') !== 'continue') {
        throw new RuntimeException('Odrzucona paczka researchowa nie może być podstawą szkicu.');
    }
    $compositionMode = resolve_article_composition_mode($research, $compositionMode);
    $lengthPolicy = article_draft_length_policy($compositionMode);
    $planPayload = $narrativePlan !== null ? narrative_plan_editorial_payload($narrativePlan) : [];
    $planTargetLength = (int) ($planPayload['target_length'] ?? 6500);
    $visualPlan = $narrativePlan !== null
        ? (json_decode((string) ($narrativePlan['visual_plan_json'] ?? '{}'), true) ?: [])
        : [];
    $planInlineSlots = array_values((array) ($visualPlan['inline_slots'] ?? []));
    $requiredInlineIllustrations = $narrativePlan !== null
        ? count($planInlineSlots)
        : ($planTargetLength <= 7000 ? 2 : 3);
    $requiredInlineSectionIds = $narrativePlan !== null
        ? array_values(array_map(static fn (array $slot): string => (string) ($slot['section_anchor'] ?? ''), $planInlineSlots))
        : array_slice(['lead', 'why-important', 'fact-1'], 0, $requiredInlineIllustrations);
    $sourceIds = array_values(array_map(
        static fn (array $source): string => (string) $source['source_id'],
        (array) ($researchInput['numbered_sources'] ?? [])
    ));
    $claimIds = array_values(array_map(
        static fn (array $claim): string => (string) $claim['claim_id'],
        (array) ($research['claims'] ?? [])
    ));
    if ($sourceIds === [] || $claimIds === []) {
        throw new RuntimeException('Zatwierdzony research nie zawiera źródeł i twierdzeń potrzebnych do szkicu.');
    }
    $unknownIds = array_keys(array_values((array) ($research['unknowns'] ?? [])));
    // A persisted NarrativePlan is a hard pre-transport contract for visual slots.
    $schema = article_draft_schema($sourceIds, $claimIds, $compositionMode, $unknownIds);
    if ($narrativePlan !== null) {
        $schema = article_draft_schema_from_plan($narrativePlan, $sourceIds, $claimIds, $compositionMode, $unknownIds);
        $schema = article_draft_schema_lock_narrative_visual_projection($schema, $narrativePlan);
    }
    $input = [
        'research_package_id' => $researchPackageId,
        'workflow_version' => 2,
        'composition_mode' => $compositionMode,
        'output_language' => [
            'code' => 'pl-PL',
            'name' => 'język polski',
            'rule' => 'Cała treść przeznaczona dla czytelnika, w tym tytuł, lead, nagłówki opisowe, alt i podpisy ilustracji, musi być napisana naturalnym językiem polskim.',
        ],
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'allowed_research_unknowns' => array_map(
            static fn (mixed $text, int $index): array => ['id' => $index, 'text' => (string) $text],
            array_values((array) ($research['unknowns'] ?? [])),
            array_keys(array_values((array) ($research['unknowns'] ?? [])))
        ),
        'length_requirements' => [
            ...$lengthPolicy,
            'target_characters' => '6000–8500',
            'section_character_budget' => $lengthPolicy['complex']
                ? [
                    'lead' => '600–850',
                    'why_important' => '700–1000',
                    'key_facts' => 'dokładnie 3 fakty po 550–850 znaków każdy',
                    'comparison_context' => '500–900 lub pusty wyłącznie wtedy, gdy research nie daje podstawy',
                    'unknowns_total' => '300–600',
                    'practical_takeaway' => '500–800',
                    'narrative_total' => '1200–2200 rozłożone pomiędzy siedem pól',
                ]
                : [
                    'lead' => '600–800',
                    'why_important' => '700–900',
                    'key_facts' => 'dokładnie 3 fakty po 550–750 znaków każdy',
                    'comparison_context' => '500–800 lub pusty wyłącznie wtedy, gdy research nie daje podstawy',
                    'unknowns_total' => '300–500',
                    'practical_takeaway' => '500–700',
                    'narrative_total' => '0; wszystkie pola narrative pozostają puste',
                ],
            'required_inline_illustrations' => $requiredInlineIllustrations,
            'required_inline_section_ids' => $requiredInlineSectionIds,
            'measurement' => 'Liczba znaków tekstu głównego: lead, znaczenie, fakty, kontekst porównawczy, niewiadome, wniosek praktyczny i — jeśli używana — narracja. Bez tytułu, briefu, SEO, kategorii, altu i metadanych.',
            'quality' => 'Osiągnij zakres konkretnym, logicznie uporządkowanym wyjaśnieniem. Nie używaj powtórzeń, lania wody ani sztucznego wydłużania.',
            'final_check' => 'Minimum jest twardym warunkiem walidacji. Przed zwróceniem JSON zsumuj orientacyjnie długości wszystkich pól tekstu głównego i rozwiń merytorycznie zbyt krótkie sekcje. Nie zwracaj szkicu poniżej minimum.',
            'complex_guidance' => $lengthPolicy['complex']
                ? '5000 znaków to wyłącznie dolna granica. Preferuj 6000–8500 znaków, gdy research A+B+C dostarcza wartościowego materiału; nie dodawaj filleru.'
                : 'Nie rozciągaj prostego tematu ponad ilość wartościowego materiału, ale przedstaw go kompletnie w wymaganym zakresie.',
        ],
        'editorial_requirements' => [
            'Napisz całość naturalnym językiem polskim (pl-PL), niezależnie od języka materiałów źródłowych. Nie pozostawiaj angielskiego tytułu ani angielskich akapitów.',
            'Nie kopiuj zdań ze źródeł. Parafrazuj, zachowując znaczenie i przypisania.',
            'Nie dodawaj testów, cytatów, plotek ani osobistych doświadczeń, których nie ma w researchu.',
            'Najpierw przygotuj od 5 do 8 wyraźnie różnych, naturalnych wariantów tytułu. Każdy oceń od 0 do 10 za trafność, konkretność, ciekawość, naturalność polszczyzny i potencjał kliknięcia; total_score ma być dokładną sumą tych pięciu ocen.',
            'W title_variants ustaw selected=true dokładnie przy jednym, najwyżej ocenionym wariancie. Pole title musi być identyczne z tym wariantem, a title_selection_reason krótko wyjaśnia wybór i jego pokrycie w artykule.',
            'Preferuj konkretną stawkę, zaskakujący fakt, konflikt, zmianę, konsekwencję dla odbiorcy lub rozsądną lukę informacyjną. Tytuł ma obiecywać wyłącznie to, co artykuł rzeczywiście wyjaśnia.',
            'Tytuł powinien mieć zwykle 45–95 znaków i jasno zawierać najważniejszy temat lub byt dla SEO. Nie urywaj go dwukropkiem, myślnikiem ani wielokropkiem.',
            'Nie używaj ALL CAPS, nadmiaru wykrzykników lub znaków zapytania, pustych formuł „Nie uwierzysz”, „Musisz to zobaczyć”, „Internet oszalał”, sztucznego dramatyzowania ani twierdzeń mocniejszych niż źródła.',
            'Pytanie w tytule jest dopuszczalne tylko wtedy, gdy tekst udziela na nie konkretnej odpowiedzi.',
            'Lead od razu odpowiada, co się wydarzyło; nie ukrywaj podstawowej informacji dla napięcia.',
            'Lead i hero mają wspierać obietnicę wybranego tytułu oraz pokazywać ten sam główny temat, ale nie mogą mechanicznie powtarzać całego tytułu.',
            'Brief to jedno lub dwa naturalne, zakończone zdania pod tytułem, od 80 do 220 znaków. Ma zaciekawić i ustawić temat, ale nie może być pierwszym zdaniem leadu, streszczeniem całego artykułu ani zdradzać wszystkich wniosków.',
            'Brief nie może powtarzać tytułu ani żadnego zdania z treści głównej.',
            'Nie używaj sensacyjnych obietnic nieobecnych w paczce.',
            'Każda sekcja faktograficzna wskazuje claim_ids i source_ids z paczki.',
            'used_source_ids zawiera dokładnie wszystkie źródła wykorzystane w szkicu.',
            'Każdy element unknowns musi mieć niepustą tablicę research_unknown_indexes. Używaj wyłącznie liczbowych id z allowed_research_unknowns; nie numeruj niewiadomych samodzielnie.',
        ],
        'illustration_requirements' => [
            "Zaplanuj osobną grafikę hero dla całego tematu oraz dokładnie {$requiredInlineIllustrations} ilustracje inline.",
            'Docelowa długość tekstu i liczba ilustracji dają około jedną ilustrację inline na 950–1050 znaków. Hero jest osobne i nie zastępuje ilustracji inline.',
            'Dla hero ustaw dokładnie role=hero, section_id=article, layout=full i status=planned.',
            'Hero ma działać jak atrakcyjna okładka: preferuj efektowną fotografię dokumentalną, pejzaż, obiekt lub scenę czytelną w miniaturze, w orientacji poziomej i z jednym wyraźnym motywem.',
            'Dla hero nie szukaj wykresu, schematu, infografiki, slajdu, mapy z legendą ani obrazu zawierającego dużo tekstu. Materiał objaśniający mechanizm należy do ilustracji inline.',
            'Zapytania hero twórz po angielsku, konkretnie i fotograficznie, np. z określeniami documentary photograph, aerial view, natural scene lub close-up; nie używaj ogólnika popular science.',
            'Dla każdej ilustracji śródtekstowej ustaw role=inline i status=planned.',
            'Użyj dokładnie wymaganych identyfikatorów z required_inline_section_ids, po jednym dla każdej ilustracji inline i bez powtórzeń.',
            'Każdą ilustrację inline przypisz do konkretnej semantycznej sekcji tekstu.',
            'Zwróć tylko intencję, oczekiwaną zawartość, zapytania, alt i podpis. Pola źródła, autora i licencji pozostaw puste, a status ustaw na planned.',
            'Nie wymyślaj adresów URL, autorów ani licencji. Wybór jest możliwy dopiero z rzeczywistych wyników Wikimedia Commons lub Openverse.',
        ],
        'composition_requirements' => $compositionMode === 'problem_discovery_return'
            ? [
                'Wypełnij siedem pól narrative w kolejności: pytanie, dążenie, temat B, ślepa uliczka, powrót do A, domknięcie B, odpowiedź i puenta.',
                'Pytanie, temat B oraz puenta muszą wynikać z przypisanych twierdzeń i źródeł.',
                'Temat B ma pomagać zrozumieć A, nie dominować tekstu i zostać domknięty.',
            'Rozwiń source-backed A+B+C jako jeden spójny artykuł; minimum 5000 znaków nie jest docelową długością ani powodem do skracania pełnego wyjaśnienia.',
            ]
            : [
                'Przekaż najważniejszą odpowiedź od razu, a dalszą treść uporządkuj bez powtórzeń i pustych zdań.',
                'Wszystkie pola narrative pozostaw puste: text jako pusty string, claim_ids i source_ids jako puste tablice.',
            ],
    ];
    // Add narrative plan to input if provided
    if ($narrativePlan !== null) {
        $input['narrative_plan'] = $narrativePlan;
        $input['editorial_plan'] = $planPayload;
        $input['dynamic_sections_contract'] = [
            'output' => 'Write sections[] in exactly the NarrativePlan order. Do not emit legacy lead/why_important/key_facts/comparison_context/unknowns/practical_takeaway/narrative fields.',
            'fields' => ['section_id','topic_role','content_type','heading','body','visual_slot_id','claim_ids','source_ids'],
            'content_types' => ['prose','explainer','curiosity','history','comparison','short_callout','unknowns','takeaway'],
            'rules' => [
                'A remains the primary thread; B explains or contextualizes it; C is a distinct source-backed curiosity/history angle when selected.',
                'Preserve the planned section order and IDs. Use normal headings for prose, explainer, history, and comparison where useful.',
                'Do not turn every section into a callout. prose/explainer/history are normal article flow; short_callout stays short.',
            ],
        ];
        // This belongs to the persisted operation identity, not only to the
        // response schema: a failed draft prepared before a stricter
        // VisualPlan contract must not be selected as an equivalent retry.
        $input['draft_visual_plan_contract_version'] = ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION;
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $versionStatement = $database->prepare(
            'SELECT COALESCE(MAX(version_number), 0) + 1
             FROM article_draft_versions WHERE research_package_id = :package_id'
        );
        $versionStatement->execute([':package_id' => $researchPackageId]);
        $versionNumber = (int) $versionStatement->fetchColumn();
        $operationId = prepare_generation_operation(
            'article_draft',
            $input,
            $schema,
            (int) $package['post_id'],
            (int) $package['topic_id']
        );
        $database->prepare(
            'INSERT INTO article_draft_versions (
                research_package_id, topic_id, post_id, generation_operation_id,
                version_number, composition_mode, execution_mode
             ) VALUES (
                :package_id, :topic_id, :post_id, :operation_id,
                :version_number, :composition_mode, :execution_mode
             )'
        )->execute([
            ':package_id' => $researchPackageId,
            ':topic_id' => (int) $package['topic_id'],
            ':post_id' => (int) $package['post_id'],
            ':operation_id' => $operationId,
            ':version_number' => $versionNumber,
            ':composition_mode' => $compositionMode,
            ':execution_mode' => generation_mode(),
        ]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        throw $exception;
    }

    return $operationId;
}

/** Creates one idempotent, inactive draft version for a concrete QC repair cycle. */
function prepare_article_qc_repair_operation(int $sourceDraftId, array $qualityCheck, array $repairDecision, int $attempt): int
{
    if ($attempt < 1 || $attempt > 2) throw new InvalidArgumentException('Automatyczna korekta QC ma limit dwóch prób.');
    $source = find_article_draft_by_id($sourceDraftId);
    if (is_array($source) && (string) $source['status'] === 'frozen') {
        throw new RuntimeException('Core text jest locked; pełny rewrite i fresh conservative rewrite są zabronione.');
    }
    if (!is_array($source) || (string) $source['status'] !== 'completed') {
        throw new RuntimeException('Korekta QC wymaga kompletnej wersji źródłowej.');
    }
    $sourceJson = json_decode((string) $source['draft_json'], true) ?: [];
    if ($sourceJson === [] || article_draft_main_content_length($sourceJson) <= 0) {
        throw new RuntimeException('Korekta QC nie może bazować na pustej wersji.');
    }
    $sourceOperation = find_generation_operation((int) ($source['generation_operation_id'] ?? 0));
    $sourceOperationInput = is_array($sourceOperation)
        ? (json_decode((string) ($sourceOperation['input_json'] ?? '{}'), true) ?: [])
        : [];
    $narrativePlan = is_array($sourceOperationInput['narrative_plan'] ?? null)
        ? $sourceOperationInput['narrative_plan']
        : null;
    $package = find_research_package((int) $source['research_package_id']);
    if (!is_array($package) || (string) $package['status'] !== 'approved') {
        throw new RuntimeException('Korekta QC wymaga nadal zatwierdzonego researchu.');
    }
    $research = json_decode((string) $package['package_json'], true) ?: [];
    $researchInput = json_decode((string) $package['research_input_json'], true) ?: [];
    $strategy = $attempt === 1 ? 'targeted_repair' : 'fresh_conservative_rewrite';
    $compositionMode = $attempt === 1 ? (string) $source['composition_mode'] : 'informational';
    if ($strategy === 'fresh_conservative_rewrite') {
        $research['claims'] = array_values(array_filter(
            (array) ($research['claims'] ?? []),
            static fn (array $claim): bool => (string) ($claim['confidence'] ?? '') === 'high'
        ));
        $research['unknowns'] = [];
        $research['comparisons'] = [];
        if ($research['claims'] === []) {
            throw new RuntimeException('Konserwatywny rewrite wymaga co najmniej jednego twierdzenia o wysokiej pewności.');
        }
    }
    $checkId = (int) ($qualityCheck['id'] ?? 0);
    $existing = bueno_database()->prepare(
        'SELECT drafts.generation_operation_id, operations.input_json
         FROM article_draft_versions drafts
         INNER JOIN generation_operations operations ON operations.id = drafts.generation_operation_id
         WHERE drafts.parent_version_id = :parent AND drafts.change_source = "auto_qc_repair"
         ORDER BY drafts.id DESC'
    );
    $existing->execute([':parent' => $sourceDraftId]);
    foreach ($existing->fetchAll() as $candidate) {
        $candidateInput = json_decode((string) $candidate['input_json'], true) ?: [];
        if ((int) ($candidateInput['qc_auto_repair']['quality_check_id'] ?? 0) === $checkId
            && (int) ($candidateInput['qc_auto_repair']['attempt'] ?? 0) === $attempt
            && (string) ($candidateInput['qc_auto_repair']['strategy'] ?? 'targeted_repair') === $strategy
            && (int) ($candidateInput['qc_repair_contract_version'] ?? 0) === ARTICLE_DRAFT_QC_REPAIR_CONTRACT_VERSION) {
            return (int) $candidate['generation_operation_id'];
        }
    }

    $sourceIds = array_values(array_filter(array_map(
        static fn (array $item): string => (string) ($item['source_id'] ?? ''),
        (array) ($researchInput['numbered_sources'] ?? [])
    )));
    $claimIds = array_values(array_filter(array_map(
        static fn (array $item): string => (string) ($item['claim_id'] ?? ''),
        (array) ($research['claims'] ?? [])
    )));
    $allowedUnknowns = array_map(
        static fn (mixed $text, int $id): array => ['id' => $id, 'text' => (string) $text],
        array_values((array) ($research['unknowns'] ?? [])),
        array_keys(array_values((array) ($research['unknowns'] ?? [])))
    );
    $repairLengthRequirements = article_draft_repair_length_requirements($compositionMode);
    $schema = article_draft_schema($sourceIds, $claimIds, $compositionMode, array_column($allowedUnknowns, 'id'));
    if ($narrativePlan !== null) {
        $schema = article_draft_schema_from_plan($narrativePlan, $sourceIds, $claimIds, $compositionMode, array_column($allowedUnknowns, 'id'));
        $schema = article_draft_schema_lock_narrative_visual_projection($schema, $narrativePlan);
    }
    $input = [
        'qc_repair_contract_version' => ARTICLE_DRAFT_QC_REPAIR_CONTRACT_VERSION,
        'revision_of_draft_version_id' => $sourceDraftId,
        'composition_mode' => $compositionMode,
        'output_language' => [
            'code' => 'pl-PL',
            'rule' => 'Cała treść czytelnicza, tytuły, SEO, alt i podpisy muszą być napisane naturalnym językiem polskim.',
        ],
        'research_package' => $research,
        'numbered_sources' => $researchInput['numbered_sources'] ?? [],
        'allowed_research_unknowns' => $allowedUnknowns,
        'length_requirements' => $repairLengthRequirements,
        'qc_auto_repair' => [
            'quality_check_id' => $checkId,
            'attempt' => $attempt,
            'strategy' => $strategy,
            'categories' => array_values((array) ($repairDecision['categories'] ?? [])),
            'instructions' => array_values((array) ($repairDecision['feedback'] ?? [])),
        ],
        'immutable_requirements' => [
            'Używaj wyłącznie zatwierdzonego researchu, source_ids i claim_ids z wejścia.',
            'Nie wymyślaj cytatów, testów ani faktów. Usuń twierdzenie, jeśli nie można go podeprzeć.',
            'Nie osłabiaj blokad deterministycznych ani oznaczeń ryzyka.',
            'Zwróć kompletny szkic; nie pozostawiaj pustych pól wymaganych przez schemat.',
        ],
        'revision_instruction' => $strategy === 'targeted_repair'
            ? 'Precyzyjnie popraw istniejącą wersję według pełnej listy uwag QC. Zachowaj wszystkie niezwiązane z uwagami, poprawne części core article; nie dopisuj pełnego rewrite. Zwróć cały szkic do ponownej pełnej walidacji i przed odpowiedzią sprawdź łączną długość main_content według length_requirements.'
            : 'Napisz od zera nowy, prostszy i konserwatywny szkic informational. Nie kopiuj struktury ani sformułowań poprzedniej wersji. Używaj wyłącznie twierdzeń confidence=high. Nie używaj cytatów dosłownych ani cudzysłowów; bezpiecznie parafrazuj fakty. Pomiń opcjonalne, słabo wsparte twierdzenia, porównania i niewiadome. Utrzymaj tekst możliwie blisko twardego minimum długości, ale spełnij pełny schemat i walidację.',
    ];
    if ($narrativePlan !== null) {
        $input['narrative_plan'] = $narrativePlan;
        $input['draft_visual_plan_contract_version'] = ARTICLE_DRAFT_VISUAL_PLAN_CONTRACT_VERSION;
    }
    if ($strategy === 'targeted_repair') {
        $input['current_version'] = $sourceJson;
        $categories = array_values((array) ($repairDecision['categories'] ?? []));
        if (array_intersect($categories, ['completeness', 'structure', 'seo']) !== []) {
            $hasTopicB = (array) ($research['comparisons'] ?? []) !== [] || count((array) ($research['claims'] ?? [])) > 1;
            $hasTopicC = count((array) ($research['claims'] ?? [])) > 2;
            $input['repair_router_contract'] = repair_router_expansion_plan($hasTopicB, $hasTopicC);
            $input['repair_router_contract']['rule'] = 'Każde B/C musi wynikać z zatwierdzonych claim_ids i source_ids; brak materiału oznacza powrót do researchu, nigdy filler.';
        }
    } else {
        $input['fresh_rewrite_contract'] = [
            'from_approved_research_only' => true,
            'discard_previous_draft_text' => true,
            'composition_mode' => 'informational',
            'allowed_confidence' => ['high'],
            'direct_quotes_allowed' => false,
            'optional_weak_claims_allowed' => false,
            'target_main_content_characters' => '6000-7000',
        ];
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $number = $database->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM article_draft_versions WHERE research_package_id = :package');
        $number->execute([':package' => (int) $source['research_package_id']]);
        $operationId = prepare_generation_operation(
            'article_draft',
            $input,
            $schema,
            (int) $source['post_id'],
            (int) $source['topic_id']
        );
        $database->prepare(
            'INSERT INTO article_draft_versions (
                research_package_id, topic_id, post_id, generation_operation_id, version_number,
                composition_mode, execution_mode, parent_version_id, change_source, repair_strategy, is_active
             ) VALUES (:package, :topic, :post, :operation, :version, :mode, :execution, :parent, "auto_qc_repair", :strategy, 0)'
        )->execute([
            ':package' => (int) $source['research_package_id'], ':topic' => (int) $source['topic_id'],
            ':post' => (int) $source['post_id'], ':operation' => $operationId, ':version' => (int) $number->fetchColumn(),
            ':mode' => $compositionMode, ':execution' => generation_mode(), ':parent' => $sourceDraftId,
            ':strategy' => $strategy,
        ]);
        $database->commit();
        return $operationId;
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function find_article_draft_by_id(int $draftId): ?array
{
    $statement = bueno_database()->prepare('SELECT * FROM article_draft_versions WHERE id = :id');
    $statement->execute([':id' => $draftId]);
    $draft = $statement->fetch();
    return is_array($draft) ? $draft : null;
}

/** Activates only a fully validated, non-empty auto-repair; the prior version remains active on every failure. */
function activate_completed_article_qc_repair(int $draftVersionId): void
{
    $draft = find_article_draft_by_id($draftVersionId);
    $json = is_array($draft) ? (json_decode((string) $draft['draft_json'], true) ?: []) : [];
    $validation = is_array($draft) ? (json_decode((string) $draft['validation_json'], true) ?: []) : [];
    $mode = is_array($draft) ? (string) ($draft['composition_mode'] ?? '') : '';
    $length = article_draft_main_content_length($json);
    $policy = in_array($mode, ARTICLE_COMPOSITION_MODES, true) ? article_draft_length_policy($mode) : null;
    if (!is_array($draft) || (string) $draft['status'] !== 'completed' || ($validation['valid'] ?? false) !== true
        || $json === [] || $length <= 0 || $policy === null
        || $length < $policy['minimum_characters'] || $length > $policy['maximum_characters']) {
        throw new RuntimeException('Nie można aktywować pustej lub niezwalidowanej korekty QC.');
    }
    $database = bueno_database();
    $database->beginTransaction();
    try {
        $database->prepare('UPDATE article_draft_versions SET is_active = 0 WHERE post_id = :post')->execute([':post' => (int) $draft['post_id']]);
        $database->prepare('UPDATE article_draft_versions SET is_active = 1 WHERE id = :id')->execute([':id' => $draftVersionId]);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

function article_draft_assert_references(
    array $section,
    array $knownClaims,
    array $knownSources,
    string $path,
    bool $allowEmpty = false,
    bool $requireClaim = true
): array {
    $text = trim((string) ($section['text'] ?? ''));
    $claimIds = array_values(array_unique((array) ($section['claim_ids'] ?? [])));
    $sourceIds = array_values(array_unique((array) ($section['source_ids'] ?? [])));
    if ($text === '') {
        if (!$allowEmpty || $claimIds !== [] || $sourceIds !== []) {
            throw new InvalidArgumentException("{$path} nie może być pusty.");
        }
        return [];
    }
    if ($sourceIds === []) {
        throw new InvalidArgumentException("{$path} nie wskazuje źródła.");
    }
    if ($requireClaim && $claimIds === []) {
        throw new InvalidArgumentException("{$path} nie wskazuje twierdzenia z researchu.");
    }
    foreach ($claimIds as $claimId) {
        if (!is_string($claimId) || !isset($knownClaims[$claimId])) {
            throw new InvalidArgumentException("{$path} wskazuje nieznane twierdzenie.");
        }
    }
    foreach ($sourceIds as $sourceId) {
        if (!is_string($sourceId) || !isset($knownSources[$sourceId])) {
            throw new InvalidArgumentException("{$path} wskazuje nieznane źródło.");
        }
    }
    if ($claimIds !== []) {
        $claimSources = [];
        foreach ($claimIds as $claimId) {
            foreach ((array) $knownClaims[$claimId]['source_ids'] as $sourceId) {
                $claimSources[$sourceId] = true;
            }
        }
        foreach ($sourceIds as $sourceId) {
            if (!isset($claimSources[$sourceId])) {
                throw new InvalidArgumentException("{$path} przypisuje twierdzenie do niewłaściwego źródła.");
            }
        }
    }

    return $sourceIds;
}

function article_draft_assert_not_copied(string $text, array $knownSources, string $path): void
{
    $normalized = research_normalize_evidence($text);
    if (mb_strlen($normalized) < 120) {
        return;
    }
    foreach ($knownSources as $source) {
        $material = research_normalize_evidence((string) $source['material']);
        if ($material !== '' && str_contains($material, $normalized)) {
            throw new InvalidArgumentException("{$path} kopiuje długi fragment materiału źródłowego.");
        }
    }
}

/** P02 keeps text in the legacy draft contract while making every planned image immutable. */
function article_draft_assert_narrative_visual_projection(array $operation, array $draft): void
{
    $input = json_decode((string) ($operation['input_json'] ?? '{}'), true);
    $narrativePlan = (array) ($input['narrative_plan'] ?? []);
    if ($narrativePlan === []) {
        return;
    }
    $contract = narrative_plan_draft_illustration_contract($narrativePlan);
    $expected = (array) ($contract['illustration_plan'] ?? []);
    $actual = (array) ($draft['illustration_plan'] ?? []);
    foreach (['hero', 'inline'] as $kind) {
        $expectedSlots = $kind === 'hero' ? [(array) ($expected['hero'] ?? [])] : (array) ($expected['inline'] ?? []);
        $actualSlots = $kind === 'hero' ? [(array) ($actual['hero'] ?? [])] : (array) ($actual['inline'] ?? []);
        if (count($expectedSlots) !== count($actualSlots)) {
            throw new InvalidArgumentException('Plan ilustracji szkicu nie zachowuje liczby slotów VisualPlan.');
        }
        foreach ($expectedSlots as $index => $expectedSlot) {
            $actualSlot = (array) ($actualSlots[$index] ?? []);
            foreach (['role', 'section_id', 'visual_intent', 'search_queries', 'expected_content', 'alt', 'caption', 'layout', 'status', 'slot_id', 'must_be_direct', 'acceptable_related', 'search_queries_related'] as $field) {
                if (($actualSlot[$field] ?? null) !== ($expectedSlot[$field] ?? null)) {
                    throw new InvalidArgumentException('Plan ilustracji szkicu odbiega od utrwalonego VisualPlan: ' . $kind . '[' . $index . '].' . $field . '.');
                }
            }
        }
    }
}

/** Return canonical NarrativePlan sections from the persisted runtime operation shape. */
function article_draft_narrative_sections(array $operation): array
{
    try {
        $input = json_decode((string) ($operation['input_json'] ?? '{}'), true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new InvalidArgumentException('narrative_sections_contract_error: input_json nie jest poprawnym JSON-em.', 0, $exception);
    }
    if (!is_array($input)) {
        throw new InvalidArgumentException('narrative_sections_contract_error: input_json ma nieprawidłowy typ.');
    }

    if (array_key_exists('sections_json', (array) ($input['narrative_plan'] ?? []))) {
        $stored = $input['narrative_plan']['sections_json'];
        if (is_string($stored)) {
            try {
                $stored = json_decode($stored, true, 128, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new InvalidArgumentException('narrative_sections_contract_error: narrative_plan.sections_json nie jest poprawnym JSON-em.', 0, $exception);
            }
        }
        if (!is_array($stored) || $stored === [] || !array_is_list($stored)) {
            throw new InvalidArgumentException('narrative_sections_contract_error: narrative_plan.sections_json ma nieprawidłowy typ lub jest puste.');
        }
        foreach ($stored as $index => $section) {
            if (!is_array($section)) {
                throw new InvalidArgumentException("narrative_sections_contract_error: narrative_plan.sections_json[{$index}] ma nieprawidłowy typ.");
            }
        }
        return array_values($stored);
    }

    $legacy = ($input['editorial_plan'] ?? [])['sections'] ?? null;
    if (is_array($legacy) && $legacy !== [] && array_is_list($legacy)) {
        return array_values($legacy);
    }
    throw new InvalidArgumentException('narrative_sections_contract_error: brak sekcji NarrativePlan.');
}

/** NarrativePlan owns V2 section identity/order/role; draft owns heading/body. */
function article_draft_normalize_narrative_sections(array $operation, array &$draft): array
{
    if (!isset($draft['sections']) || !is_array($draft['sections'])) return [];
    $planned = article_draft_narrative_sections($operation);
    $actual =& $draft['sections'];
    if (count($actual) !== count($planned)) {
        throw new InvalidArgumentException('narrative_section_contract_conflict: liczba sekcji nie odpowiada NarrativePlan.');
    }
    $plannedIds = [];
    foreach ($planned as $index => $section) {
        $id = trim((string)($section['section_id'] ?? ''));
        if ($id === '' || isset($plannedIds[$id])) {
            throw new InvalidArgumentException('narrative_section_contract_conflict: NarrativePlan ma niejednoznaczną strukturę sekcji.');
        }
        $plannedIds[$id] = $index;
    }
    $explicitIds = [];
    $normalized = [];
    foreach ($actual as $index => &$section) {
        if (!is_array($section)) throw new InvalidArgumentException("narrative_section_contract_conflict: $.sections[{$index}] ma nieprawidłowy typ.");
        $canonical = (array)$planned[$index];
        $canonicalId = (string)$canonical['section_id'];
        $actualId = trim((string)($section['section_id'] ?? ''));
        if ($actualId !== '') {
            if (isset($explicitIds[$actualId])) throw new InvalidArgumentException('narrative_section_contract_conflict: duplicate section_id ' . $actualId . '.');
            $explicitIds[$actualId] = true;
            if (!isset($plannedIds[$actualId]) || $actualId !== $canonicalId) {
                throw new InvalidArgumentException("narrative_section_contract_conflict: $.sections[{$index}].section_id zmienia kolejność NarrativePlan.");
            }
        } else {
            $section['section_id'] = $canonicalId;
            $normalized[] = "sections[{$index}].section_id";
        }
        foreach (['topic_role','content_type'] as $field) {
            $expected = (string)($canonical[$field] ?? '');
            $actualValue = trim((string)($section[$field] ?? ''));
            if ($actualValue === '') {
                $section[$field] = $expected;
                $normalized[] = "sections[{$index}].{$field}";
            } elseif ($actualValue !== $expected) {
                throw new InvalidArgumentException("narrative_section_contract_conflict: $.sections[{$index}].{$field} zmienia NarrativePlan.");
            }
        }
    }
    unset($section);
    return $normalized;
}

function article_draft_assert_narrative_sections(array $operation, array $sections): void
{
    $plannedSections = article_draft_narrative_sections($operation);
    $plannedById = [];
    foreach ($plannedSections as $planned) $plannedById[(string) ($planned['section_id'] ?? '')] = $planned;
    $seenSections = [];
    foreach ($sections as $index => $section) {
        $id = (string) ($section['section_id'] ?? '');
        if ($id === '' || isset($seenSections[$id]) || !isset($plannedById[$id])) {
            throw new InvalidArgumentException("$.sections[{$index}] nie odpowiada NarrativePlan.");
        }
        $seenSections[$id] = true;
        foreach (['topic_role', 'content_type'] as $field) {
            if (($section[$field] ?? null) !== ($plannedById[$id][$field] ?? null)) {
                throw new InvalidArgumentException("$.sections[{$index}].{$field} zmienia NarrativePlan.");
            }
        }
    }
    $actualOrder = array_keys($seenSections);
    $plannedOrder = array_values(array_map(static fn (array $section): string => (string) ($section['section_id'] ?? ''), $plannedSections));
    if ($actualOrder !== $plannedOrder) {
        throw new InvalidArgumentException('Szkic nie zachował kolejności sekcji NarrativePlan.');
    }
}

/** Restore omitted legacy slot identity/policy only from one persisted P02 slot. */
function article_draft_normalize_narrative_visual_slot_identity(array $narrativePlan, array &$draft): void
{
    $contract = narrative_plan_draft_illustration_contract($narrativePlan);
    $expected = (array) ($contract['illustration_plan'] ?? []);
    $actualPlan =& $draft['illustration_plan'];
    if (!is_array($actualPlan)) {
        throw new InvalidArgumentException('Szkic nie zawiera planu ilustracji do normalizacji VisualPlan.');
    }

    $normalize = static function (array &$actual, array $candidates, string $kind): void {
        $slotId = trim((string) ($actual['slot_id'] ?? ''));
        $matches = $slotId !== ''
            ? array_values(array_filter($candidates, static fn (array $candidate): bool => (string) ($candidate['slot_id'] ?? '') === $slotId))
            : array_values(array_filter($candidates, static function (array $candidate) use ($actual): bool {
                return (string) ($candidate['role'] ?? '') === (string) ($actual['role'] ?? '')
                    && (string) ($candidate['section_id'] ?? '') === (string) ($actual['section_id'] ?? '');
            }));
        if (count($matches) !== 1 || trim((string) ($matches[0]['slot_id'] ?? '')) === '') {
            throw new InvalidArgumentException('visual_plan_slot_mapping_ambiguous: ' . $kind);
        }
        $canonical = $matches[0];
        foreach (['slot_id', 'role', 'section_id', 'must_be_direct', 'acceptable_related', 'search_queries', 'search_queries_related'] as $field) {
            $missing = !array_key_exists($field, $actual) || $actual[$field] === null
                || (is_string($actual[$field]) && trim($actual[$field]) === '');
            if ($missing) {
                $actual[$field] = $canonical[$field];
                continue;
            }
            if (in_array($field, ['search_queries', 'search_queries_related'], true)
                && is_array($actual[$field]) && is_array($canonical[$field])) {
                $actualQueries = array_values(array_unique(array_map('strval', $actual[$field])));
                $canonicalQueries = array_values(array_unique(array_map('strval', $canonical[$field])));
                if ($actualQueries !== [] && array_diff($actualQueries, $canonicalQueries) === []) {
                    $actual[$field] = $canonical[$field];
                    continue;
                }
            }
            if ($actual[$field] !== $canonical[$field]) {
                throw new InvalidArgumentException('visual_plan_policy_conflict: ' . $kind . '.' . $field);
            }
        }
        $actual['layout'] = $canonical['layout'];
    };

    $hero =& $actualPlan['hero'];
    if (!is_array($hero)) {
        throw new InvalidArgumentException('Szkic nie zawiera hero do normalizacji VisualPlan.');
    }
    $normalize($hero, [(array) ($expected['hero'] ?? [])], 'hero');

    $inline =& $actualPlan['inline'];
    if (!is_array($inline)) {
        throw new InvalidArgumentException('Szkic nie zawiera tablicy inline do normalizacji VisualPlan.');
    }
    $candidates = array_values((array) ($expected['inline'] ?? []));
    foreach ($inline as $index => &$slot) {
        if (!is_array($slot)) {
            throw new InvalidArgumentException('Szkic zawiera nieprawidłowy slot inline do normalizacji VisualPlan.');
        }
        $normalize($slot, $candidates, 'inline[' . $index . ']');
    }
    unset($slot);
    $bySlotId = [];
    foreach ($inline as $slot) $bySlotId[(string) ($slot['slot_id'] ?? '')] = $slot;
    $ordered = [];
    foreach ($candidates as $candidate) {
        $slotId = (string) ($candidate['slot_id'] ?? '');
        if (!isset($bySlotId[$slotId])) throw new InvalidArgumentException('visual_plan_slot_mapping_ambiguous: inline-order');
        $ordered[] = $bySlotId[$slotId];
    }
    $inline = $ordered;
    unset($hero, $inline);
}

function article_draft_assert_brief_contract(string $value): string
{
    $brief = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    if (mb_strlen($brief) < 80 || mb_strlen($brief) > 220) {
        throw new InvalidArgumentException('Brief musi mieć od 80 do 220 znaków.');
    }
    $briefSentences = preg_split('/(?<=[.!?])\s+/u', $brief, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($briefSentences) < 1 || count($briefSentences) > 2 || preg_match('/[.!?]$/u', $brief) !== 1) {
        throw new InvalidArgumentException('Brief musi zawierać od jednego do dwóch zakończonych zdań.');
    }

    return $brief;
}

function validate_article_draft_output(array $operation, array &$draft): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    article_draft_normalize_narrative_sections($operation, $draft);
    $research = (array) ($input['research_package'] ?? []);
    $knownClaims = [];
    foreach ((array) ($research['claims'] ?? []) as $claim) {
        $knownClaims[(string) $claim['claim_id']] = $claim;
    }
    $knownSources = [];
    foreach ((array) ($input['numbered_sources'] ?? []) as $source) {
        $knownSources[(string) $source['source_id']] = $source;
    }
    $mode = (string) ($input['composition_mode'] ?? '');
    if (($draft['composition_mode'] ?? '') !== $mode || !in_array($mode, ARTICLE_COMPOSITION_MODES, true)) {
        throw new InvalidArgumentException('Wynik zmienił wybrany tryb kompozycji.');
    }
    $lengthPolicy = article_draft_length_policy($mode);
    $contentLength = article_draft_main_content_length($draft);
    if ($contentLength < $lengthPolicy['minimum_characters'] || $contentLength > $lengthPolicy['maximum_characters']) {
        throw new InvalidArgumentException(
            'Treść główna szkicu ma ' . $contentLength . ' znaków; dla trybu ' . $mode
            . ' wymagany jest zakres ' . $lengthPolicy['minimum_characters']
            . '–' . $lengthPolicy['maximum_characters'] . ' znaków.'
        );
    }
    article_draft_assert_polish_language($draft);
    $repeatedSentence = article_draft_repeated_sentence($draft);
    if ($repeatedSentence !== null) {
        throw new InvalidArgumentException(
            'Treść główna powtarza to samo zdanie i nie może osiągać wymaganej długości przez duplikowanie treści.'
        );
    }
    $seoWarning = article_draft_normalize_seo_description($draft);
    $narrativePlan = is_array($input['narrative_plan'] ?? null) ? $input['narrative_plan'] : null;
    $visualSlotCount = 1 + count((array) (($draft['illustration_plan'] ?? [])['inline'] ?? []));
    if ($narrativePlan !== null) {
        article_draft_normalize_narrative_visual_slot_identity($narrativePlan, $draft);
    }
    if ($narrativePlan === null) {
    validate_article_illustration_plan(
            (array) ($draft['illustration_plan'] ?? []),
            article_section_blocks($draft),
        $contentLength
    );
    article_draft_assert_narrative_visual_projection($operation, $draft);
        $narrativePlanEvidence = null;
    } else {
        $contract = narrative_plan_draft_illustration_contract($narrativePlan);
        $expectedPlan = (array) ($contract['illustration_plan'] ?? []);
        $actualPlan = (array) ($draft['illustration_plan'] ?? []);
        $operationSchema = json_decode((string) ($operation['output_schema_json'] ?? '{}'), true) ?: [];
        $illustrationSchema = (array) ($operationSchema['properties']['illustration_plan'] ?? []);
        if ($illustrationSchema === []) throw new InvalidArgumentException('Operacja szkicu nie zawiera utrwalonego schematu VisualPlan.');
        validate_generation_value($actualPlan, $illustrationSchema, '$.illustration_plan');
        $sections = article_section_blocks($draft);
        $sectionIds = array_column($sections, 'id');
        validate_planned_article_image((array) ($actualPlan['hero'] ?? []), 'hero', $sectionIds);
        $actualInline = array_values((array) ($actualPlan['inline'] ?? []));
        $expectedInline = array_values((array) ($expectedPlan['inline'] ?? []));
        if (count($actualInline) !== count($expectedInline)) {
            throw new InvalidArgumentException('Szkic nie zachował wymaganej liczby slotów VisualPlan.');
        }
        $visualSlotCount = 1 + count($expectedInline);
        foreach (['role', 'section_id', 'visual_intent', 'search_queries', 'expected_content', 'alt', 'caption', 'layout', 'status', 'slot_id', 'must_be_direct', 'acceptable_related', 'search_queries_related'] as $field) {
            if (($actualPlan['hero'][$field] ?? null) !== ($expectedPlan['hero'][$field] ?? null)) {
                throw new InvalidArgumentException('Szkic zmienił wymagany hero VisualPlan: ' . $field . '.');
            }
        }
        $usedSections = [];
        foreach ($actualInline as $index => $image) {
            validate_planned_article_image((array) $image, 'inline', $sectionIds);
            if (isset($usedSections[(string) ($image['section_id'] ?? '')])) {
                throw new InvalidArgumentException('Szkic przypisał dwa sloty VisualPlan do tej samej sekcji.');
            }
            $usedSections[(string) $image['section_id']] = true;
            foreach (['role', 'section_id', 'visual_intent', 'search_queries', 'expected_content', 'alt', 'caption', 'layout', 'status', 'slot_id', 'must_be_direct', 'acceptable_related', 'search_queries_related'] as $field) {
                if (($image[$field] ?? null) !== ($expectedInline[$index][$field] ?? null)) {
                    throw new InvalidArgumentException('Szkic zmienił wymagany slot VisualPlan: ' . $field . '.');
                }
            }
        }
        $narrativePlanEvidence = [
            'plan_id' => (int) ($contract['plan_id'] ?? 0),
            'slot_count' => $visualSlotCount,
            'slot_ids' => (array) ($contract['slot_ids'] ?? []),
        ];
    }
    foreach (['title', 'brief', 'seo_description', 'category', 'image_alt'] as $field) {
        if (trim((string) ($draft[$field] ?? '')) === '') {
            throw new InvalidArgumentException("Pole {$field} nie może być puste.");
        }
    }
    $brief = article_draft_assert_brief_contract((string) $draft['brief']);
    $normalizedBrief = mb_strtolower(rtrim($brief, ".!? \t\n\r\0\x0B"));
    $normalizedTitle = mb_strtolower(rtrim(trim((string) $draft['title']), ".!? \t\n\r\0\x0B"));
    if ($normalizedBrief === $normalizedTitle) {
        throw new InvalidArgumentException('Brief nie może powtarzać tytułu.');
    }
    foreach (article_draft_main_content_texts($draft) as $contentText) {
        if (str_contains(
            mb_strtolower(preg_replace('/\s+/u', ' ', $contentText) ?? $contentText),
            $normalizedBrief
        )) {
            throw new InvalidArgumentException('Brief nie może powtarzać zdania z treści głównej.');
        }
    }
    $usedSources = [];
    $isV2Sections = isset($draft['sections']) && is_array($draft['sections']);
    if ($isV2Sections) {
        article_draft_assert_narrative_sections($operation, $draft['sections']);
        foreach ($draft['sections'] as $index => $section) {
            $referenceSection = [...(array) $section, 'text'=>(string) ($section['body'] ?? '')];
            foreach (article_draft_assert_references($referenceSection, $knownClaims, $knownSources, "$.sections[{$index}]") as $sourceId) $usedSources[$sourceId] = true;
            article_draft_assert_not_copied((string) ($section['body'] ?? ''), $knownSources, "$.sections[{$index}].body");
        }
    } else {
    foreach (['lead', 'why_important', 'comparison_context', 'practical_takeaway'] as $field) {
        $allowEmpty = $field === 'comparison_context';
        foreach (article_draft_assert_references(
            (array) ($draft[$field] ?? []),
            $knownClaims,
            $knownSources,
            '$.' . $field,
            $allowEmpty,
            $field !== 'comparison_context'
        ) as $sourceId) {
            $usedSources[$sourceId] = true;
        }
        article_draft_assert_not_copied((string) ($draft[$field]['text'] ?? ''), $knownSources, '$.' . $field);
    }
    if (((array) ($draft['key_facts'] ?? [])) === []) {
        throw new InvalidArgumentException('Szkic musi zawierać co najmniej jeden najważniejszy fakt.');
    }
    foreach ((array) $draft['key_facts'] as $index => $fact) {
        foreach (article_draft_assert_references(
            (array) $fact,
            $knownClaims,
            $knownSources,
            "$.key_facts[{$index}]"
        ) as $sourceId) {
            $usedSources[$sourceId] = true;
        }
        article_draft_assert_not_copied((string) ($fact['text'] ?? ''), $knownSources, "$.key_facts[{$index}]");
    }
    $researchUnknowns = (array) ($research['unknowns'] ?? []);
    foreach ((array) ($draft['unknowns'] ?? []) as $index => $unknown) {
        if (trim((string) ($unknown['text'] ?? '')) === '') {
            throw new InvalidArgumentException("$.unknowns[{$index}] nie może być pusty.");
        }
        $indexes = array_values(array_unique((array) ($unknown['research_unknown_indexes'] ?? [])));
        if ($indexes === []) {
            throw new InvalidArgumentException("$.unknowns[{$index}] nie wskazuje niewiadomej z researchu.");
        }
        foreach ($indexes as $unknownIndex) {
            if (!is_int($unknownIndex) || !array_key_exists($unknownIndex, $researchUnknowns)) {
                throw new InvalidArgumentException("$.unknowns[{$index}] wskazuje nieznaną niewiadomą.");
            }
        }
    }
    $narrativeKeys = [
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ];
    foreach ($narrativeKeys as $key) {
        $sectionSources = article_draft_assert_references(
            (array) ($draft['narrative'][$key] ?? []),
            $knownClaims,
            $knownSources,
            '$.narrative.' . $key,
            $mode === 'informational'
        );
        foreach ($sectionSources as $sourceId) {
            $usedSources[$sourceId] = true;
        }
    }
    }
    $declaredSources = array_values(array_unique((array) ($draft['used_source_ids'] ?? [])));
    sort($declaredSources);
    $actualSources = array_keys($usedSources);
    sort($actualSources);
    if ($declaredSources === [] || $declaredSources !== $actualSources) {
        throw new InvalidArgumentException('used_source_ids nie odpowiada dokładnie źródłom wykorzystanym w szkicu.');
    }
    article_draft_assert_not_copied((string) $draft['title'], $knownSources, '$.title');
    // Validate titles last: title-only repair is safe only after every content,
    // attribution, language, length and composition invariant has passed.
    $titleValidation = validate_article_title_strategy($draft, $knownClaims);

    $visualTargetState = editorial_v2_visual_target_state($contentLength, $visualSlotCount);
    return [
        'valid' => true,
        'composition_mode' => $mode,
        'claim_reference_count' => count($knownClaims),
        'used_source_count' => count($usedSources),
        'key_fact_count' => count((array) ($draft['key_facts'] ?? [])),
        'unknown_count' => count((array) ($draft['unknowns'] ?? [])),
        'main_content_character_count' => $contentLength,
        'main_content_minimum' => $lengthPolicy['minimum_characters'],
        'main_content_maximum' => $lengthPolicy['maximum_characters'],
        'title_variant_count' => $titleValidation['variant_count'],
        'selected_title_score' => $titleValidation['selected_score'],
        'supported_title_tokens' => $titleValidation['supported_title_tokens'],
        'narrative_plan_contract' => $narrativePlanEvidence,
        ...$visualTargetState,
        'warnings' => $seoWarning === null ? [] : [$seoWarning],
    ];
}

function persist_completed_article_draft(int $operationId, array $draft, array $validation): void
{
    $statement = bueno_database()->prepare(
        'UPDATE article_draft_versions
         SET status = "completed", draft_json = :draft_json,
             validation_json = :validation_json,
             completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([
        ':draft_json' => generation_json($draft),
        ':validation_json' => generation_json($validation),
        ':operation_id' => $operationId,
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Nie znaleziono wersji szkicu.');
    }
    bueno_database()->prepare(
        'UPDATE article_feedback_operations
         SET status = "completed", completed_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    )->execute([':operation_id' => $operationId]);
    $completed = find_article_draft_by_operation($operationId);
    if (is_array($completed)) {
        if ($completed['parent_version_id'] === null) {
            bueno_database()->prepare('UPDATE article_draft_versions SET is_active = 0 WHERE post_id = :post_id')
                ->execute([':post_id' => (int) $completed['post_id']]);
            bueno_database()->prepare('UPDATE article_draft_versions SET is_active = 1 WHERE id = :id')
                ->execute([':id' => (int) $completed['id']]);
        } else {
            bueno_database()->prepare(
                'UPDATE article_draft_versions SET is_active = 1
                 WHERE id = :id AND NOT EXISTS (
                    SELECT 1 FROM article_draft_versions active
                    WHERE active.post_id = :post_id AND active.is_active = 1
                 )'
            )->execute([':id' => (int) $completed['id'], ':post_id' => (int) $completed['post_id']]);
        }
    }
}

function mark_article_draft_failed(int $operationId, string $errorMessage): void
{
    bueno_database()->prepare(
        'UPDATE article_draft_versions
         SET status = "failed",
             validation_json = :validation_json,
             updated_at = CURRENT_TIMESTAMP
         WHERE generation_operation_id = :operation_id'
    )->execute([
        ':validation_json' => generation_json(['valid' => false, 'error' => mb_substr($errorMessage, 0, 2000)]),
        ':operation_id' => $operationId,
    ]);
    bueno_database()->prepare(
        'UPDATE article_feedback_operations SET status = "failed"
         WHERE generation_operation_id = :operation_id'
    )->execute([':operation_id' => $operationId]);
}

function find_article_draft_by_operation(int $operationId): ?array
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_draft_versions WHERE generation_operation_id = :operation_id'
    );
    $statement->execute([':operation_id' => $operationId]);
    $draft = $statement->fetch();

    return is_array($draft) ? $draft : null;
}

function list_article_draft_versions(int $limit = 500): array
{
    $statement = bueno_database()->prepare(
        'SELECT drafts.*, topics.title AS topic_title
         FROM article_draft_versions AS drafts
         INNER JOIN editorial_topics AS topics ON topics.id = drafts.topic_id
         ORDER BY drafts.id DESC LIMIT :limit'
    );
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function article_draft_content_blocks(array $draft, array $imageIdsBySection = []): array
{
    $blocks = [];
    $makeSection = static function (
        string $id,
        string $text,
        string $variant
    ) use ($imageIdsBySection): array {
        $sectionBlocks = [];
        $sectionBlocks[] = [
            'type' => 'paragraph',
            'text' => $text,
        ];
        $imageId = (int) ($imageIdsBySection[$id] ?? 0);
        if ($imageId > 0) {
            $sectionBlocks[] = ['type' => 'illustration', 'image_id' => $imageId];
        }

        return [
            'type' => 'section',
            'id' => $id,
            'variant' => $variant,
            'blocks' => $sectionBlocks,
        ];
    };
    $appendTextSection = static function (
        string $id,
        mixed $value,
        string $variant
    ) use (&$blocks, $makeSection): void {
        $text = trim(strip_tags((string) $value));
        if ($text !== '') {
            $blocks[] = $makeSection($id, $text, $variant);
        }
    };

    if (isset($draft['sections']) && is_array($draft['sections'])) {
        foreach ($draft['sections'] as $section) {
            $id = trim((string) ($section['section_id'] ?? ''));
            $body = trim(strip_tags((string) ($section['body'] ?? '')));
            if ($id === '' || $body === '') continue;
            $contentType = (string) ($section['content_type'] ?? 'prose');
            $sectionBlock = $makeSection($id, $body, 'v2-' . $contentType);
            $sectionBlock['topic_role'] = (string) ($section['topic_role'] ?? 'A');
            $sectionBlock['content_type'] = $contentType;
            $heading = trim((string) ($section['heading'] ?? ''));
            if ($heading !== '') array_unshift($sectionBlock['blocks'], ['type'=>'heading','level'=>2,'text'=>$heading]);
            $blocks[] = $sectionBlock;
        }
        validate_article_blocks($blocks);
        return $blocks;
    }

    $appendTextSection('lead', $draft['lead']['text'] ?? '', 'lead');
    $appendTextSection(
        'why-important',
        $draft['why_important']['text'] ?? '',
        'importance'
    );

    $factBlocks = [];
    foreach ((array) ($draft['key_facts'] ?? []) as $index => $fact) {
        $text = trim(strip_tags((string) ($fact['text'] ?? '')));
        if ($text !== '') {
            $factBlocks[] = $makeSection('fact-' . ($index + 1), $text, 'fact');
        }
    }
    if ($factBlocks !== []) {
        $blocks[] = [
            'type' => 'section',
            'id' => 'key-facts',
            'variant' => 'facts',
            'blocks' => $factBlocks,
        ];
    }

    $appendTextSection(
        'comparison',
        $draft['comparison_context']['text'] ?? '',
        'context'
    );

    $unknownBlocks = [];
    foreach ((array) ($draft['unknowns'] ?? []) as $index => $unknown) {
        $text = trim(strip_tags((string) ($unknown['text'] ?? '')));
        if ($text !== '') {
            $unknownBlocks[] = $makeSection('unknown-' . ($index + 1), $text, 'unknown');
        }
    }
    if ($unknownBlocks !== []) {
        $blocks[] = [
            'type' => 'section',
            'id' => 'unknowns',
            'variant' => 'unknowns',
            'blocks' => $unknownBlocks,
        ];
    }

    foreach ((array) ($draft['narrative'] ?? []) as $key => $narrative) {
        $appendTextSection(
            'narrative-' . str_replace('_', '-', (string) $key),
            $narrative['text'] ?? '',
            'narrative'
        );
    }
    $appendTextSection(
        'takeaway',
        $draft['practical_takeaway']['text'] ?? '',
        'takeaway'
    );
    validate_article_blocks($blocks);

    return $blocks;
}

function promote_article_draft_to_post(int $draftVersionId): int
{
    $statement = bueno_database()->prepare(
        'SELECT * FROM article_draft_versions WHERE id = :id'
    );
    $statement->execute([':id' => $draftVersionId]);
    $draftRecord = $statement->fetch();
    if (!is_array($draftRecord) || !in_array((string) $draftRecord['status'], ['completed', 'frozen'], true)) {
        throw new RuntimeException('Do edytora można przenieść wyłącznie ukończony lub zatwierdzony przez QC szkic.');
    }

    $postId = (int) $draftRecord['post_id'];
    $post = find_post($postId);
    if ($post === null) {
        throw new RuntimeException('Nie znaleziono posta powiązanego ze szkicem.');
    }
    if (in_array((string) $post['status'], ['scheduled', 'published'], true)) {
        throw new RuntimeException('Szkic Gemini nie może nadpisać zaplanowanego ani opublikowanego posta.');
    }

    $draft = json_decode((string) $draftRecord['draft_json'], true, 128, JSON_THROW_ON_ERROR);
    $imageIdsBySection = [];
    $existingImages = [];
    foreach (list_article_images($postId) as $existingImage) {
        $existingImages[(string) $existingImage['role'] . ':' . (string) $existingImage['section_id']] = $existingImage;
    }
    foreach ([
        (array) ($draft['illustration_plan']['hero'] ?? []),
        ...(array) ($draft['illustration_plan']['inline'] ?? []),
    ] as $plannedImage) {
        if ($plannedImage === []) {
            continue;
        }
        $existingImage = $existingImages[
            (string) $plannedImage['role'] . ':' . (string) $plannedImage['section_id']
        ] ?? null;
        $preserveExisting = is_array($existingImage)
            && in_array((string) $existingImage['status'], ['selected', 'downloaded', 'manual_review'], true);
        if ($preserveExisting
            && (string) ($plannedImage['role'] ?? '') === 'hero'
            && !source_image_candidate_is_suitable_for_role(
                [
                    'title' => '',
                    'source_page_url' => (string) ($existingImage['source_page_url'] ?? ''),
                    'width' => (int) ($existingImage['width'] ?? 0),
                    'height' => (int) ($existingImage['height'] ?? 0),
                ],
                $plannedImage
            )) {
            $preserveExisting = false;
        }
        $imageId = $preserveExisting
            ? (int) $existingImage['id']
            : persist_article_image($postId, $plannedImage);
        if (($plannedImage['role'] ?? '') === 'inline') {
            $imageIdsBySection[(string) $plannedImage['section_id']] = $imageId;
        }
    }

    $blocks = article_draft_content_blocks($draft, $imageIdsBySection);
    $content = render_article_blocks($blocks, list_article_images($postId));
    $excerpt = mb_substr(trim((string) ($draft['brief'] ?? '')), 0, 500);
    update_post(
        $postId,
        trim((string) $draft['title']),
        $excerpt,
        $content,
        (string) ($post['image_path'] ?? ''),
        false,
        (string) ($post['content_image_path'] ?? ''),
        isset($post['gallery_id']) ? (int) $post['gallery_id'] : null,
        post_content_image_items($post),
        (string) ($post['image_fit'] ?? 'cover'),
        post_main_image_crop($post),
        'draft'
    );
    bueno_database()->prepare(
        'UPDATE posts
         SET content_blocks = :content_blocks,
             seo_description = :seo_description,
             image_alt = :image_alt,
             ai_assisted = 1,
             ai_components = :ai_components,
             ai_disclosure = :ai_disclosure,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute([
        ':id' => $postId,
        ':content_blocks' => generation_json($blocks),
        ':seo_description' => mb_substr(trim((string) ($draft['seo_description'] ?? '')), 0, 200),
        ':image_alt' => mb_substr(trim((string) ($draft['image_alt'] ?? '')), 0, 250),
        ':ai_components' => generation_json(['research', 'text', 'seo']),
        ':ai_disclosure' => 'Research, kompozycję tekstu i plan ilustracji wspomogło Gemini; publikacja wymaga kontroli redakcyjnej.',
    ]);

    return $postId;
}

function article_draft_mock_generation_value(array $operation): array
{
    $input = json_decode((string) $operation['input_json'], true, 128, JSON_THROW_ON_ERROR);
    $research = (array) $input['research_package'];
    $claim = (array) $research['claims'][0];
    $claimId = (string) $claim['claim_id'];
    $sourceId = (string) $claim['source_ids'][0];
    $section = static fn (string $text): array => [
        'text' => $text,
        'claim_ids' => [$claimId],
        'source_ids' => [$sourceId],
    ];
    $empty = ['text' => '', 'claim_ids' => [], 'source_ids' => []];
    $mode = (string) $input['composition_mode'];
    $narrative = [];
    foreach ([
        'opening_question',
        'pursuit',
        'topic_b',
        'apparent_dead_end',
        'return_to_topic_a',
        'close_topic_b',
        'answer_and_punchline',
    ] as $key) {
        $narrative[$key] = $mode === 'informational'
            ? $empty
            : $section('Kontrolowana część narracji ' . $key . ' oparta na twierdzeniu ' . $claimId . '.');
    }

    $mockTitle = ($input['qc_auto_repair']['strategy'] ?? '') === 'fresh_conservative_rewrite'
        ? 'Kontrolowany wynik badania: ' . trim((string) $claim['claim'])
        : trim((string) $claim['claim']);
    if (mb_strlen($mockTitle) < 35) {
        $mockTitle .= ': znaczenie i ograniczenia opisanego wyniku';
    }
    $draft = [
        'composition_mode' => $mode,
        'title' => mb_substr($mockTitle, 0, 100),
        'brief' => 'Za prostym wynikiem kryje się szerszy mechanizm, którego znaczenie ujawnia dopiero zestawienie danych i ograniczeń.',
        'lead' => $section(
            'Lokalny szkic sprawdza przepływ techniczny i wyjaśnia zatwierdzone twierdzenie: '
            . $mockTitle
            . ', jego znaczenie oraz ograniczenia.'
        ),
        'why_important' => $section('Znaczenie wynika z zatwierdzonego twierdzenia researchowego.'),
        'key_facts' => [
            $section('Pierwszy najważniejszy fakt pochodzi z przypisanego źródła.'),
            $section('Drugi fakt rozwija znaczenie zatwierdzonego twierdzenia bez dodawania nowych danych.'),
            $section('Trzeci fakt porządkuje ograniczenia interpretacji opisane w researchu.'),
        ],
        'comparison_context' => $empty,
        'unknowns' => isset($research['unknowns'][0])
            ? [['text' => (string) $research['unknowns'][0], 'research_unknown_indexes' => [0]]]
            : [],
        'practical_takeaway' => $section('Czytelnik powinien traktować ten wynik jako test techniczny.'),
        'seo_description' => 'Lokalny szkic testowy systemu generowania artykułu z zatwierdzonego researchu.',
        'category' => 'how-it-works',
        'image_alt' => 'Ilustracja lokalnego testu generowania szkicu',
        'used_source_ids' => [$sourceId],
        'narrative' => $narrative,
    ];
    $draft = [...$draft, ...build_article_title_strategy_fixture((string) $draft['title'])];
    $hasPlannedSections = array_key_exists('sections_json', (array) ($input['narrative_plan'] ?? []))
        || isset($input['editorial_plan']['sections']);
    $plannedSections = $hasPlannedSections ? article_draft_narrative_sections($operation) : [];
    if ($plannedSections !== []) {
        $draft['sections'] = array_map(static function (array $planned, int $plannedIndex) use ($claimId, $sourceId, $mockTitle): array {
            $sectionId = (string) $planned['section_id'];
            $opening = $plannedIndex === 0 ? ' Tematem tej części jest ' . $mockTitle . '.' : '';
            return [
                'section_id'=>$sectionId, 'topic_role'=>(string) $planned['topic_role'],
                'content_type'=>(string) $planned['content_type'], 'heading'=>(string) ($planned['heading'] ?? ''),
                'body'=>'Kontrolowana sekcja dynamiczna ' . $sectionId . ' opisuje zatwierdzone twierdzenie, które jest ważne dla tej części artykułu, oraz jego ograniczenia interpretacyjne.' . $opening,
                'visual_slot_id'=>'', 'claim_ids'=>[$claimId], 'source_ids'=>[$sourceId],
            ];
        }, $plannedSections, array_keys($plannedSections));
        foreach (['lead','why_important','key_facts','comparison_context','unknowns','practical_takeaway','narrative'] as $legacyField) unset($draft[$legacyField]);
    }
    if (($input['qc_auto_repair']['strategy'] ?? '') === 'fresh_conservative_rewrite') {
        if ($plannedSections !== []) $draft['sections'][array_key_last($draft['sections'])]['body'] .= ' Jest to ostrożny opis, który nie wykracza poza potwierdzone dane.';
        else $draft['practical_takeaway']['text'] .= ' Jest to ostrożny opis dla czytelnika, który nie wykracza poza najlepiej potwierdzone dane oraz zachowuje ich ograniczenia.';
    }
    $policy = article_draft_length_policy($mode);
    $index = 1;
    while (article_draft_main_content_length($draft) < $policy['minimum_characters']) {
        $addition = ' Techniczny kontekst ' . $index . ' opisuje zakres danych, który jest ważny dla interpretacji, oraz wyjaśnia, czego nie można pominąć przy zachowaniu przypisań do zatwierdzonego twierdzenia.';
        if ($plannedSections !== []) {
            $sectionIndex = ($index - 1) % count($draft['sections']);
            $draft['sections'][$sectionIndex]['body'] .= $addition;
        } else $draft['practical_takeaway']['text'] .= $addition;
        $index++;
    }
    $makeImage = static fn (string $role, string $sectionId, string $layout, string $intent): array => [
        'role' => $role,
        'section_id' => $sectionId,
        'visual_intent' => $intent,
        'search_queries' => [$role === 'hero'
            ? 'documentary photograph scientific research natural scene'
            : 'popular science ' . str_replace('-', ' ', $sectionId)],
        'expected_content' => $intent,
        'source_page_url' => '',
        'source_file_url' => '',
        'local_path' => '',
        'author' => '',
        'license' => '',
        'license_url' => '',
        'attribution' => '',
        'alt' => $intent,
        'caption' => $intent,
        'layout' => $layout,
        'status' => 'planned',
    ];
    $draft['illustration_plan'] = [
        'hero' => $makeImage(
            'hero',
            'article',
            'full',
            'Reprezentatywny obraz całego tematu artykułu: ' . (string) $draft['title']
        ),
        'inline' => [],
    ];
    $target = article_inline_image_target_count(article_draft_main_content_length($draft));
    foreach (array_slice(article_section_blocks($draft), 0, $target) as $imageIndex => $articleSection) {
        $draft['illustration_plan']['inline'][] = $makeImage(
            'inline',
            (string) $articleSection['id'],
            ['full', 'left', 'right', 'breakout'][$imageIndex % 4],
            'Konkretna ilustracja treści sekcji ' . (string) $articleSection['id']
        );
    }
    if (is_array($input['narrative_plan'] ?? null)) {
        $draft['illustration_plan'] = (array) (narrative_plan_draft_illustration_contract($input['narrative_plan'])['illustration_plan'] ?? []);
    }

    return $draft;
}
