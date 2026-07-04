<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Response;
use App\Services\BookStorage;

final class ChaptersController
{
    public static function create(array $args): void
    {
        $book = Book::find((int) $args['bookId']);
        if ($book === null) {
            Response::json(['error' => 'book_not_found'], 404);
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            Response::json(['error' => 'title_required'], 422);
            return;
        }

        $chapter = Chapter::create($book, $title);
        Response::json(['chapter' => $chapter], 201);
    }

    public static function show(array $args): void
    {
        [$book, $chapter, $error] = self::resolve($args);
        if ($error !== null) {
            Response::json(['error' => $error], 404);
            return;
        }

        $chapter['content'] = BookStorage::readChapterFile($book['path'], $chapter['filename']);
        Response::json(['chapter' => $chapter]);
    }

    public static function update(array $args): void
    {
        [$book, $chapter, $error] = self::resolve($args);
        if ($error !== null) {
            Response::json(['error' => $error], 404);
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $content = (string) ($body['content'] ?? '');
        $title = trim((string) ($body['title'] ?? $chapter['title']));

        Chapter::updateContent($book, $chapter, $content, 'manual');

        if ($title !== $chapter['title']) {
            $stmt = \App\Database::get()->prepare('UPDATE chapters SET title = :title WHERE id = :id');
            $stmt->execute(['title' => $title, 'id' => $chapter['id']]);
        }

        Response::json(['chapter' => Chapter::find((int) $chapter['id'])]);
    }

    public static function delete(array $args): void
    {
        [$book, $chapter, $error] = self::resolve($args);
        if ($error !== null) {
            Response::json(['error' => $error], 404);
            return;
        }
        Chapter::delete($book, $chapter);
        Response::noContent();
    }

    public static function reorder(array $args): void
    {
        $book = Book::find((int) $args['bookId']);
        if ($book === null) {
            Response::json(['error' => 'book_not_found'], 404);
            return;
        }
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $orderedIds = array_map('intval', $body['chapter_ids'] ?? []);
        Chapter::reorder((int) $book['id'], $orderedIds);
        Response::json(['chapters' => Chapter::forBook((int) $book['id'])]);
    }

    public static function snapshots(array $args): void
    {
        [$book, $chapter, $error] = self::resolve($args);
        if ($error !== null) {
            Response::json(['error' => $error], 404);
            return;
        }
        Response::json(['snapshots' => Chapter::snapshots((int) $chapter['id'])]);
    }

    public static function restoreSnapshot(array $args): void
    {
        [$book, $chapter, $error] = self::resolve($args);
        if ($error !== null) {
            Response::json(['error' => $error], 404);
            return;
        }
        Chapter::restoreSnapshot($book, $chapter, (int) $args['snapshotId']);
        Response::json(['chapter' => Chapter::find((int) $chapter['id'])]);
    }

    /** @return array{0: ?array, 1: ?array, 2: ?string} */
    private static function resolve(array $args): array
    {
        $book = Book::find((int) $args['bookId']);
        if ($book === null) {
            return [null, null, 'book_not_found'];
        }
        $chapter = Chapter::find((int) $args['chapterId']);
        if ($chapter === null || (int) $chapter['book_id'] !== (int) $book['id']) {
            return [null, null, 'chapter_not_found'];
        }
        return [$book, $chapter, null];
    }
}
