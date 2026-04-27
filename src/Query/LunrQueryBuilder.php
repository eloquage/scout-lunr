<?php

declare(strict_types=1);

namespace IllumaLaw\ScoutLunr\Query;

/**
 * @see https://lunrjs.com/guides/searching.html
 */
final class LunrQueryBuilder
{
    /** @var list<string> */
    private array $parts = [];

    public static function make(): self
    {
        return new self;
    }

    public function term(string $term): self
    {
        $clone = clone $this;
        $clone->parts[] = $this->escapeTerm($term);

        return $clone;
    }

    public function required(string $term): self
    {
        $clone = clone $this;
        $clone->parts[] = '+'.$this->escapeTerm($term);

        return $clone;
    }

    public function excluded(string $term): self
    {
        $clone = clone $this;
        $clone->parts[] = '-'.$this->escapeTerm($term);

        return $clone;
    }

    public function inField(string $field, string $term): self
    {
        $clone = clone $this;
        $clone->parts[] = $field.':'.$this->escapeTerm($term);

        return $clone;
    }

    public function wildcard(string $prefix): self
    {
        $clone = clone $this;
        $clone->parts[] = $this->escapeTerm($prefix).'*';

        return $clone;
    }

    /**
     * @param  positive-int  $editDistance  1 or 2 (Lunr only supports 1 and 2)
     */
    public function fuzzy(string $term, int $editDistance = 1): self
    {
        $clone = clone $this;
        $distance = max(1, min(2, $editDistance));
        $clone->parts[] = $this->escapeTerm($term).'~'.$distance;

        return $clone;
    }

    /**
     * @param  positive-int|float  $factor  Boost multiplier (must be > 0)
     */
    public function boost(string $term, int|float $factor): self
    {
        $clone = clone $this;
        $clone->parts[] = $this->escapeTerm($term).'^'.$factor;

        return $clone;
    }

    public function build(): string
    {
        return implode(' ', $this->parts);
    }

    private function escapeTerm(string $term): string
    {
        return preg_replace('/([:\^~*+\-])/', '\\\\$1', $term) ?? $term;
    }
}
