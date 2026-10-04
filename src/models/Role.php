<?php

namespace justinholtweb\portfolio\models;

use Craft;

/**
 * The roles a field can play in a portfolio.
 *
 * A role is the stable name; the field handle behind it is not. Everything on the front end asks
 * for a role, so a site can rename `portfolioClient` to `clientName`, or point the role at a field
 * it already had, without a single template changing.
 *
 * Pure constants and metadata — no Craft state is read here.
 */
final class Role
{
    public const SUMMARY = 'summary';
    public const DESCRIPTION = 'description';
    public const FEATURED_IMAGE = 'featuredImage';
    public const GALLERY = 'gallery';
    public const CLIENT = 'client';
    public const COMPLETED_DATE = 'completedDate';
    public const PROJECT_URL = 'projectUrl';
    public const CATEGORIES = 'categories';
    public const TAGS = 'tags';

    /** Roles that hold one or more related elements, in the order the starter templates use them. */
    public const RELATIONAL = [
        self::FEATURED_IMAGE,
        self::GALLERY,
        self::CATEGORIES,
        self::TAGS,
    ];

    /** Roles the taxonomy is built from. Unticking these skips the category and tag groups too. */
    public const TAXONOMY = [
        self::CATEGORIES,
        self::TAGS,
    ];

    public static function all(): array
    {
        return [
            self::SUMMARY,
            self::DESCRIPTION,
            self::FEATURED_IMAGE,
            self::GALLERY,
            self::CLIENT,
            self::COMPLETED_DATE,
            self::PROJECT_URL,
            self::CATEGORIES,
            self::TAGS,
        ];
    }

    public static function exists(string $role): bool
    {
        return in_array($role, self::all(), true);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::SUMMARY => Craft::t('portfolio', 'Summary'),
            self::DESCRIPTION => Craft::t('portfolio', 'Description'),
            self::FEATURED_IMAGE => Craft::t('portfolio', 'Featured Image'),
            self::GALLERY => Craft::t('portfolio', 'Gallery'),
            self::CLIENT => Craft::t('portfolio', 'Client'),
            self::COMPLETED_DATE => Craft::t('portfolio', 'Completed'),
            self::PROJECT_URL => Craft::t('portfolio', 'Project URL'),
            self::CATEGORIES => Craft::t('portfolio', 'Categories'),
            self::TAGS => Craft::t('portfolio', 'Tags'),
            default => $role,
        };
    }

    public static function description(string $role): string
    {
        return match ($role) {
            self::SUMMARY => Craft::t('portfolio', 'A short teaser, shown on cards and archives.'),
            self::DESCRIPTION => Craft::t('portfolio', 'The full write-up of the project.'),
            self::FEATURED_IMAGE => Craft::t('portfolio', 'The single image that represents the project.'),
            self::GALLERY => Craft::t('portfolio', 'Additional images, shown on the project page.'),
            self::CLIENT => Craft::t('portfolio', 'Who the work was for.'),
            self::COMPLETED_DATE => Craft::t('portfolio', 'When the work was finished. Archives sort on this.'),
            self::PROJECT_URL => Craft::t('portfolio', 'A link to the live work.'),
            self::CATEGORIES => Craft::t('portfolio', 'What kind of work it is. Drives archive URLs and filtering.'),
            self::TAGS => Craft::t('portfolio', 'Free-form keywords, for looser grouping.'),
            default => '',
        };
    }
}
