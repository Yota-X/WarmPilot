<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use YotaX\WarmPilot\Cron_Manager;

final class CronHarness extends Cron_Manager {
    use ExposesProtectedMethods;
    public array $processedJobs = [];
    public array $jobResponses = [];
    public function __construct() {}

    protected function process_job_batch(int $job_id): array|\WP_Error {
        $this->processedJobs[] = $job_id;
        return array_shift($this->jobResponses[$job_id]) ?? ['status' => 'finished', 'remaining' => 0];
    }
}

final class CronManagerTest extends TestCase {
    private CronHarness $cron;

    protected function setUp(): void {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_deleted_options'] = [];
        $this->cron = new CronHarness();
    }

    public function testRegistersCustomIntervalsWithoutRemovingExistingOnes(): void {
        $result = $this->cron->cron_schedules(['hourly' => ['interval' => 3600]]);
        self::assertSame(60, $result['warmpilot_minute']['interval']);
        self::assertSame(300, $result['five_minutes']['interval']);
        self::assertSame(900, $result['fifteen_minutes']['interval']);
        self::assertSame(604800, $result['weekly']['interval']);
        self::assertArrayHasKey('hourly', $result);
    }

    /**
     * @dataProvider validExpressionProvider
     */
    public function testValidCronExpressions(string $expression): void {
        self::assertTrue($this->cron->call('validate_cron_expression', $expression));
    }

    public function validExpressionProvider(): array {
        return [
            ['* * * * *'],
            ['*/15 0-23/2 * * 1-5'],
            ['0 0 1,15 * 0'],
            ['5 4 * * 7'],
        ];
    }

    /**
     * @dataProvider invalidExpressionProvider
     */
    public function testRejectsInvalidCronExpressions(string $expression): void {
        self::assertFalse($this->cron->call('validate_cron_expression', $expression));
    }

    public function invalidExpressionProvider(): array {
        return [
            ['* * * *'],
            ['60 * * * *'],
            ['* 24 * * *'],
            ['*/0 * * * *'],
            ['10-5 * * * *'],
            ['JAN * * * *'],
        ];
    }

    public function testCronMatchingSupportsStepsRangesListsAndSundaySeven(): void {
        self::assertTrue($this->cron->call('cron_field_matches', '*/15', 30, 0, 59));
        self::assertFalse($this->cron->call('cron_field_matches', '*/15', 31, 0, 59));
        self::assertTrue($this->cron->call('cron_field_matches', '1-5', 3, 0, 7, true));
        self::assertTrue($this->cron->call('cron_field_matches', '7', 0, 0, 7, true));
    }

    public function testFindsNextCronOccurrence(): void {
        $from = new DateTimeImmutable('2026-07-24 10:07:42', new DateTimeZone('UTC'));
        $next = $this->cron->call('cron_next_occurrence', '*/15 * * * *', $from);
        self::assertSame('2026-07-24 10:15:00', $next?->format('Y-m-d H:i:s'));
    }

    public function testAlignedIntervalsUseExpectedBoundaries(): void {
        $timestamp = (new DateTimeImmutable('2026-07-24 10:07:42', new DateTimeZone('UTC')))->getTimestamp();
        self::assertSame('2026-07-24 10:10:00', $this->cron->call('aligned_next_run_mysql', 'five_minutes', $timestamp));
        self::assertSame('2026-07-24 10:15:00', $this->cron->call('aligned_next_run_mysql', 'fifteen_minutes', $timestamp));
        self::assertSame('2026-07-24 11:00:00', $this->cron->call('aligned_next_run_mysql', 'hourly', $timestamp));
        self::assertSame('2026-07-24 12:00:00', $this->cron->call('aligned_next_run_mysql', 'twicedaily', $timestamp));
        self::assertSame('2026-07-25 00:00:00', $this->cron->call('aligned_next_run_mysql', 'daily', $timestamp));
    }

    public function testScheduleLabelsIncludeCustomExpression(): void {
        self::assertSame('Cron: 0 2 * * *', $this->cron->call('schedule_label', (object) [
            'interval_key' => 'custom_cron',
            'cron_expression' => '0 2 * * *',
        ]));
    }

    public function testGlobalCronLockRejectsASecondRunner(): void {
        self::assertTrue($this->cron->call('acquire_cron_lock'));
        self::assertFalse($this->cron->call('acquire_cron_lock'));
        $this->cron->call('release_cron_lock');
        self::assertTrue($this->cron->call('acquire_cron_lock'));
    }

    public function testCronJobsAreProcessedSequentiallyInIdOrder(): void {
        $this->cron->jobResponses = [
            10 => [
                ['status' => 'running', 'remaining' => 2],
                ['status' => 'finished', 'remaining' => 0],
            ],
            20 => [
                ['status' => 'finished', 'remaining' => 0],
            ],
        ];

        $this->cron->call('process_cron_jobs', [10, 20], microtime(true) + 5);

        self::assertSame([10, 10, 20], $this->cron->processedJobs);
    }
}
