<?php
/**
 * Copyright 2020 Cloud Creativity Limited
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CloudCreativity\LaravelStripe;

use CloudCreativity\LaravelStripe\Connect\Adapter;
use CloudCreativity\LaravelStripe\Contracts\Connect\AdapterInterface;
use CloudCreativity\LaravelStripe\Contracts\Connect\StateProviderInterface;
use CloudCreativity\LaravelStripe\Contracts\Webhooks\ProcessorInterface;
use CloudCreativity\LaravelStripe\Events\AccountDeauthorized;
use CloudCreativity\LaravelStripe\Events\ClientReceivedResult;
use CloudCreativity\LaravelStripe\Events\ClientWillSend;
use CloudCreativity\LaravelStripe\Events\OAuthSuccess;
use CloudCreativity\LaravelStripe\Listeners\DispatchAuthorizeJob;
use CloudCreativity\LaravelStripe\Listeners\DispatchWebhookJob;
use CloudCreativity\LaravelStripe\Listeners\RemoveAccountOnDeauthorize;
use CloudCreativity\LaravelStripe\Log\Logger;
use CloudCreativity\LaravelStripe\Models\StripeAccount;
use CloudCreativity\LaravelStripe\Webhooks\Processor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Stripe;

class ServiceProvider extends BaseServiceProvider
{
    /** Boot services. */
    public function boot(Router $router, Events $events): void
    {
        Stripe::setApiKey(config('cashier.secret'));
        Stripe::setClientId(config('stripe.client_id'));

        if ($version = config('stripe.api_version')) {
            Stripe::setApiVersion($version);
        }

        $this->bootLogging($events);
        $this->bootWebhooks($events);
        $this->bootConnect($events);

        /** Config */
        $this->publishes([
            __DIR__.'/../config/stripe.php' => config_path('stripe.php'),
        ], 'stripe');

        /** Brand Assets */
        $this->publishes([
            __DIR__.'/../resources' => public_path('vendor/stripe'),
        ], 'stripe-brand');

        /** Commands */
        $this->commands(Console\Commands\StripeQuery::class);

        /** Middleware */
        $router->aliasMiddleware('stripe.verify', Http\Middleware\VerifySignature::class);

        /** Migrations and Factories */
        $this->publishes([
            __DIR__.'/../database/factories' => database_path('factories'),
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'stripe-migrations');
    }

    /** Bind services into the service container. */
    public function register(): void
    {
        /** Service */
        $this->app->singleton(StripeService::class);
        $this->app->alias(StripeService::class, 'stripe');

        /** Connect */
        $this->bindConnect();

        /** Webhooks */
        $this->bindWebhooks();

        /** Logger */
        $this->app->singleton(Logger::class, function (Application $app) {
            $level = config('stripe.log.level');

            return new Logger(
                $level ? $app->make(LoggerInterface::class) : new NullLogger(),
                $level,
                config('stripe.log.exclude', [])
            );
        });

        $this->app->alias(Logger::class, 'stripe.log');
    }

    /** Bind the Stripe Connect implementation into the service container. */
    private function bindConnect(): void
    {
        $this->app->singleton(AdapterInterface::class, function (Application $app) {
            return $app->make(LaravelStripe::$connect);
        });

        $this->app->alias(AdapterInterface::class, 'stripe.connect');

        $this->app->bind(Adapter::class, function () {
            return new Adapter(new StripeAccount);
        });

        $this->app->bind(StateProviderInterface::class, function (Application $app) {
            return $app->make(LaravelStripe::$oauthState);
        });
    }

    /** Boot the "Connect" implementation. */
    private function bootConnect(Events $events): void
    {
        $events->listen(OAuthSuccess::class, DispatchAuthorizeJob::class);
        $events->listen(AccountDeauthorized::class, RemoveAccountOnDeauthorize::class);
    }

    /** Bind the webhook implementation into the service container. */
    private function bindWebhooks(): void
    {
        $this->app->singleton(ProcessorInterface::class, function (Application $app) {
            return $app->make(LaravelStripe::$webhooks);
        });

        $this->app->alias(ProcessorInterface::class, 'stripe.webhooks');

        $this->app->bind(Processor::class, function (Application $app) {
            return new Processor(
                $app->make(Dispatcher::class),
                $app->make(Events::class),
                $app->make('stripe.connect'),
                Config::webhookModel()
            );
        });
    }

    /** Boot the webhook implementation. */
    private function bootWebhooks(Events $events): void
    {
        $events->listen('stripe.webhooks', DispatchWebhookJob::class);
        $events->listen('stripe.connect.webhooks', DispatchWebhookJob::class);
    }

    /** Boot the logging implementation. */
    private function bootLogging(Events $events): void
    {
        Stripe::setLogger(app(LoggerInterface::class));

        $this->app->afterResolving(StripeService::class, function () {
            app(Logger::class)->log('Service booted.', [
                'api_key' => substr(Stripe::getApiKey(), 3, 4),
                'api_version' => Stripe::getApiVersion(),
            ]);
        });

        $events->listen(ClientWillSend::class, Listeners\LogClientRequests::class);
        $events->listen(ClientReceivedResult::class, Listeners\LogClientResults::class);
    }
}
