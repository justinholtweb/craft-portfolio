<?php

namespace justinholtweb\portfolio\models;

/**
 * The Lite/Pro line, in one place.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without a licence and read without chasing calls. `Plugin::isPro()` is the only thing in
 * the codebase that asks Craft what edition is active.
 *
 * The line: **Lite is the content model and the queries. Pro is presentation and scale.**
 */
final class Edition
{
    public const LITE_MAX_PORTFOLIOS = 1;

    /** How many portfolios may exist. Null means unlimited. */
    public static function maxPortfolios(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_PORTFOLIOS;
    }

    /** Whether an existing, hand-built section can be adopted as a portfolio. */
    public static function allowsAdoption(bool $isPro): bool
    {
        return $isPro;
    }

    /** Whether `craft.portfolio.grid()` renders markup rather than explaining itself. */
    public static function allowsGrid(bool $isPro): bool
    {
        return $isPro;
    }

    /** Whether CreativeWork JSON-LD is emitted. */
    public static function allowsJsonLd(bool $isPro): bool
    {
        return $isPro;
    }

}
