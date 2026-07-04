<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\Services\BookStorage;
use App\Services\Slug;

final class Book
{
    public static function all(): array
    {
        return Database::get()->query('SELECT * FROM books ORDER BY updated_at DESC')->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM books WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $title, string $genre, int $wordTarget): array
    {
        $db = Database::get();
        $slug = Slug::make($title);
        $base = $slug;
        $i = 2;
        while (self::slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }

        BookStorage::createBookFolder($slug, $title);

        $stmt = $db->prepare(
            'INSERT INTO books (title, slug, path, genre, status, word_target) VALUES (:title, :slug, :path, :genre, :status, :word_target)'
        );
        $stmt->execute([
            'title' => $title,
            'slug' => $slug,
            'path' => $slug,
            'genre' => $genre,
            'status' => 'draft',
            'word_target' => $wordTarget,
        ]);

        return self::find((int) $db->lastInsertId());
    }

    public static function delete(int $id): void
    {
        $book = self::find($id);
        if ($book === null) {
            return;
        }
        BookStorage::deleteBookFolder($book['path']);
        $stmt = Database::get()->prepare('DELETE FROM books WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public static function touch(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE books SET updated_at = datetime('now') WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    private static function slugExists(string $slug): bool
    {
        $stmt = Database::get()->prepare('SELECT 1 FROM books WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        return (bool) $stmt->fetchColumn();
    }
}
