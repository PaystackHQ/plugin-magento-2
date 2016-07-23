<?php
namespace Profibro\Paystack\Controller\Pop;

use Profibro\Paystack\Controller\AbstractAction;
use Magento\Sales\Model\Order;

class Index extends AbstractAction
{
	protected $_pageString = '';
	protected $_callbackJs = '';

	public function execute()
	{
		if($this->sessionHasValidRequestData()){
			$this->buildPageString();
			$this->printPage();
		} else {
			$this->redirectToCheckout();
		}
	}

	protected function printPage(){
		echo $this->_pageString;
	}

	protected function addScriptImports(){
		$this->_pageString .= '<script src="https://code.jquery.com/jquery-3.1.0.slim.min.js"></script>
			<form ><script src="https://js.paystack.co/v1/inline.js"></script></form>';
	}

	protected function addPaystackHandlerScript(){
		$this->_pageString .= '<script>
			var paystackHandler = PaystackPop.setup({
				key: \''.addslashes(trim($this->getPublicKey())).'\',
				email: \''.addslashes(trim($this->_requestData['email'])).'\',
				amount: '.$this->_requestData['amount'].',
				ref: \''.addslashes(trim($this->_requestData['reference'])).'\',
				callback: function(response)'.$this->_callbackJs.',
				onClose: function()'.$this->_callbackJs.'
			});
			</script>';
	}

	protected function addPopScript(){
		$this->_pageString .= '<script>
			$( document ).ready(function() {
				if (paystackHandler.fallback) {
					// Handle non-support of iframes by attempting an initialize
					window.location.href = \''.addslashes($this->_url->getUrl('*/initialize')).'\';
				} else {
					paystackHandler.openIframe();
				}
			});
			</script>';
	}

	protected function buildCallbackJs(){
		$this->_callbackJs = '{
		window.location.href = \'' . addslashes($this->_requestData['callback_url']
		. ((strpos($this->_requestData['callback_url'], '?')===FALSE) ? "?" : "&" )
		. "trxref=".urlencode($this->_requestData['reference'])).'\';
		}';
	}

	protected function buildPageString(){
		$this->addScriptImports();
		$this->buildCallbackJs();
		$this->addPaystackHandlerScript();
		$this->addPopScript();
	}

}