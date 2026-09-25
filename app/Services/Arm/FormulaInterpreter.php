<?php

namespace App\Services\Arm;

use InvalidArgumentException;

/**
 * Подмножество выражений FoxPro из FLSM/FLSP: IIF, .AND./.OR., сравнения, + - * /.
 */
class FormulaInterpreter
{
    /** @var list<string> */
    private array $tokens = [];

    private int $pos = 0;

    /** @var array<string, float> */
    private array $vars = [];

    /**
     * @param  array<string, float|int|string>  $variables
     */
    public function evaluate(string $formula, array $variables): float
    {
        $this->vars = [];
        foreach ($variables as $key => $value) {
            $this->vars[strtolower((string) $key)] = (float) $value;
        }
        $this->tokens = $this->tokenize($formula);
        $this->pos = 0;
        if ($this->tokens === []) {
            return 0.0;
        }
        $value = $this->parseOr();
        if ($this->pos < count($this->tokens)) {
            throw new InvalidArgumentException('Лишние токены в формуле: '.$this->peek());
        }

        return round((float) $value, 4);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $formula): array
    {
        preg_match_all(
            '/IIF|\.AND\.|\.OR\.|\.NOT\.|[A-Za-z_][A-Za-z0-9_]*|\d+\.?\d*|<>|!=|>=|<=|[+\-*\/(),=<>]/i',
            $formula,
            $matches
        );

        return $matches[0] ?? [];
    }

    private function parseOr(): float
    {
        $left = $this->parseAnd();
        while ($this->is('OR') || $this->is('.OR.')) {
            $this->next();
            $right = $this->parseAnd();
            $left = ($left || $right) ? 1.0 : 0.0;
        }

        return $left;
    }

    private function parseAnd(): float
    {
        $left = $this->parseCompare();
        while ($this->is('AND') || $this->is('.AND.')) {
            $this->next();
            $right = $this->parseCompare();
            $left = ($left && $right) ? 1.0 : 0.0;
        }

        return $left;
    }

    private function parseCompare(): float
    {
        if ($this->is('NOT') || $this->is('.NOT.')) {
            $this->next();

            return $this->parseCompare() ? 0.0 : 1.0;
        }
        $left = $this->parseAdd();
        $op = $this->peek();
        if (in_array($op, ['>=', '<=', '<>', '!=', '=', '>', '<'], true)) {
            $this->next();
            $right = $this->parseAdd();
            $ok = match ($op) {
                '>=' => $left >= $right,
                '<=' => $left <= $right,
                '<>', '!=' => $left != $right,
                '=' => $left == $right,
                '>' => $left > $right,
                '<' => $left < $right,
                default => false,
            };

            return $ok ? 1.0 : 0.0;
        }

        return $left;
    }

    private function parseAdd(): float
    {
        $left = $this->parseMul();
        while ($this->is('+') || $this->is('-')) {
            $op = $this->next();
            $right = $this->parseMul();
            $left = $op === '+' ? $left + $right : $left - $right;
        }

        return $left;
    }

    private function parseMul(): float
    {
        $left = $this->parseUnary();
        while ($this->is('*') || $this->is('/')) {
            $op = $this->next();
            $right = $this->parseUnary();
            $left = $op === '*' ? $left * $right : ($right == 0.0 ? 0.0 : $left / $right);
        }

        return $left;
    }

    private function parseUnary(): float
    {
        if ($this->is('-')) {
            $this->next();

            return -$this->parseUnary();
        }
        if ($this->is('+')) {
            $this->next();

            return $this->parseUnary();
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): float
    {
        $token = $this->peek();
        if ($token === null) {
            return 0.0;
        }
        if (strcasecmp($token, 'IIF') === 0) {
            $this->next();
            $this->expect('(');
            $cond = $this->parseOr();
            $this->expect(',');
            $a = $this->parseOr();
            $this->expect(',');
            $b = $this->parseOr();
            $this->expect(')');

            return $cond ? $a : $b;
        }
        if ($token === '(') {
            $this->next();
            $value = $this->parseOr();
            $this->expect(')');

            return $value;
        }
        if (is_numeric($token)) {
            $this->next();

            return (float) $token;
        }
        $this->next();

        return $this->vars[strtolower($token)] ?? 0.0;
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function is(string $value): bool
    {
        $token = $this->peek();

        return $token !== null && strcasecmp($token, $value) === 0;
    }

    private function next(): string
    {
        $token = $this->peek() ?? '';
        $this->pos++;

        return $token;
    }

    private function expect(string $value): void
    {
        if (! $this->is($value)) {
            throw new InvalidArgumentException('Ожидалось '.$value.', получено '.($this->peek() ?? 'EOF'));
        }
        $this->next();
    }
}
