<?php

/**
 * Selects all products without a price.
 *
 * @authors: Nicolaas [at] Sunny Side Up .co.nz
 * @package: ecommerce
 * @sub-package: reports
 * @inspiration: Silverstripe Ltd, Jeremy
 **/
class EcommerceSideReport_NoPriceProducts extends SS_Report
{
    /**
     * The class of object being managed by this report.
     * Set by overriding in your subclass.
     */
    protected $dataClass = 'Product';

    /**
     * @return string
     */
    public function title()
    {
        return _t('EcommerceSideReport.NOPRICE', 'E-commerce: Products without Price').
        ' ('.$this->sourceRecords()->count().')';
    }

    /**
     * not sure if this is used in SS3.
     *
     * @return string
     */
    public function group()
    {
        return _t('EcommerceSideReport.ECOMMERCEGROUP', 'Ecommerce');
    }

    /**
     * @return int - for sorting reports
     */
    public function sort()
    {
        return 7000;
    }

    /**
     * working out the items.
     *
     * @return DataList
     */
    public function sourceRecords($params = null)
    {
        return Product::get()
            ->where('"Product"."Price" IS NULL OR "Product"."Price" = 0 ')
            ->sort('FullSiteTreeSort', 'ASC');
    }

    /**
     * @return array
     */
    public function columns()
    {
        return array(
            'Title' => array(
                'title' => 'FullName',
                'link' => true,
            ),
        );
    }

    /**
     * @return FieldList
     */
    public function getParameterFields()
    {
        return new FieldList();
    }
}
