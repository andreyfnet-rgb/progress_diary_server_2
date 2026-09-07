<?php

declare(strict_types=1);

namespace Gdpd\Infrastructure;

/**
 * Evaluates simple arithmetic expressions ("+ - * / ( )" over decimal
 * numbers), replacing the original server's use of the
 * MSScriptControl.ScriptControl COM object to run arbitrary VBScript for
 * salary formulas stored in the stavka.sqlfield column (see
 * getdatashow0722 in the legacy SrvMetod.pas). The one real production
 * example found (stavka.ID=24, raschet=true) was "6+7+8+9+10+11+12" --
 * plain addition of previously computed indices -- so full VBScript
 * execution is unnecessary risk for what is, in every observed case,
 * simple arithmetic.
 *
 * Numbers may use either '.' or ',' as the decimal separator, matching the
 * original Delphi code's locale-dependent CurrToStr/StrToCurr, which runs
 * under a ru-RU locale (comma decimal separator) -- confirmed by real data
 * in stavka.mnojstv containing literals like "chek*-0,15".
 */
final class ArithmeticExpressionEvaluator
{
    /** @var list<string> */
    private array $tokens;
    private int $pos = 0;

    public static function evaluate(string $expression): float
    {
        $evaluator = new self($expression);
        $result = $evaluator->parseExpression();
        if ($evaluator->pos !== count($evaluator->tokens)) {
            throw new \UnexpectedValueException(sprintf(
                "Unexpected token '%s' in expression \"%s\".",
                $evaluator->tokens[$evaluator->pos] ?? '',
                $expression
            ));
        }

        return $result;
    }

    private function __construct(string $expression)
    {
        $this->tokens = self::tokenize($expression);
    }

    /** @return list<string> */
    private static function tokenize(string $expression): array
    {
        $tokens = [];
        $i = 0;
        $length = strlen($expression);
        while ($i < $length) {
            $c = $expression[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if (in_array($c, ['+', '-', '*', '/', '(', ')'], true)) {
                $tokens[] = $c;
                $i++;
                continue;
            }

            if (ctype_digit($c) || $c === '.' || $c === ',') {
                $start = $i;
                while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.' || $expression[$i] === ',')) {
                    $i++;
                }
                $tokens[] = str_replace(',', '.', substr($expression, $start, $i - $start));
                continue;
            }

            throw new \UnexpectedValueException(sprintf("Unexpected character '%s' in expression \"%s\".", $c, $expression));
        }

        return $tokens;
    }

    private function parseExpression(): float
    {
        $value = $this->parseTerm();
        while (($this->tokens[$this->pos] ?? null) === '+' || ($this->tokens[$this->pos] ?? null) === '-') {
            $op = $this->tokens[$this->pos++];
            $rhs = $this->parseTerm();
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    private function parseTerm(): float
    {
        $value = $this->parseFactor();
        while (($this->tokens[$this->pos] ?? null) === '*' || ($this->tokens[$this->pos] ?? null) === '/') {
            $op = $this->tokens[$this->pos++];
            $rhs = $this->parseFactor();
            $value = $op === '*' ? $value * $rhs : $value / $rhs;
        }

        return $value;
    }

    private function parseFactor(): float
    {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token === null) {
            throw new \UnexpectedValueException('Unexpected end of expression.');
        }

        if ($token === '-') {
            $this->pos++;
            return -$this->parseFactor();
        }

        if ($token === '+') {
            $this->pos++;
            return $this->parseFactor();
        }

        if ($token === '(') {
            $this->pos++;
            $value = $this->parseExpression();
            if (($this->tokens[$this->pos] ?? null) !== ')') {
                throw new \UnexpectedValueException('Missing closing parenthesis.');
            }
            $this->pos++;
            return $value;
        }

        if (!is_numeric($token)) {
            throw new \UnexpectedValueException(sprintf("'%s' is not a number.", $token));
        }
        $this->pos++;
        return (float) $token;
    }
}
