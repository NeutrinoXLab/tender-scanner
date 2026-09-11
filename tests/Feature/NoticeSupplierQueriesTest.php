<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Services\NoticeSupplierQueries;
use Tests\TestCase;

class NoticeSupplierQueriesTest extends TestCase
{
    public function test_cleans_ted_country_and_classification_prefix(): void
    {
        $notice = new Notice(['title' => 'România – Utilaje excavatoare – Furnizare excavator amfibiu Long Reach']);

        $this->assertSame('excavator amfibiu Long Reach', app(NoticeSupplierQueries::class)->suggest($notice)[0]['query']);
    }

    public function test_cleans_administrative_title_and_only_suggests_products_mentioned_in_data(): void
    {
        $notice = new Notice(['title' => 'Contract de furnizare echipamente IT pentru dotarea laboratorului',
            'description' => 'Se solicită laptopuri și imprimante.', 'authority_name' => 'Primaria Exemplu']);

        $queries = app(NoticeSupplierQueries::class)->suggest($notice);
        $terms = array_column($queries, 'query');

        $this->assertSame('echipamente IT', $terms[0]);
        $this->assertContains('laptopuri', $terms);
        $this->assertContains('imprimante', $terms);
        $this->assertNotContains('monitoare', $terms);
    }

    public function test_extracts_separate_lots_and_their_explicit_ean_quantity_and_specs(): void
    {
        $notice = new Notice(['title' => 'Achiziție de produse', 'cpv_code' => '30200000-1', 'raw_data' => ['piNoticeV2' => ['section2' => [[
            'section2_2' => ['lots' => ['items' => [
                ['title' => 'Laptopuri', 'descriptionOfProcurement' => '12 buc, EAN: 4006381333931, memorie 16 GB'],
                ['title' => 'Imprimante'],
            ]]],
        ]]]]]);
        $notice->id = 1;
        $builder = app(NoticeSupplierQueries::class);
        $queries = $builder->suggest($notice);

        $this->assertSame('Laptopuri', $queries[0]['query']);
        $this->assertSame('Imprimante', $queries[1]['query']);
        $context = $builder->context($notice, $queries[0], 'Laptopuri');
        $this->assertSame('4006381333931', $context['ean']);
        $this->assertSame(['value' => 12.0, 'unit' => 'buc'], $context['quantity']);
        $this->assertStringContainsString('16 GB', $context['specifications']);
        $edited = $builder->context($notice, $queries[0], 'Monitor');
        $this->assertNull($edited['ean']);
        $this->assertNull($edited['quantity']);
    }

    public function test_deduplicates_terms_strips_authority_and_handles_missing_or_malformed_data(): void
    {
        $builder = app(NoticeSupplierQueries::class);
        $notice = new Notice(['title' => 'Achiziție de laptopuri Primaria Exemplu', 'authority_name' => 'Primaria Exemplu',
            'description' => 'Laptopuri', 'raw_data' => ['piNoticeV2' => ['section2' => 'invalid']]]);

        $this->assertCount(1, $builder->suggest($notice));
        $this->assertSame('laptopuri', $builder->suggest($notice)[0]['query']);
        $this->assertSame([], $builder->suggest(new Notice));
        $this->assertSame('30200000-1', $builder->suggest(new Notice(['cpv_code' => '30200000-1']))[0]['query']);
    }

    public function test_extracts_terms_from_ted_lot_descriptions_and_rejects_invalid_ean(): void
    {
        $notice = new Notice(['title' => 'Furnizare', 'ted_data' => ['description-lot' => ['ron' => ['Produse: dulap metalic; raft arhivare', 'Laptopuri']]]]);
        $notice->id = 1;
        $builder = app(NoticeSupplierQueries::class);
        $terms = array_column($builder->suggest($notice), 'query');

        $this->assertContains('dulap metalic', $terms);
        $this->assertContains('raft arhivare', $terms);
        $this->assertContains('Laptopuri', $terms);
        $this->assertNull($builder->context($notice, null, 'EAN: 4006381333932')['ean']);
    }
}
