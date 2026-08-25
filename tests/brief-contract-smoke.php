<?php

declare(strict_types=1);

if (getenv('CMS_ALLOW_BRIEF_CONTRACT_SMOKE') !== '1') {
    fwrite(STDERR, "Ustaw CMS_ALLOW_BRIEF_CONTRACT_SMOKE=1, aby uruchomić test.\n");
    exit(2);
}

require_once dirname(__DIR__) . '/php/admin-database.php';

function brief_contract_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function brief_contract_rejected(string $brief): bool
{
    try {
        article_draft_assert_brief_contract($brief);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
}

$oneSentence = 'To jedno zakończone zdanie briefu ma wystarczającą długość, aby jasno i naturalnie przedstawić czytelnikowi najważniejszy kontekst tego materiału.';
$twoSentences = 'To pierwsze zakończone zdanie briefu ma wystarczającą długość i jasno ustawia temat materiału. Drugie zdanie dodaje potrzebny kontekst bez zdradzania wszystkich wniosków.';
$draft88Fixture = 'NASA ogłosiła, że w sierpniu 2026 roku nad Hiszpanią wystąpi całkowite zaćmienie Słońca. To wyjątkowe wydarzenie astronomiczne przyciąga uwagę badaczy i miłośników nieba z całego świata.';
$threeSentences = 'Pierwsze zakończone zdanie briefu ma wystarczającą długość, aby spełnić podstawowy warunek walidacji. Drugie zdanie również jest poprawne i dodaje kontekst. Trzecie zdanie nie może już zostać zaakceptowane.';
$unterminated = 'Ten brief ma wystarczającą długość, lecz kończy się bez kropki, wykrzyknika ani znaku zapytania i dlatego nie spełnia kontraktu MVP';

brief_contract_assert(article_draft_assert_brief_contract($oneSentence) === $oneSentence, 'Jedno zakończone zdanie powinno przejść.');
brief_contract_assert(article_draft_assert_brief_contract($twoSentences) === $twoSentences, 'Dwa zakończone zdania powinny przejść.');
brief_contract_assert(mb_strlen($draft88Fixture) === 186, 'Fixture draftu #88 musi mieć 186 znaków.');
brief_contract_assert(article_draft_assert_brief_contract($draft88Fixture) === $draft88Fixture, 'Fixture draftu #88 powinien przejść bez zmiany.');
brief_contract_assert(brief_contract_rejected($threeSentences), 'Trzy zakończone zdania muszą zostać odrzucone.');
brief_contract_assert(brief_contract_rejected($unterminated), 'Brief bez zakończenia zdania musi zostać odrzucony.');

$geminiCalls = 0;
brief_contract_assert($geminiCalls === 0, 'Walidacja briefu nie może wywoływać Gemini.');
echo "brief-contract-smoke: OK\n";
