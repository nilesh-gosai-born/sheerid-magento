<?php

class SheerID_Verify_Block_Onepage_Verify extends Mage_Checkout_Block_Onepage_Abstract
{
    protected function _construct()
    {    	
        $this->getCheckout()->setStepData('verify', array(
            'label'     => Mage::helper('sheerid_verify')->__("Verification"),
            'is_show'   => true
        ));
        
        parent::_construct();
    }
}