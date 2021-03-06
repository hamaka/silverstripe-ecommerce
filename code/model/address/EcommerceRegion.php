<?php

/**
 * @description: This class helps you to manage regions within the context of e-commerce.
 * The regions can be states (e.g. we only sell within New York and Penn State), suburbs (pizza delivery place),
 * or whatever other geographical borders you are using.
 * Each region has one country, so a region can not span more than one country.
 *
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: address
 * @inspiration: Silverstripe Ltd, Jeremy
 **/
class EcommerceRegion extends DataObject implements EditableEcommerceObject
{
    /**
     * what variables are accessible through  http://mysite.com/api/ecommerce/v1/EcommerceRegion/.
     *
     * @var array
     */
    private static $api_access = array(
        'view' => array(
                'Code',
                'Name',
            ),
     );

    /**
     * standard SS variable.
     *
     * @var array
     */
    private static $db = array(
        'Code' => 'Varchar(20)',
        'Name' => 'Varchar(200)',
        'DoNotAllowSales' => 'Boolean',
        'IsDefault' => 'Boolean',
    );

    /**
     * standard SS variable.
     *
     * @var array
     */
    private static $has_one = array(
        'Country' => 'EcommerceCountry',
    );

    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $indexes = array(
        'Code' => true,
        'DoNotAllowSales' => true,
    );

    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $default_sort = '"Name" ASC';

    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $singular_name = 'Region';
    public function i18n_singular_name()
    {
        return _t('EcommerceRegion.REGION', 'Region');
    }

    /**
     * standard SS variable.
     *
     * @var string
     */
    private static $plural_name = 'Regions';
    public function i18n_plural_name()
    {
        return _t('EcommerceRegion.REGIONS', 'Regions');
    }

    /**
     * standard SS variable.
     *
     * @var array
     */
    private static $searchable_fields = array(
        'Name' => 'PartialMatchFilter',
        'Code' => 'PartialMatchFilter',
    );

    /**
     * standard SS variable.
     *
     * @var array
     */
    private static $field_labels = array(
        'Name' => 'Region',
    );

    /**
     * standard SS variable.
     *
     * @var array
     */
    private static $summary_fields = array(
        'Name' => 'Name',
        'Country.Title' => 'Country',
    );

    /**
     * Standard SS variable.
     *
     * @var string
     */
    private static $description = 'A region within a country.  This can be a state or a province or the equivalent.';

    /**
     * do we use regions at all in this ecommerce application?
     *
     * @return bool
     **/
    public static function show()
    {
        if (Config::inst()->get('EcommerceRegion', 'show_freetext_region_field')) {
            return true;
        }
        return EcommerceRegion::get()->First() ? true : false;
    }

    /**
     * Standard SS FieldList.
     *
     * @return FieldList
     **/
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $title = singleton('EcommerceRegion')->i18n_singular_name();
        $fields->removeByName('CountryID');

        return $fields;
    }

    /**
     * link to edit the record.
     *
     * @param string | Null $action - e.g. edit
     *
     * @return string
     */
    public function CMSEditLink($action = null)
    {
        return Controller::join_links(
            Director::baseURL(),
            '/admin/shop/'.$this->ClassName.'/EditForm/field/'.$this->ClassName.'/item/'.$this->ID.'/',
            $action
        );
    }

    /**
     * checks if a code is allowed.
     *
     * @param string $code - e.g. NZ, NSW, or CO
     *
     * @return bool
     */
    public static function code_allowed($code)
    {
        $region = EcommerceRegion::get()->filter('Code', $code)->First();
        if ($region) {
            return self::regionid_allowed($region->ID);
        }

        return false;
    }

    /**
     * checks if a code is allowed.
     *
     * @param string $code - e.g. NZ, NSW, or CO
     *
     * @return bool
     */
    public static function regionid_allowed($regionID)
    {
        return array_key_exists($regionID, self::list_of_allowed_entries_for_dropdown());
    }

    /**
     * converts a code into a proper title.
     *
     * @param int $regionID (Code)
     *
     * @return string ( name)
     */
    public static function find_title($regionID)
    {
        $options = self::get_default_array();
        // check if code was provided, and is found in the country array
        if ($options && isset($options[$regionID])) {
            return $options[$regionID];
        } else {
            return '';
        }
    }

    /**
     * This function returns back the default list of regions, filtered by the currently selected country.
     *
     * @return array - array of Region.ID => Region.Name
     **/
    protected static function get_default_array()
    {
        $defaultArray = array();
        $regions = EcommerceRegion::get()
            ->Exclude(array('DoNotAllowSales' => 1));
        $defaultRegion = EcommerceCountry::get_country_id();
        if ($defaultRegion) {
            $regions = $regions->Filter(array('CountryID' => EcommerceCountry::get_country_id()));
        }
        if ($regions && $regions->count()) {
            foreach ($regions as $region) {
                $defaultArray[$region->ID] = $region->Name;
            }
        }

        return $defaultArray;
    }

    // DYNAMIC LIMITS.....

    /**
     * takes the defaultArray and limits it with "only show" and "do not show" value, relevant for the current order.
     *
     * @return array (Code, Title)
     **/
    public static function list_of_allowed_entries_for_dropdown()
    {
        $defaultArray = self::get_default_array();
        $onlyShow = self::get_for_current_order_only_show_regions();
        $doNotShow = self::get_for_current_order_do_not_show_regions();
        if (is_array($onlyShow) && count($onlyShow)) {
            foreach ($defaultArray as $id => $value) {
                if (!in_array($id, $onlyShow)) {
                    unset($defaultArray[$id]);
                }
            }
        }
        if (is_array($doNotShow) && count($doNotShow)) {
            foreach ($doNotShow as $id) {
                if (isset($defaultArray[$id])) {
                    unset($defaultArray[$id]);
                }
            }
        }

        return $defaultArray;
    }

    /**
     * these variables and methods allow to to "dynamically limit the regions available, based on, for example: ordermodifiers, item selection, etc....
     * for example, if hot delivery of a catering item is only available in a certain region, then the regions can be limited with the methods below.
     * NOTE: these methods / variables below are IMPORTANT, because they allow the dropdown for the region to be limited for just that order.
     *
     * @var array of regions codes, e.g. ("NSW", "WA", "VIC");
     **/
    protected static $_for_current_order_only_show_regions = array();

    /**
     * @param int $orderID
     *
     * @return array
     */
    public static function get_for_current_order_only_show_regions($orderID = 0)
    {
        $orderID = ShoppingCart::current_order_id($orderID);

        return isset(self::$_for_current_order_only_show_regions[$orderID]) ? self::$_for_current_order_only_show_regions[$orderID] : array();
    }

    /**
     * merges arrays...
     *
     * @param array $a
     * @param int   $orderID
     */
    public static function set_for_current_order_only_show_regions(array $a, $orderID = 0)
    {
        //We MERGE here because several modifiers may limit the countries
        $previousArray = self::get_for_current_order_only_show_regions($orderID);
        self::$_for_current_order_only_show_regions[$orderID] = array_intersect($a, $previousArray);
    }

    /**
     * @var array
     */
    private static $_for_current_order_do_not_show_regions = array();

    /**
     * @param int $orderID
     *
     * @return array
     */
    public static function get_for_current_order_do_not_show_regions($orderID = 0)
    {
        $orderID = ShoppingCart::current_order_id($orderID);

        return isset(self::$_for_current_order_do_not_show_regions[$orderID]) ? self::$_for_current_order_do_not_show_regions[$orderID] : array();
    }

    /**
     * merges arrays...
     *
     * @param array $a
     * @param int   $orderID
     */
    public static function set_for_current_order_do_not_show_regions(array $a, $orderID = 0)
    {
        //We MERGE here because several modifiers may limit the countries
        $previousArray = self::get_for_current_order_do_not_show_regions($orderID);
        self::$_for_current_order_do_not_show_regions[$orderID] = array_merge($a, $previousArray);
    }

    /**
     * This function works out the most likely region for the current order.
     *
     * @return int
     **/
    public static function get_region_id()
    {
        $regionID = 0;
        if ($order = ShoppingCart::current_order()) {
            if ($region = $order->Region()) {
                $regionID = $region->ID;
            }
        }
        //3. check GEOIP information
        if (!$regionID) {
            $regions = EcommerceRegion::get()->filter(array('IsDefault' => 1));
            if ($regions) {
                $regionArray = self::list_of_allowed_entries_for_dropdown();
                foreach ($regions as $region) {
                    if (in_array($region->ID, $regionArray)) {
                        return $region->ID;
                    }
                }
            }
            if (is_array($regionArray) && count($regionArray)) {
                foreach ($regionArray as $regionID => $regionName) {
                    break;
                }
            }
        }

        return $regionID;
    }

    /**
     * returns the country based on the Visitor Country Provider.
     * this is some sort of IP recogniser system (e.g. Geoip Class).
     *
     * @return int
     **/
    public static function get_region_from_ip()
    {
        $visitorCountryProviderClassName = EcommerceConfig::get('EcommerceCountry', 'visitor_region_provider');
        if (!$visitorCountryProviderClassName) {
            $visitorCountryProviderClassName = 'EcommerceRegion_VisitorRegionProvider';
        }
        $visitorCountryProvider = new $visitorCountryProviderClassName();

        return $visitorCountryProvider->getRegion();
    }

    /**
     * @alias for get_region_id
     */
    public static function get_region()
    {
        return self::get_region_id();
    }

    /**
     * standard SS method
     * cleans up codes.
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        $filter = EcommerceCodeFilter::create();
        $filter->checkCode($this);
    }
}

class EcommerceRegion_VisitorRegionProvider extends Object
{
    /**
     * @return int - region ID
     */
    public function getRegion()
    {
        $region = EcommerceRegion::get()->First();
        if ($region) {
            return $region->ID;
        }
    }
}
