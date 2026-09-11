<?php

namespace App\Services;

class SupplierSearchResult
{
    /** @param array<int, array{supplier: ?string, product: ?string, price: ?string, currency: ?string, availability: ?string, match_score: ?float, url: ?string}> $items */
    public function __construct(public readonly string $status, public readonly array $items = [], public readonly ?string $message = null) {}
}
