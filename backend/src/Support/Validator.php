<?php

declare(strict_types=1);

namespace Checkmate\Support;

/**
 * Declarative request validation.
 *
 * Rule syntax: 'required|string|min:3|max:20|regex:/^[A-Za-z0-9_]+$/'
 * Special rules: email, password (policy from config), in:a,b,c, integer, boolean.
 * Returns a map field => generic message; empty array means valid.
 */
final class Validator
{
    /** @param array<string,mixed> $input @param array<string,string> $rules @return array<string,string> */
    public static function make(array $input, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $input[$field] ?? null;
            $present = $value !== null && $value !== '';
            $ruleList = $ruleString === '' ? [] : explode('|', $ruleString);

            if (!$present) {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = 'This field is required.';
                }
                continue;
            }

            if (is_array($value)) {
                $errors[$field] = 'This field is invalid.';
                continue;
            }
            $stringValue = is_scalar($value) ? (string) $value : '';

            foreach ($ruleList as $rule) {
                if (isset($errors[$field])) {
                    break;
                }
                $param = null;
                if (str_contains($rule, ':')) {
                    [$rule, $param] = explode(':', $rule, 2);
                }

                switch ($rule) {
                    case 'required':
                    case 'string':
                        if (!is_string($value)) {
                            $errors[$field] = 'This field is invalid.';
                        }
                        break;

                    case 'email':
                        $email = trim($stringValue);
                        if ($email === '' || strlen($email) > 255
                            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                            $errors[$field] = 'This field is invalid.';
                        }
                        break;

                    case 'min':
                        if (is_string($value) && mb_strlen($value) < (int) $param) {
                            $errors[$field] = 'This field is too short.';
                        } elseif (is_numeric($value) && (float) $value < (float) $param) {
                            $errors[$field] = 'This field is too small.';
                        }
                        break;

                    case 'max':
                        if (is_string($value) && mb_strlen($value) > (int) $param) {
                            $errors[$field] = 'This field is too long.';
                        } elseif (is_numeric($value) && (float) $value > (float) $param) {
                            $errors[$field] = 'This field is too large.';
                        }
                        break;

                    case 'regex':
                        if (!preg_match($param, $stringValue)) {
                            $errors[$field] = 'This field is invalid.';
                        }
                        break;

                    case 'in':
                        if (!in_array($stringValue, explode(',', (string) $param), true)) {
                            $errors[$field] = 'This field is invalid.';
                        }
                        break;

                    case 'integer':
                        if (!preg_match('/^-?\d+$/', $stringValue)) {
                            $errors[$field] = 'This field is invalid.';
                        }
                        break;

                    case 'password':
                        if (!self::passwordAcceptable($stringValue)) {
                            $errors[$field] = 'This password is too weak.';
                        }
                        break;
                }
            }
        }

        return $errors;
    }

    /** Central password policy (config-driven). */
    public static function passwordAcceptable(string $password): bool
    {
        $min = (int) config('security.password_min_length', 10);
        if (mb_strlen($password) < $min) {
            return false;
        }
        if ((bool) config('security.password_require_letter', true) && !preg_match('/[A-Za-z]/', $password)) {
            return false;
        }
        if ((bool) config('security.password_require_digit', true) && !preg_match('/\d/', $password)) {
            return false;
        }
        return true;
    }
}
