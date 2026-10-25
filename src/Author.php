<?php

declare(strict_types=1);

namespace Atannex\Matrix;

use Illuminate\Validation\ValidationException;

/**
 * The public name and private email a guest signs a comment or review with.
 */
final readonly class Author
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}

    /**
     * @throws ValidationException
     */
    public static function make(?string $name, ?string $email): self
    {
        $name = trim((string) $name);
        $email = trim((string) $email);
        $errors = [];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['guest_name'] = __('Give a name between 2 and 80 characters.');
        }

        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['guest_email'] = __('Give a valid email address.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($name, mb_strtolower($email));
    }

    /**
     * @return array{guest_name: string, guest_email: string}
     */
    public function attributes(): array
    {
        return ['guest_name' => $this->name, 'guest_email' => $this->email];
    }
}
