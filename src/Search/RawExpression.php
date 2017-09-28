<?php

declare(strict_types=1);

namespace Ddeboer\Imap\Search;

/**
 * Represents a raw expression.
 */
final class RawExpression implements ConditionInterface
{
    /**
     * Text to be used for the condition.
     *
     * @var string
     */
    private $expression;

    /**
     * @param string $text text for the condition
     */
    public function __construct(string $expression)
    {
        $this->expression = $expression;
    }

    /**
     * @return string
     */
    public function toString(): string
    {
        return $this->expression;
    }
}