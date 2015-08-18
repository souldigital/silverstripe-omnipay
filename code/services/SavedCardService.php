<?php
use Omnipay\Common\CreditCard;

/**
 * Wrapper for create/update/deleteCard methods on omnipay gateway.
 *
 * @package omnipay
 */
class SavedCardService extends PaymentService {

	/**
	 * @param string $object The object type to create (Card|Customer)
	 * @param array $data The data provided
	 *
	 * Abstract method for creating objects
	 *
	 * @return GatewayResponse|null
	 * @throws ValidationException
	 * @throws null
	 */
	private function __create($object, $data){
		if(!in_array($object, array("Card", "Customer"))){
			return null;
		}

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

		$request = $this->oGateway()->{'create'.$object}($gatewaydata);

		$message = $this->createMessage('Create'.$object.'Request', $request);
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
				$this->createMessage('Create'.$object.'Response', $response);

				// create the saved card
				$card = new SavedCreditCard(array(
					'LastFourDigits' => substr($data['number'], -4),
					'Name'           => $data['cardName'],
					'UserID'         => Member::currentUserID(),
				));

				if($object == "Card"){
					$card->CardReference  = $response->getCardReference();
				}else{
					$card->CustomerReference  = $response->getCustomerToken();
				}

				$card->write();

				$this->payment->SavedCreditCardID = $card->ID;
				$this->payment->Status = 'Captured'; // set payment status to captured so we know this payment has actually done something and we don't get stuck in a loop
				$this->payment->write();
				$gatewayresponse->setMessage($object." created successfully");
			} elseif ($response->isRedirect()) {
				// redirect to off-site payment gateway
				$this->createMessage('Create'.$object.'RedirectResponse', $response);
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
				$this->createMessage('Create'.$object.'Error', $response);
				$gatewayresponse->setMessage(
					"Error (".$response->getCode()."): ".$response->getMessage()
				);
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage('Create'.$object.'Error', $e);
			$gatewayresponse->setMessage($e->getMessage());
		}

		// not sure if this is needed
		$gatewayresponse->setRedirectURL($this->getRedirectURL());

		return $gatewayresponse;
	}

	private function __complete($object, $data){
		$gatewayresponse = $this->createGatewayResponse();

		//set the client IP address, if not already set
		if(!isset($data['clientIp'])){
			$data['clientIp'] = Controller::curr()->getRequest()->getIP();
		}

		$gatewaydata = array_merge($data, array(
			'amount' => (float) $this->payment->MoneyAmount,
			'currency' => $this->payment->MoneyCurrency
		));
		$this->payment->extend('onBeforeCompleteCreate'.$object, $gatewaydata);

		$gateway = $this->oGateway();
		$request = (method_exists($gateway, 'completeCreate'.$object)?$gateway->{"completeCreate".$object}($gatewaydata):$gateway->{"create".$object}($gatewaydata));
		$this->createMessage('CompleteCreate'.$object.'Request', $request);
		$response = null;
		$card = $this->payment->SavedCreditCard();
		try {
			$response = $this->response = $request->send();
			$gatewayresponse->setOmnipayResponse($response);
			if ($response->isSuccessful()) {
				//successful card creation
				$this->createMessage('create'.$object.'Response', $response);

				// update the saved card
				if($object == "Card"){
					$card->CardReference  = $response->getCardReference();
				}else{
					$card->CustomerReference  = $response->getCustomerToken();
				}

				$card->write();

				$this->payment->SavedCreditCardID = $card->ID;
				$this->payment->Status = 'Captured'; // set payment status to captured so we know this payment has actually done something and we don't get stuck in a loop
				$this->payment->write();
				$gatewayresponse->setMessage($object." created successfully");
			} else {
				$this->createMessage('CompleteCreate'.$object.'Error', $response);
				$card->delete();
			}
		} catch (Omnipay\Common\Exception\OmnipayException $e) {
			$this->createMessage("CompleteCreate'.$object.'Error", $e);
			$card->delete();
		}

		return $gatewayresponse;
	}

	/**
	 * Attempt to save a new credit card.
	 *
	 * @param  array $data
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function createCard($data = array()) {
		return $this->__create("Card", $data);
	}

	/**
	 * Attempt to save a new customer.
	 *
	 * @param  array $data
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function createCustomer($data = array()) {
		return $this->__create("Customer", $data);
	}


	/**
	 * Finalise this card, after off-site external processing.
	 * This is usually only called by PaymentGatewayController.
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function completeCreateCard($data = array()) {
		return $this->__complete("Card", $data);
	}

	/**
	 * Finalise this card, after off-site external processing.
	 * This is usually only called by PaymentGatewayController.
	 * @return \Omnipay\Common\Message\ResponseInterface omnipay's response class, specific to the chosen gateway.
	 */
	public function completeCreateCustomer($data = array()) {
		return $this->__complete("Customer", $data);
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