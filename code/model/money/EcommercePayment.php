<?php
/**
 * "Abstract" class for a number of different payment
 * types allowing a user to pay for something on a site.
 *
 *
 * This can't be an abstract class because sapphire doesn't
 * support abstract DataObject classes.
 *
 * @package payment
 */
class EcommercePayment extends DataObject implements EditableEcommerceObject {

	/**
	 * standard SS Variable
	 * @var Array
	 */
	private static $dependencies = array(
		'supportedMethodsProvider' => '%$EcommercePaymentSupportedMethodsProvider'
	);

	/**
	 * automatically populated by the dependency manager
	 * @var EcommercePaymentSupportedMethodsProvider
	 */
	public $supportedMethodsProvider = null;

	/**
	 * Incomplete (default): Payment created but nothing confirmed as successful
	 * Success: Payment successful
	 * Failure: Payment failed during process
	 * Pending: Payment awaiting receipt/bank transfer etc
	 */
	private static $db = array(
		'Status' => "Enum('Incomplete,Success,Failure,Pending','Incomplete')",
		'Amount' => 'Money',
		'Message' => 'Text',
		'IP' => 'Varchar',
		'ProxyIP' => 'Varchar',
		'ExceptionError' => 'Text'
	);

	private static $has_one = array(
		'PaidBy' => 'Member',
		'Order' => 'Order'
	);

	private static $summary_fields = array(
		"Order.Title",
		"Type" => "ClassName",
		"Date" => "Created",
		"AmountValue" => "Amount",
		"Status" => "Status"
	);

	private static $casting = array(
		'AmountValue' => 'Currency',
		'AmountCurrency' => 'Varchar'
	);

	private static $searchable_fields = array(
		'OrderID' => array(
			'field' => 'NumericField',
			'title' => 'Order Number'
		),
		'Created' => array(
			'title' => 'Date (e.g. today)',
			'field' => 'TextField',
			'filter' => 'EcommercePaymentFilters_AroundDateFilter'
		),
		'IP' => array(
			'title' => 'IP Address',
			'filter' => 'PartialMatchFilter'
		),
		'Status'
	);

	/**
	 * standard SS variable
	 * @var String
	 */
	private static $default_sort = '"Created" DESC';

	/**
	 * @param Order $order - the order that is being paid
	 * @param Form $form - the form that is being submitted
	 * @param Array $data - Array of data that is submittted
	 * @return Boolean - true if the data is valid
	 */
	public static function validate_payment(Order $order, Form $form, Array $data) {
		if(!$order){
			$form->sessionMessage(_t('EcommercePayment.NOORDER','Order not found.'), 'bad');
			return false;
		}

		//nothing to pay, always valid
		if($order->TotalOutstanding() == 0) {
			return true;
		}
		$hasValidPaymentClass = false;
		$paymentClass = (!empty($data['PaymentMethod'])) ? $data['PaymentMethod'] : null;
		if($paymentClass) {
			if(class_exists($paymentClass)) {
				$paymentClass = new $paymentClass();
				if($paymentClass instanceof EcommercePayment) {
					$hasValidPaymentClass = true;
				}
			}
		}
		if(!$hasValidPaymentClass) {
			$form->sessionMessage(_t('EcommercePayment.NOPAYMENTOPTION','No Payment option selected.'), 'bad');
			return false;
		}
		// Check payment, get the result back
		return $paymentClass->validatePayment($data, $form);
	}

	/**
	 * Process payment form and return next step in the payment process.
	 * Steps taken are:
	 * 1. create new payment
	 * 2. save form into payment
	 * 3. return payment result
	 *
	 * @param Order $order - the order that is being paid
	 * @param Form $form - the form that is being submitted
	 * @param Array $data - Array of data that is submittted
	 * @return Boolean - if successful, this method will return TRUE
	 */
	public static function process_payment_form_and_return_next_step(Order $order, Form $form, Array $data) {
		$payment = null;
		$paymentClass = (!empty($data['PaymentMethod'])) ? $data['PaymentMethod'] : null;
		if($paymentClass) {
			if(class_exists($paymentClass)) {
				$payment = new $paymentClass();
			}
		}
		if(!$payment) {
			return false;
		}
		// Save payment data from form and process payment
		$form->saveInto($payment);
		$payment->OrderID = $order->ID;
		//important to set the amount and currency.
		$payment->Amount = $order->getTotalOutstandingAsMoney();
		$payment->write();
		// Process payment, get the result back
		$result = $payment->processPayment($data, $form);
		if(!(is_a($result, Object::getCustomClass("Payment_Result")))) {
			$form->controller->redirectBack();
			return false;
		}
		else {
			if($result->isProcessing()) {
				//IMPORTANT!!!
				// isProcessing(): Long payment process redirected to another website (PayPal, Worldpay)
				//redirection is taken care of by payment processor
				return $result->getValue();
			}
			else {
				//payment is done, redirect to either returntolink
				//OR to the link of the order ....
				if(isset($data["returntolink"])) {
					$form->controller->redirect($data["returntolink"]);
				}
				else {
					$form->controller->redirect($order->Link());
				}
			}
			return true;
		}
	}

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->replaceField("OrderID", new ReadonlyField("OrderID", "Order ID"));
		return $fields;
	}

	/**
	 * link to edit the record
	 * @param String | Null $action - e.g. edit
	 * @return String
	 */
	function CMSEditLink($action = null) {
		return Controller::join_links(
			Director::baseURL(),
			"/admin/sales/".$this->ClassName."/EditForm/field/".$this->ClassName."/item/".$this->ID."/",
			$action
		);
	}


	/**
	 * Standard SS method
	 * @param Member $member
	 * @return Boolean
	 */
	function canCreate($member = null) {
		if(Permission::checkMember($member, Config::inst()->get("EcommerceRole", "admin_permission_code"))) {return true;}
		return parent::canCreate($member);
	}


	function canView($member = null){
		if(Permission::checkMember($member, Config::inst()->get("EcommerceRole", "admin_permission_code"))) {return true;}
		return parent::canCreate($member);
	}

	/**
	 * Standard SS method
	 * @param Member $member
	 * @return Boolean
	 */
	function canEdit($member = null) {
		if($this->Status == "Pending" || $this->Status == "Incomplete") {
			if(Permission::checkMember($member, Config::inst()->get("EcommerceRole", "admin_permission_code"))) {return true;}
			return parent::canCreate($member);
		}
		return false;
	}

	/**
	 * Standard SS method
	 * set to false as a security measure...
	 * @param Member $member
	 * @return Boolean
	 */
	function canDelete($member = null) {
		return false;
	}

	/**
	 * redirect to order action
	 */
	function redirectToOrder() {
		$order = $this->Order();
		if($order) {
			Controller::curr()->redirect($order->Link());
		}
		else {
			user_error("No order found with this payment: ".$this->ID, E_USER_NOTICE);
		}
		return;
	}

	/**
	 * @return float
	 **/
	function AmountValue(){return $this->getAmountValue();}
	function getAmountValue() {
		return $this->Amount->getAmount();
	}

	/**
	 * @return String
	 **/
	function AmountCurrency(){return $this->getAmountCurrency();}
	function getAmountCurrency() {
		return $this->Amount->getCurrency();
	}


	/**
	 * standard SS method
	 * try to finalise order if payment has been made.
	 */
	function onAfterWrite() {
		parent::onAfterWrite();
		$order = $this->Order();
		if($order && is_a($order, Object::getCustomClass("Order")) && $order->IsSubmitted()) {
			$order->tryToFinaliseOrder();
		}
	}


	/**
	 *@return String
	 **/
	function Status() {
		return _t('Payment.'.strtoupper($this->Status),$this->Status);
	}

	/**
	 * Return the site currency in use.
	 * @return string
	 */
	public static function site_currency() {
		$currency = EcommerceConfig::get("EcommerceCurrency", "default_currency");
		if(!$currency) {
			user_error("It is highly recommended that you set a default currency using the config files (EcommerceCurrency.default_currency)", E_USER_NOTICE);
		}
		return $currency;
	}

	/**
	 * Set currency to default one.
	 * Set IP address
	 *
	 */
	function populateDefaults() {
		parent::populateDefaults();
		$this->Amount->Currency = EcommerceConfig::get("EcommerceCurrency", "default_currency");
		$this->setClientIP();
 	}

	/**
	 * Set the IP address of the user to this payment record.
	 * This isn't perfect - IP addresses can be hidden fairly easily.
	 */
	protected function setClientIP() {
		$proxy = null;
		$ip = null;

		if(isset($_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		}
		elseif(isset($_SERVER['REMOTE_ADDR'])) {
			$ip = $_SERVER['REMOTE_ADDR'];
		}
		else {
			$ip = null;
		}

		if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			//swapsies
			$proxy = $ip;
			$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		}

		// Only set the IP and ProxyIP if none currently set
		if(!$this->IP) $this->IP = $ip;
		if(!$this->ProxyIP) $this->ProxyIP = $proxy;
	}

	/**
	 * Returns the Payment type currently in use.
	 * @return string | null
	 */
	function PaymentMethod() {
		$supportedMethods = self::get_supported_methods($this->Order());
		if(isset($supportedMethods[$this->ClassName])) {
			return $supportedMethods[$this->ClassName];
		}
	}

	/**
	 * Return a set of payment fields from all enabled
	 * payment methods for this site, given the .
	 * is used to define which methods are available.
	 *
	 * @param String $amount formatted amount (e.g. 12.30) without the currency
	 * @param Null | Order $order
	 *
	 * @return FieldList
	 */
	public static function combined_form_fields($amount, $order = null) {
		// Create the initial form fields, which defines an OptionsetField
		// allowing the user to choose which payment method to use.
		$supportedMethods = self::get_supported_methods($order);
		$fields = new FieldList(
			new OptionsetField(
				'PaymentMethod',
				'',
				$supportedMethods
			)
		);
		foreach($supportedMethods as $methodClass => $methodName) {
			// Create a new CompositeField with method specific fields,
			// as defined on each payment method class using getPaymentFormFields()
			$methodFields = new CompositeField($methodClass::create()->getPaymentFormFields());
			$methodFields->addExtraClass("methodFields_$methodClass");
			$methodFields->addExtraClass('paymentfields');
			// Add those fields to the initial FieldSet we first created
			$fields->push($methodFields);
		}

		// Add the amount and subtotal fields for the payment amount
		$fields->push(new HeaderField('Amount', _t('Payment.AMOUNT_COLON', 'Amount to be charged: ').'<u class="totalAmountToBeCharged">'.$amount.'</u>', 4));
		return $fields;
	}


	/**
	 * Static method to quickly update the payment method on runtime
	 * associative array that goes like ClassName => Description ...
	 *
	 * e.g. MyPaymentClass => Best Payment Method Ever	 * @param array $array -
	 * @param Array $array
	 */
	public static function set_supported_methods($array) {
		Config::inst()->update("EcommercePayment", "supported_methods", null);
		Config::inst()->update("EcommercePayment", "supported_methods", $array);
	}


	/**
	 * returns the list of supported methods
	 * test methods are included if the site is in DEV mode OR
	 * the current user is a ShopAdmin.
	 * @return Array
	 */
	public static function get_supported_methods($order = null){
		$obj = EcommercePayment::create();
		return $obj->supportedMethodsProvider->SupportedMethods($order);
	}

	/**
	 * Return the form requirements for all the payment methods.
	 *
	 * @param NULL | Array
	 * @return An array suitable for passing to CustomRequiredFields
	 */
	public static function combined_form_requirements($order = null) {
		return null;
	}

	/**
	 * Return the payment form fields that should
	 * be shown on the checkout order form for the
	 * payment type. Example: for {@link DPSPayment},
	 * this would be a set of fields to enter your
	 * credit card details.
	 *
	 * @return FieldList
	 */
	function getPaymentFormFields(){user_error("Please implement getPaymentFormFields() on $this->class", E_USER_ERROR);}

	/**
	 * Define what fields defined in {@link Order->getPaymentFormFields()}
	 * should be required.
	 *
	 * @see DPSPayment->getPaymentFormRequirements() for an example on how
	 * this is implemented.
	 *
	 * @return array
	 */
	function getPaymentFormRequirements(){user_error("Please implement getPaymentFormRequirements() on $this->class", E_USER_ERROR);}

	/**
	 * Checks if all the data for payment is correct (e.g. credit card)
	 * By default it returns true, because lots of payments gatewawys
	 * do not have any fields required here.
	 *
	 * @param array $data The form request data - see OrderForm
	 * @param OrderForm $form The form object submitted on
	 */
	function validatePayment($data, $form){return true;}

	/**
	 * Perform payment processing for the type of
	 * payment. For example, if this was a credit card
	 * payment type, you would perform the data send
	 * off to the payment gateway on this function for
	 * your payment subclass.
	 *
	 * This is used by {@link OrderForm} when it is
	 * submitted.
	 *
	 * @param array $data The form request data - see OrderForm
	 * @param OrderForm $form The form object submitted on
	 */
	function processPayment($data, $form){user_error("Please implement processPayment() on $this->class", E_USER_ERROR);}

	protected function handleError($e){
		$this->ExceptionError = $e->getMessage();
		$this->write();
	}

	function PaidObject(){
		return $this->Order();
	}


	/**
	 * checks if a credit card is a real credit card number
	 * @reference: http://en.wikipedia.org/wiki/Luhn_algorithm
	 *
	 * @param String | Int $number
	 * @return Boolean
	 */
	public function validCreditCard($cardNumber) {
		if(!$cardNumber) {
			return false;
		}
		for ($sum = 0, $i = strlen($cardNumber) - 1; $i >= 0; $i--) {
			$digit = (int) $cardNumber[$i];
			$sum += (($i % 2) === 0) ? array_sum(str_split($digit * 2)) : $digit;
		}
		return (($sum % 10) === 0);
	}

	/**
	 * @todo: finish!
	 * valid expiry date
	 * @param String $monthYear - e.g. 0218
	 * @return Boolean
	 */
	public function validExpiryDate($monthYear) {
		$month = intval(substr($monthYear, 0, 2));
		$year = intval("20".substr($monthYear, 2));
		$currentYear = intval(Date("Y"));
		$currentMonth = intval(Date("m"));
		if(($month > 0 || $month < 13) && $year > 0 ) {
			if($year > $currentYear) {
				return true;
			}
			elseif($year == $currentYear) {
				if($currentMonth <= $month) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * @todo: TEST
	 * valid CVC/CVV number?
	 *
	 * @param int $cardNumber
	 * @param int $cvv
	 * @return Boolean
	 */
	public function validCVV($cardNumber, $cvv) {
		$cardNumber = preg_replace('/\D/', '', $cardNumber);
		$cvv = preg_replace('/\D/', '', $cvv);

		//Checks to see whether the submitted value is numeric (After spaces and hyphens have been removed).
		if(is_numeric($cardNumber)) {
			//Checks to see whether the submitted value is numeric (After spaces and hyphens have been removed).
			if(is_numeric($cvv)) {
				//Splits up the card number into various identifying lengths.
				$firstOne = substr($cardNumber, 0, 1);
				$firstTwo = substr($cardNumber, 0, 2);

				//If the card is an American Express
				if($firstTwo == "34" || $firstTwo == "37") {
					if (!preg_match("/^\d{4}$/", $cvv)) {
						// The credit card is an American Express card
						// but does not have a four digit CVV code
						return false;
					}
				}
				else if (!preg_match("/^\d{3}$/", $cvv)) {
					// The credit card is a Visa, MasterCard, or Discover Card card
					// but does not have a three digit CVV code
					return false;
				}
				//passed all checks
				return true;
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}
	}


	/**
	 * Debug helper method.
	 * Access through : /shoppingcart/debug/
	 */
	public function debug() {
		$html =  EcommerceTaskDebugCart::debug_object($this);
		return $html;
	}

}

/**
 * Payment object representing a generic test payment
 *
 * @package ecommerce
 * @subpackage payment
 */
class EcommercePayment_Test extends EcommercePayment {

	function getPaymentFormRequirements() {
		return null;
	}
}

/**
 * Payment object representing a TEST = SUCCESS
 *
 * @package ecommerce
 * @subpackage payment
 */
class EcommercePayment_TestSuccess extends EcommercePayment_Test {

	function processPayment($data, $form) {
		$this->Status = 'Success';
		$this->Message = '<div>PAYMENT TEST: SUCCESS</div>';
		$this->write();
		return new Payment_Success();
	}

	function getPaymentFormFields() {
		return new FieldList(
			new LiteralField("SuccessBlurb", '<div>SUCCESSFUL PAYMENT TEST</div>')
		);
	}

	function getPaymentFormRequirements() {
		return null;
	}
}


/**
 * Payment object representing a TEST = PENDING
 *
 * @package ecommerce
 * @subpackage payment
 */
class EcommercePayment_TestPending extends EcommercePayment_Test {

	function processPayment($data, $form) {
		$this->Status = 'Pending';
		$this->Message = '<div>PAYMENT TEST: PENDING</div>';
		$this->write();
		return new Payment_Processing();
	}

	function getPaymentFormFields() {
		return new FieldList(
			new LiteralField("SuccessBlurb", '<div>PENDING PAYMENT TEST</div>')
		);
	}

}

/**
 * Payment object representing a TEST = FAILURE
 *
 * @package ecommerce
 * @subpackage payment
 */
class EcommercePayment_TestFailure extends EcommercePayment_Test {

	function processPayment($data, $form) {
		$this->Status = 'Failure';
		$this->Message = '<div>PAYMENT TEST: FAILURE</div>';
		$this->write();
		return new Payment_Failure();
	}

	function getPaymentFormFields() {
		return new FieldList(
			new LiteralField("SuccessBlurb", '<div>FAILURE PAYMENT TEST</div>')
		);
	}

}

abstract class Payment_Result {

	protected $value;

	function __construct($value = null) {
		$this->value = $value;
	}

	function getValue() {
		return $this->value;
	}

	abstract function isSuccess();

	abstract function isProcessing();

}


class Payment_Success extends Payment_Result {

	function isSuccess() {
		return true;
	}

	function isProcessing() {
		return false;
	}
}

class Payment_Processing extends Payment_Result {

	function isSuccess() {
		return false;
	}

	function isProcessing() {
		return true;
	}
}

class Payment_Failure extends Payment_Result {

	function isSuccess() {
		return false;
	}

	function isProcessing() {
		return false;
	}
}
