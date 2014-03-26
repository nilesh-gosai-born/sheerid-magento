<?php
class SheerID_Verify_Helper_Data extends Mage_Core_Helper_Abstract
{

	private static $NOTIFIER_TAG = "magento";

	public function handleVerifyPost($request, $response, $quote) {
		if ($request->isPost()) {
			$post_data = $request->getPost();
			$verify = $post_data['verify'];

			$organizationId = $verify['organizationId'];
			$dob = $this->readDate($verify, 'BIRTH_DATE');
			$ssd = $this->readDate($verify, 'STATUS_START_DATE');

			$ba = $quote->getBillingAddress();
			if ($ba) {
				$firstName = $ba->getFirstname();
				$lastName = $ba->getLastname();
				$postalCode = $ba->getPostcode();
				$email = $ba->getEmail();
			}
			
			$ALLOW_NAME = true;
			if ($ALLOW_NAME && $verify['FIRST_NAME']) {
				$firstName = $verify['FIRST_NAME'];
			}
			if ($ALLOW_NAME && $verify['LAST_NAME']) {
				$lastName = $verify['LAST_NAME'];
			}

			$data = array();
			$data["FIRST_NAME"] = $firstName;
			$data["LAST_NAME"] = $lastName;
			if (strlen($dob) == 10) {
				$data["BIRTH_DATE"] = $dob;
			}
			if (strlen($ssd) == 10) {
				$data["STATUS_START_DATE"] = $ssd;
			}
			$data["ID_NUMBER"] = $verify['ID_NUMBER'];

			if ($verify['POSTAL_CODE']) {
				$data["POSTAL_CODE"] = $verify['POSTAL_CODE'];
			} else {
				$data['POSTAL_CODE'] = $postalCode;
			}
			if ($verify['EMAIL']) {
				$data['EMAIL'] = $verify['EMAIL'];
			} else if ($email) {
				$data['EMAIL'] = $email;
			}
			
			if ($verify['affiliation_types']) {
				//TODO: use config object
				$data["_affiliationTypes"] = $verify['affiliation_types'];
			}

			if (!is_numeric($organizationId)) {
				$data['organizationName'] = $organizationId;
				$organizationId = null;
			}

			$data[':ipv4Address'] = $_SERVER['REMOTE_ADDR'];

			$SheerID = Mage::helper('sheerid_verify/rest')->getService();
			
			$result = array();
			
			if (!$SheerID) {
				$result["error"] = true;
				$result['message'] = "No access token";
				return $result;
			}

			try {
				$data = $this->filterEmptyFields($data);
				$resp = $SheerID->verify($data, $organizationId);
				$result["result"] = $resp->result;
				if (!$resp->result) {
					if ($resp->requestId && $this->allowSendEmail()) {
						// provide a success URL so we know where to send users after asset review success
						$SheerID->updateMetadata($resp->requestId, array('successUrl' => $this->getSuccessUrl($resp->requestId)));
					}
					$errors = $resp->errors;
					if (count($errors) == 1 && $errors[0]->code == 39 && $this->allowUploads()) {
						$result["awaiting_upload"] = true;
					}
				}
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

	public function getSheeridAffiliations($quote=null) {
		if (!$quote) {
			$quote = $this->getCurrentQuote(false);
		}
		$affiliations = array();
		if ($quote) {
			$affiliations = array_merge($affiliations, explode(',', $quote->getSheeridAffiliations()));
			if ($quote->getCustomer() && $quote->getCustomer()->getId()) {
				$affiliations = array_merge($affiliations, explode(',', $quote->getCustomer()->getSheeridAffiliations()));
			}
		}
		return array_filter(array_unique($affiliations));
	}

	public function shouldShowInCheckout() {
		$show_in_checkout = $this->getSetting("show_in_checkout");
		$cookie_name = $this->getSetting("show_in_checkout_cookie_name");
		$quote = Mage::getSingleton('checkout/cart')->getQuote();

		if ('false' == $show_in_checkout) {
			return false;
		} else {
			$affiliation_types = $this->getCheckoutAffiliationTypes();
			return count(array_intersect($affiliation_types, $this->getSheeridAffiliations($quote))) == 0;
		}
	}

	public function getCheckoutAffiliationTypes() {
		$affiliation_types_list = '';
		$show_in_checkout = $this->getSetting("show_in_checkout");
		if ('cookie' == $show_in_checkout) {
			$affiliation_types_list = $_COOKIE[$this->getSetting("show_in_checkout_cookie_name")];
		} else if ('true' == $show_in_checkout) {
			$affiliation_types_list = $this->getSetting("show_in_checkout_affiliation_types");
		}
		return array_filter(explode(',', $affiliation_types_list));
	}

	public function getCurrentQuote($create=true) {
		$quote = Mage::getSingleton('checkout/cart')->getQuote();
		$session = Mage::getSingleton('checkout/session');
		if (!$session->getQuoteId() && $create) {
			$quote->save();
			$session->setQuoteId($quote->getId());
		}
		return $quote;
	}

	public function getFieldLabel($key) {
		$lbl = strtolower(str_replace("_", " ", $key));
		return $this->__(preg_replace_callback("/\b([a-z])/", array($this, 'titleCaseReplace'), $lbl));
	}

	public function getOrganizationType($affiliation_types) {
		return $SheerID = Mage::helper('sheerid_verify/rest')->getService()->getOrganizationType($affiliation_types);
	}

	public function getOrganizationLabel($orgType) {
		if ("UNIVERSITY" == $orgType) {
			return "School";
		} else if ('MILITARY' == $orgType) {
			return "Branch";
		}
		return "Organization";
	}

	private function titleCaseReplace($m) {
		return strtoupper($m[1]);
	}
	
	public function getSetting($key) {
		return Mage::getStoreConfig("sheerid_options/settings/$key");
	}
	
	public function getBooleanSetting($key) {
		$val = $this->getSetting($key);
		return $val === 'true' || $val === 1 || $val === '1' || $val === true;
	}

	public function isSetUp() {
		return !!$this->getSetting("access_token");
	}

	public function allowUploads() {
		return $this->getBooleanSetting('allow_uploads');
	}

	public function allowSendEmail() {
		return $this->allowUploads() && $this->getBooleanSetting('send_email');
	}

	public function getSuccessUrl($requestId) {
		return Mage::getUrl('SheerID/Verify/claim')."?requestId=$requestId";
	}

	public function getEmailNotifier() {
		$SheerID = Mage::helper('sheerid_verify/rest')->getService();
		if ($SheerID) {
			$notifiers = $SheerID->getJson('/notifier', array('tag' => self::$NOTIFIER_TAG));
			if (count($notifiers)) {
				return $notifiers[0];
			}
		}
	}

	public function addEmailNotifier() {
		$SheerID = Mage::helper('sheerid_verify/rest')->getService();
		if ($SheerID) {
			$config = array(
				'type' => 'EMAIL',
				'emailFromAddress' => 'Verify@SheerID.com',
				'emailFromName' => $this->getSetting('email_from_name'),
				'successEmailSubject' => $this->__('Successful Verification'),
				'successEmail' => $this->__('Use the following URL to claim your offer: %successUrl%'),
				'failureEmailSubject' => $this->__('Additional Information Required'),
				'failureEmail' => $this->__('Unable to verify for the following reasons: %errorblock%, please try again.'),
				'tag' => self::$NOTIFIER_TAG
			);
			return json_decode($SheerID->post('/notifier', $config));
		}
	}

	public function removeEmailNotifier() {
		$notifier = $this->getEmailNotifier();
		if ($notifier) {
			$notifierId = $notifier->id;
			$SheerID = Mage::helper('sheerid_verify/rest')->getService();
			if ($SheerID) {
				$SheerID->delete("/notifier/$notifierId");
			}
		}
	}

	public function getFields($affiliation_types=null, $org_id=0) {
                if ($affiliation_types && is_string($affiliation_types)) {
                        $affiliation_types = explode(',', $affiliation_types);
                }
		$SheerID = Mage::helper('sheerid_verify/rest')->getService();
		$fields = $SheerID->getFields($affiliation_types);
		if ($this->allowUploads() && array_search('EMAIL', $fields) === FALSE) {
			$fields[] = 'EMAIL';
		}
		return $fields;
	}

	private function readDate($request, $field) {
		$m = $request["$field.month"];
		$d = $request["$field.day"];
		$y = $request["$field.year"];
		if ($m && $d && $y) {
			return "$y-$m-$d";
		}
	}

	private function filterEmptyFields($params) {
		$data = array();
		foreach ($params as $k => $v) {
			if ($v) {
				$data[$k] = $v;
			}
		}
		return $data;
	}
}
