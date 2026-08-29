<?php

namespace justinholtweb\portfolio\models;

use craft\base\Model;

/**
 * Everything a build would do, worked out without writing anything.
 *
 * Also used *after* a build, to detect drift: re-planning a built portfolio should report reuse
 * for every item, and anything reported as create is something that has since been deleted.
 */
class BuildPlan extends Model
{
    public ?Blueprint $blueprint = null;

    /** @var PlanItem[] */
    public array $items = [];

    /** Problems that stop the build outright, in plain language. */
    public array $blockers = [];

    public function add(PlanItem $item): void
    {
        $this->items[] = $item;
    }

    /** @return PlanItem[] */
    public function creations(): array
    {
        return array_values(array_filter($this->items, fn(PlanItem $i) => $i->isCreate()));
    }

    /** @return PlanItem[] */
    public function reuses(): array
    {
        return array_values(array_filter($this->items, fn(PlanItem $i) => $i->isReuse()));
    }

    /** @return PlanItem[] */
    public function conflicts(): array
    {
        return array_values(array_filter($this->items, fn(PlanItem $i) => $i->isConflict()));
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }

    public function isBlocked(): bool
    {
        return $this->blockers !== [] || $this->hasConflicts();
    }

    /** Nothing to do — every item already exists as wanted. */
    public function isSatisfied(): bool
    {
        return $this->creations() === [] && !$this->hasConflicts();
    }

    /** @return PlanItem[] */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->items, fn(PlanItem $i) => $i->kind === $kind));
    }

    public function itemForRole(string $role): ?PlanItem
    {
        foreach ($this->items as $item) {
            if ($item->role === $role) {
                return $item;
            }
        }

        return null;
    }

    /** A one-line summary for a console command or a flash message. */
    public function summary(): string
    {
        $create = count($this->creations());
        $reuse = count($this->reuses());
        $conflict = count($this->conflicts());

        $parts = [];
        $parts[] = "$create to create";

        if ($reuse) {
            $parts[] = "$reuse to reuse";
        }

        if ($conflict) {
            $parts[] = "$conflict in conflict";
        }

        return implode(', ', $parts);
    }
}
