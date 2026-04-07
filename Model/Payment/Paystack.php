<?php
/**
 * Paystack Magento2 Module using \Magento\Payment\Model\Method\AbstractMethod
 * Copyright (C) 2019 Paystack.com
 * 
 * This file is part of Pstk/Paystack.
 * 
 * Pstk/Paystack is free software: you can redistribute it and/or modify
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Pstk\Paystack\Model\Payment;

/**
 * Paystack main payment method model.
 * Intentionally not available in admin (Create Order) to avoid any impact on
 * admin order creation UI or MFTF tests.
 *
 * @author Olayode Ezekiel <kielsoft@gmail.com>
 */
class Paystack extends \Magento\Payment\Model\Method\AbstractMethod
{

    const CODE = 'pstk_paystack';

    protected $_code = self::CODE;
    protected $_isOffline = true;

    /**
     * Not available for admin (internal) order creation — frontend checkout only.
     */
    protected $_canUseInternal = false;

    /**
     * Check whether the method is available so that it never breaks admin or checkout.
     * Returns false on any exception to avoid breaking payment method list or layout.
     *
     * @param \Magento\Quote\Api\Data\CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(
        ?\Magento\Quote\Api\Data\CartInterface $quote = null
    ) {
        try {
            return parent::isAvailable($quote);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
