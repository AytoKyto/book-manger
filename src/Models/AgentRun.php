<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;
use PDO;

final class AgentRun
{
    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM agent_runs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(int $bookId, ?int $chapterId, string $agentName, string $instruction): array
    {
        $db = Database::get();
        $stmt = $db->prepare(
            'INSERT INTO agent_runs (book_id, chapter_id, agent_name, instruction, status) VALUES (:book_id, :chapter_id, :agent_name, :instruction, :status)'
        );
        $stmt->execute([
            'book_id' => $bookId,
            'chapter_id' => $chapterId,
            'agent_name' => $agentName,
            'instruction' => $instruction,
            'status' => 'pending',
        ]);
        return self::find((int) $db->lastInsertId());
    }

    /** Atomically claims the oldest pending run so a single worker never double-processes one. */
    public static function claimNextPending(): ?array
    {
        $db = Database::get();
        $db->beginTransaction();

        $stmt = $db->query("SELECT * FROM agent_runs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
        $run = $stmt->fetch();

        if ($run === false) {
            $db->commit();
            return null;
        }

        $update = $db->prepare("UPDATE agent_runs SET status = 'running', started_at = datetime('now') WHERE id = :id AND status = 'pending'");
        $update->execute(['id' => $run['id']]);

        $db->commit();

        return $update->rowCount() === 1 ? self::find((int) $run['id']) : null;
    }

    public static function markAwaitingReview(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE agent_runs SET status = 'awaiting_review', completed_at = datetime('now') WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public static function markError(int $id, string $message): void
    {
        $stmt = Database::get()->prepare("UPDATE agent_runs SET status = 'error', error_message = :msg, completed_at = datetime('now') WHERE id = :id");
        $stmt->execute(['id' => $id, 'msg' => $message]);
    }

    public static function markStatus(int $id, string $status): void
    {
        $stmt = Database::get()->prepare('UPDATE agent_runs SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public static function cancelIfPending(int $id): bool
    {
        $stmt = Database::get()->prepare("UPDATE agent_runs SET status = 'rejected', completed_at = datetime('now') WHERE id = :id AND status = 'pending'");
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
    }
}
