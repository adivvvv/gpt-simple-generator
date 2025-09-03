<?php
// src/Services/IdeaStore.php
declare(strict_types=1);

namespace App\Services;

final class IdeaStore
{
    private string $file;

    public function __construct(string $lang)
    {
        $base = __DIR__ . '/../../storage/cache';
        if (!is_dir($base)) @mkdir($base, 0775, true);
        $this->file = $base . '/ideas_' . $lang . '.json';
    }

    public function load(): array
    {
        if (!is_file($this->file)) return ['ideas'=>[],'index'=>[]];
        $data = json_decode((string)file_get_contents($this->file), true);
        if (!is_array($data) || !isset($data['ideas'])) return ['ideas'=>[],'index'=>[]];
        $data['index'] = $data['index'] ?? [];
        return $data;
    }

    public function save(array $data): void
    {
        @file_put_contents($this->file, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    public function addIdeas(array $newIdeas, int $cap = 2000): int
    {
        $data = $this->load();
        $index = $data['index'] ?? [];
        $ideas = $data['ideas'] ?? [];
        $added = 0;

        foreach ($newIdeas as $i) {
            if (!is_array($i)) continue;
            $title = trim((string)($i['title'] ?? ''));
            $pk    = trim((string)($i['primary_keyword'] ?? ''));
            if ($title === '' || $pk === '') continue;

            $key = mb_strtolower(preg_replace('/\s+/', ' ', $title)) . '|' . mb_strtolower($pk);
            if (isset($index[$key])) continue;

            $ideas[] = $i;
            $index[$key] = 1;
            $added++;

            if (count($ideas) >= $cap) break;
        }

        $this->save(['ideas'=>$ideas,'index'=>$index]);
        return $added;
    }

    public function list(int $limit = 100, bool $shuffle = true): array
    {
        $data = $this->load();
        $ideas = $data['ideas'] ?? [];
        if ($shuffle) shuffle($ideas);
        return array_slice($ideas, 0, max(1,$limit));
    }

    public function count(): int
    {
        $data = $this->load();
        return isset($data['ideas']) ? count($data['ideas']) : 0;
    }
}
