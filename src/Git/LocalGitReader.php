<?php

namespace App\Git;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

final class LocalGitReader
{
    public function __construct(
        #[Autowire(env: 'resolve:STATECAN_GIT_ROOT')] private readonly string $root,
    ) {
    }

    public function canonicalPath(string $path): string
    {
        $root = realpath($this->root);
        $real = realpath($path);
        if (false === $root || false === $real || !is_dir($real) || !str_starts_with($real.'/', $root.'/')) {
            throw new \InvalidArgumentException('Repository must be inside the configured Git root.');
        }
        $gitDir = realpath(trim($this->run($real, ['rev-parse', '--absolute-git-dir'])));
        if (false === $gitDir || !str_starts_with($gitDir.'/', $root.'/')) {
            throw new \InvalidArgumentException('Git directory must be inside the configured Git root.');
        }

        return $real;
    }

    public function snapshot(string $path, ?callable $heartbeat = null): array
    {
        $path = $this->canonicalPath($path);
        $refs = [];
        foreach (explode("\n", trim($this->run($path, ['for-each-ref', '--format=%(refname) %(objectname)', 'refs/heads', 'refs/remotes', 'refs/tags']))) as $line) {
            if (str_contains($line, ' ')) {
                [$name, $sha] = explode(' ', $line, 2);
                $refs[$name] = $sha;
            }
        }
        $commits = [];
        foreach ($refs as $ref => $tip) {
            // --end-of-options and validated ref names prevent revision option injection.
            foreach (explode("\n", trim($this->run($path, ['rev-list', '--end-of-options', $tip, '--']))) as $sha) {
                if (preg_match('/^[a-f0-9]{40,64}$/D', $sha) && !isset($commits[$sha])) {
                    $heartbeat && $heartbeat();
                    $parts = explode("\0", $this->run($path, ['show', '-s', '--format=%P%x00%an%x00%ae%x00%aI%x00%B', $sha, '--']), 5);
                    $body = $parts[4] ?? '';
                    $trailers = [];
                    // Git parses the final trailer block; arbitrary body text is not evidence.
                    $process = $this->process($path, ['interpret-trailers', '--parse']);
                    $process->setInput($body);
                    $process->mustRun();
                    foreach (explode("\n", $process->getOutput()) as $line) {
                        if (preg_match('/^Statecan-(Task|Session|Agent):\s*(.*?)\s*$/i', $line, $match)) {
                            $trailers[strtolower($match[1])][] = $match[2];
                        }
                    }
                    preg_match('/This reverts commit ([a-f0-9]{40,64})\./', $body, $revert);
                    $files = array_values(array_filter(explode("\0", $this->run($path, ['diff-tree', '--no-ext-diff', '--no-textconv', '--root', '--no-commit-id', '--name-only', '-r', '-m', '-z', $sha, '--'])), static fn ($v) => '' !== $v));
                    $commits[$sha] = ['source' => 'local_git', 'parents' => array_values(array_filter(explode(' ', $parts[0]))), 'author' => $parts[1], 'email' => $parts[2], 'authoredAt' => trim($parts[3]), 'message' => $body, 'files' => array_values(array_unique($files)), 'trailers' => $trailers, 'reverts' => $revert[1] ?? null];
                }
            }
        }

        return ['refs' => $refs, 'commits' => $commits];
    }

    private function run(string $path, array $arguments): string
    {
        return $this->process($path, $arguments)->mustRun()->getOutput();
    }

    private function process(string $path, array $arguments): Process
    {
        return new Process(['git', '--no-pager', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-c', 'core.quotePath=false', '-C', $path, ...$arguments], null, ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_NO_REPLACE_OBJECTS' => '1', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_DIR' => false, 'GIT_COMMON_DIR' => false, 'GIT_CONFIG' => false, 'GIT_CONFIG_COUNT' => false, 'GIT_NAMESPACE' => false, 'GIT_WORK_TREE' => false, 'GIT_OBJECT_DIRECTORY' => false, 'GIT_ALTERNATE_OBJECT_DIRECTORIES' => false], null, 120);
    }
}
