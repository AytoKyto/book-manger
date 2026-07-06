<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class RunDiff
{
    public static function createForRun(int $runId, string $filePath, string $diffText): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO run_diffs (run_id, file_path, diff_text) VALUES (:run_id, :file_path, :diff_text)'
        );
        $stmt->execute(['run_id' => $runId, 'file_path' => $filePath, 'diff_text' => $diffText]);
    }

    public static function forRun(int $runId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM run_diffs WHERE run_id = :run_id ORDER BY file_path ASC');
        $stmt->execute(['run_id' => $runId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM run_diffs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function setDecision(int $id, string $decision): void
    {
        $stmt = Database::get()->prepare("UPDATE run_diffs SET decision = :decision, applied_at = datetime('now') WHERE id = :id");
        $stmt->execute(['decision' => $decision, 'id' => $id]);
    }
}
