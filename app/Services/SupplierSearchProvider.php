<?php

namespace App\Services;

interface SupplierSearchProvider
{
    public function isConfigured(): bool;

    /** @param array{notice_id: int, cpv: ?string, ean: ?string, quantity: ?array{value: float, unit: string}, specifications: ?string} $context */
    public function search(string $query, array $context): SupplierSearchResult;
}
