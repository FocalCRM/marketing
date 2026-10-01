<?php

declare(strict_types=1);

use Focal\Marketing\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in(__DIR__);
