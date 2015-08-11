<?php
use Omnipay\Common\CreditCard;

/**
 * Wrapper for create/update/deleteCard methods on omnipay gateway.
 *
 * @package omnipay
 */
class SavedCardService extends PaymentService {

	/**
	 * Attempt to save a new credit card.
	 *
	 * @param  array $data
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function createCard($data = array()) {
		if ($this->payment->Status !== "Created") {
			return null; //could be handled better? send payment response?
		}
		if (!$this->payment->isInDB()) {
			$this->payment->write();
		}

		//update success/fail urls
		$this->update($data);

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		// if they didn't give us a name, create one from the masked number
		if (empty($data['cardName'])) {
			$data['cardName'] = preg_replace('/[^0-9]/', '', $data['number']);               // normalize out dashes and spaced
			$data['cardName'] = preg_replace('/[0-9]/', '*', $data['cardName']);                 // replace numbers
			$data['cardName'] = substr($data['cardName'], 0, -4) . substr($data['number'], -4);  // swap in the last 4 digits
		}

		$gatewaydata = array_merge($data,array(
				'card' => $this->getCreditCard($data),
				//set all gateway return/cancel/notify urls to PaymentGatewayController endpoint
				'returnUrl' => $this->getEndpointURL("complete", $this->payment->Identifier),
				'cancelUrl' => $this->getEndpointURL("cancel", $this->payment->Identifier),
				'notifyUrl' => $this->getEndpointURL("notify", $this->payment->Identifier)
			)
		);

		if(!isset($gatewaydata['transactionId'])){
			$gatewaydata['transactionId'] = $this->payment->Identifier;
		}

		$request = $this->oGateway()->createCard($gatewaydata);

		$message = $this->createMessage('CreateCardRequest', $request);
		$message->SuccessURL = $this->getReturnUrl();
		$message->FailureURL = $this->getCancelUrl();
		$message->write();

		$gatewayresponse = $this->createGatewayResponse();
		try {
			$response = $this->response = $request->send();
			$gatewayresponse->setOmnipayResponse($response);
			//update payment model
			if ($response->isSuccessful()) {
				//successful card creation
				$this->createMessage('CreateCardResponse', $response);

				// create the saved card
				$card = new SavedCreditCard(array(
					'CardReference'  => $response->getCardReference(),
					'LastFourDigits' => substr($data['number'], -4),
					'Name'           => $data['cardName'],
					'UserID'         => Member::currentUserID(),
				));

				$card->write();

				$this->payment->SavedCreditCardID = $card->ID;
				$this->payment->Status = 'Captured'; // set payment status to captured so we know this payment has actually done something and we don't get stuck in a loop
				$this->payment->write();
				$gatewayresponse->setMessage("Card created successfully");
			} elseif ($response->isRedirect()) {
				// redirect to off-site payment gateway
				$this->createMessage('CreateCardRedirectResponse', $response);
				$this->payment->Status = 'Authorized';

				// create the saved card - do this here (even without the actual card reference) to store the name + last four digits
				$card = new SavedCreditCard(array(
					'LastFourDigits' => substr($data['number'], -4),
					'Name'           => $data['cardName'],
					'UserID'         => Member::currentUserID(),
				));

				$card->write();
				$this->payment->SavedCreditCardID = $card->ID;
				$this->payment->write();

				$gatewayresponse->setMessage("Redirecting to gateway");
			}  else {
				//handle error
				$this->createMessage('CreateCardError', $response);
				$gatewayresponse->setMessage(
					"Error (".$response->getCode()."): ".$response->getMessage()
				);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage('CreateCardError', $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		// not sure if this is needed
		$gatewayresponse->setRedirectURL($this->getRedirectURL());

		return $gatewayresponse;
	}


	/**
	 * Finalise this card, after off-site external processing.
	 * This is usually only called by PaymentGatewayController.
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function completeCreateCard($data = array()) {
		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));
		$this->payment->extend('onBeforeCompleteCreateCard', $gatewaydata);

		$gateway = $this->oGateway();
		$request = (method_exists($gateway, 'completeCreateCard')?$gateway->completeCreateCard($gatewaydata):$gateway->createCard($gatewaydata));
		$this->createMessage('CompleteCreateCardRequest', $request);
		$response = null;
		$card = $this->payment->SavedCreditCard();
		try {
			$response = $this->response = $request->send();
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				//successful card creation
				$this->createMessage('CreateCardResponse', $response);

				// update the saved card
				$card->CardReference = $response->getCardReference();
				$card->write();

				$this->payment->SavedCreditCardID = $card->ID;
				$this->payment->Status = 'Captured'; // set payment status to captured so we know this payment has actually done something and we don't get stuck in a loop
				$this->payment->write();
				$gatewayresponse->setMessage("Card created successfully");
			} else {
				$this->createMessage('CompleteCreateCardError', $response);
				$card->delete();
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CompleteCreateCardError", $e);
			$card->delete();
		}

		return $gatewayresponse;
	}

	public function updateCard(SavedCreditCard $card, $data = array()) {
		// TODO
	}

	public function deleteCard(SavedCreditCard $card) {
		// TODO
	}

	/**
	 * @param array $data
	 * @return \Omnipay\Common\CreditCard
	 */
	protected function getCreditCard($data) {
		return new CreditCard($data);
	}


}