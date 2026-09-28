<?php

/**
 * Paystack Magento2 Module using \Magento\Payment\Model\Method\AbstractMethod
 * Copyright (C) 2019 Paystack.com
 *
 * This file is part of Pstk/Paystack.
 *
 * Pstk/Paystack is free software => you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http =>//www.gnu.org/licenses/>.
 */

namespace Pstk\Paystack\Gateway;

/**
 * Static and untyped on purpose: `sales_order.grand_total` arrives from PDO as
 * a numeric string. Any FUTURE caller file that declares `strict_types=1`
 * would get a `TypeError` calling this with a numeric string like
 * `'19.9900'`, and this module's money-path callers swallow `\Throwable` —
 * turning that into a silent refusal of every paid order. Untyped and
 * non-strict on purpose. Shared by Controller/Payment/Setup.php (send side)
 * and Gateway/Validator/TransactionValidator.php (accept side) so the two can
 * never drift apart independently.
 *
 * `* 100` is correct for every currency Paystack supports, including
 * zero-decimal currencies like XOF/RWF — verified, do not add a
 * per-currency multiplier map.
 */
class SubunitConverter
{
    public static function toSubunit($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
