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

namespace Pstk\Paystack\Controller\Payment;

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\StoreManagerInterface;
use Pstk\Paystack\Gateway\PaystackApiClient;


abstract class AbstractPaystackStandard extends \Magento\Framework\App\Action\Action {

    protected $resultPageFactory;

    /**
     *
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     *
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $orderInterface;
    protected $checkoutSession;
    protected $paymentHelper;
    protected $method;
    protected $messageManager;

    /**
     *
     * @var \Pstk\Paystack\Model\Ui\ConfigProvider
     */
    protected $configProvider;

    /**
     *
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     *
     * @var PaystackApiClient
     */
    protected $paystackClient;

    /**
     * @var \Magento\Framework\Event\Manager
     */
    protected $eventManager;

    /**
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     *
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     *
     * @var \Pstk\Paystack\Gateway\Validator\TransactionValidator
     */
    protected $transactionValidator;

    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context  $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
            \Magento\Framework\App\Action\Context $context,
            \Magento\Framework\View\Result\PageFactory $resultPageFactory,
            \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
            \Magento\Sales\Api\Data\OrderInterface $orderInterface,
            \Magento\Checkout\Model\Session $checkoutSession,
            PaymentHelper $paymentHelper,
            \Magento\Framework\Message\ManagerInterface $messageManager,
            \Pstk\Paystack\Model\Ui\ConfigProvider $configProvider,
            StoreManagerInterface $storeManager,
            \Magento\Framework\Event\Manager $eventManager,
            \Magento\Framework\App\Request\Http $request,
            \Psr\Log\LoggerInterface $logger,
            PaystackApiClient $paystackClient,
            ?\Pstk\Paystack\Gateway\Validator\TransactionValidator $transactionValidator = null
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->orderRepository = $orderRepository;
        $this->orderInterface = $orderInterface;
        $this->checkoutSession = $checkoutSession;
        $this->paymentHelper = $paymentHelper;
        $this->messageManager = $messageManager;
        $this->configProvider = $configProvider;
        $this->storeManager = $storeManager;
        $this->eventManager = $eventManager;
        $this->request = $request;
        $this->logger = $logger;
        $this->paystackClient = $paystackClient;
        // Defaulted, not because the settlement gate is optional — it is not, and
        // a null here would silently disable it — but because this is a public
        // abstract base in a Marketplace-distributed module. A required parameter
        // added to it fatals any third-party subclass on upgrade. Magento's own
        // convention for widening a released constructor applies: fall back to the
        // ObjectManager so the guard is always present either way.
        $this->transactionValidator = $transactionValidator
            ?: \Magento\Framework\App\ObjectManager::getInstance()
                ->get(\Pstk\Paystack\Gateway\Validator\TransactionValidator::class);

        parent::__construct($context);
    }

    /**
     * @return \Magento\Payment\Model\MethodInterface
     */
    protected function getMethod()
    {
        if ($this->method === null) {
            $this->method = $this->paymentHelper->getMethodInstance(\Pstk\Paystack\Model\Payment\Paystack::CODE);
        }
        return $this->method;
    }
    
    protected function redirectToFinal($successFul = true, $message="") {
        if($successFul){
            if($message) $this->messageManager->addSuccessMessage(__($message));
            return $this->_redirect('checkout/onepage/success');
        } else {
            if($message) $this->messageManager->addErrorMessage(__($message));
            return $this->_redirect('checkout/onepage/failure');
        }
    }
}
