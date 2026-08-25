<?php
require_once dirname(__DIR__) . '/php/admin-database.php';

echo "=== UTF-8 Tokenizer Test ===\n\n";

// Polish text with proper UTF-8 characters
$polish = 'neuroplastyczność mózg synapsy zażółć gęślą jaźń';
$tokens = article_image_semantic_gate_tokenize($polish);
echo "Polish: $polish\n";
echo "Tokens: " . implode(', ', $tokens) . "\n\n";

// English text
$english = 'neurons synapses micrograph brain plasticity';
$tokensEn = article_image_semantic_gate_tokenize($english);
echo "English: $english\n";
echo "Tokens: " . implode(', ', $tokensEn) . "\n\n";

// Mixed text
$mixed = 'Neuroplastyczność - brain neurons synapses micrograph';
$tokensMix = article_image_semantic_gate_tokenize($mixed);
echo "Mixed: $mixed\n";
echo "Tokens: " . implode(', ', $tokensMix) . "\n\n";

// Score test with Polish planned + Polish candidate
$planned = [
    'visual_intent' => 'mikrofotografia neuronów i synaps ilustrująca neuroplastyczność',
    'expected_content' => 'neurony synapsy mózg',
];
$candidate = [
    'title' => 'Mikrofotografia neuronów i synaps mózgu',
    'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Neuron_synapse.jpg',
];
$score = article_image_semantic_gate_score($candidate, $planned);
echo "Polish planned + Polish candidate score: $score\n";

// Score test with Polish planned + English candidate (should still work as prefilter)
$candidateEn = [
    'title' => 'Neurons and synapses micrograph',
    'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Neuron_synapse.jpg',
];
$scoreEn = article_image_semantic_gate_score($candidateEn, $planned);
echo "Polish planned + English candidate score: $scoreEn\n";
