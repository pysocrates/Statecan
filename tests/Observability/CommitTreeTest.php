<?php

namespace App\Tests\Observability;

use App\Observability\CommitTree;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommitTreeTest extends KernelTestCase
{
    public function testVerticalForkAndMergeKeepRefTipsAndCollapseOnlyRealAncestry(): void
    {
        self::bootKernel();
        $sha = static fn (int $n): string => str_pad(dechex($n), 40, '0', STR_PAD_LEFT);
        $parents = [9 => [8], 8 => [4, 7], 7 => [6], 6 => [5], 5 => [2], 4 => [3], 3 => [2], 2 => [1], 1 => []];
        $rows = [];
        foreach ($parents as $id => $list) {
            $rows[] = ['sha' => $sha($id), 'parents' => json_encode(array_map($sha, $list)), 'message' => 'Commit '.$id];
        }
        $tree = self::getContainer()->get(CommitTree::class)->build($rows, ['refs/heads/main' => $sha(9), 'refs/heads/agents/alpha' => $sha(4), 'refs/heads/agents/beta' => $sha(7)], 6);
        self::assertCount(6, $tree['graph']['nodes']);
        self::assertCount(3, $tree['branches']);
        self::assertSame('graph TD', explode("\n", $tree['mermaid'])[0]);
        $nodes = $tree['graph']['nodes'];
        self::assertNotSame($nodes['c'.$sha(4)]['x'], $nodes['c'.$sha(7)]['x']);
        self::assertLessThan($nodes['c'.$sha(8)]['y'], $nodes['c'.$sha(4)]['y']);
        self::assertSame(['refs/heads/agents/alpha'], $nodes['c'.$sha(4)]['refs']);
        $edges = array_column($tree['graph']['edges'], null, 'to');
        self::assertSame(2, $edges['c'.$sha(7)]['skipped']);
        self::assertStringContainsString('|2 intermediate commits|', $tree['mermaid']);
        self::assertCount(2, array_filter($tree['graph']['edges'], static fn (array $edge): bool => 'c'.$sha(8) === $edge['to']));
        self::assertFalse($nodes['c'.$sha(1)]['boundary']);
    }
}
