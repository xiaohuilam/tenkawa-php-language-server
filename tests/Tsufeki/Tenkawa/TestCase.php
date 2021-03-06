<?php declare(strict_types=1);

namespace Tests\Tsufeki\Tenkawa;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Recoil\Kernel;
use Tsufeki\Tenkawa\Server\Utils\PriorityKernel\ScheduledReactKernel;

abstract class TestCase extends BaseTestCase
{
    /**
     * @var Kernel
     */
    protected $kernel;

    protected function setUp()
    {
        parent::setUp();
        $this->kernel = ScheduledReactKernel::create();
    }

    protected function async($coroutine)
    {
        $result = null;
        $exception = null;

        $this->kernel->execute(function () use ($coroutine, &$result, &$exception) {
            try {
                $result = yield $coroutine;
            } catch (\Throwable $e) {
                $exception = $e;
            }
        });

        $this->kernel->run();

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    public function assertJsonEquivalent($expected, $actual)
    {
        $this->assertJsonStringEqualsJsonString(json_encode($expected) ?: '', json_encode($actual) ?: '');
    }
}
