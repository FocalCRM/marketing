<?php

declare(strict_types=1);

// Dependency rules scan source text; see sourceFilesMatching() in tests/Pest.php.
it('marketing domain remains strictly headless (no Filament or Livewire)', function (): void {
    expect(sourceFilesMatching('/(?<![\\\\\w])(Filament|Livewire)\\\\+[A-Z]/'))->toBeEmpty();
});

it('marketing does not depend on service or the Filament UI (sales is an optional, guarded integration)', function (): void {
    expect(sourceFilesMatching('/\bOdden\\\\+(Service|Filament)\\\\+/'))->toBeEmpty();
});

arch('no debug functions are left in the code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('all marketing domain actions have an execute method')
    ->expect('Odden\Marketing\Actions')
    ->toHaveMethod('execute');

arch('all marketing enums are string backed for database agnosticism')
    ->expect('Odden\Marketing\Enums')
    ->toBeStringBackedEnums();
