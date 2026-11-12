<?php

namespace App\Tests\Git;

use App\Git\LocalGitReader;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class LocalGitReaderTest extends KernelTestCase
{
    public function testCollectsCommitFilesAndTrailers(): void
    {
        $path = sys_get_temp_dir().'/statecan-git-'.bin2hex(random_bytes(6));
        mkdir($path);
        try {
            (new Process(['git', 'init', '-b', 'main', $path]))->mustRun();
            file_put_contents($path.'/hello.txt', 'hello');
            (new Process(['git', '-C', $path, 'add', 'hello.txt']))->mustRun();
            (new Process(['git', '-C', $path, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '-m', "Initial\n\nStatecan-Task: task-id"]))->mustRun();
            $snapshot = (new LocalGitReader($path))->snapshot($path);
            self::assertCount(1, $snapshot['commits']);
            self::assertArrayHasKey('refs/heads/main', $snapshot['refs']);
            $commit = array_values($snapshot['commits'])[0];
            self::assertSame(['hello.txt'], $commit['files']);
            self::assertSame(['task-id'], $commit['trailers']['task']);
        } finally {
            (new Filesystem())->remove($path);
        }
    }
}
