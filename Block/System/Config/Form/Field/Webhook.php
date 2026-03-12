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

namespace Pstk\Paystack\Block\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Backend system config field renderer for displaying the Paystack webhook URL.
 */
class Webhook extends \Magento\Config\Block\System\Config\Form\Field
{

    /**
     * Returns element html
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        try {
            $webhookUrl = $this->_storeManager->getStore()->getBaseUrl() . 'paystack/payment/webhook';
        } catch (\Exception $e) {
            $webhookUrl = '{{store_base_url}}paystack/payment/webhook';
        }

        $value = "You may login to <a target=\"_blank\" href=\"https://dashboard.paystack.co/#/settings/developer\">Paystack Developer Settings</a> to update your Webhook URL to:<br><br>"
                . "<strong style='color:red;'>$webhookUrl</strong>";

        $element->setValue($webhookUrl);

        return $value;
    }
}
