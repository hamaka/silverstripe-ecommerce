<?php
/**
 * Customisations to {@link Payment} specifically
 * for the ecommerce module.
 *
 * @package ecommerce
 */
class EcommercePayment extends DataObjectDecorator {

	function extraStatics() {

		return array(
			'has_one' => array(
				'Order' => 'Order'
			),
			'searchable_fields' => array(
				'OrderID' => array('title' => 'Order ID'),
				'Amount' => array('title' => 'Amount'),
				'IP' => array('title' => 'IP Address', 'filter' => 'PartialMatchFilter'),
				'Status'
			)
		);
	}

	function canCreate($member = null) {
		return false;
	}

	function canDelete($member = null) {
		return false;
	}

	function updateSummaryFields(&$fields){
		$fields['Created'] = 'Date';
		$fields['OrderID'] = 'OrderID';
		$fields['Amount'] = 'Amount';
		$fields['IP'] = 'Amount';
		$fields['Total'] = 'Total';
	}

	function onBeforeWrite() {
		if($this->owner->Status == 'Success' && $this->owner->Order()) {
			$order = $this->owner->Order();
			$order->Status = 'Paid';
			$order->write();
			$order->sendReceipt();
		}
	}

	function redirectToOrder() {
		$order = $this->owner->Order();
		Director::redirect($order->Link());
		return;
	}

	function setPaidObject(DataObject $do){
		$this->owner->PaidForID = $do->ID;
		$this->owner->PaidForClass = $do->ClassName;
	}

	function requiredDefaultRecords() {
		$bt = defined('DB::USE_ANSI_SQL') ? "\"" : "`";
		parent::requiredDefaultRecords();
		if(isset($_GET["updatepayment"])) {
			DB::query("
				UPDATE {$bt}Payment{$bt}
				SET {$bt}AmountAmount{$bt} = {$bt}Amount{$bt}
				WHERE
					{$bt}Amount{$bt} > 0
					AND (
						{$bt}AmountAmount{$bt} IS NULL
						OR {$bt}AmountAmount{$bt} = 0
					)
				"
			);
			DB::query("
				UPDATE {$bt}Payment{$bt}
				SET {$bt}AmountCurrency{$bt} = {$bt}Currency{$bt}
				WHERE
					{$bt}Currency{$bt} <> ''
					AND {$bt}Currency{$bt} IS NOT NULL
					AND (
						{$bt}AmountCurrency{$bt} IS NULL
						OR {$bt}AmountCurrency{$bt} = ''
					)
				"
			);
		}
	}


}
