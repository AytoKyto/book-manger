<?php

declare(strict_types=1);

namespace App\Services;

final class Slug
{
    public static function make(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text;
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text === '' ? 'livre' : $text;
    }
}
