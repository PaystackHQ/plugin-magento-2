<?php
namespace Profibro\Paystack\Model;

use Magento\Payment\Helper\Data as PaymentHelper;
use Yabacon\Paystack;
use Exception;

class Payment implements \Profibro\Paystack\Api\PaymentInterface
{
    const CODE = 'profibro_paystack';

    protected $config;
    protected $paystack;
    protected $checkoutSession;

    public function __construct(
        PaymentHelper $paymentHelper,
        \Magento\Sales\Model\OrderFactory $salesOrderFactory,
        \Magento\Checkout\Model\Session $checkoutSession
    )
    {
        $this->config = $paymentHelper->getMethodInstance(self::CODE);

        $secretKey = $this->config->getConfigData('live_secret_key');
        if ($this->config->getConfigData('test_mode')) {
            $secretKey = $this->config->getConfigData('test_secret_key');
        }

        $this->paystack = new Paystack($secretKey);
        $this->checkoutSession = $checkoutSession;
        $this->salesOrderFactory = $salesOrderFactory;
    }

    /**
     * @return bool
     */
    public function verifyPayment($reference, )
    {
        try{
            $transaction_details = $this->paystack->transaction->verify([
                'reference' => $reference
            ]);
            if($transaction_details->data->status === 'success'){
                $_orderId = $this->checkoutSession->getLastRealOrderId();
                $order = $this->salesOrderFactory->create();
                $order->loadByIncrementId($_orderId);
                $orderTotal = round($order->getGrandTotal(), 2) * 100;
                if(intval($orderTotal)===intval($verifyResponse->data->amount)){
                    return true;
                }
            }
        } catch(Exception $e) {
            //
        }
        return false;
    }
}
