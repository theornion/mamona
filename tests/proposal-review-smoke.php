<?php

declare(strict_types=1);

putenv('CMS_SKIP_PUBLIC_SYNC=1');
require_once dirname(__DIR__) . '/php/admin-database.php';

function proposal_smoke_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$draftColumns = database_table_columns(bueno_database(), 'article_draft_versions');
foreach (['parent_version_id', 'change_source', 'is_active'] as $column) {
    proposal_smoke_assert(in_array($column, $draftColumns, true), 'Brak kolumny wersjonowania: ' . $column);
}
$tables = array_column(bueno_database()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(), 'name');
proposal_smoke_assert(in_array('article_feedback_operations', $tables, true), 'Brak audytu feedbacku.');
proposal_smoke_assert(in_array('article_proposal_audit', $tables, true), 'Brak audytu decyzji.');

$base = [
    'title' => 'Stary tytuł', 'lead' => ['text' => 'Stary lead'],
    'why_important' => ['text' => 'A'], 'key_facts' => [], 'comparison_context' => ['text' => ''],
    'unknowns' => [], 'narrative' => [], 'practical_takeaway' => ['text' => 'Wniosek'],
    'illustration_plan' => ['hero' => ['alt' => 'A']],
];
$new = $base;
$new['title'] = 'Nowy tytuł';
$new['lead']['text'] = 'Nowy lead';
$diff = proposal_diff(
    ['draft_json' => generation_json($base)],
    ['draft_json' => generation_json($new)]
);
proposal_smoke_assert(in_array('title', $diff['changed_fields'], true), 'Diff nie wykrył tytułu.');
proposal_smoke_assert(in_array('lead', $diff['changed_fields'], true), 'Diff nie wykrył leadu.');
proposal_smoke_assert($diff['old_title'] === 'Stary tytuł' && $diff['new_title'] === 'Nowy tytuł', 'Diff zamienił strony.');

$rules = proposal_immutable_rules();
proposal_smoke_assert(count($rules) >= 4, 'Brakuje niezmiennych reguł regeneracji.');
proposal_smoke_assert(str_contains(implode(' ', $rules), 'zatwierdzonego researchu'), 'Prompt nie chroni faktów.');
proposal_smoke_assert(str_contains(implode(' ', $rules), 'bez generowania AI'), 'Prompt nie blokuje AI images.');
foreach (['article', 'titles', 'lead', 'section', 'style', 'images', 'caption_alt', 'other'] as $scope) {
    proposal_smoke_assert(in_array($scope, ARTICLE_FEEDBACK_SCOPES, true), 'Brak zakresu: ' . $scope);
}

$page = (string) file_get_contents(dirname(__DIR__) . '/php/admin-proposals.php');
$service = (string) file_get_contents(dirname(__DIR__) . '/php/proposal-review-service.php');
proposal_smoke_assert(str_contains($service, 'function list_article_proposals_for_review'), 'Missing separate review list.');
proposal_smoke_assert(!str_contains($service, 'checks.status = "completed" AND checks.passed = 1'), 'Review list still hides failed QC.');
proposal_smoke_assert(!str_contains($service, 'EXISTS (SELECT 1 FROM article_images proposal_images'), 'Review list still requires an image record.');
proposal_smoke_assert(str_contains($page, 'proposal-draft-content'), 'Selected draft is not rendered in full.');
proposal_smoke_assert(str_contains($page, 'review_quality_risk(') && str_contains($page, "'quality_human_review'"), 'Human decision is not handled and audited.');
proposal_smoke_assert(str_contains($page, 'Ta blokada nie podlega ręcznemu obejściu'), 'Non-reviewable block has no repair path.');
proposal_smoke_assert(str_contains($page, 'admin-post-preview.php?post='), 'Preview nie używa renderera publikacji.');
proposal_smoke_assert(str_contains($page, '&amp;draft=<?php echo (int) $selected[\'id\']; ?>'), 'Osobny podgląd nie wskazuje wybranej wersji szkicu.');
proposal_smoke_assert(str_contains($page, "\$proposalQueue === 'action'") && str_contains($page, 'list_action_required_proposals()'), 'Szkice wymagające akcji nie mają osobnej kolejki.');
proposal_smoke_assert(str_contains($page, 'list_action_required_topic_payload()') && str_contains($page, 'Wszystkie tematy wymagające akcji'), 'Zakładka akcji nie obejmuje wszystkich tematów wymagających reakcji.');
$nav = (string) file_get_contents(dirname(__DIR__) . '/php/admin-nav.php');
proposal_smoke_assert(str_contains($nav, 'Wymagające akcji') && strpos($nav, 'Wymagające akcji') < strpos($nav, 'Gotowe propozycje'), 'Brak nowej zakładki między Tematami i Gotowymi propozycjami.');
proposal_smoke_assert(str_contains($page, 'approved=1#topic-'), 'Akceptacja nie wraca do karty tematu.');
proposal_smoke_assert(str_contains($service, 'function execute_article_feedback_pipeline'), 'Feedback nie ma pełnego automatycznego pipeline.');
proposal_smoke_assert(str_contains($service, 'article_draft_main_content_length($draftJson) <= 0'), 'Pusta poprawiona wersja nie jest blokowana.');
proposal_smoke_assert(str_contains($service, 'activate_proposal_version((int) $draft[\'id\']') && str_contains($service, 'prepare_quality_check_operation((int) $draft[\'id\']'), 'Poprawiona wersja nie jest automatycznie aktywowana i przekazywana do QC.');
proposal_smoke_assert(str_contains($service, 'fulfill_article_source_images($postId'), 'Pipeline feedbacku nie kontynuuje do legalnych grafik po zaliczonym QC.');
proposal_smoke_assert(str_contains($page, '$displayVersions') && str_contains($page, 'article_draft_main_content_length'), 'Puste placeholdery nadal trafiają do porównania wersji.');
proposal_smoke_assert(str_contains($page, 'confirm_publish'), 'Brak osobnego potwierdzenia publikacji.');
proposal_smoke_assert(!str_contains($page, 'image_gen'), 'Ekran wywołuje generator obrazów AI.');
proposal_smoke_assert(str_contains($page, 'Uwagi do zmian'), 'Brak jednego wspólnego pola uwag.');
proposal_smoke_assert(!str_contains($page, 'data-feedback-scope'), 'Ekran nadal wymaga ręcznego wyboru zakresu uwag.');
proposal_smoke_assert(proposal_infer_feedback_scope('zmień drugą grafikę na zdjęcie teleskopu') === 'images', 'Uwaga tylko do grafiki nie została rozpoznana.');
proposal_smoke_assert(proposal_infer_feedback_scope('skróć wstęp i zmień drugą grafikę') === 'article', 'Mieszana uwaga nie obejmuje tekstu i grafiki.');

echo "proposal-review-smoke: OK\n";
