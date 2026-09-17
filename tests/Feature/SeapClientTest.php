<?php

namespace Tests\Feature;

use App\Services\SeapAccessRestricted;
use App\Services\SeapClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SeapClientTest extends TestCase
{
    public static function families(): array
    {
        return ['participare' => ['CN', 'PUBLICCNotice'], 'simplificate' => ['SCN', 'PublicRFQInvitation'], 'intentii' => ['PI', 'PUBLICPINotice']];
    }

    #[DataProvider('families')]
    public function test_queries_the_public_json_family_and_preserves_pagination_and_dates(string $family, string $endpoint): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/'.$endpoint.'/GetAll/' => Http::response(['total' => 0, 'items' => []])]);

        $result = app(SeapClient::class)->getFamilyNotices($family, 18, 2, '2026-08-03', '2026-09-10');

        $this->assertSame(['total' => 0, 'items' => []], $result);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['pageIndex'] === 2
            && $request['pageSize'] === 18 && $request['publicationDateFrom'] === '2026-08-03'
            && $request['publicationDateTo'] === '2026-09-10'
            && ($family !== 'SCN' || $request['sysNoticeType'] === ['id' => 17]));
    }

    public function test_retries_503_and_recovers(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::sequence()->push([], 503)->push(['total' => 0, 'items' => []])]);

        $this->assertSame(0, app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null)['total']);
        Http::assertSentCount(2);
    }

    public function test_retries_connection_timeouts_three_times_then_throws(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => function () use (&$attempts) {
            $attempts++;

            return Http::failedConnection('timeout');
        }]);

        try {
            app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null);
            $this->fail('Timeout expected.');
        } catch (ConnectionException) {
            $this->assertSame(3, $attempts);
        }
    }

    public function test_does_not_retry_http_403(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response([], 403)]);

        try {
            app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null);
            $this->fail('HTTP error expected.');
        } catch (SeapAccessRestricted $exception) {
            $this->assertStringContainsString('403', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_does_not_retry_http_429(): void
    {
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response([], 429)]);

        try {
            app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null);
            $this->fail('HTTP error expected.');
        } catch (SeapAccessRestricted $exception) {
            $this->assertStringContainsString('429', $exception->getMessage());
            Http::assertSentCount(1);
        }
    }

    public function test_spaces_out_public_requests(): void
    {
        Sleep::fake();
        config(['services.seap.request_delay_ms' => 1000]);
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response(['total' => 0, 'items' => []])]);

        app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null);

        Sleep::assertSequence([Sleep::for(1)->second()]);
    }

    public static function invalidResponses(): array
    {
        return ['HTML' => ['<html>error</html>'], 'schema lipsa' => [[]],
            'trunchiat' => [['total' => 3000, 'items' => [], 'searchTooLong' => true]]];
    }

    #[DataProvider('invalidResponses')]
    public function test_rejects_malformed_or_truncated_responses(array|string $response): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://e-licitatie.ro/api-pub/PUBLICCNotice/GetAll/' => Http::response($response)]);
        $this->expectException(RuntimeException::class);

        app(SeapClient::class)->getFamilyNotices('CN', 18, 0, null, null);
    }
}
