<?php
namespace Profibro\Paystack\Controller\Initialize;

use Profibro\Paystack\Controller\AbstractAction;

class Index extends AbstractAction
{
	protected $transaction = false;
	
	protected function getSessionRequestData(){
		return $this->paystackSession->getRequestData();
	}

	protected function redirectToCheckout(){
		$this->getResponse()->setRedirect($this->_url->getUrl('checkout'));
	}

	protected function redirectToPaystack(){
		$this->getResponse()->setRedirect($this->transaction->data->authorization_url);
	}

	protected function initializeTransaction(){
		$this->transaction = $this->_paystack
				->transaction
				->initialize(
					$this->getSessionRequestData()
				);
	}

	public function execute()
	{
		// initialize transaction
		$this->initializeTransaction();
		if(!$this->transaction){
			$this->redirectToCheckout();
		} else {
			$this->redirectToPaystack();
		}
	}
}
