<?php

namespace App\Providers;

use App\Queue\Connectors\HwidObservationRedisConnector;
use App\Support\HwidObservationConfigValidator;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;

class SubscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(QueueManager::class, function (QueueManager $manager): void {
            $manager->addConnector(HwidObservationConfigValidator::DRIVER, function () {
                HwidObservationConfigValidator::assertReleaseSwitchCombination();
                HwidObservationConfigValidator::assertQueueParity();

                return new HwidObservationRedisConnector($this->app['redis']);
            });
        });
    }

    public function boot(): void
    {
        HwidObservationConfigValidator::assertReleaseSwitchCombination();

        if (
            (bool) config('subscription.hwid_async_metadata', false)
            || (bool) config('subscription.hwid_observation_worker_enabled', false)
        ) {
            HwidObservationConfigValidator::assertQueueParity();
        }
    }
}
