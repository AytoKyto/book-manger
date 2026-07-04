<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use App\Services\BookStorage;
use App\Services\Slug;

final class Chapter
{
    public static function forBook(int $bookId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM chapters WHERE book_id = :book_id ORDER BY order_index ASC');
        $stmt->execute(['book_id' => $bookId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM chapters WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $book, string $title): array
    {
        $db = Database::get();

        $stmt = $db->prepare('SELECT COALESCE(MAX(order_index), -1) + 1 FROM chapters WHERE book_id = :book_id');
        $stmt->execute(['book_id' => $book['id']]);
        $orderIndex = (int) $stmt->fetchColumn();

        $filename = sprintf('%02d-%s.md', $orderIndex + 1, Slug::make($title));

        BookStorage::writeChapterFile($book['path'], $filename, "# $title\n\n");

        $stmt = $db->prepare(
            'INSERT INTO chapters (book_id, title, filename, order_index, word_count) VALUES (:book_id, :title, :filename, :order_index, 0)'
        );
        $stmt->execute([
            'book_id' => $book['id'],
            'title' => $title,
            'filename' => $filename,
            'order_index' => $orderIndex,
        ]);

        Book::touch((int) $book['id']);

        return self::find((int) $db->lastInsertId());
    }

    public static function updateContent(array $book, array $chapter, string $content, string $snapshotReason = 'manual'): void
    {
        $previous = BookStorage::readChapterFile($book['path'], $chapter['filename']);
        if ($previous !== '' && $previous !== $content) {
            self::snapshot((int) $chapter['id'], $previous, $snapshotReason);
        }

        BookStorage::writeChapterFile($book['path'], $chapter['filename'], $content);

        $stmt = Database::get()->prepare(
            "UPDATE chapters SET word_count = :word_count, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([
            'word_count' => BookStorage::wordCount($content),
            'id' => $chapter['id'],
        ]);

        Book::touch((int) $book['id']);
    }

    public static function delete(array $book, array $chapter): void
    {
        BookStorage::deleteChapterFile($book['path'], $chapter['filename']);
        $stmt = Database::get()->prepare('DELETE FROM chapters WHERE id = :id');
        $stmt->execute(['id' => $chapter['id']]);
    }

    public static function reorder(int $bookId, array $orderedIds): void
    {
        $db = Database::get();
        $stmt = $db->prepare('UPDATE chapters SET order_index = :idx WHERE id = :id AND book_id = :book_id');
        foreach ($orderedIds as $index => $id) {
            $stmt->execute(['idx' => $index, 'id' => $id, 'book_id' => $bookId]);
        }
    }

    public static function snapshots(int $chapterId): array
    {
        $stmt = Database::get()->prepare('SELECT id, reason, created_at FROM snapshots WHERE chapter_id = :chapter_id ORDER BY created_at DESC');
        $stmt->execute(['chapter_id' => $chapterId]);
        return $stmt->fetchAll();
    }

    public static function restoreSnapshot(array $book, array $chapter, int $snapshotId): void
    {
        $stmt = Database::get()->prepare('SELECT content FROM snapshots WHERE id = :id AND chapter_id = :chapter_id');
        $stmt->execute(['id' => $snapshotId, 'chapter_id' => $chapter['id']]);
        $content = $stmt->fetchColumn();
        if ($content === false) {
            throw new \RuntimeException('Snapshot introuvable');
        }
        self::updateContent($book, $chapter, $content, 'before_restore');
    }

    private static function snapshot(int $chapterId, string $content, string $reason): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO snapshots (chapter_id, content, reason) VALUES (:chapter_id, :content, :reason)'
        );
        $stmt->execute(['chapter_id' => $chapterId, 'content' => $content, 'reason' => $reason]);
    }
}
