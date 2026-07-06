<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Book;
use App\Models\Chapter;
use App\Response;

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
        Response::json(['book' => $book]);
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
