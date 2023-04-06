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

namespace CloudCreativity\LaravelStripe\Webhooks;

use CloudCreativity\LaravelStripe\Config;
use CloudCreativity\LaravelStripe\Contracts\Connect\AccountInterface;
use CloudCreativity\LaravelStripe\Contracts\Connect\AdapterInterface;
use CloudCreativity\LaravelStripe\Contracts\Webhooks\ProcessorInterface;
use CloudCreativity\LaravelStripe\Jobs\ProcessWebhook;
use CloudCreativity\LaravelStripe\Models\StripeEvent;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Stripe\Event;

class Processor implements ProcessorInterface
{
    public const EVENT_PREFIX = 'stripe.webhooks';

    public const CONNECT_EVENT_PREFIX = 'stripe.connect.webhooks';

    /**
     * @var Bus
     */
    private $queue;

    /**
     * @var Events
     */
    private $events;

    /**
     * @var AdapterInterface
     */
    private $accounts;

    /**
     * @var Model
     */
    private $model;

    /**
     * Processor constructor.
     *
     * @param  Bus  $queue
     * @param  Events  $events
     * @param  AdapterInterface  $accounts
     * @param  Model  $model
     */
    public function __construct(
        Bus $queue,
        Events $events,
        AdapterInterface $accounts,
        Model $model
    ) {
        $this->queue = $queue;
        $this->events = $events;
        $this->accounts = $accounts;
        $this->model = $model;
    }

    /**
     * {@inheritDoc}
     */
    public function receive(Event $event): void
    {
        $data = $event->jsonSerialize();

        /**
         * Create the model, giving it the option of filling the account as `account_id`.
         */
        $model = $this->model->create(
            collect($data)->put('account_id', Arr::get($data, 'account'))->all()
        );

        /** Get the queue config for this specific event.  */
        $accountId = data_get($event, 'account');
        $queue = Config::webhookQueue($event->type, (bool) $accountId);

        /** Dispatch a job to asynchronously process the webhook. */
        $job = new ProcessWebhook($model, $data);
        $job->onConnection($queue['connection'])->onQueue($queue['queue']);

        $this->queue->dispatch($job);
    }

    /**
     * {@inheritDoc}
     */
    public function didReceive(Event $event): bool
    {
        return $this->model->newQuery()->whereKey($event->id)->exists();
    }

    /**
     * Dispatch a processed webhook.
     *
     * For each webhook, we dispatch events to give applications options
     * as to how they want to bind.
     *
     * E.g. if the Stripe event name is `payment_intent.succeeded`, we dispatch:
     *
     * - `stripe.webhooks`
     * - `stripe.webhooks:payment_intent`
     * - `stripe.webhooks:payment_intent.succeeded`
     *
     * However, if the event is a "Connect" webhook, we dispatch:
     *
     * - `stripe.connect.webhooks`
     * - `stripe.connect.webhooks:payment_intent`
     * - `stripe.connect.webhooks:payment_intent.succeeded`
     */
    public function dispatch(Event $webhook, ?StripeEvent $model = null): void
    {
        if ($accountId = Arr::get($webhook, 'account')) {
            $this->dispatchConnect($webhook, $this->accounts->find($accountId), $model);
        } else {
            $this->dispatchAccount($webhook, $model);
        }

        /** Update the timestamps on the stored event */
        if ($model instanceof Model) {
            $model->touch();
        }
    }

    /** Dispatch a webhook for the application's Stripe account. */
    protected function dispatchAccount(Event $event, mixed $model): void
    {
        $webhook = new Webhook(
            $event,
            $model,
            Config::webhookQueue($event->type)
        );

        foreach ($this->eventsFor($event->type, false) as $name) {
            $this->events->dispatch($name, $webhook);
        }
    }

    /** Dispatch a webhook for a Stripe Connect account. */
    protected function dispatchConnect(Event $event, ?AccountInterface $account = null, mixed $model = null): void
    {
        $webhook = new ConnectWebhook(
            $event,
            $account,
            $model,
            Config::webhookQueue($event->type, true)
        );

        foreach ($this->eventsFor($event->type, true) as $name) {
            $this->events->dispatch($name, $webhook);
        }
    }

    /** Get event names for the specified webhook. */
    protected function eventsFor(string $type, bool $connect): array
    {
        $prefix = $connect ? static::CONNECT_EVENT_PREFIX : static::EVENT_PREFIX;

        return [
            $prefix,
            sprintf('%s:%s', $prefix, explode('.', $type)[0]),
            sprintf('%s:%s', $prefix, $type),
        ];
    }
}
