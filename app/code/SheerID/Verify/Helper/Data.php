<?php
class SheerID_Verify_Helper_Data extends Mage_Core_Helper_Abstract
{
	public function handleVerifyPost($request, $response, $quote) {
		if ($request->isPost()) {
			$post_data = $request->getPost();
			$verify = $post_data['verify'];

			$organizationId = $verify['organizationId'];
			$dob = $verify['birth_year']."-".$verify['birth_month']."-".$verify['birth_day'];

			$ba = $quote->getBillingAddress();
			if ($ba) {
				$firstName = $ba->getFirstname();
				$lastName = $ba->getLastname();
				$postalCode = $ba->getPostcode();
			}
			
			$ALLOW_NAME = true;
			if ($ALLOW_NAME && $verify['firstName']) {
				$firstName = $verify['firstName'];
			}
			if ($ALLOW_NAME && $verify['lastName']) {
				$lastName = $verify['lastName'];
			}

			$data = array();
			$data["FIRST_NAME"] = $firstName;
			$data["LAST_NAME"] = $lastName;
			$data["BIRTH_DATE"] = $dob;

			if ($verify['postalCode']) {
				$data["POSTAL_CODE"] = $verify['postalCode'];
			} else {
				$data['POSTAL_CODE'] = $postalCode;
			}
			
			if ($verify['affiliation_types']) {
				//TODO: use config object
				$data["_affiliationTypes"] = $verify['affiliation_types'];
			}

			$rest_helper = Mage::helper('sheerid_verify/rest');
			$SheerID = $rest_helper->getService();

			$result = array();

			try {
				$resp = $SheerID->verify($data, $organizationId);
				$result["result"] = $resp->result;
			} catch (Exception $e) {
				$result["error"] = true;
				$result['message'] = $e->getMessage();
			}

			$this->saveResponseToQuote($quote, $resp);

			return $result;
        }
	}

	public function saveResponseToQuote($quote, $resp) {
		if ($quote && $resp) {
			$affs = array();
			if ($resp->affiliations) {
				foreach ($resp->affiliations as $aff) {
					$affs[] = $aff->type;
				}
			}

			$quote->setSheeridRequestId($resp->requestId);
			$quote->setSheeridResult($resp->result);
			$quote->setSheeridAffiliations(implode(",", $affs));
			$quote->save();
			
			if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
				$this->saveResponseToCustomer($quote->getCustomer(), $resp);
			}
		}
	}
	
	public function saveResponseToCustomer($cust, $resp) {
		if ($cust && $resp) {
			$affs = array();
			foreach (explode(",", $cust->getSheeridAffiliations()) as $a) {
				if ($a) {
					$affs[] = $a;
				}
			}
			if ($resp->affiliations) {
				foreach ($resp->affiliations as $aff) {
					$affs[] = $aff->type;
				}
			}
			$cust->setSheeridAffiliations(implode(",", array_unique($affs)));
			$cust->save();
		}
	}
	
	public function shouldShowInCheckout() {
		$show_in_checkout = $this->getSetting("show_in_checkout");
		$cookie_name = $this->getSetting("show_in_checkout_cookie_name");
		$quote = Mage::getSingleton('checkout/cart')->getQuote();

		if ("false" == $show_in_checkout || $quote->getSheeridResult() == 1) {
			return false;
		} else if ("true" == $show_in_checkout) {
			return true;
		} else {
			$val = $_COOKIE[$cookie_name];
			return !!$val;
		}
	}

	public function getFieldLabel($key) {
		$lbl = strtolower(str_replace("_", " ", $key));
		return preg_replace_callback("/\b([a-z])/", array($this, 'titleCaseReplace'), $lbl);
	}

	private function titleCaseReplace($m) {
		return strtoupper($m[1]);
	}
	
	public function getSetting($key) {
		return Mage::getStoreConfig("sheerid_options/settings/$key");
	}

	// TODO: fetch this from the service!
	public function getFields($affiliation_types=null, $org_id=0) {
		$fields = array('FIRST_NAME', 'LAST_NAME');
		if ($affiliation_types && is_string($affiliation_types)) {
			$affiliation_types = explode(',', $affiliation_types);
		}
		if (array_search('STUDENT_FULL_TIME', $affiliation_types) !== FALSE || array_search('STUDENT_PART_TIME', $affiliation_types) !== FALSE) {
			$fields[] = 'BIRTH_DATE';
		}
		if (array_search('ACTIVE_DUTY', $affiliation_types) !== FALSE) {
			$fields[] = 'ID_NUMBER';
		}

		return $fields;
	}
}
