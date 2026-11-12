<?php

namespace App\Observability;

/** Server-side visual layout for the identifier-only Mermaid subset emitted by projections. */
final class GraphPreview
{
    public function layout(string $source): array
    {
        preg_match_all('/\b[ct][a-f0-9]{16,64}\b/', $source, $matches);
        $ids = array_slice(array_values(array_unique($matches[0])), 0, 50);
        $levels = array_fill_keys($ids, 0);
        $indegree = array_fill_keys($ids, 0);
        $edges = [];
        preg_match_all('/\b([ct][a-f0-9]{16,64})\s+-->\s+([ct][a-f0-9]{16,64})\b/', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($levels[$match[1]], $levels[$match[2]])) {
                $edges[] = ['from' => $match[1], 'to' => $match[2]];
                ++$indegree[$match[2]];
            }
        }
        $queue = array_keys(array_filter($indegree, static fn (int $degree): bool => 0 === $degree));
        while ([] !== $queue) {
            $id = array_shift($queue);
            foreach ($edges as $edge) {
                if ($edge['from'] === $id) {
                    $levels[$edge['to']] = max($levels[$edge['to']], $levels[$id] + 1);
                    if (0 === --$indegree[$edge['to']]) {
                        $queue[] = $edge['to'];
                    }
                }
            }
        }
        $rows = [];
        $nodes = [];
        foreach ($levels as $id => $level) {
            $row = $rows[$level] ?? 0;
            $rows[$level] = $row + 1;
            $nodes[$id] = [
                'x' => 90 + 190 * $level, 'y' => 40 + 70 * $row,
                'label' => 'c' === $id[0] ? substr($id, 1, 12) : 'task …'.substr($id, -8),
            ];
        }

        return [
            'nodes' => $nodes, 'edges' => $edges,
            'width' => max(200, 190 * (1 + max([0, ...array_values($levels)]))),
            'height' => max(90, 70 * max([1, ...array_values($rows)])),
        ];
    }
}
