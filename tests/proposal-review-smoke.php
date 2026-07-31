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
proposal_smoke_assert(str_contains($page, 'admin-post-preview.php?post='), 'Preview nie używa renderera publikacji.');
proposal_smoke_assert(str_contains($page, 'confirm_publish'), 'Brak osobnego potwierdzenia publikacji.');
proposal_smoke_assert(!str_contains($page, 'image_gen'), 'Ekran wywołuje generator obrazów AI.');
proposal_smoke_assert(str_contains($page, 'Uwagi do zmian'), 'Brak jednego wspólnego pola uwag.');
proposal_smoke_assert(!str_contains($page, 'data-feedback-scope'), 'Ekran nadal wymaga ręcznego wyboru zakresu uwag.');
proposal_smoke_assert(proposal_infer_feedback_scope('zmień drugą grafikę na zdjęcie teleskopu') === 'images', 'Uwaga tylko do grafiki nie została rozpoznana.');
proposal_smoke_assert(proposal_infer_feedback_scope('skróć wstęp i zmień drugą grafikę') === 'article', 'Mieszana uwaga nie obejmuje tekstu i grafiki.');

echo "proposal-review-smoke: OK\n";
