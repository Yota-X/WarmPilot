<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use WP_Error;
use YotaX\WarmPilot\Job_Runner;

final class JobConcurrencyHarness extends Job_Runner {
    use ExposesProtectedMethods;
    public int $processed = 0;

    public function __construct() {}

    protected function process_locked_job_batch(int $job_id): array|WP_Error {
        $this->processed++;
        return ['status' => 'running', 'remaining' => 1];
    }
}

final class JobConcurrencyTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['wp_test_options'] = [];
        $GLOBALS['wp_test_deleted_options'] = [];
    }

    public function testSecondAjaxProcessorCannotEnterTheSameJob(): void {
        $runner = new JobConcurrencyHarness();
        self::assertTrue($runner->call('acquire_job_lock', 42));

        $result = $runner->call('process_job_batch', 42);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('job_locked', $result->code);
        self::assertSame(0, $runner->processed);
        $runner->call('release_job_lock', 42);
    }

    public function testJobLockIsReleasedAfterAProcessedBatch(): void {
        $runner = new JobConcurrencyHarness();

        $result = $runner->call('process_job_batch', 42);

        self::assertSame('running', $result['status']);
        self::assertSame(1, $runner->processed);
        self::assertArrayNotHasKey('warmpilot_job_lock_42', $GLOBALS['wp_test_options']);
    }

    public function testDifferentJobsHaveIndependentLocks(): void {
        $runner = new JobConcurrencyHarness();

        self::assertTrue($runner->call('acquire_job_lock', 41));
        self::assertTrue($runner->call('acquire_job_lock', 42));
        self::assertFalse($runner->call('acquire_job_lock', 41));
    }
}
