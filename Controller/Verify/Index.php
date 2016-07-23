<?php
namespace Profibro\Paystack\Controller\Verify;

use Profibro\Paystack\Controller\AbstractAction;
use Magento\Sales\Model\Order;

class Index extends AbstractAction
{
	protected $_orderId;
	protected $_trxRef;
	
	protected function orderMatchesRef(){
		// for a reference to match the order, the ref must start with the orderid and a dash
		return (strpos($this->_trxRef, '' . $this->_orderId . '-')===0);
	}
	
	protected function fetchAndVerifyOrderIdFromSession(){
		$this->_orderId = $this->checkoutSession->getLastRealOrderId();
		if(!$this->_orderId){
			$this->messageManager->addError(__('Unable to start verification'));
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
			return false;
		}
		return true;
	}

	protected function fetchAndVerifyTrxRefFromGET(){
		$this->_trxRef = filter_input(INPUT_GET, 'trxref');
		if(!$this->orderMatchesRef()){
			$this->messageManager->addError( __('Unable to load order.') );
			$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
			return false;
		}
		return true;
	}

	public function execute()
	{
		if(!fetchAndVerifyOrderIdFromSession())
			return;

		if(!fetchAndVerifyTrxRefFromGET())
			return;

		$order = $this->salesOrderFactory->create();
		$order->loadByIncrementId($this->_orderId);
		$payment = $order->getPayment();
		// die($payment->getMethodCode());
		// if($payment->getMethodCode() != 'profibro_paystack'){
		//	 $this->messageManager->addError(__('Requested payment method does not match with order.'));
		//	 $this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
		// }

		$verifyResponse = $this->_paystack->transaction->verify(['reference'=>$this->_trxRef]);

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
	
	protected function cancelOrder($order){
		try {
			// TODO verify if this logic of order cancellation is deprecated
			/** @var \Magento\Sales\Model\Order $order */
			if ($order && $order->getId() && $order->getQuoteId() == $this->checkoutSession->getQuoteId()) {
				$order->cancel()->save();
				$this->checkoutSession
					->unsLastQuoteId()
					->unsLastSuccessQuoteId()
					->unsLastOrderId()
					->unsLastRealOrderId();
				$this->messageManager->addSuccessMessage(
					__('Order has been canceled.')
				);
			}
		} catch (\Magento\Framework\Exception\LocalizedException $e) {
			$this->messageManager->addExceptionMessage($e, $e->getMessage());
		} catch (\Exception $e) {
			$this->messageManager->addExceptionMessage($e, __('Unable to cancel order'));
		}
	}
}