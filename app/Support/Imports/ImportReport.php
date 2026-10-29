<?php

namespace App\Support\Imports;

/** What an import batch did, in a shape the browser can add up across batches. */
class ImportReport
{
    public array $counts = [];
    public array $problems = [];
    public array $notes = [];

    public function add(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function problem(int $row, string $message): void
    {
        $this->add('problems');

        if (count($this->problems) < 100) {
            $this->problems[] = ['row' => $row, 'message' => $message];
        }
    }

    public function note(string $text): void
    {
        if (! in_array($text, $this->notes, true) && count($this->notes) < 60) {
            $this->notes[] = $text;
        }
    }

    public function toArray(): array
    {
        return ['counts' => $this->counts, 'problems' => $this->problems, 'notes' => $this->notes];
    }
}
