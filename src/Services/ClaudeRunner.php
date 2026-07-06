<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;
use App\Models\AgentRun;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\RunDiff;

/**
 * Drives one agent_runs row end to end: clones the book into an isolated
 * working directory, invokes the Claude Code CLI headlessly inside it
 * (never touching the real files), then diffs the result back for review.
 */
final class ClaudeRunner
{
    public static function execute(array $run): void
    {
        $runId = (int) $run['id'];
        $runDir = Config::$runsDir . '/' . $runId;
        $workDir = $runDir . '/workdir';
        $logPath = $runDir . '/log.ndjson';

        try {
            $book = Book::find((int) $run['book_id']);
            if ($book === null) {
                throw new \RuntimeException('Livre introuvable');
            }

            $chapter = $run['chapter_id'] !== null ? Chapter::find((int) $run['chapter_id']) : null;

            $agents = AgentTemplate::listAvailable();
            $agent = $agents[$run['agent_name']] ?? null;
            if ($agent === null) {
                throw new \RuntimeException("Agent inconnu : {$run['agent_name']}");
            }

            $originalRoot = BookStorage::bookPath($book['path']);
            BookStorage::copyTree($originalRoot, $workDir);

            if (!is_dir($runDir)) {
                mkdir($runDir, 0775, true);
            }
            touch($logPath);

            $prompt = self::buildPrompt($agent, $chapter, (string) $run['instruction']);

            $command = [
                Config::claudeBinary(),
                '-p',
                '--output-format', 'stream-json',
                '--verbose',
                '--include-partial-messages',
                '--allowedTools', implode(',', $agent->tools),
                '--permission-mode', $agent->permissionMode,
                $prompt,
            ];

            self::runProcess($command, $workDir, $logPath);

            $diffs = DiffService::diffTree($originalRoot, $workDir);
            foreach ($diffs as $path => $diffText) {
                RunDiff::createForRun($runId, $path, $diffText);
            }

            AgentRun::markAwaitingReview($runId);
        } catch (\Throwable $e) {
            AgentRun::markError($runId, $e->getMessage());
        }
    }

    private static function buildPrompt(AgentTemplate $agent, ?array $chapter, string $instruction): string
    {
        $lines = [];
        $lines[] = "Utilise le sous-agent « {$agent->name} » pour traiter la demande suivante.";

        if ($chapter !== null) {
            $lines[] = "Fichier concerné : chapitres/{$chapter['filename']}.";
            $lines[] = 'Ne modifie aucun autre fichier que celui-ci.';
        } else {
            $lines[] = "La demande concerne l'ensemble du livre (tous les fichiers du dossier chapitres/).";
        }

        if (trim($instruction) !== '') {
            $lines[] = 'Instruction complémentaire : ' . trim($instruction);
        }

        $lines[] = 'Termine ta réponse par un résumé clair des changements effectués (ou de ce que tu as trouvé, si tu ne modifies rien).';

        return implode("\n", $lines);
    }

    /** Spawns the CLI via argv (no shell involved) and streams stdout into the NDJSON log as it arrives. */
    private static function runProcess(array $command, string $cwd, string $logPath): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd, null);
        if (!is_resource($process)) {
            throw new \RuntimeException('Impossible de démarrer le CLI Claude');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $log = fopen($logPath, 'ab');
        $start = time();
        $timeout = Config::runTimeoutSeconds();
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);

            $stdout = stream_get_contents($pipes[1]);
            if ($stdout !== false && $stdout !== '') {
                fwrite($log, $stdout);
            }

            $stderr = stream_get_contents($pipes[2]);
            if ($stderr !== false && $stderr !== '') {
                fwrite($log, json_encode(['type' => 'stderr', 'text' => $stderr], JSON_UNESCAPED_UNICODE) . "\n");
            }

            if (!$status['running']) {
                break;
            }

            if (time() - $start > $timeout) {
                proc_terminate($process, 15);
                $timedOut = true;
                fwrite($log, json_encode(['type' => 'error', 'text' => 'Délai maximum dépassé, run interrompu'], JSON_UNESCAPED_UNICODE) . "\n");
                break;
            }

            usleep(200_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        fclose($log);

        if ($timedOut) {
            throw new \RuntimeException('Le run a dépassé le délai maximum autorisé');
        }
        if ($exitCode !== 0) {
            throw new \RuntimeException("Le CLI Claude a quitté avec le code $exitCode");
        }
    }
}
