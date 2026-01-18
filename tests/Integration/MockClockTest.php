<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use EnterpriseToolingForSymfony\SharedBundle\DateAndTime\Service\DateAndTimeService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;

final class MockClockTest extends KernelTestCase
{
    public function test(): void
    {
        // Set clock BEFORE booting kernel
        $mockClock = new MockClock();
        Clock::set($mockClock);

        $mockClock->modify('2001-02-03');

        self::bootKernel();
        $container = static::getContainer();

        // Get the service instance from the container
        /** @var DateAndTimeService $dateAndTimeService */
        $dateAndTimeService = $container->get(DateAndTimeService::class);

        // Call the non-static method on the instance
        $this->assertSame(
            '2001-02-03',
            $dateAndTimeService->getDateTimeImmutable()
                               ->format('Y-m-d')
        );

        // Reset clock - This can interfere with other tests if run in same process
        // Clock::set(new Clock());
    }
}
