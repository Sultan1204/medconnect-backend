<?php

namespace App\Modules\Prescriptions\Data;

final class SafetyCheckResult
{
    /** @var list<array{severity: string, type: string, message: string, item_index: int|null}> */
    private array $findings = [];

    public function add(string $severity, string $type, string $message, ?int $itemIndex = null): void
    {
        $this->findings[] = ['severity' => $severity, 'type' => $type, 'message' => $message, 'item_index' => $itemIndex];
    }

    public function merge(self $other): void
    {
        array_push($this->findings, ...$other->findings());
    }

    /** @return list<array{severity: string, type: string, message: string, item_index: int|null}> */
    public function findings(): array
    {
        return $this->findings;
    }

    /** passed | warnings | blocked */
    public function status(): string
    {
        $severities = array_column($this->findings, 'severity');

        return match (true) {
            in_array('block', $severities, true) => 'blocked',
            in_array('warning', $severities, true) => 'warnings',
            default => 'passed',
        };
    }
}
