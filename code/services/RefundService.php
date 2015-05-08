<?php

class RefundService extends PaymentService{

	/**
	 * Return money to the previously charged credit card.
	 * @return PaymentResponse encapsulated response info
	 */
	public function refund($data = array()) {
		if (!$this->payment->canRefund()) {
			return null; //could be handled better? send payment response?
		}
		if (!$this->payment->isInDB()) {
			$this->payment->write();
		}

		if(empty($data['receipt'])) {
			return null;
		}

		if (empty($data['amount'])) {
			$data['amount'] = $this->payment->MoneyCurrency;
		}

		// If needed, find the transaction reference for the first capture that worked.
		// Most payment gateways need this.
		$firstPurchaseMessage = PurchasedResponse::get()
			->filter('PaymentID', $this->payment->ID)
			->exclude('Reference', null)
			->sort('Created')
			->first();

		if ($firstPurchaseMessage && empty($data['transactionReference'])) {
			$data['transactionReference'] = $firstPurchaseMessage->Reference;
		}

		$message = $this->createMessage('RefundRequest');
		$message->write();

		$requestData = array_merge(
			$data,
			array(
				'currency' => $this->payment->MoneyCurrency,
				'receipt' => (int) $data['receipt'],
			)
		);

		$this->payment->extend('onBeforeRefund', $requestData);
		$request = $this->oGateway()->refund($requestData);
		$this->logToFile($request->getParameters(), 'RefundRequest_post');
		$gatewayresponse = $this->createGatewayResponse();

		try {
			$response = $this->response = $request->send();
			//update payment model
			if ($response->isSuccessful()) {
				// if this was a partial refund, we need to create a
				// duplicate payment with the remaining amount
				if ($this->payment->MoneyAmount > $data['amount']) {
					$newPayment = $this->payment->duplicate();
					$newPayment->Created = date('Y-m-d H:i:s');
					$newPayment->dbObject('Money')->setAmount($this->payment->MoneyAmount - $data['amount']);
					$newPayment->write();

					if ($firstPurchaseMessage) {
						$newMessage = $firstPurchaseMessage->duplicate(false);
						$newMessage->PaymentID = $newPayment->ID;
						$newMessage->write();
					}

					$this->payment->dbObject('Money')->setAmount($data['amount']);
				}

				//successful payment
				$this->createMessage('RefundedResponse', $response);
				$this->payment->Status = 'Refunded';
				$gatewayresponse->setMessage('Payment refunded');
				$this->payment->extend('onRefunded', $gatewayresponse);
			} else {
				//handle error
				$this->createMessage('RefundError', $response);
				$gatewayresponse->setMessage(
					"Error (".$response->getCode()."): ".$response->getMessage()
				);
			}
			$this->payment->write();
			$gatewayresponse->setOmnipayResponse($response);
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage('GatewayErrorMessage', $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		return $gatewayresponse;
	}

}
