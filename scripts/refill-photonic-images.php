<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/php/admin-database.php';

$postId = 27;
$queries = [
    'lead' => ['beam splitter optics diagram', 'light refraction prism diagram'],
    'why-important' => ['Beam splitter optics public domain', 'optical beam path diagram'],
    'fact-1' => ['Metasurface 5941035620', 'metasurface NIST'],
];
$copyOnly = in_array('--copy-only', $argv, true);
$statement = bueno_database()->prepare(
    'UPDATE article_images SET search_queries_json = :queries, status = "missing" '
    . 'WHERE post_id = :post_id AND role = "inline" AND section_id = :section_id '
    . 'AND (status != "downloaded" OR section_id = "why-important")'
);
foreach ($copyOnly ? [] : $queries as $sectionId => $sectionQueries) {
    $statement->execute([
        ':queries' => generation_json($sectionQueries),
        ':post_id' => $postId,
        ':section_id' => $sectionId,
    ]);
}

if (!$copyOnly) {
    echo generation_json(fulfill_article_source_images($postId)), PHP_EOL;
}

$copy = bueno_database()->prepare(
    'UPDATE article_images SET relationship = :relationship, alt = :alt, caption = :caption '
    . 'WHERE post_id = :post_id AND section_id = :section_id'
);
$copy->execute([
    ':post_id' => $postId, ':section_id' => 'lead', ':relationship' => 'mechanism',
    ':alt' => 'Historyczny diagram pokazujący załamanie i zmianę kierunku fal elektromagnetycznych w pryzmacie.',
    ':caption' => 'Historyczny diagram ilustruje zmianę kierunku fal elektromagnetycznych w materiale — mechanizm pokrewny sterowaniu drogą światła, ale nie urządzenie Caltech.',
]);
$copy->execute([
    ':post_id' => $postId, ':section_id' => 'fact-1', ':relationship' => 'related_context',
    ':alt' => 'Metasurface NIST złożona z miedzianych struktur i przewodów na płytce.',
    ':caption' => 'Metasurface NIST pokazuje szerszą rodzinę sztucznie projektowanych powierzchni; nie jest to optyczne urządzenie opisane w badaniu Caltech.',
]);
$copy->execute([
    ':post_id' => $postId, ':section_id' => 'why-important', ':relationship' => 'mechanism',
    ':alt' => 'Schemat układu optycznego wyświetlacza z zaznaczoną drogą światła i elementami rozdzielającymi wiązkę.',
    ':caption' => 'Schemat układu optycznego Google Glass pokazuje praktyczny przykład prowadzenia i rozdzielania światła; nie przedstawia urządzenia Caltech.',
]);
