<?php

/**
 * @description: each order has a billing address.
 *
 *
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: address
 * @inspiration: Silverstripe Ltd, Jeremy
 **/

class BillingAddress extends OrderAddress {

	/**
	 * what variables are accessible through  http://mysite.com/api/ecommerce/v1/BillingAddress/
	 * @var array
	 */
	private static $api_access = array(
		'view' => array(
			'Prefix',
			'FirstName',
			'Surname',
			'Address',
			'Address2',
			'City',
			'PostalCode',
			'RegionCode',
			'Country',
			'Phone',
			'MobilePhone',
			'Email'
		)
	);

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $db = array(
		'Prefix' => 'Varchar(10)',
		'FirstName' => 'Varchar(100)',
		'Surname' => 'Varchar(100)',
		'Address' => 'Varchar(200)',
		'Address2' => 'Varchar(200)',
		'City' => 'Varchar(100)',
		'PostalCode' => 'Varchar(30)',
		'Country' => 'Varchar(4)',
		'RegionCode' => 'Varchar(20)',
		'Phone' => 'Varchar(50)',
		'MobilePhone' => 'Varchar(50)',
		'Email' => 'Varchar(250)',
		'Obsolete' => 'Boolean',
		'OrderID' => 'Int' //NOTE: we have this here for faster look-ups and to make addresses behave similar to has_many dataobjects
	);

	/**
	 * HAS_ONE =array(ORDER => ORDER);
	 * we place this relationship here
	 * (rather than in the parent class: OrderAddress)
	 * because that makes for a cleaner relationship
	 * (otherwise we ended up with a "has two" relationship in Order)
	 **/
	private static $has_one = array(
		"Region" => "EcommerceRegion"
	);

	/**
	 * standard SS static definition
	 **/
	private static $belongs_to = array(
		"Order" => "Order"
	);

	/**
	 * standard SS static definition
	 */
	private static $default_sort = "\"BillingAddress\".\"ID\" DESC";

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $indexes = array(
		"Obsolete" => true,
		"OrderID" => true
	);

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $casting = array(
		"FullCountryName" => "Varchar"
	);

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $searchable_fields = array(
		"OrderID" => array(
			"field" => "NumericField",
			"title" => "Order Number"
		),
		"Email" => "PartialMatchFilter",
		"FirstName" => "PartialMatchFilter",
		"Surname" => "PartialMatchFilter",
		"Address" => "PartialMatchFilter",
		"City" => "PartialMatchFilter",
		"Country" => "PartialMatchFilter",
		"Obsolete"
	);

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $summary_fields = array(
		"Order.Title",
		"Surname",
		"City"
	);

	/**
	 * standard SS variable
	 * @return Array
	 */
	private static $field_labels = array(
		"Order.Title" => "Order",
		"Obsolete" => "Do not use for future transactions"
	);

	/**
	 * Field to Google Geo Code Conversion
	 * @return Array
	 */
	private static $fields_to_google_geocode_conversion = array(
		"Address" => array('street_number'=> 'long_name', 'route'=> 'long_name'),
		"Address2" => array(
			'location' => 'long_name',
			'sublocality_level_3' => 'long_name',
			'sublocality_level_2' => 'long_name',
			'sublocality_level_1' => 'long_name'
		),
		"City" => array(
			'locality'=> 'long_name',
			'administrative_area_level_3'=> 'long_name',
			'administrative_area_level_2'=> 'long_name',
			'administrative_area_level_1'=> 'long_name'
		),
		"Country" => array('country'=> 'short_name'),
		"PostalCode" => array('postal_code'=> 'long_name')
	);

	/**
	 * standard SS variable
	 * @return String
	 */
	private static $singular_name = "Billing Address";
		function i18n_singular_name() { return _t("OrderAddress.BILLINGADDRESS", "Billing Address");}

	/**
	 * standard SS variable
	 * @return String
	 */
	private static $plural_name = "Billing Addresses";
		function i18n_plural_name() { return _t("OrderAddress.BILLINGADDRESSES", "Billing Addresses");}

	/**
	 * Standard SS variable.
	 * @var String
	 */
	private static $description = "The details of the person buying the order.";

	/**
	 * method for casted variable
	 *@return String
	 **/
	function FullCountryName() {return $this->getFullCountryName();}
	function getFullCountryName() {
		return EcommerceCountry::find_title($this->Country);
	}

	/**
	 *
	 *@return FieldList
	 **/
	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->replaceField("OrderID", new ReadonlyField("OrderID", _t("OrderAddress.ORDERID", "Order #")));
		$fields->replaceField("Email", new EmailField("Email", _t("OrderAddress.EMAIL", "Email")));
		$fields->replaceField("RegionID", $this->getRegionField("RegionID"));
		$fields->replaceField("Country", $this->getCountryField("Country"));
		return $fields;
	}

	/**
	 * @param Member $member
	 * @return FieldList
	 **/
	public function getFields(Member $member = null) {
		$fields = parent::getEcommerceFields();
		$fields->push(new HeaderField('BillingDetails', _t('OrderAddress.BILLINGDETAILS','Billing Details'), 3));
		$billingFields = new CompositeField();
		$hasPreviousAddresses = false;
		if($member) {
			if($member->exists()) {
				$this->FillWithLastAddressFromMember($member, true);
				$addresses = $member->previousOrderAddresses($this->baseClassLinkingToOrder(), $this->ID, $onlyLastRecord = false, $keepDoubles = false);
				//we want MORE than one here not just one.
				if($addresses->count() > 1) {
					$fields->push(SelectOrderAddressField::create('SelectBillingAddressField', _t('OrderAddress.SELECTBILLINGADDRESS','Select Billing Address'), $addresses));
					$hasPreviousAddresses = true;
				}
			}
		}
		if(!$hasPreviousAddresses) {
			$billingFields->push(
				$billingEcommerceGeocodingField = new EcommerceGeocodingField(
					'BillingEcommerceGeocodingField',
					_t('OrderAddress.Find_Address','Find address')
				)
			);
			$billingEcommerceGeocodingField->setFieldMap($this->Config()->get("fields_to_google_geocode_conversion"));
		}
		//$billingFields->push(new TextField('Prefix', _t('OrderAddress.PREFIX','Title (e.g. Ms)')));
		$billingFields->push(new TextField('Address', _t('OrderAddress.ADDRESS','Address')));
		$billingFields->push(new TextField('Address2', _t('OrderAddress.ADDRESS2','&nbsp;')));
		$billingFields->push(new TextField('City', _t('OrderAddress.CITY','Town')));
		$billingFields->push($this->getPostalCodeField("PostalCode"));
		$billingFields->push($this->getRegionField("RegionID"));
		$billingFields->push($this->getCountryField("Country"));
		$billingFields->push(new TextField('Phone', _t('OrderAddress.PHONE','Phone')));
		$billingFields->push(new TextField('MobilePhone', _t('OrderAddress.MOBILEPHONE','Mobile Phone')));
		$billingFields->SetID('BillingFields');
		$billingFields->addExtraClass("orderAddressHolder");
		$this->makeSelectedFieldsReadOnly($billingFields);
		$fields->push($billingFields);
		$this->extend('augmentEcommerceBillingAddressFields', $fields);
		return $fields;
	}

	/**
	 * Return which billing address fields should be required on {@link OrderFormAddress}
	 *
	 * @return array
	 */
	function getRequiredFields() {
		$requiredFieldsArray = array(
			'Email',
			'FirstName',
			'Surname',
			'Address',
			'City',
			'PostalCode',
		);
		$this->extend('augmentEcommerceBillingAddressRequiredFields', $requiredFieldsArray);
		return $requiredFieldsArray;
	}

	/**
	 * standard SS method
	 * sets the country to the best known country {@link EcommerceCountry}
	 **/
	//function populateDefaults() {
		//parent::populateDefaults();
		//$this->Country = EcommerceCountry::get_country();
	//}



}
