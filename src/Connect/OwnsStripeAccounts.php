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

namespace CloudCreativity\LaravelStripe\Connect;

use CloudCreativity\LaravelStripe\Config;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use JetBrains\PhpStorm\Pure;
use LogicException;
use function get_class;

trait OwnsStripeAccounts
{

    /** Get the unique identifier for the Stripe account owner. */
    #[Pure] public function getStripeIdentifier(): int|string
    {
        return $this->{$this->getKeyName()};
    }


    /**
     * @return HasMany
     */
    public function stripeAccounts(): HasMany
    {
        return $this->hasMany(
            Config::connectModel(),
            'owner_id',
            $this->{$this->getKeyName()}
        );
    }
}
