<?php

declare(strict_types=1);

// Long-running daemon: polls agent_runs for pending work and processes it one
// at a time. Deliberately single-threaded — the Claude Code CLI's concurrent
// session limits per subscription aren't documented, so we never risk it.

require __DIR__ . '/../src/autoload.php';

use App\Config;
use App\Models\AgentRun;
use App\Services\ClaudeRunner;

Config::init(dirname(__DIR__));

fwrite(STDOUT, "[worker] démarré — en attente de runs (Ctrl+C pour arrêter)\n");

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
    pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
}

while ($running) {
    $run = AgentRun::claimNextPending();

    if ($run === null) {
        sleep(2);
        continue;
    }

    fwrite(STDOUT, sprintf("[worker] run #%d (%s) démarré\n", $run['id'], $run['agent_name']));
    ClaudeRunner::execute($run);
    fwrite(STDOUT, sprintf("[worker] run #%d terminé\n", $run['id']));
}

fwrite(STDOUT, "[worker] arrêt propre\n");
