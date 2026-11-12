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
        preg_match_all('/\b([ct][a-f0-9]{16,64})\s+-->\s*(?:\|[^|\n]*\|\s*)?([ct][a-f0-9]{16,64})\b/', $source, $matches, PREG_SET_ORDER);
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
        $vertical = (bool) preg_match('/^(?:graph|flowchart)\s+(?:TD|TB)\b/', trim($source));
        $positions = [];
        if ($vertical) {
            $children = array_fill_keys($ids, []);
            foreach ($edges as $edge) {
                $children[$edge['from']][] = $edge['to'];
            }
            $leaves = [];
            foreach ($children as $id => $list) {
                if ([] === $list) {
                    $leaves[$id] = [count($leaves)];
                }
            }
            $bottomUp = $levels;
            arsort($bottomUp);
            foreach ($bottomUp as $id => $_) {
                $lanes = $leaves[$id] ?? [];
                foreach ($children[$id] as $child) {
                    $lanes = [...$lanes, ...($leaves[$child] ?? [])];
                }
                $leaves[$id] = $lanes = array_unique($lanes ?: [0]);
                $positions[$id] = 120 + 230 * array_sum($lanes) / count($lanes);
            }
            // Keep independent branches in their leaf lanes. Separate any shared
            // junctions that land too close together at the same depth.
            foreach (array_unique($levels) as $level) {
                $rank = array_intersect_key($positions, array_filter($levels, static fn (int $value): bool => $value === $level));
                asort($rank);
                $previous = -110;
                foreach ($rank as $id => $x) {
                    $positions[$id] = $previous = max($x, $previous + 230);
                }
            }
        }
        foreach ($levels as $id => $level) {
            $row = $rows[$level] ?? 0;
            $rows[$level] = $row + 1;
            $nodes[$id] = [
                'x' => $vertical ? $positions[$id] : 90 + 190 * $level,
                'y' => $vertical ? 65 + 105 * $level : 40 + 70 * $row,
                'label' => 'c' === $id[0] ? substr($id, 1, 12) : 'task …'.substr($id, -8),
            ];
        }

        return [
            'nodes' => $nodes, 'edges' => $edges,
            'width' => $vertical ? max(260, max([120, ...$positions]) + 120) : max(200, 190 * (1 + max([0, ...array_values($levels)]))),
            'height' => $vertical ? 130 + 105 * max([0, ...array_values($levels)]) : max(90, 70 * max([1, ...array_values($rows)])),
        ];
    }
}
