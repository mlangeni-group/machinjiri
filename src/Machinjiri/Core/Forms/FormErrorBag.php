<?php
namespace Mlangeni\Machinjiri\Core\Forms;

class FormErrorBag
{
    private array $errors = [];

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function has(?string $field = null): bool
    {
        if ($field === null) {
            return !empty($this->errors);
        }
        return isset($this->errors[$field]);
    }

    public function get(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    public function all(): array
    {
        return $this->errors;
    }

    public function clear(): void
    {
        $this->errors = [];
    }

    public function merge(array $errors): void
    {
        foreach ($errors as $field => $messages) {
            $this->errors[$field] = array_merge($this->errors[$field] ?? [], (array)$messages);
        }
    }

    public function toSession(Session $session, string $key = 'form_errors'): void
    {
        $session->set($key, $this->errors);
    }
}