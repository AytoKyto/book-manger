<?php

declare(strict_types=1);

namespace App\Services;

final class DiffService
{
    /** Unified diff between two strings, or null when identical. Uses the system `diff` binary via argv (no shell). */
    public static function diffText(string $originalContent, string $workingContent, string $label = 'chapitre'): ?string
    {
        if ($originalContent === $workingContent) {
            return null;
        }

        $origFile = tempnam(sys_get_temp_dir(), 'bm_orig_');
        $workFile = tempnam(sys_get_temp_dir(), 'bm_work_');
        file_put_contents($origFile, $originalContent);
        file_put_contents($workFile, $workingContent);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['diff', '-u', '--label', "avant/$label", '--label', "après/$label", $origFile, $workFile],
            $descriptors,
            $pipes
        );

        $diff = null;
        if (is_resource($process)) {
            $diff = stream_get_contents($pipes[1]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        unlink($origFile);
        unlink($workFile);

        return ($diff === null || $diff === '') ? null : $diff;
    }

    /**
     * Compares two directory trees recursively and returns a unified diff per
     * changed file (added, removed, or modified). `.claude/` config files are
     * never part of the reviewable diff surface.
     *
     * @return array<string, string> relative file path => unified diff
     */
    public static function diffTree(string $originalRoot, string $workingRoot): array
    {
        $originalFiles = is_dir($originalRoot) ? BookStorage::listFilesRecursive($originalRoot) : [];
        $workingFiles = is_dir($workingRoot) ? BookStorage::listFilesRecursive($workingRoot) : [];

        $allPaths = array_unique(array_filter(
            array_merge($originalFiles, $workingFiles),
            fn (string $p) => !str_starts_with($p, '.claude/')
        ));
        sort($allPaths);

        $diffs = [];
        foreach ($allPaths as $path) {
            $origPath = $originalRoot . '/' . $path;
            $workPath = $workingRoot . '/' . $path;
            $origContent = is_file($origPath) ? (file_get_contents($origPath) ?: '') : '';
            $workContent = is_file($workPath) ? (file_get_contents($workPath) ?: '') : '';

            $diff = self::diffText($origContent, $workContent, $path);
            if ($diff !== null) {
                $diffs[$path] = $diff;
            }
        }

        return $diffs;
    }
}
