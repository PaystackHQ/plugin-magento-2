<?php
namespace Profibro\Paystack\Controller\Pop;

use Profibro\Paystack\Controller\AbstractAction;
use Magento\Sales\Model\Order;

class Index extends AbstractAction
{
	public function execute()
	{
		$orderId = $this->checkoutSession->getLastRealOrderId();
		if(!$orderId){
			$this->messageManager->addError(__('Unable to start verification'));
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
		}

		$trxref = filter_input(INPUT_GET, 'trxref');
		if(!$this->orderMatchesRef($orderId, $trxref)){
			$this->messageManager->addError( __('Unable to load order.') );
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
		}

		$order = $this->salesOrderFactory->create();
		$order->loadByIncrementId($orderId);
		$payment	= $order->getPayment();
		// die($payment->getMethodCode());
		// if($payment->getMethodCode() != 'profibro_paystack'){
		//	 $this->messageManager->addError(__('Requested payment method does not match with order.'));
		//	 $this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
		// }

		$verifyResponse = $this->_paystack->transaction->verify(['reference'=>$trxref]);

		$orderTotal = round($order->getGrandTotal(), 2) * 100;
		if(
			($verifyResponse->data->status === 'success')
			&& 
			(intval($orderTotal)===intval($verifyResponse->data->amount))
		){
			// successful transaction
			$this->messageManager->addSuccess( __('Payment successful.') );
			// update payment info and close transaction
			$payment->setIsTransactionApproved(true)
					->setIsTransactionClosed(true)
					->setAdditionalInformation('RAW_JSON_RESPONSE', json_encode($verifyResponse));
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout/onepage/success'));
		} else {
			$this->cancelOrder($order);
			$payment->setAdditionalInformation('RAW_JSON_RESPONSE', json_encode($verifyResponse));
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout/cart'));
		}
	}
}