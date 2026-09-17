<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

class SeapNormalizer
{
    public function normalize(string $family, array $item, array $details): array
    {
        if ($family === 'ADV') {
            return $this->normalizeAdv($item, $details);
        }
        if ($family === 'PI') {
            $attributes = $this->normalizePi($item, $details);
            $attributes['notice_type'] = 'PI — Anunț de intenție';
        } else {
            $section21 = $details['section21'] ?? [];
            $lot = $section21['section22'] ?? [];
            $address = data_get($details, 'section1.noticeEntityAddress', []);
            $nuts = data_get($address, 'nutsCode.text') ?? ($lot['nutsCode'] ?? null);
            $county = data_get($address, 'countyItem.text') ?? ($address['county'] ?? null);
            if ($county === null && is_string($nuts) && preg_match('/^RO\d{3}\s+(.+)$/u', $nuts, $matches)) {
                $county = $matches[1];
            }
            $attributes = [
                'title' => $item['contractTitle'] ?? $item['title'] ?? $section21['title'] ?? 'Fără titlu',
                'notice_number' => $item['noticeNo'] ?? null,
                'notice_type' => $family === 'CN' ? 'CN — Anunț de participare' : 'SCN — Anunț de participare simplificat',
                'description' => $section21['shortDescription'] ?? null,
                'authority_name' => data_get($item, 'contractingAuthority.text'),
                'authority_seap_id' => isset($item['contractingAuthority']['id']) ? (string) $item['contractingAuthority']['id'] : null,
                'cpv_code' => data_get($item, 'cpvCode.code') ?? data_get($item, 'cpvCode.localeKey') ?? ($section21['mainCpvCode'] ?? null),
                'contract_type' => data_get($item, 'sysAcquisitionContractType.text'),
                'procedure_type' => data_get($details, 'section4.sysProcedureTypeName') ?? data_get($item, 'sysProcedureType.text') ?? ($family === 'SCN' ? 'Procedura simplificata' : null),
                'contract_assignment_type' => data_get($item, 'sysContractAssigmentType.text') ?? data_get($details, 'section4.sysContractAssignmentTypeName'),
                'estimated_value' => $section21['maxEstimatedValue'] ?? $section21['estimatedValue'] ?? $item['maxEstimatedValue'] ?? $item['estimatedValue'] ?? null,
                'currency' => $section21['currencyCode'] ?? $item['currencyCode'] ?? null,
                'publication_date' => $item['publicationDate'] ?? null,
                'submission_deadline' => $item['tenderReceiptDeadline'] ?? null,
                'county' => $county,
                'locality' => $address['city'] ?? null,
                'nuts_code' => $nuts,
                'award_criteria' => $lot['sysAwardCriteriaTypeName'] ?? null,
                'contract_duration_months' => $lot['monthDuration'] ?? null,
                'is_eu_funded' => $lot['euProgrRelated'] ?? null,
                'funding_program' => $this->text($lot['sysEuropeanFund'] ?? null) ?? ($lot['communityProgramReference'] ?? null),
                'status' => data_get($item, 'sysProcedureState.text') ?? data_get($item, 'sysNoticeState.text'),
            ];
        }
        $config = SeapClient::FAMILIES[$family];
        $id = (string) $item[$config['id']];
        $attributes['seap_url'] = 'https://e-licitatie.ro/pub/notices/'.$config['path'].'/'.$id;
        $metadata = [
            'bulk_only' => false,
            'family' => $family, 'family_id' => $id,
            'notice_id' => $item['noticeId'] ?? $item['noticeID'] ?? $details['noticeId'] ?? null,
            'procedure_id' => $item['procedureId'] ?? null,
            'notice_type_id' => $item['sysNoticeTypeId'] ?? data_get($item, 'sysNoticeType.id') ?? $config['type'],
            'contract_type_id' => data_get($item, 'sysAcquisitionContractType.id') ?? data_get($details, 'sectionGeneral.sysAcquisitionContractType.id'),
            'procedure_state_id' => data_get($item, 'sysProcedureState.id'),
            'legislation' => data_get($details, 'sectionGeneral.sysLegislationType'),
            'is_utility' => $item['isUtilityContract'] ?? data_get($details, 'sectionGeneral.isUtility'),
            'category' => $family === 'PI' ? 'planning' : 'opportunity',
            'ted_references' => $this->tedReferences($details),
        ];
        if ($family === 'PI') {
            $metadata['planned_start_date'] = data_get($details, 'piNoticeV2.section4.section4_2.awardProceduresStartDate');
        }
        $attributes['seap_metadata'] = $metadata;

        return $attributes;
    }

    private function text(mixed $value): ?string
    {
        return is_array($value) ? ($value['text'] ?? null) : (is_string($value) && trim($value) !== '' ? $value : null);
    }

    /** @return array<string, mixed> */
    private function normalizeAdv(array $item, array $details): array
    {
        $number = $details['noticeNo'] ?? $item['noticeNo'] ?? '';
        if (! preg_match('/^ADV\d+$/', $number) || empty($item['contractObject'])) {
            throw new RuntimeException('Identitate sau titlu ADV invalid.');
        }
        if ($details !== [] && $number !== ($item['noticeNo'] ?? null)) {
            throw new RuntimeException('Numărul ADV din detaliu diferă de listare.');
        }
        $data = array_replace($item, $details);
        $deadline = $data['tenderReceiptDeadline'] ?? null;
        $parsed = is_string($deadline) ? date_parse($deadline) : null;
        $confirmed = is_string($deadline) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $deadline)
            && $parsed['error_count'] === 0 && $parsed['warning_count'] === 0;
        $cpv = $item['cpvCode'] ?? '';
        preg_match('/^(\d{8}-\d)\s*-\s*(.*)$/u', is_string($cpv) ? $cpv : '', $cpvParts);
        $stateId = data_get($data, 'sysNoticeState.id') ?? ($data['sysNoticeStateId'] ?? 0);
        $state = $stateId === 2 ? 'Publicat' : (data_get($data, 'sysNoticeState.text') ?? 'Necunoscut');
        $type = data_get($details, 'sysAdvertisingNotice.text') ?? ($item['sysAdvertisingNotice'] ?? null);
        $metadata = [
            'family' => 'ADV', 'family_id' => (string) $item['advNoticeId'], 'category' => 'opportunity', 'bulk_only' => false,
            'contract_type_id' => $details['sysAcquisitionContractTypeId'] ?? null, 'notice_state_id' => $stateId,
            'deadline_confirmed' => (bool) $confirmed,
            'opportunity_type_confirmed' => in_array($type, ['Achizitii directe', 'Cumparari directe', 'Anexa 2'], true),
            'advertising_type' => $type, 'advertising_type_id' => $details['sysAdvertisingNoticeId'] ?? null,
            'parent_id' => $details['parentAdvertisingNoticeId'] ?? null,
            'version_publication_date' => $details['versionPublicationDate'] ?? null,
            'authority_cui' => data_get($details, 'noticeEntityAddress.nationalIDNumber'),
            'cpv_name' => data_get($details, 'cpvCode.text') ?? ($cpvParts[2] ?? null),
            'estimated_range' => ['min' => $data['minEstimatedValue'] ?? null, 'max' => $data['maxEstimatedValue'] ?? null],
            'ted_references' => [],
            'participation_conditions' => $details['participationConditions'] ?? null,
            'contract_conditions' => $details['contractRelatedConditions'] ?? null,
            'additional_information' => $details['additionalInformation'] ?? null,
        ];
        if ($details !== []) {
            $metadata['document'] = [
                'id' => $details['documentID'] ?? null, 'name' => $details['documentName'] ?? null,
                'reference' => $details['documentUrl'] ?? null, 'identification_code' => $details['documentUniqueIdentificationCode'] ?? null,
            ];
        }

        return [
            'notice_number' => $number, 'title' => $data['contractObject'], 'notice_type' => 'ADV — Anunț de publicitate',
            'authority_name' => $details['contractAuthorityName'] ?? ($item['contractingAuthority'] ?? null),
            'authority_seap_id' => isset($details['contractingAuthorityId']) ? (string) $details['contractingAuthorityId'] : null,
            'publication_date' => $data['publicationDate'] ?? null,
            'submission_deadline' => $confirmed ? CarbonImmutable::parse($deadline)->timezone('Europe/Bucharest')->toIso8601String() : null,
            'status' => $state, 'cpv_code' => data_get($details, 'cpvCode.localeKey') ?? ($cpvParts[1] ?? null),
            'contract_type' => data_get($details, 'sysAcquisitionContractType.text') ?? ($item['sysAcquisitionContractType'] ?? null),
            'estimated_value' => $data['maxEstimatedValue'] ?? $data['estimatedValue'] ?? ($details['value'] ?? null),
            'currency' => data_get($details, 'currency.text'), 'description' => $details['contractDescription'] ?? null,
            'county' => data_get($details, 'noticeEntityAddress.county.text'), 'locality' => data_get($details, 'noticeEntityAddress.city'),
            'award_criteria' => $details['awardCriteria'] ?? null,
            'seap_url' => 'https://e-licitatie.ro/pub/notices/adv-notices/view/'.$item['advNoticeId'], 'seap_metadata' => $metadata,
        ];
    }

    /** @return array<string, string> */
    private function tedReferences(array $details): array
    {
        $references = [];
        foreach (['general.tedNoticeNumber', 'noticeNoJoue', 'joueNoticeNo', 'tedNoticeNumber'] as $path) {
            $value = data_get($details, $path);
            if (is_string($value) && trim($value) !== '') {
                $references[$path] = trim($value);
            }
        }

        return $references;
    }

    public function normalizePi(array $item, ?array $details): array
    {
        $piNoticeId = $item['piNoticeId'];
        $currency =
            $item['estimatedValueCurrency']['text'] ?? null;

        /*
         * 3. Extragem informatiile suplimentare din detalii.
         */
        $description = null;
        $contractType = null;
        $contractAssignmentType = null;
        $nutsCode = null;
        $awardCriteria = null;
        $contractDurationMonths = null;
        $isElectronicSubmission = null;
        $isEuFunded = null;
        $fundingProgram = null;
        $submissionDeadline = null;
        $county = null;
        $locality = null;
        $status = null;
        $procedureType = null;

        /*
         * Valoarea estimata:
         * pornim de la valoarea din lista SEAP.
         */
        $estimatedValue =
            $item['estimatedValue'] ?? null;

        if (is_array($details)) {
            /*
             * Structura principala a anuntului.
             */
            $piNoticeV2 = $details['piNoticeV2'] ?? [];

            /*
             * Sectiunea 1 - Autoritate contractanta si localizare.
             */
            $section1 = $piNoticeV2['section1'] ?? [];
            $section1_1 = $section1['section1_1'] ?? [];
            $caAddress = $section1_1['caAddress'] ?? [];

            $county =
                $caAddress['county']['text'] ?? null;

            $locality =
                $caAddress['city'] ?? null;

            $nutsCode =
                $caAddress['nutsCodeItem']['text'] ?? null;

            /*
             * Depunere electronica.
             */
            $section1_3 = $section1['section1_3'] ?? [];

            $isElectronicSubmission =
                $section1_3['tenderOrRequestSubmittedElectronically'] ?? null;

            /*
             * Tipul contractului.
             */
            $sectionGeneral =
                $details['sectionGeneral'] ?? [];

            $contractType =
                $sectionGeneral['sysAcquisitionContractType']['text'] ?? null;

            /*
             * Sectiunea 2 - obiect, descriere, loturi.
             */
            $sections2 =
                $piNoticeV2['section2'] ?? [];

            if (isset($sections2[0]) && is_array($sections2[0])) {
                $section2 = $sections2[0];

                $section2_1 =
                    $section2['section2_1'] ?? [];

                $description =
                    $section2_1['description'] ?? null;

                /*
                 * VALOAREA ESTIMATA
                 *
                 * Sursa principala verificata pe SEAP:
                 *
                 * contractInfo.maxEstimatedValue
                 *
                 * Daca aceasta lipseste:
                 * 1. contractInfo.estimatedValue
                 * 2. suma valorilor maxime ale loturilor
                 * 3. pastram valoarea din lista
                 */
                $contractInfo =
                    $section2_1['contractInfo'] ?? [];

                $detailMaxEstimatedValue =
                    $contractInfo['maxEstimatedValue'] ?? null;

                $detailEstimatedValue =
                    $contractInfo['estimatedValue'] ?? null;

                if (
                    $detailMaxEstimatedValue !== null &&
                    is_numeric($detailMaxEstimatedValue)
                ) {
                    $estimatedValue =
                        (float) $detailMaxEstimatedValue;
                } elseif (
                    $detailEstimatedValue !== null &&
                    is_numeric($detailEstimatedValue)
                ) {
                    $estimatedValue =
                        (float) $detailEstimatedValue;
                }

                /*
                 * Preluam loturile.
                 */
                $section2_2 =
                    $section2['section2_2'] ?? [];

                $lots =
                    $section2_2['lots']['items'] ?? [];

                if (is_array($lots)) {
                    foreach ($lots as $lot) {
                        if (! is_array($lot)) {
                            continue;
                        }

                        /*
                         * NUTS din lot, daca nu exista la autoritate.
                         */
                        if ($nutsCode === null) {
                            $nutsCode =
                                $lot['nutsCodes']['text'] ?? null;
                        }

                        /*
                         * Criteriul de atribuire.
                         */
                        if ($awardCriteria === null) {
                            $awardCriteria =
                                $lot['sysAwardCriteriaType']['text'] ?? null;
                        }

                        /*
                         * Durata contractului.
                         */
                        if ($contractDurationMonths === null) {
                            $duration =
                                $lot['contractDuration']['monthDuration'] ?? null;

                            if (
                                $duration !== null &&
                                is_numeric($duration)
                            ) {
                                $contractDurationMonths =
                                    (int) $duration;
                            }
                        }

                        /*
                         * Finantare europeana.
                         */
                        if ($isEuFunded === null) {
                            $isEuFunded =
                                $lot['isCommunityFinanced'] ?? null;
                        }

                        /*
                         * Program / fond european.
                         */
                        if ($fundingProgram === null) {
                            $fundingProgram =
                                $lot['sysEuropeanFund']['text'] ?? null;
                        }
                    }

                    /*
                     * Daca SEAP nu a furnizat nicio valoare
                     * in contractInfo, incercam sa calculam
                     * valoarea maxima din descrierile loturilor.
                     */
                    if (
                        (
                            $detailMaxEstimatedValue === null &&
                            $detailEstimatedValue === null
                        )
                    ) {
                        $lotMaxValues = [];

                        foreach ($lots as $lot) {
                            if (! is_array($lot)) {
                                continue;
                            }

                            /*
                             * Unele structuri pot avea direct
                             * valoarea maxima in estimatedValue.
                             */
                            $lotEstimatedValue =
                                $lot['estimatedValue']['maxEstimatedValue'] ?? null;

                            if (
                                $lotEstimatedValue !== null &&
                                is_numeric($lotEstimatedValue)
                            ) {
                                $lotMaxValues[] =
                                    (float) $lotEstimatedValue;

                                continue;
                            }

                            /*
                             * Pentru acordurile-cadru analizate,
                             * valoarea maxima apare in textul
                             * descriptionOfProcurement.
                             *
                             * Exemplu:
                             * Valoarea minima - maxima estimata
                             * acord-cadru, lei fara TVA/ lot=
                             * 6048-12096 lei
                             */
                            $lotDescription =
                                $lot['descriptionOfProcurement'] ?? null;

                            if (! $lotDescription) {
                                continue;
                            }

                            if (preg_match(
                                '/Valoarea\s+minima\s*-\s*maxima\s+estimata\s+acord-cadru.*?=\s*[\d\s.,]+-\s*([\d\s.,]+)/iu',
                                $lotDescription,
                                $matches
                            )) {
                                $value =
                                    str_replace(
                                        [' ', "\t"],
                                        '',
                                        $matches[1]
                                    );

                                $value =
                                    str_replace(',', '', $value);

                                if (is_numeric($value)) {
                                    $lotMaxValues[] =
                                        (float) $value;
                                }
                            }
                        }

                        if ($lotMaxValues !== []) {
                            $estimatedValue =
                                array_sum($lotMaxValues);
                        }
                    }
                }
            }

            /*
             * Sectiunea 4 - procedura si termen.
             */
            $section4 =
                $piNoticeV2['section4'] ?? [];

            $section4_1 =
                $section4['section4_1'] ?? [];

            $section4_2 =
                $section4['section4_2'] ?? [];

            $procedureType =
                $section4_1['sysProcedureType']['text'] ?? null;

            if ($procedureType === null) {
                $additionalInfo =
                    $details['piNoticeV2']['section6']['section6_3']['additionalInfo'] ?? null;

                if (
                    is_string($additionalInfo) &&
                    preg_match('/licitație\s+deschisă|licitatie\s+deschisa/iu', $additionalInfo)
                ) {
                    $procedureType = 'Licitație deschisă';
                }
            }

            $contractAssignmentType =
                $section4_1['sysContractAssigmentType']['text'] ?? null;

            /*
             * Termen structurat, daca SEAP il furnizeaza.
             */
            $submissionDeadline =
                $section4_2['timelimitForReceiptOfTenders'] ?? null;

            /*
             * Statusul anuntului.
             */
            $status =
                $details['sysNoticeState']['text'] ?? null;
        }

        /*
         * Deadline:
         * folosim mai intai valoarea structurata din SEAP.
         * Daca lipseste, incercam sa o extragem din descriere.
         */
        if ($submissionDeadline === null && $description) {
            $matches = [];

            if (preg_match(
                '/termen\s+(?:limita\s+)?(?:de\s+)?depunere\s+(?:oferta|oferte)[^0-9]*(\d{2}\.\d{2}\.\d{4})[^0-9]*(?:ora\s*)?(\d{1,2})(?::(\d{2}))?/iu',
                $description,
                $matches
            )) {
                $date = $matches[1];
                $hour = (int) $matches[2];

                $minute =
                    isset($matches[3]) && $matches[3] !== ''
                        ? (int) $matches[3]
                        : 0;

                $dateTime =
                    \DateTime::createFromFormat(
                        '!d.m.Y H:i',
                        sprintf(
                            '%s %02d:%02d',
                            $date,
                            $hour,
                            $minute
                        ),
                        new \DateTimeZone('Europe/Bucharest')
                    );

                if ($dateTime !== false) {
                    $submissionDeadline =
                        $dateTime->format('Y-m-d H:i:s');
                }
            }
        }

        return [
            'source' => 'seap',
            'notice_number' => $item['noticeNo'] ?? null,

            'title' => $item['title'] ?? 'Fara titlu',

            'description' => $description,

            'notice_type' => null,

            'procedure_type' => $procedureType,

            'contract_type' => $contractType,

            'contract_assignment_type' => $contractAssignmentType,

            'authority_name' => $item['contractingAuthority']['text'] ?? null,

            'authority_seap_id' => isset($item['contractingAuthority']['id'])
                    ? (string) $item['contractingAuthority']['id']
                    : null,

            'cpv_code' => $item['cpvList'] ?? null,

            'estimated_value' => $estimatedValue,

            'currency' => $currency,

            'publication_date' => $item['publicationDate'] ?? null,

            'submission_deadline' => $submissionDeadline,

            'county' => $county,

            'locality' => $locality,

            'nuts_code' => $nutsCode,

            'award_criteria' => $awardCriteria,

            'contract_duration_months' => $contractDurationMonths,

            'is_electronic_submission' => $isElectronicSubmission,

            'is_eu_funded' => $isEuFunded,

            'funding_program' => $fundingProgram,

            'status' => $status,

            'seap_url' => 'https://e-licitatie.ro/pub/notices/pi-notice/view/v2/'.
                $piNoticeId,

            /*
                             * Pastram raspunsul complet de la SEAP.
                             * Daca detaliile au fost obtinute, acestea devin
                             * sursa principala. Altfel pastram datele din lista.
                             */
            'raw_data' => $details ?? $item,
        ];
    }
}
