<?php

declare(strict_types=1);

namespace App\Services;

use App\Config;

/** Reads the tiny YAML-like frontmatter of a .claude/agents/*.md persona file. */
final class AgentTemplate
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $tools,
        public readonly string $model,
        public readonly string $permissionMode,
        public readonly string $body,
    ) {
    }

    public static function fromFile(string $path): self
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Impossible de lire l'agent : $path");
        }

        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
            throw new \RuntimeException("Frontmatter invalide dans : $path");
        }

        $fields = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (trim($line) === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $fields[trim($key)] = trim($value);
        }

        $tools = array_map('trim', explode(',', $fields['tools'] ?? 'Read'));

        return new self(
            name: $fields['name'] ?? pathinfo($path, PATHINFO_FILENAME),
            description: $fields['description'] ?? '',
            tools: $tools,
            model: $fields['model'] ?? 'sonnet',
            permissionMode: $fields['permissionMode'] ?? 'default',
            body: trim($m[2]),
        );
    }

    /** @return array<string, self> keyed by agent name */
    public static function listAvailable(): array
    {
        $agents = [];
        foreach (glob(Config::$agentsTemplatesDir . '/*.md') as $file) {
            $agent = self::fromFile($file);
            $agents[$agent->name] = $agent;
        }
        return $agents;
    }
}
