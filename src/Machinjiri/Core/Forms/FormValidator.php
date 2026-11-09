<?php

namespace Mlangeni\Machinjiri\Core\Forms;

use Mlangeni\Machinjiri\Core\Forms\RuleBuilder;

class FormValidator
{
  private array $data;
  private array $rules = [];
  private array $errors = [];
  private array $customMessages = [];
  private array $fieldLabels = [];
  private array $validatedData = [];

  public function __construct(array $data = []) {
    $this->data = $data;
  }

  public function setData(array $data): self
  {
    $this->data = $data;
    return $this;
  }

  public function setFieldLabel(string $field, string $label): self
  {
    $this->fieldLabels[$field] = $label;
    return $this;
  }

  public function setCustomMessage(string $field, string $rule, string $message): self
  {
    $this->customMessages[$field][$rule] = $message;
    return $this;
  }

  public function field(string $field): RuleBuilder
  {
    return new RuleBuilder($field, $this);
  }

  public function addRule(string $field, $rule, ...$params): self
  {
    $this->rules[$field][] = [
      'rule' => $rule,
      'params' => $params
    ];
    return $this;
  }

  public function validate(): bool
  {
    $this->errors = [];
    $this->validatedData = [];

    foreach ($this->rules as $field => $rules) {
        $value = $this->getValue($field);

        foreach ($rules as $ruleDef) {
            $rule = $ruleDef['rule'];
            $params = $ruleDef['params'];

            // Handle callable rules
            if (is_callable($rule)) {
              $result = $rule($value, ...$params);
              if ($result !== true) {
                $this->addError($field, 'custom', $result);
              }
              continue;
            }

            // Handle built-in rules
            $method = "validate" . ucfirst($rule);
            if (method_exists($this, $method)) {
              if (!$this->$method($value, ...$params)) {
                $this->addError($field, $rule, ...$params);
              }
            }
        }

        // If valid and not empty, add to validated data
        if (!isset($this->errors[$field])) {
          $this->validatedData[$field] = $value;
        }
        
    }

      return empty($this->errors);
    }

    public function getErrors(): array
    {
      return $this->errors;
    }

    public function getError(string $field): ?string
    {
      return $this->errors[$field][0] ?? null;
    }

    public function getValidatedData(): array
    {
      return $this->validatedData;
    }

    private function getValue(string $field) {
      return $this->data[$field] ?? null;
    }

    private function addError(string $field, string $rule, ...$params): void
    {
      $label = $this->fieldLabels[$field] ?? $field;

      // Use custom message if set
      if (isset($this->customMessages[$field][$rule])) {
        $message = $this->customMessages[$field][$rule];
      } else {
        $message = $this->getDefaultMessage($label, $rule, $params);
      }

      $this->errors[$field][] = $message;
    }

    private function getDefaultMessage(string $label, string $rule, array $params): string
    {
      return match ($rule) {
        'required' => "{$label} is required",
        'email' => "{$label} must be a valid email address",
        'min' => "{$label} must be at least {$params[0]} characters",
        'max' => "{$label} must be no more than {$params[0]} characters",
        'numeric' => "{$label} must be a number",
        'integer' => "{$label} must be an integer",
        'float' => "{$label} must be a decimal number",
        'url' => "{$label} must be a valid URL",
        'ip' => "{$label} must be a valid IP address",
        'date' => "{$label} must be a valid date",
        'regex' => "{$label} format is invalid",
        'in' => "{$label} must be one of: " . implode(', ', $params[0]),
        'notIn' => "{$label} must not be one of: " . implode(', ', $params[0]),
        'same' => "{$label} must match {$params[0]}",
        'different' => "{$label} must be different from {$params[0]}",
        'between' => "{$label} must be between {$params[0]} and {$params[1]}",
        'digits' => "{$label} must be exactly {$params[0]} digits",
        'digitsBetween' => "{$label} must be between {$params[0]} and {$params[1]} digits",
        'alpha' => "{$label} must contain only letters",
        'alphaNum' => "{$label} must contain only letters and numbers",
        'alphaDash' => "{$label} must contain only letters, numbers, dashes and underscores",
        'confirmed' => "{$label} confirmation does not match",
        default => "{$label} is invalid"
        };
      }

      // Built-in validation rules
      private function validateRequired($value): bool
      {
        if (is_string($value)) {
          $value = trim($value);
        }
        return !empty($value) || $value === '0' || $value === 0;
      }

      private function validateEmail($value): bool
      {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
      }

      private function validateMin($value, $min): bool
      {
        if (is_numeric($value)) {
          return $value >= $min;
        }
        if (is_string($value) || is_array($value)) {
          return count($value) >= $min;
        }
        return false;
      }

      private function validateMax($value, $max): bool
      {
        if (is_numeric($value)) {
          return $value <= $max;
        }
        if (is_string($value) || is_array($value)) {
          return count($value) <= $max;
        }
        return false;
      }

      private function validateNumeric($value): bool
      {
        return is_numeric($value);
      }

      private function validateInteger($value): bool
      {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
      }

      private function validateFloat($value): bool
      {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
      }

      private function validateUrl($value): bool
      {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
      }

      private function validateIp($value): bool
      {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
      }

      private function validateDate($value): bool
      {
        return strtotime($value) !== false;
      }

      private function validateRegex($value, $pattern): bool
      {
        return preg_match($pattern, $value) === 1;
      }

      private function validateIn($value, array $options): bool
      {
        return in_array($value, $options);
      }

      private function validateNotIn($value, array $options): bool
      {
        return !in_array($value, $options);
      }

      private function validateSame($value, $field): bool
      {
        return $value === $this->getValue($field);
      }

      private function validateDifferent($value, $field): bool
      {
        return $value !== $this->getValue($field);
      }

      private function validateBetween($value, $min, $max): bool
      {
        return $value >= $min && $value <= $max;
      }

      private function validateDigits($value, $length): bool
      {
        return is_numeric($value) && strlen((string)$value) === $length;
      }

      private function validateDigitsBetween($value, $min, $max): bool
      {
        $length = strlen((string)$value);
        return $length >= $min && $length <= $max;
      }

      private function validateAlpha($value): bool
      {
        return ctype_alpha($value);
      }

      private function validateAlphaNum($value): bool
      {
        return ctype_alnum($value);
      }

      private function validateAlphaDash($value): bool
      {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $value) === 1;
      }

      private function validateConfirmed($value, $field): bool
      {
        $confirmationField = $field . '_confirmation';
        return $value === $this->getValue($confirmationField);
      }
    }