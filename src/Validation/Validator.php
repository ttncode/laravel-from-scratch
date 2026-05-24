<?php

namespace Framework\Validation;

class Validator
{
    protected array $data = [];
    protected array $rules = [];
    protected array $errors = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public function validate(): void
    {
        foreach ($this->rules as $field => $rules) {
            foreach ($rules as $rule) {
                $this->applyRule($field, $rule);
            }
        }
    }

    protected function applyRule(string $field, string $rule): void
    {
        // Parse rules like "min:8" into rule="min", param="8"
        $param = null;
        if (str_contains($rule, ':')) {
            [$rule, $param] = explode(':', $rule, 2);
        }

        $value = $this->data[$field] ?? null;

        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    $this->addError($field, "The $field field is required.");
                }
                break;
            case 'email':
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "The $field field must be a valid email address.");
                }
                break;
            case 'min':
                if (strlen($value) < (int) $param) {
                    $this->addError($field, "The $field field must be at least $param characters.");
                }
                break;
        }
    }

    public function fails(): bool
    {
        $this->validate();

        return count($this->errors) > 0;
    }

    protected function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
