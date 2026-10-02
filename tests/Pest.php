<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Marketing\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);

/**
 * Package source files (src, database, routes, resources, config) whose contents match a pattern.
 *
 * Dependency rules use this instead of arch()->not->toUse(): Pest's arch plugin cannot see
 * classes that are not installed, so a rule against an absent package would always pass.
 *
 * @return list<string>
 */
function sourceFilesMatching(string $pattern): array
{
    $root = dirname(__DIR__);
    $matches = [];

    foreach (['src', 'database', 'routes', 'resources', 'config'] as $directory) {
        if (! is_dir("{$root}/{$directory}")) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.php')
                && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $matches[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    sort($matches);

    return $matches;
}
