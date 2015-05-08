<?php 
/**
 * Decorates LeftAndMain to add actions for payments - currently just refund.
 * I'm using this method instead of a GridFieldDetailForm_ItemRequest so
 * that payments can be manipulated outside of PaymentAdmin - for example
 * when editing an order.
 *
 * @package omnipay
 */
class PaymentItemRequestExtension extends Extension
{
	private static $allowed_actions = array('doPaymentRefund', 'doPaymentPartialRefund');


	/**
	 * @param Form $form
	 */
	public function updateItemEditForm($form) {
		if ($this->owner->record instanceof Payment) {
			if ($this->owner->record->canRefund()) {
				$form->Actions()->push(
					FormAction::create('doPaymentRefund', 'Full Refund')
						->addExtraClass('ss-ui-button-ajax ui-button-text-icon-primary ss-ui-button')
						->setAttribute('data-icon', 'back')
						->setUseButtonTag(true)
				);

				$form->Actions()->push(
					NumericField::create('_partialRefundAmount', '')->setAttribute('placeholder', 'Amount')
				);

				$form->Actions()->push(
					FormAction::create('doPaymentPartialRefund', 'Partial Refund')
						->addExtraClass('ss-ui-button-ajax ui-button-text-icon-primary ss-ui-button')
						->setAttribute('data-icon', 'back')
						->setUseButtonTag(true)
				);
			}
		}
	}


	/**
	 * @param array $data
	 * @param Form  $form
	 * @return SS_HTTPResponse
	 * @throws SS_HTTPResponse_Exception
	 */
	public function doPaymentRefund($data, $form) {
		$this->doRefund($form, $this->owner->record);

		// direct
		if ($this->owner->gridField->getList()->byId($this->owner->record->ID)) {
			return $this->owner->edit(Controller::curr()->getRequest());
		} else {
			$noActionURL = Controller::curr()->removeAction($data['url']);
			Controller::curr()->getRequest()->addHeader('X-Pjax', 'Content');
			return Controller::curr()->redirect($noActionURL, 302);
		}
	}


	/**
	 * @param array $data
	 * @param Form  $form
	 * @return SS_HTTPResponse
	 * @throws SS_HTTPResponse_Exception
	 */
	public function doPaymentPartialRefund($data, $form) {
		$this->doRefund($form, $this->owner->record, (float)$data['_partialRefundAmount']);

		// direct
		if ($this->owner->gridField->getList()->byId($this->owner->record->ID)) {
			return $this->owner->edit(Controller::curr()->getRequest());
		} else {
			$noActionURL = Controller::curr()->removeAction($data['url']);
			Controller::curr()->getRequest()->addHeader('X-Pjax', 'Content');
			return Controller::curr()->redirect($noActionURL, 302);
		}
	}


	/**
	 * @param Form $form
	 * @param Payment $payment
	 * @param float $amount
	 */
	protected function doRefund($form, $payment, $amount=0.0) {
		if (!$payment->canRefund()) {
			$form->sessionMessage('This payment cannot be refunded.', 'bad');
			return;
		}

		$maxRefund = $payment->getMaxRefundAmount();
		if ($amount <= 0) {
			$amount = $maxRefund;
		}

		if ($amount > $maxRefund) {
			$form->sessionMessage('The maximum amount that can be refunded is '
				. $payment->getCurrency() . number_format($maxRefund), 'bad');
			return;
		}

		$service = new RefundService($payment);
		$response = $service->refund(array(
			'amount' => $amount,
			'receipt' => true,
		));

		if (!$response) {
			$form->sessionMessage('This payment is not eligible for a refund.', 'bad');
		} elseif (!$response->isSuccessful()) {
			$form->sessionMessage($response->getMessage(), 'bad');
		} else {
			$form->sessionMessage('Payment was successfully refunded '
				. $payment->getCurrency() . number_format($amount, 2), 'good');
		}
	}

}
