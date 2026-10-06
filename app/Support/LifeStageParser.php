<?php

namespace App\Support;

/**
 * Life stage ("Kitten", "Adult 7+", "Senior", "All Life Stages") is a facet that travels with the product, not a rung of
 * the brand ladder: one maker prints it inside a line name, another in the product name, another not at all. Reading it
 * out of free text keeps it off the ladder (so a line is only ever a line) and on a tag we can filter by.
 */
final class LifeStageParser
{
    /** The words that, on their own, make a line or variety label a life stage rather than a product line. */
    private const WORDS = '/\b(kittens?|adults?|seniors?|mature|all|life|stages?|and|for|cats?)\b|\d+\s*\+|[&,\/|+\-]/iu';

    /** @return list<string> tag keys such as "life_stage:kitten" */
    public static function tagKeys(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }
        $keys = [];
        if (preg_match('/\ball\s+life\s*stages?\b/i', $text)) {
            $keys[] = 'life_stage:all';
        }
        if (preg_match('/\bkittens?\b/i', $text)) {
            $keys[] = 'life_stage:kitten';
        }
        if (preg_match('/\b(adult|senior|mature)\s*(cat\s*)?\d{1,2}\s*\+|\b7\s*\+/i', $text)) {
            $keys[] = 'life_stage:adult-7plus';
        } elseif (preg_match('/\b(seniors?|mature)\b/i', $text)) {
            $keys[] = 'life_stage:senior';
        } elseif (preg_match('/\badults?\b/i', $text)) {
            $keys[] = 'life_stage:adult';
        }

        return $keys;
    }

    /** "Kitten", "Senior 7+", "Adult 7+", "All Life Stages" yes; "Adult Indoor", "Prime Plus" no. */
    public static function isOnlyLifeStage(?string $text): bool
    {
        if ($text === null || trim($text) === '' || ! self::tagKeys($text)) {
            return false;
        }

        return trim(preg_replace(self::WORDS, '', $text)) === '';
    }
}
