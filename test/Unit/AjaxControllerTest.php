<?php
declare(strict_types=1);

namespace WarmPilot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WarmPilot\Tests\Support\ExposesProtectedMethods;
use YotaX\WarmPilot\Ajax_Controller;

final class AjaxHarness extends Ajax_Controller {
    use ExposesProtectedMethods;
    public function __construct() {}
}

final class AjaxControllerTest extends TestCase {
    public function testCsvLineQuotesFieldsAndEscapesEmbeddedQuotes(): void {
        $ajax = new AjaxHarness();
        self::assertSame(
            "\"one\",\"two,three\",\"say \"\"hello\"\"\"\r\n",
            $ajax->call('csv_line', ['one', 'two,three', 'say "hello"'])
        );
    }
}
