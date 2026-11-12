<?php

namespace App\Observability;

/** A bounded view of actual ancestry; collapsed paths never imply a merge. */
final readonly class CommitTree
{
    public function __construct(private GraphPreview $preview)
    {
    }

    public function build(array $rows, array $refs, int $limit = 50): array
    {
        $commits = [];
        foreach ($rows as $row) {
            if (preg_match('/^[a-f0-9]{40,64}$/D', $row['sha'])) {
                $row['parents'] = json_decode($row['parents'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
                $commits[$row['sha']] = $row;
            }
        }
        $children = array_fill_keys(array_keys($commits), []);
        foreach ($commits as $sha => $row) {
            foreach ($row['parents'] as $parent) {
                if (isset($children[$parent])) {
                    $children[$parent][] = $sha;
                }
            }
        }
        $tips = [];
        foreach ($refs as $ref => $sha) {
            if (isset($commits[$sha])) {
                $tips[$sha][] = $ref;
            }
        }
        // Keep branch tips, forks, merges and ancestry boundaries. A compact tree
        // omits ordinary linear history; the timeline still exposes each commit.
        $selected = [];
        foreach (array_keys($tips) as $sha) {
            $selected[$sha] = true;
        }
        foreach ($commits as $sha => $row) {
            if (count($children[$sha]) > 1 || count($row['parents']) > 1 || [] === $children[$sha] || [] === $row['parents'] || array_diff($row['parents'], array_keys($commits))) {
                $selected[$sha] = true;
            }
        }
        foreach (array_keys($tips) as $sha) {
            $parent = $commits[$sha]['parents'][0] ?? null;
            if (isset($commits[$parent])) {
                $selected[$parent] = true;
            }
        }
        $selected = array_slice($selected, 0, $limit, true);
        $lines = ['graph TD'];
        foreach ($selected as $sha => $_) {
            $lines[] = 'c'.$sha.'["'.substr($sha, 0, 12).'"]';
        }
        $paths = [];
        foreach ($selected as $sha => $_) {
            foreach ($commits[$sha]['parents'] as $parent) {
                $pending = [[$parent, 0]];
                $seen = [$sha => true];
                while ($pending) {
                    [$ancestor, $skipped] = array_shift($pending);
                    if (isset($seen[$ancestor]) || !isset($commits[$ancestor])) {
                        continue;
                    }
                    $seen[$ancestor] = true;
                    if (isset($selected[$ancestor])) {
                        $lines[] = 'c'.$ancestor.' -->'.($skipped ? '|'.$skipped.' intermediate commits|' : '').' c'.$sha;
                        $paths['c'.$ancestor.':c'.$sha] = $skipped;
                    } else {
                        foreach ($commits[$ancestor]['parents'] as $next) {
                            $pending[] = [$next, $skipped + 1];
                        }
                    }
                }
            }
        }
        $source = implode("\n", array_unique($lines));
        $graph = $this->preview->layout($source);
        foreach ($graph['nodes'] as $id => &$node) {
            $sha = substr($id, 1);
            $node['subject'] = explode("\n", $commits[$sha]['message'] ?? '')[0];
            $node['refs'] = $tips[$sha] ?? [];
            $node['boundary'] = (bool) array_diff($commits[$sha]['parents'], array_keys($commits));
        }
        unset($node);
        foreach ($graph['edges'] as &$edge) {
            $edge['skipped'] = $paths[$edge['from'].':'.$edge['to']] ?? 0;
        }
        unset($edge);
        $branches = [];
        foreach ($refs as $ref => $sha) {
            $branches[] = ['ref' => $ref, 'sha' => $sha, 'visible' => isset($selected[$sha])];
        }

        return ['graph' => $graph, 'mermaid' => $source, 'branches' => $branches, 'candidate_count' => count($commits)];
    }
}
