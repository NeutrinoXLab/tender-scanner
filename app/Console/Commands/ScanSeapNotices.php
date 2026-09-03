<?php

namespace App\Console\Commands;

use App\Models\Notice;
use App\Services\SeapClient;
use Illuminate\Console\Command;

class ScanSeapNotices extends Command
{
    protected $signature = 'seap:scan
                            {--from= : Data de inceput, format YYYY-MM-DD}
                            {--to= : Data de sfarsit, format YYYY-MM-DD}';

    protected $description = 'Scaneaza si salveaza anunturile publice din SEAP';

    public function handle(SeapClient $seap): int
    {
        $from = $this->option('from');
        $to = $this->option('to');

        $this->info('Scanez anunturile SEAP...');

        if ($from) {
            $this->line("De la: {$from}");
        }

        if ($to) {
            $this->line("Pana la: {$to}");
        }

        $pageSize = 18;
        $pageIndex = 0;
        $totalFetched = 0;
        $totalSaved = 0;
        $totalUpdated = 0;
        $totalDetails = 0;
        $totalDetailsFailed = 0;
        $total = 0;

        try {
            do {
                $data = $seap->getOpenNotices(
                    pageSize: $pageSize,
                    pageIndex: $pageIndex,
                    cpv: null,
                    publicationDateFrom: $from,
                    publicationDateTo: $to
                );

                $total = $data['total'] ?? 0;
                $items = $data['items'] ?? [];

                $totalPages = $total > 0
                    ? (int) ceil($total / $pageSize)
                    : 0;

                $this->newLine();
                $this->info(
                    "Pagina " . ($pageIndex + 1) . "/{$totalPages}" .
                    " - " . count($items) . " rezultate"
                );

                foreach ($items as $item) {
                    $piNoticeId = $item['piNoticeId'] ?? null;

                    if (!$piNoticeId) {
                        $this->warn('Anunt fara piNoticeId. Sar peste el.');
                        continue;
                    }

                    /*
                     * 1. Obtinem detaliile complete ale anuntului.
                     */
                    $details = null;

                    try {
                        $details = $seap->getNoticeDetails((int) $piNoticeId);

                        $totalDetails++;

                        $this->line(
                            "  Detalii obtinute pentru {$piNoticeId}"
                        );
                    } catch (\Throwable $e) {
                        $totalDetailsFailed++;

                        $this->warn(
                            "  Nu am putut obtine detaliile pentru {$piNoticeId}: " .
                            $e->getMessage()
                        );
                    }

                    /*
                     * 2. Pregatim datele din lista.
                     */
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
                                    if (!is_array($lot)) {
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
                                        if (!is_array($lot)) {
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

                                        if (!$lotDescription) {
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

                    /*
                     * 4. Salvam anuntul.
                     */
                    $notice = Notice::updateOrCreate(
                        [
                            'seap_id' => (string) $piNoticeId,
                        ],
                        [
                            'notice_number' =>
                                $item['noticeNo'] ?? null,

                            'title' =>
                                $item['title'] ?? 'Fara titlu',

                            'description' =>
                                $description,

                            'notice_type' =>
                                null,

                            'procedure_type' =>
                                $procedureType,

                            'contract_type' =>
                                $contractType,

                            'contract_assignment_type' =>
                                $contractAssignmentType,

                            'authority_name' =>
                                $item['contractingAuthority']['text'] ?? null,

                            'authority_seap_id' =>
                                isset($item['contractingAuthority']['id'])
                                    ? (string) $item['contractingAuthority']['id']
                                    : null,

                            'cpv_code' =>
                                $item['cpvList'] ?? null,

                            'estimated_value' =>
                                $estimatedValue,

                            'currency' =>
                                $currency,

                            'publication_date' =>
                                $item['publicationDate'] ?? null,

                            'submission_deadline' =>
                                $submissionDeadline,

                            'county' =>
                                $county,

                            'locality' =>
                                $locality,

                            'nuts_code' =>
                                $nutsCode,

                            'award_criteria' =>
                                $awardCriteria,

                            'contract_duration_months' =>
                                $contractDurationMonths,

                            'is_electronic_submission' =>
                                $isElectronicSubmission,

                            'is_eu_funded' =>
                                $isEuFunded,

                            'funding_program' =>
                                $fundingProgram,

                            'status' =>
                                $status,

                            'seap_url' =>
                                'https://e-licitatie.ro/pub/notices/pi-notice/view/v2/' .
                                $piNoticeId,

                            /*
                             * Pastram raspunsul complet de la SEAP.
                             * Daca detaliile au fost obtinute, acestea devin
                             * sursa principala. Altfel pastram datele din lista.
                             */
                            'raw_data' =>
                                $details ?? $item,
                        ]
                    );

                    $totalFetched++;

                    if ($notice->wasRecentlyCreated) {
                        $totalSaved++;
                    } else {
                        $totalUpdated++;
                    }

                    $this->line(
                        ($item['noticeNo'] ?? '-') .
                        ' | ' .
                        ($item['title'] ?? '-')
                    );
                }

                $pageIndex++;

                if (count($items) === 0) {
                    break;
                }

            } while ($totalFetched < $total);

            $this->newLine();
            $this->info("Total rezultate raportate de SEAP: {$total}");
            $this->info("Total anunturi preluate: {$totalFetched}");
            $this->info("Detalii obtinute: {$totalDetails}");
            $this->info("Detalii esuate: {$totalDetailsFailed}");
            $this->info("Anunturi noi salvate: {$totalSaved}");
            $this->info("Anunturi actualizate: {$totalUpdated}");

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Scanarea a esuat.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}