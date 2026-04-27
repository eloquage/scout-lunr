<?php

declare(strict_types=1);

namespace IllumaLaw\ScoutLunr\Contracts;

interface LunrConfigurable
{
    /**
     * @return array<string, int|float> e.g. ['title' => 10, 'body' => 1]
     */
    public function searchableBoosts(): array;

    /**
     * @return array{k1?: float, b?: float}
     */
    public function searchableBm25(): array;

    public function searchableLanguage(): string;
}
