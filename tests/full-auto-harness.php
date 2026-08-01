<?php

declare(strict_types=1);

final class FullAutoFailure extends RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }
}

final class FullAutoHarness
{
    private const ORDER = ['ingestion', 'scoring', 'research', 'draft', 'qc', 'images'];
    private array $state = [
        'stage' => 'new', 'published' => false, 'versions' => [], 'operations' => [],
        'artifacts' => [], 'failures' => [], 'qc_failures' => 0,
    ];

    public static function restore(array $snapshot): self
    {
        $self = new self();
        $self->state = $snapshot;
        return $self;
    }

    public function snapshot(): array { return $this->state; }

    public function runStage(string $stage, string $operationId, callable $work): array
    {
        if (!in_array($stage, self::ORDER, true)) {
            throw new InvalidArgumentException('Nieznany etap: ' . $stage);
        }
        if (isset($this->state['operations'][$operationId])) {
            return $this->state['operations'][$operationId];
        }
        $currentIndex = array_search($this->state['stage'], self::ORDER, true);
        $expected = $currentIndex === false ? self::ORDER[0] : (self::ORDER[$currentIndex + 1] ?? 'complete');
        if ($stage !== $expected) {
            throw new FullAutoFailure('quality', "Oczekiwano etapu {$expected}, otrzymano {$stage}.");
        }
        try {
            $result = $work();
            $this->validate($stage, $result);
        } catch (FullAutoFailure $failure) {
            $this->state['failures'][] = ['stage' => $stage, 'category' => $failure->category];
            if ($stage === 'qc') {
                $this->state['qc_failures']++;
            }
            throw $failure;
        }
        $version = ($this->state['versions'][$stage] ?? 0) + 1;
        $record = ['stage' => $stage, 'version' => $version, 'result' => $result];
        $this->state['versions'][$stage] = $version;
        $this->state['operations'][$operationId] = $record;
        $this->state['artifacts'][$stage][] = $record;
        $this->state['stage'] = $stage;
        return $record;
    }

    public function repair(string $stage): void
    {
        $index = array_search($stage, self::ORDER, true);
        if ($index === false) { throw new InvalidArgumentException('Nieznany etap naprawy.'); }
        $safe = $index === 0 ? 'new' : self::ORDER[$index - 1];
        if ($stage === 'qc' && $this->state['qc_failures'] >= 2) { $safe = 'draft'; }
        $this->state['stage'] = $safe;
    }

    private function validate(string $stage, mixed $result): void
    {
        if (!is_array($result)) { throw new FullAutoFailure('schema', 'Wynik nie jest obiektem.'); }
        if (($result['_transport'] ?? '') === 'timeout' || ($result['_transport'] ?? '') === '429') {
            throw new FullAutoFailure('transport', 'Kontrolowany błąd transportu.');
        }
        if ($stage === 'research') {
            $sourceIds = (array) ($result['source_ids'] ?? []);
            foreach ((array) ($result['claims'] ?? []) as $claim) {
                if (!in_array($claim['source_id'] ?? null, $sourceIds, true)) {
                    throw new FullAutoFailure('source_id', 'Nieznany source_id.');
                }
                $source = (string) ($result['sources'][$claim['source_id']] ?? '');
                if (($claim['evidence'] ?? '') === '' || !str_contains($source, (string) $claim['evidence'])) {
                    throw new FullAutoFailure('exact-evidence', 'Evidence nie jest dosłownym fragmentem.');
                }
            }
        }
        if ($stage === 'draft') {
            if (trim((string) ($result['text'] ?? '')) === '') { throw new FullAutoFailure('quality', 'Pusty szkic.'); }
            foreach ((array) ($result['unknown_ids'] ?? []) as $id) {
                if (!in_array($id, (array) ($result['allowed_unknown_ids'] ?? []), true)) {
                    throw new FullAutoFailure('unknown_id', 'Nieznany unknown_id.');
                }
            }
        }
        if ($stage === 'qc' && (($result['pass'] ?? false) !== true || ($result['hard_blocks'] ?? []) !== [])) {
            throw new FullAutoFailure('quality', 'QC niezaliczone lub aktywny hard-block.');
        }
        if ($stage === 'images' && (($result['status'] ?? '') !== 'ready')) {
            throw new FullAutoFailure('quality', 'Obraz niegotowy.');
        }
    }
}

function full_auto_fixture(string $stage): array
{
    return match ($stage) {
        'ingestion' => ['topic_id' => 'T1', 'items' => ['I1']],
        'scoring' => ['score' => 91, 'qualified' => true],
        'research' => ['source_ids' => ['S1'], 'sources' => ['S1' => 'Eksperyment osiągnął stabilny wynik w trzech kontrolowanych próbach.'], 'claims' => [['source_id' => 'S1', 'evidence' => 'stabilny wynik']]],
        'draft' => ['text' => 'Kontrolowany eksperyment dał stabilny wynik.', 'unknown_ids' => ['U1'], 'allowed_unknown_ids' => ['U1']],
        'qc' => ['pass' => true, 'hard_blocks' => [], 'score' => 96],
        'images' => ['status' => 'ready', 'versions' => 1, 'width' => 1280, 'height' => 720],
        default => throw new InvalidArgumentException($stage),
    };
}
