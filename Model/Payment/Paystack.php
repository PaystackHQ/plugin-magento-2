<?php
/**
 * Paystack Magento2 Module
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

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Paystack payment method model.
 *
 * Implements MethodInterface directly (does not extend AbstractMethod) so that
 * Adobe Commerce EE plugins on AbstractMethod cannot be inherited by this class.
 * This prevents EE-specific interceptors with frontend-only dependencies from
 * crashing admin pages such as the order-creation customer-selection grid.
 */
class Paystack extends DataObject implements MethodInterface
{
    const CODE = 'pstk_paystack';

    /** @var string */
    protected $_code = self::CODE;

    /** @var string */
    protected $_infoBlockType = \Magento\Payment\Block\Info::class;

    /** @var InfoInterface|null */
    private $infoInstance;

    /** @var int|string|null */
    private $storeId;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($data);
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    public function getCode()
    {
        return $this->_code;
    }

    // -------------------------------------------------------------------------
    // Store scope
    // -------------------------------------------------------------------------

    public function setStore($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }

    public function getStore()
    {
        return $this->storeId;
    }

    // -------------------------------------------------------------------------
    // Configuration helpers
    // -------------------------------------------------------------------------

    public function getConfigData($field, $storeId = null)
    {
        if ($storeId === null) {
            $storeId = $this->getStore();
        }
        $path = 'payment/' . $this->getCode() . '/' . $field;
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getTitle()
    {
        return $this->getConfigData('title');
    }

    // -------------------------------------------------------------------------
    // Availability checks
    // -------------------------------------------------------------------------

    public function isActive($storeId = null)
    {
        return (bool)(int)$this->getConfigData('active', $storeId);
    }

    public function isAvailable(?CartInterface $quote = null)
    {
        $storeId = $quote ? $quote->getStoreId() : null;
        return $this->isActive($storeId) && $this->canUseCheckout();
    }

    public function canUseInternal()
    {
        return false;
    }

    public function canUseCheckout()
    {
        $value = $this->getConfigData('can_use_checkout');
        return $value === null ? true : (bool)(int)$value;
    }

    public function canUseForCountry($country)
    {
        if (!$country) {
            return true;
        }
        if ((int)$this->getConfigData('allowspecific') === 1) {
            $specificCountries = explode(',', (string)$this->getConfigData('specificcountry'));
            if (!in_array($country, $specificCountries, true)) {
                return false;
            }
        }
        return true;
    }

    public function canUseForCurrency($currencyCode)
    {
        return true;
    }

    // -------------------------------------------------------------------------
    // Capability flags — Paystack is an external redirect gateway
    // -------------------------------------------------------------------------

    public function isGateway()
    {
        return true;
    }

    public function isOffline()
    {
        return false;
    }

    public function isInitializeNeeded()
    {
        return false;
    }

    public function canOrder()
    {
        return false;
    }

    public function canAuthorize()
    {
        return false;
    }

    public function canCapture()
    {
        return false;
    }

    public function canCapturePartial()
    {
        return false;
    }

    public function canCaptureOnce()
    {
        return false;
    }

    public function canRefund()
    {
        return false;
    }

    public function canRefundPartialPerInvoice()
    {
        return false;
    }

    public function canVoid()
    {
        return false;
    }

    public function canEdit()
    {
        return true;
    }

    public function canFetchTransactionInfo()
    {
        return false;
    }

    public function canReviewPayment()
    {
        return false;
    }

    // -------------------------------------------------------------------------
    // Block types
    // -------------------------------------------------------------------------

    public function getFormBlockType()
    {
        return \Magento\Payment\Block\Form::class;
    }

    public function getInfoBlockType()
    {
        return $this->_infoBlockType;
    }

    // -------------------------------------------------------------------------
    // Info instance (used by Magento to store payment data on quotes/orders)
    // -------------------------------------------------------------------------

    public function getInfoInstance()
    {
        return $this->infoInstance;
    }

    public function setInfoInstance(InfoInterface $info)
    {
        $this->infoInstance = $info;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Payment actions — Paystack processes payments externally
    // -------------------------------------------------------------------------

    public function validate()
    {
        return $this;
    }

    public function assignData(DataObject $data)
    {
        return $this;
    }

    public function initialize($paymentAction, $stateObject)
    {
        return $this;
    }

    public function getConfigPaymentAction()
    {
        return $this->getConfigData('payment_action');
    }

    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        return [];
    }

    public function order(InfoInterface $payment, $amount)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Order action is not supported by Paystack.')
        );
    }

    public function authorize(InfoInterface $payment, $amount)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Authorize action is not supported by Paystack.')
        );
    }

    public function capture(InfoInterface $payment, $amount)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Capture action is not supported by Paystack.')
        );
    }

    public function refund(InfoInterface $payment, $creditMemo)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Refund action is not supported by Paystack.')
        );
    }

    public function cancel(InfoInterface $payment)
    {
        return $this;
    }

    public function void(InfoInterface $payment)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Void action is not supported by Paystack.')
        );
    }

    public function acceptPayment(InfoInterface $payment)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Accept payment action is not supported by Paystack.')
        );
    }

    public function denyPayment(InfoInterface $payment)
    {
        throw new \Magento\Framework\Exception\LocalizedException(
            __('Deny payment action is not supported by Paystack.')
        );
    }
}
