<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

final class BookStorage
{
    public static function bookPath(string $slug): string
    {
        return Config::$booksDir . '/' . $slug;
    }

    public static function chaptersDir(string $slug): string
    {
        return self::bookPath($slug) . '/chapitres';
    }

    public static function chapterPath(string $slug, string $filename): string
    {
        return self::chaptersDir($slug) . '/' . $filename;
    }

    /** Creates the on-disk skeleton for a new book: folders, seed files, agent personas. */
    public static function createBookFolder(string $slug, string $title): void
    {
        $root = self::bookPath($slug);
        if (is_dir($root)) {
            throw new \RuntimeException("Un dossier existe déjà pour ce livre : $slug");
        }

        mkdir($root, 0775, true);
        mkdir($root . '/chapitres', 0775, true);
        mkdir($root . '/.claude/agents', 0775, true);

        file_put_contents($root . '/contexte.md', "# Contexte du livre\n\n_Décris ici le pitch, le genre, le ton et les enjeux principaux. Ce texte est toujours transmis aux agents, quel que soit le chapitre traité._\n");
        file_put_contents($root . '/personnages.md', "# Personnages\n\n_Décris ici les personnages principaux pour aider les agents à vérifier la continuité._\n");
        file_put_contents($root . '/style-guide.md', "# Guide de style\n\n_Ton, registre de langue, règles perso pour l'agent styliste._\n");

        foreach (glob(Config::$agentsTemplatesDir . '/*.md') as $template) {
            copy($template, $root . '/.claude/agents/' . basename($template));
        }
    }

    public static function deleteBookFolder(string $slug): void
    {
        self::rrmdir(self::bookPath($slug));
    }

    public static function writeChapterFile(string $slug, string $filename, string $content): void
    {
        $dir = self::chaptersDir($slug);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(self::chapterPath($slug, $filename), $content);
    }

    public static function readChapterFile(string $slug, string $filename): string
    {
        $path = self::chapterPath($slug, $filename);
        return is_file($path) ? (file_get_contents($path) ?: '') : '';
    }

    public static function deleteChapterFile(string $slug, string $filename): void
    {
        $path = self::chapterPath($slug, $filename);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Generic read/write for any file inside a book (e.g. personnages.md), by path relative to the book root. */
    public static function readFileAtRelativePath(string $slug, string $relativePath): string
    {
        $path = self::bookPath($slug) . '/' . $relativePath;
        return is_file($path) ? (file_get_contents($path) ?: '') : '';
    }

    public static function writeFileAtRelativePath(string $slug, string $relativePath, string $content): void
    {
        $path = self::bookPath($slug) . '/' . $relativePath;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($path, $content);
    }

    /** Public wrapper so callers (e.g. run cleanup) can remove an arbitrary directory tree, not just a book. */
    public static function deleteTree(string $dir): void
    {
        self::rrmdir($dir);
    }

    public static function wordCount(string $content): int
    {
        $stripped = trim(preg_replace('/[#*_`>\-\[\]!]/', ' ', $content) ?? '');
        if ($stripped === '') {
            return 0;
        }
        return count(preg_split('/\s+/', $stripped));
    }

    /** Recursively copies a book folder to a working directory for an isolated agent run. */
    public static function copyTree(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $items = scandir($source);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $srcPath = $source . '/' . $item;
            $destPath = $dest . '/' . $item;
            if (is_dir($srcPath)) {
                self::copyTree($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /** @return string[] relative paths (from $root) of every file under $root */
    public static function listFilesRecursive(string $root): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $result[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }
        sort($result);
        return $result;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
