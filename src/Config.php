<?php
/*
 * Copyright 2023 Cloud Creativity Limited
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

use CloudCreativity\LaravelStripe\Contracts\Connect\AccountOwnerInterface;
use CloudCreativity\LaravelStripe\Models\StripeEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Webhook;

class Config
{
    /** Get Connect account owner model. */
    public static function connectOwner(): Model|AccountOwnerInterface|RuntimeException
    {
        $class = self::fqn('connect.owner');

        return new $class;
    }

    /** Get the Connect queue config. */
    public static function connectQueue(): array
    {
        return [
            'connection' => config('stripe.connect.queue_connection'),
            'queue' => config('stripe.connect.queue'),
        ];
    }

    public static function webhookModel(): StripeEvent
    {
        $class = self::fqn('webhooks.model');

        return new $class;
    }

    /** Get a webhook signing secret by key. */
    public static function webhookSigningSecrect(string $name): RuntimeException|string
    {
        if (! $secret = config("stripe.webhooks.signing_secrets.$name")) {
            throw new RuntimeException("Webhook signing secret does not exist: $name");
        }

        if (! is_string($secret) || empty($secret)) {
            throw new RuntimeException("Invalid webhook signing secret: $name");
        }

        return $secret;
    }

    /** Get the queue config for the named webhook event. */
    public static function webhookQueue(string $type, bool $connect = false): array
    {
        $path = sprintf(
            'webhooks.%s.%s',
            $connect ? 'connect' : 'account',
            str_replace('.', '_', $type)
        );

        return array_replace([
            'connection' => config('stripe.webhooks.default_queue_connection'),
            'queue' => config('stripe.webhooks.default_queue'),
            'job' => null,
        ], (array) config("stripe.$path"));
    }

    /**
     * Get the currencies the application supports.
     *
     * We lowercase the currencies because Stripe returns currencies from the API
     * in lowercase.
     *
     * @return Collection
     */
    public static function currencies(): Collection
    {
        $currencies = collect(config('stripe.currencies'))->map(function ($currency) {
            return strtolower($currency);
        });

        if ($currencies->isEmpty()) {
            throw new RuntimeException('Expecting to support at least one currency.');
        }

        return $currencies;
    }

    /** Get the minimum charge amount for the specified currency. */
    public static function minimum(string $currency): int
    {
        if (! is_string($currency) || empty($currency)) {
            throw new InvalidArgumentException('Expecting a non-empty string.');
        }

        $currency = strtoupper($currency);
        $min = collect(config('stripe.minimum_charge_amounts'))->get($currency);

        if (! is_int($min) || 1 > $min) {
            throw new RuntimeException("Invalid minimum {$currency} charge amount for currency.");
        }

        return $min;
    }

    private static function fqn(string $key): RuntimeException|string
    {
        $fqn = config("stripe.$key");

        if (! class_exists($fqn)) {
            throw new RuntimeException("Configured class at 'stripe.{$key}' does not exist: {$fqn}");
        }

        return $fqn;
    }
}
