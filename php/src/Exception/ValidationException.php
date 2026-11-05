<?php

declare(strict_types=1);

namespace Labyrinthe\Connect\Exception;

/**
 * 422 VALIDATION_ERROR: one or more fields are missing or invalid.
 */
final class ValidationException extends ApiException
{
    /**
     * Error messages per field, e.g. ["body" => ["Le champ body est obligatoire."]].
     *
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        $errors = [];
        foreach ($this->getDetails() as $field => $messages) {
            $messages = \is_array($messages) ? $messages : [$messages];
            $list = [];
            foreach ($messages as $message) {
                if (\is_scalar($message)) {
                    $list[] = (string) $message;
                }
            }
            $errors[(string) $field] = $list;
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public function getFieldErrors(string $field): array
    {
        return $this->getErrors()[$field] ?? [];
    }
}
