<?php

declare(strict_types=1);

namespace Odden\Marketing\Enums;

enum WorkflowEnrollmentStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Exited = 'exited';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active In Progress',
            self::Paused => 'Paused',
            self::Completed => 'Completed',
            self::Exited => 'Exited Early',
        };
    }

    public function getColor(): string
    {
        return $this->color();
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'info',
            self::Paused => 'warning',
            self::Completed => 'success',
            self::Exited => 'gray',
        };
    }
}
