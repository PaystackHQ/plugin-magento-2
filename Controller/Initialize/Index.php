<?php
namespace Profibro\Paystack\Controller\Initialize;

use Profibro\Paystack\Controller\PayAction;

class Index extends PayAction
{
	protected $_transaction = false;
	
	protected function redirectToPaystack(){
		$this->getResponse()->setRedirect($this->_transaction->data->authorization_url);
	}

	protected function initializeTransaction(){
		$this->_transaction = $this->_paystack
				->transaction
				->initialize(
					$this->_requestData
				);
	}

	public function execute()
	{
		if($this->sessionHasValidRequestData()){
			// initialize transaction
			$this->initializeTransaction();
			$this->redirectToPaystack();
		} else {
			$this->redirectToCheckout();
		}
	}
}
