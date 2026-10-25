<?php

declare(strict_types=1);

namespace Atannex\Matrix\Enums;

/**
 * Moderation state of a comment or review. Only approved ones are public;
 * authors always see their own pending ones.
 */
enum Status: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Spam = 'spam';

    public function label(): string
    {
        return __(ucfirst($this->value));
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'gray',
            self::Spam => 'danger',
        };
    }

    /**
     * Compare with another status or its value ("approved").
     */
    public function is(self|string|null $status): bool
    {
        return $status instanceof self ? $this === $status : $this->value === $status;
    }

    public function isPublic(): bool
    {
        return $this === self::Approved;
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $s) => $s->value, self::cases()),
            array_map(fn (self $s) => $s->label(), self::cases()),
        );
    }
}
