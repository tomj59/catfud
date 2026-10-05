<?php

namespace App\Support;

/**
 * Turns free text from a spreadsheet or a retailer ("Cuts in Gravy", "Shreds & Pate", "Mince in Broth") into texture
 * and medium tag slugs. Unknown words are simply ignored (the raw text stays on the product for the audit).
 */
final class TextureParser
{
    private const TEXTURE = [
        'pate' => '/\b(pate|pât[eé]|paté)\b/iu', 'mousse' => '/\bmousse\b/i', 'shreds' => '/\bshred(s|ded)?\b/i',
        'chunks' => '/\bchunk(s|y)?\b/i', 'flaked' => '/\bflake(s|d)?\b/i', 'minced' => '/\bminc(e|ed)\b/i',
        'morsels' => '/\bmorsels?\b/i', 'cuts' => '/\bcuts\b/i', 'sliced' => '/\b(slices|sliced)\b/i', 'loaf' => '/\bloaf\b/i',
    ];

    private const MEDIUM = [
        'gravy' => '/\bgravy\b/i', 'broth' => '/\bbroth\b/i', 'sauce' => '/\bsauce\b/i',
        'jelly' => '/\b(jelly|gelee|gelée)\b/iu', 'aspic' => '/\baspic\b/i', 'stew' => '/\bstew\b/i',
    ];

    /** @return list<string> tag keys such as "texture:pate", "medium:gravy" */
    public static function tagKeys(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $keys = [];
        foreach (self::TEXTURE as $slug => $re) {
            if (preg_match($re, $text)) {
                $keys[] = "texture:{$slug}";
            }
        }
        foreach (self::MEDIUM as $slug => $re) {
            if (preg_match($re, $text)) {
                $keys[] = "medium:{$slug}";
            }
        }

        return $keys;
    }
}
