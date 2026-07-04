<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Models\AgentRun;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\RunDiff;
use App\Response;
use App\Services\AgentTemplate;
use App\Services\BookStorage;

final class RunsController
{
    public static function create(array $args): void
    {
        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $bookId = (int) ($body['book_id'] ?? 0);
        $chapterId = isset($body['chapter_id']) && $body['chapter_id'] !== null ? (int) $body['chapter_id'] : null;
        $agentName = trim((string) ($body['agent_name'] ?? ''));
        $instruction = trim((string) ($body['instruction'] ?? ''));

        $book = Book::find($bookId);
        if ($book === null) {
            Response::json(['error' => 'book_not_found'], 404);
            return;
        }

        if ($chapterId !== null) {
            $chapter = Chapter::find($chapterId);
            if ($chapter === null || (int) $chapter['book_id'] !== $bookId) {
                Response::json(['error' => 'chapter_not_found'], 404);
                return;
            }
        }

        if (!isset(AgentTemplate::listAvailable()[$agentName])) {
            Response::json(['error' => 'unknown_agent'], 422);
            return;
        }

        $run = AgentRun::create($bookId, $chapterId, $agentName, $instruction);
        Response::json(['run' => $run], 201);
    }

    public static function show(array $args): void
    {
        $run = AgentRun::find((int) $args['id']);
        if ($run === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        Response::json(['run' => $run]);
    }

    public static function diffs(array $args): void
    {
        $run = AgentRun::find((int) $args['id']);
        if ($run === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        Response::json(['diffs' => RunDiff::forRun((int) $run['id'])]);
    }

    public static function decideDiff(array $args): void
    {
        $diff = RunDiff::find((int) $args['diffId']);
        if ($diff === null || (int) $diff['run_id'] !== (int) $args['id']) {
            Response::json(['error' => 'diff_not_found'], 404);
            return;
        }

        $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?? [];
        $decision = (string) ($body['decision'] ?? '');
        if (!in_array($decision, ['accepted', 'rejected'], true)) {
            Response::json(['error' => 'invalid_decision'], 422);
            return;
        }

        RunDiff::setDecision((int) $diff['id'], $decision);
        Response::json(['diff' => RunDiff::find((int) $diff['id'])]);
    }

    public static function finalize(array $args): void
    {
        $run = AgentRun::find((int) $args['id']);
        if ($run === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        if ($run['status'] !== 'awaiting_review') {
            Response::json(['error' => 'not_awaiting_review'], 409);
            return;
        }

        $book = Book::find((int) $run['book_id']);
        $workDir = Config::$runsDir . '/' . $run['id'] . '/workdir';
        $chapters = Chapter::forBook((int) $book['id']);

        $applied = [];
        foreach (RunDiff::forRun((int) $run['id']) as $diff) {
            if ($diff['decision'] !== 'accepted') {
                continue;
            }

            $relPath = $diff['file_path'];
            $workFile = $workDir . '/' . $relPath;
            $newContent = is_file($workFile) ? (file_get_contents($workFile) ?: '') : '';

            $chapter = self::matchChapterByPath($chapters, $relPath);
            if ($chapter !== null) {
                Chapter::updateContent($book, $chapter, $newContent, 'before_agent_apply');
            } else {
                BookStorage::writeFileAtRelativePath($book['path'], $relPath, $newContent);
            }

            $applied[] = $relPath;
        }

        AgentRun::markStatus((int) $run['id'], 'applied');
        self::cleanupWorkdir((int) $run['id']);

        Response::json(['applied_files' => $applied]);
    }

    public static function cancel(array $args): void
    {
        $run = AgentRun::find((int) $args['id']);
        if ($run === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }
        if ($run['status'] === 'running') {
            Response::json(['error' => 'run_in_progress'], 409);
            return;
        }

        if ($run['status'] === 'pending') {
            AgentRun::cancelIfPending((int) $run['id']);
        } else {
            AgentRun::markStatus((int) $run['id'], 'rejected');
        }

        self::cleanupWorkdir((int) $run['id']);
        Response::noContent();
    }

    /** Server-Sent Events: tails the run's NDJSON log and closes once the run leaves 'running'. */
    public static function events(array $args): void
    {
        $run = AgentRun::find((int) $args['id']);
        if ($run === null) {
            Response::json(['error' => 'not_found'], 404);
            return;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ini_set('zlib.output_compression', '0');
        ini_set('output_buffering', 'off');

        $logPath = Config::$runsDir . '/' . $run['id'] . '/log.ndjson';
        $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
        $deadline = time() + 3600;

        while (true) {
            clearstatcache(true, $logPath);
            if (is_file($logPath)) {
                $size = filesize($logPath);
                if ($size > $offset) {
                    $fh = fopen($logPath, 'rb');
                    fseek($fh, $offset);
                    $chunk = fread($fh, $size - $offset);
                    fclose($fh);
                    $offset = $size;

                    foreach (explode("\n", rtrim($chunk, "\n")) as $line) {
                        if ($line === '') {
                            continue;
                        }
                        echo "event: log\n";
                        echo 'data: ' . $line . "\n\n";
                    }
                    @flush();
                }
            }

            $current = AgentRun::find((int) $run['id']);
            $stillInFlight = $current !== null && in_array($current['status'], ['pending', 'running'], true);
            if (!$stillInFlight) {
                echo "event: status\n";
                echo 'data: ' . json_encode(['status' => $current['status'] ?? 'unknown', 'offset' => $offset]) . "\n\n";
                @flush();
                break;
            }

            if (connection_aborted() || time() > $deadline) {
                break;
            }
            usleep(500_000);
        }
    }

    private static function matchChapterByPath(array $chapters, string $relPath): ?array
    {
        if (!str_starts_with($relPath, 'chapitres/')) {
            return null;
        }
        $filename = substr($relPath, strlen('chapitres/'));
        foreach ($chapters as $chapter) {
            if ($chapter['filename'] === $filename) {
                return $chapter;
            }
        }
        return null;
    }

    private static function cleanupWorkdir(int $runId): void
    {
        $dir = Config::$runsDir . '/' . $runId;
        if (is_dir($dir)) {
            BookStorage::deleteTree($dir);
        }
    }
}
