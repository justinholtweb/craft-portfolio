<?php

namespace justinholtweb\portfolio\models;

use craft\base\Model;

/**
 * What a build actually did.
 *
 * Deliberately *not* using Yii's `errors`/`addError()`: `yii\base\Model` already defines both, with
 * an incompatible signature, and overriding them is a fatal error at autoload time rather than at
 * the call site. Problems live under their own name.
 */
class BuildResult extends Model
{
    public bool $success = false;
    public ?Portfolio $portfolio = null;

    /** Human-readable descriptions of what was created, in the order it happened. */
    public array $created = [];

    /** Descriptions of what already existed and was pointed at rather than recreated. */
    public array $reused = [];

    /** Anything that went wrong, in plain language. */
    public array $problems = [];

    public function addCreated(string $what): void
    {
        $this->created[] = $what;
    }

    public function addReused(string $what): void
    {
        $this->reused[] = $what;
    }

    public function addProblem(string $problem): void
    {
        $this->problems[] = $problem;
        $this->success = false;
    }

    public function hasProblems(): bool
    {
        return $this->problems !== [];
    }

    public function summary(): string
    {
        return sprintf('%d created, %d reused', count($this->created), count($this->reused));
    }
}
