<?php

declare(strict_types=1);

use IllumaLaw\ScoutLunr\Query\LunrQueryBuilder;

it('returns empty string when no clauses added', function () {
    expect(LunrQueryBuilder::make()->build())->toBe('');
});

it('adds a plain term', function () {
    expect(LunrQueryBuilder::make()->term('laravel')->build())->toBe('laravel');
});

it('adds multiple plain terms separated by a space', function () {
    expect(
        LunrQueryBuilder::make()->term('laravel')->term('php')->build()
    )->toBe('laravel php');
});

it('prefixes required terms with +', function () {
    expect(LunrQueryBuilder::make()->required('laravel')->build())->toBe('+laravel');
});

it('prefixes excluded terms with -', function () {
    expect(LunrQueryBuilder::make()->excluded('lumen')->build())->toBe('-lumen');
});

it('scopes a term to a field', function () {
    expect(LunrQueryBuilder::make()->inField('title', 'scout')->build())->toBe('title:scout');
});

it('appends a trailing wildcard', function () {
    expect(LunrQueryBuilder::make()->wildcard('cloud')->build())->toBe('cloud*');
});

it('appends a fuzzy suffix with default edit distance 1', function () {
    expect(LunrQueryBuilder::make()->fuzzy('serach')->build())->toBe('serach~1');
});

it('appends a fuzzy suffix with explicit edit distance', function () {
    expect(LunrQueryBuilder::make()->fuzzy('serach', 2)->build())->toBe('serach~2');
});

it('clamps fuzzy edit distance to 2 at maximum', function () {
    expect(LunrQueryBuilder::make()->fuzzy('foo', 5)->build())->toBe('foo~2');
});

it('clamps fuzzy edit distance to 1 at minimum', function () {
    expect(LunrQueryBuilder::make()->fuzzy('foo', 0)->build())->toBe('foo~1');
});

it('appends a boost multiplier with ^', function () {
    expect(LunrQueryBuilder::make()->boost('laravel', 10)->build())->toBe('laravel^10');
});

it('supports float boost values', function () {
    expect(LunrQueryBuilder::make()->boost('term', 2.5)->build())->toBe('term^2.5');
});

it('composes a complex query from multiple clause types', function () {
    $query = LunrQueryBuilder::make()
        ->required('php')
        ->term('laravel')
        ->inField('title', 'scout')
        ->wildcard('cloud')
        ->fuzzy('frmaework', 1)
        ->boost('laravel', 10)
        ->excluded('mysql')
        ->build();

    expect($query)->toBe('+php laravel title:scout cloud* frmaework~1 laravel^10 -mysql');
});

it('is immutable — each method returns a new instance', function () {
    $original = LunrQueryBuilder::make();
    $modified = $original->term('foo');

    expect($original->build())->toBe('')
        ->and($modified->build())->toBe('foo');
});

it('escapes special Lunr characters inside terms', function () {
    $query = LunrQueryBuilder::make()->term('foo:bar')->build();

    expect($query)->toContain('\\:');
});
