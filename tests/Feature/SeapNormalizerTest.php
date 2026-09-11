<?php

namespace Tests\Feature;

use App\Services\SeapNormalizer;
use Tests\TestCase;

class SeapNormalizerTest extends TestCase
{
    public function test_normalizes_participation_fields_and_stores_exact_ted_reference(): void
    {
        $result = app(SeapNormalizer::class)->normalize('CN', [
            'cNoticeID' => 123, 'noticeId' => 900, 'noticeNo' => 'CN100', 'contractTitle' => 'Lucrari',
            'contractingAuthority' => ['id' => 5, 'text' => 'Primarie'], 'cpvCode' => ['code' => '45000000-7'],
            'sysAcquisitionContractType' => ['text' => 'Lucrari'], 'publicationDate' => '2026-09-10T18:00:00+03:00',
            'tenderReceiptDeadline' => '2026-10-01T15:00:00+03:00', 'estimatedValue' => 100,
        ], [
            'general' => ['tedNoticeNumber' => '538493-2026'],
            'section1' => ['noticeEntityAddress' => ['city' => 'Buzoesti', 'nutsCode' => ['text' => 'RO311 Arges']]],
            'section21' => ['shortDescription' => 'Descriere', 'maxEstimatedValue' => 150, 'currencyCode' => 'RON',
                'section22' => ['euProgrRelated' => false, 'sysEuropeanFund' => null, 'sysAwardCriteriaTypeName' => 'Pret', 'monthDuration' => 12]],
            'section4' => ['sysProcedureTypeName' => 'Licitatie deschisa'],
        ]);

        $this->assertSame('Lucrari', $result['title']);
        $this->assertSame('45000000-7', $result['cpv_code']);
        $this->assertSame(150, $result['estimated_value']);
        $this->assertFalse($result['is_eu_funded']);
        $this->assertSame('Arges', $result['county']);
        $this->assertSame('Licitatie deschisa', $result['procedure_type']);
        $this->assertSame(['general.tedNoticeNumber' => '538493-2026'], $result['seap_metadata']['ted_references']);
        $this->assertSame('opportunity', $result['seap_metadata']['category']);
        $this->assertSame('https://e-licitatie.ro/pub/notices/c-notice/v2/view/123', $result['seap_url']);
    }

    public function test_normalizes_simplified_cpv_and_preserves_unknown_funding(): void
    {
        $result = app(SeapNormalizer::class)->normalize('SCN', ['rfqInvitationId' => 123,
            'title' => 'Servicii', 'cpvCode' => ['text' => 'Servicii', 'localeKey' => '55524000-9']], []);

        $this->assertSame('55524000-9', $result['cpv_code']);
        $this->assertNull($result['is_eu_funded']);
        $this->assertSame('Procedura simplificata', $result['procedure_type']);
        $this->assertSame('https://e-licitatie.ro/pub/notices/simplified-notice/v2/view/123', $result['seap_url']);
    }

    public function test_preserves_prior_information_normalization_and_labels_it_as_planning(): void
    {
        $result = app(SeapNormalizer::class)->normalize('PI', ['piNoticeId' => 123, 'title' => 'Plan'], [
            'noticeNoJoue' => '2026/S 123-456789', 'piNoticeV2' => ['section2' => [[
                'section2_1' => ['description' => 'Descriere', 'contractInfo' => ['maxEstimatedValue' => 200]],
            ]]],
        ]);

        $this->assertSame('Descriere', $result['description']);
        $this->assertSame(200.0, $result['estimated_value']);
        $this->assertSame('planning', $result['seap_metadata']['category']);
        $this->assertSame(['noticeNoJoue' => '2026/S 123-456789'], $result['seap_metadata']['ted_references']);
    }
}
