<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Response;
use App\Services\BookStorage;

final class BooksController
{
    public static function index(): void
    {
        Response::json(['books' => Book::all()]);
    }

    public static function show(array $args): void
    {
        $book = Book::find((int) $args['id']);
        if ($book === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        $book['chapters'] = Chapter::forBook((int) $book['id']);
        $book['context'] = BookStorage::readFileAtRelativePath($book['path'], 'contexte.md');
        Response::json(['book' => $book]);
    }

    public static function updateContext(array $args): void
    {
        $book = Book::find((int) $args['id']);
        if ($book === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $content = (string) ($body['content'] ?? '');

        BookStorage::writeFileAtRelativePath($book['path'], 'contexte.md', $content);
        Book::touch((int) $book['id']);

        Response::json(['context' => $content]);
    }

    public static function create(): void
    {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            Response::json(['error' => 'title_required'], 422);
            return;
        }

        $genre = trim((string) ($body['genre'] ?? ''));
        $wordTarget = (int) ($body['word_target'] ?? 0);

        $book = Book::create($title, $genre, $wordTarget);
        Response::json(['book' => $book], 201);
    }

    public static function delete(array $args): void
    {
        $id = (int) $args['id'];
        if (Book::find($id) === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        Book::delete($id);
        Response::noContent();
    }
}
