<?php
require_once('MCAPI.class.php');
/**
 * SilverChimp Page object 
 * @author Matt Cockayne <matt@zucchi.co.uk>
 * @package SilverChimp 
 */
class SilverChimpSubscriptionPage extends Page {

    /**
     * Definition of additional data fields required for SilverChimp
     * @var array
     */
    static $db = array(
        'ListID'                 => 'Varchar(50)',    // list_unique_id
        'SubscribeSuccess'       => 'HTMLText',
        'DisableGroupSelection'  => 'Boolean',    // prevent frontend selection of groups
        'AllowUpdateExisting'    => 'Boolean',      // Allow a subscriber to update an existing subscription overrides default in SilverChimpSettings
        'DefaultGroupSelections' => 'Text',      // serialised array containing default group selections
        'SubscribeButtonText'    => 'Text', 
    );
    
    static $defaults = array(
        "DisableGroupSelection"   => 0,
        "AllowUpdateExisting"     => 0,
        "DefaultGroupSelections"  => 'a:0:{}',    // use serialize to prevent dependancy for json support 
        "SubscribeButtonText"     => 'Subscribe',
    );

    /**
     * Add SilverShimp Specific fields to administration area
     * @see Page::getCMSFields()
     */
    function getCMSFields() 
    {
        $fields = parent::getCMSFields();
        // get api key
        $api_key = SilverChimpSettings::$api_key;
        if ($api_key && strlen($api_key)) {
            $api = new MCAPI($api_key, SilverChimpSettings::$secure_connection);
            $lists = $api->lists();
            
            $listSource = array();
			if (is_array($lists['data'])){
				foreach ($lists['data'] AS $l) {
					$listSource[$l['id']] = $l['name'];
				}
			}
			
            $settingsTab = 'Root.ChimpSettings';
            $fields->findOrMakeTab($settingsTab);
            
            $message = "<p>";
            $message .= _t('SilverChimp.SETTINGSNOTE', "You must save your changes to display/update the Chimp Fields tab");
            $message .=  "</p>";
            
            $fields->addFieldsToTab($settingsTab,array(
                new DropdownField('ListID', _t('SilverChimp.LISTLABEL', 'Select the list you wish to use'),$listSource),
                new OptionsetField("DisableGroupSelection", _t('SilverChimp.DISABLEGROUPS', "Disable groups from appearing on the frontend"), array(0 => 'Enable', 1 => 'Disable')),
                new OptionsetField("AllowUpdateExisting", _t('SilverChimp.UPDATEEXISTING', "Allow a subscriber to update an existing subscription"), array(0 => 'No', 1 => 'Yes'), SilverChimpSettings::$update_existing),
                new TextField('SubscribeButtonText', _t('SilverChimp.BUTTONTEXT', "What text do you want to appear on the Subscribe button")),
                new LiteralField("ChimpFieldsInto", $message),
                new HtmlEditorField('SubscribeSuccess', _t('SilverChimp.SUBSCRIBESUCCESS', 'Enter something to display when a subscription has been sucessful'))
                
            ));
            
            
            
            
            if ($this->ListID && strlen($this->ListID)) {
                
                
                // set up fields
                $mergeVars = $api->listMergeVars($this->ListID);
                $fieldsTab = 'Root.ChimpFields';
                $fields->findOrMakeTab($fieldsTab);
                
                $message = "<p>";
                $message .= _t('SilverChimp.FIELDSINTRO', "These fields have been generated by Mail Chimp and will be used in your subscription form");
                $message .=  "</p>";
                $fields->addFieldsToTab($fieldsTab, new LiteralField("ChimpFieldsIntro", $message));
                
				if (is_array($mergeVars) && count($mergeVars)){
					foreach ($mergeVars as $var) {
						$fields->addFieldToTab($fieldsTab, new ReadonlyField('SC-' . $var['tag'], $var['tag'], $var['name'] , ' (' . $var['field_type'] . ')'));
					} 
				}
                
                
                // set up groups
                $groupData = $api->listInterestGroupings($this->ListID);
                $groupsTab = 'Root.ChimpGroups';
                $fields->findOrMakeTab($groupsTab);
                
                $message = "<p>";
                $message .= _t('SilverChimp.GROUPSINTRO', "These groups have been set up for the selected list and will be displayed in your subscription form unless disabled. From here you may select the default values to use for this page");
                
                $message .=  "</p>";
                $fields->addFieldsToTab($groupsTab, new LiteralField("ChimpGroupssIntro", $message));
                
                $groupDefaults = unserialize($this->DefaultGroupSelections);
                if (is_array($groupData) && count($groupData)){
					foreach ($groupData AS $gr) {
						$source = array();
						foreach ($gr['groups'] as $opt) {
							$source[$opt['bit']] = $opt['name'];
						}
						$name = 'SCG-' . preg_replace('/[^0-9A-Za-z]/', '-', $gr['name']);
						$values = (isset($groupDefaults[$name])) ? $groupDefaults[$name] : null;

						$displayName = $gr['name'];
						if ($gr['form_field'] == 'hidden') {
							$displayName .= _t('SilverChimp.HIDDENGROUP', ' (Hidden by MailChimp)');
						}

						$fields->addFieldToTab($groupsTab, new CheckboxSetField($name, $displayName, $source, $values));
					}
				}
            }
    
            $this->extend('updateSilverChimpCMSFields');
        }

        return $fields;
    }
    
    /**
     * grab selected defaults from $_REQUEST and populate defaults
     * @see SiteTree::onBeforeWrite()
     */
    protected function onBeforeWrite()
    {
        $api_key = SilverChimpSettings::$api_key;
        if ($api_key && strlen($api_key) && $this->ListID) {
            $api = new MCAPI($api_key, SilverChimpSettings::$secure_connection);

            $defaults = array();
            // get list of grroups
            $groupData = $api->listInterestGroupings($this->ListID);
            // loop
			if (is_array($groupData)){
				foreach ($groupData AS $gr) {
					// evaluate the field name used
					$name = 'SCG-' . preg_replace('/[^0-9A-Za-z]/', '-', $gr['name']);
					// get field value && set into defaults array
					if (isset($_REQUEST[$name])) {
						$defaults[$name] = $_REQUEST[$name]; 
					}
				}
			}
            $this->DefaultGroupSelections = serialize($defaults);
        }
        parent::onBeforeWrite();
    }
}

/**
 * SilverChimp page controller
 * @author Matt Cockayne <matt@zucchi.co.uk>
 * @package SilverChimp
 */
class SilverChimpSubscriptionPage_Controller extends Page_Controller {

    /**
     * The MailChimp API wrapper
     * @var MCAPI
     */
    protected $api = null;
    
    /**
     * initialise the mailchimp api
     * @see ContentController::init()
     */
    public function init()
    {
        parent::init();
        $this->api = new MCAPI(SilverChimpSettings::$api_key,SilverChimpSettings::$secure_connection);
    }
    
    /**
     * Build a form
     * @return mixed
     */
    function Form() {
        if (Session::get('SilverChimp.SUCCESS')) {
            Session::clear('SilverChimp.SUCCESS');
            return false;
        }

        $mergeVars = $this->api->listMergeVars($this->ListID);
        $fields = array();
        $required = array();
        
        // loop through and add merge variables
		if (is_array($mergeVars) && count($mergeVars)){
			foreach ($mergeVars AS $var) {
				if ($new = $this->buildField($var)) {
					if (is_array($new)) {
						$fields = array_merge($fields, $new);
					} else {
						$fields[] = $new;
					}

					if ($var['req']) {
						$required[] = $var['tag'];
					}

				}
			}
		}
        
        // if group selection allowed on frontend loop through and add
        if (false == $this->DisableGroupSelection) {
            $groupData = $this->api->listInterestGroupings($this->ListID);
			if (is_array($groupData) && count($groupData)){
				foreach ($groupData AS $gr) {
					if ($new = $this->buildGroupField($gr)) {
						$fields[] = $new;
					}
				}
			}
        }
        
        $form = new Form($this, 'Form',
            new FieldList($fields),
            new FieldList(new FormAction('SubscribeAction', $this->SubscribeButtonText)),
            new RequiredFields($required)
        );

        $this->extend('updateSilverChimpForm', $form);

        return $form;
    }

    /**
     * Method to override page content with message on success
     * @return SilverChimpSubscriptionPage_Controller
     */
    public function subscribeSuccess() {
        if (Session::get('SilverChimp.SUCCESS'))
            $this->Content = $this->SubscribeSuccess;
        return $this;
    }

    /**
     * Action to process subscriptions
     * @param array $raw_data
     * @param Form $form
     * @return SilverChimpSubscriptionPage_Controller
     */
    function SubscribeAction($raw_data, $form) {
        $data = Convert::raw2sql($raw_data);

        // get list of mergeVars to check for from API
        $mergeVars = $this->api->listMergeVars($this->ListID);
        
        // initialise data container
        $postedVars = array(); 
        
        // loop through merge vars and only poopulate data required
		if (is_array($mergeVars) && count($mergeVars)){
			foreach ($mergeVars AS $var) {
				if (isset($data[$var['tag']])) {
					$postedVars[$var['tag']] = $data[$var['tag']];
				}
			}
		}
        
        // get all groups for list
        $groupData = $this->api->listInterestGroupings($this->ListID);
        
        // get all defaults for list
        $groupDefaults = unserialize($this->DefaultGroupSelections);
        
        // loop through groups
		if (is_array($groupData) && count($groupData)){
			foreach ($groupData AS $gr) {
				// initialise valiable containing the key for defaults test
				$defaultsName = 'SCG-' . preg_replace('/[^0-9A-Za-z]/', '-', $gr['name']);

				// if a GROUPINGS value for the current group exists
				if (isset($data['GROUPINGS'][$gr['name']])) {
					$newGroups = array();
					// check current group is in submitted values
					foreach ($gr['groups'] AS $gd) {
						if (in_array($gd['bit'], $data['GROUPINGS'][$gr['name']])) {
							$newGroups[] = $gd['name'];
						}
					}

					// add groups to data for subscription
					$postedVars['GROUPINGS'][] = array(
						'name' => $gr['name'],
						'groups' => implode(',',$newGroups),
					);


				} else if (isset($groupDefaults[$defaultsName])) { // if defaults present
					$newGroups = array();
					// loop through groups and check in defaults 
					foreach ($gr['groups'] AS $gd) {
						if (in_array($gd['bit'], $groupDefaults[$defaultsName])) {
							$newGroups[] = $gd['name'];
						}
					}

					// add groups to data for subscription
					$postedVars['GROUPINGS'][] = array(
						'name' => $gr['name'],
						'groups' => implode(',',$newGroups),
					);
				}
			}
		}
        $this->extend('updateSilverChimpSignupAction', $data, $postedVars);

        // send subscription data to MailChimp
        $result = $this->api->listSubscribe(
            $this->ListID, 
            $postedVars['EMAIL'], 
            $postedVars, 
            SilverChimpSettings::$email_type,
            SilverChimpSettings::$double_opt_in,
            $this->AllowUpdateExisting,
            SilverChimpSettings::$replace_groups,
            SilverChimpSettings::$send_welcome
        );
        
        if (true === $result) {
            //    success!
            Session::set('SilverChimp.SUCCESS', true);
            return $this->subscribeSuccess();
            
        } else {
            //    failure!
            $form->sessionMessage($this->api->errorMessage, 'warning');
            return $this;
        }
    }

    /**
     * parse merge var array and build appropriate field
     * 
     * @todo: add more support for all mailchip fields as limited functionality currently implemented
     * @param array $var
     * @return mixed
     */
    protected function buildField($var)
    {
        if ($var['public']) {
            switch ($var['field_type']) {
                case 'email':
                    return new EmailField($var['tag'], $var['name']);
                    break;
                case 'dropdown':
                    return new DropdownField($var['tag'],$var['name'], $var['choices']);
                    break;
                case 'radio':
                        return new OptionsetField($var['tag'],$var['name'], $var['choices']);
                        break;
                case 'date':
                case 'birthday':
                        return new DateField($var['tag'],$var['name']);
                    break;
                case 'address':
                    return array(
                        new TextField($var['tag'].'-1', _t('SilverChimp.ADDRESS1', 'Street Address')),
                        new TextField($var['tag'].'-2', _t('SilverChimp.ADDRESS2', 'Address Line 2')),
                        new TextField($var['tag'].'-3', _t('SilverChimp.ADDRESS3', 'City')),
                        new TextField($var['tag'].'-4', _t('SilverChimp.ADDRESS4', 'State/Province/Region')),
                        new TextField($var['tag'].'-5', _t('SilverChimp.ADDRESS5', 'Postal/Zip Code')),
                        new CountryDropdownField($var['tag'].'-6', _t('SilverChimp.COUNTRY', 'Postal/Zip Code')),
                    );    
                    break;
                case 'zip':
                    return new TextField($var['tag'].'-5', _t('SilverChimp.ADDRESS5', 'Postal/Zip Code'));
                    break;
                case 'phone':
                        return new PhoneNumberField($var['tag'], $var['name']);
                        break;
                case 'number':
                            return new NumericField($var['tag'], $var['name']);
                            break;
                case 'website':
                case 'imageurl':
                case 'text':
                    return new TextField($var['tag'], $var['name']);
                    break;
            }
        }
        
        return false;
    }
    
    /**
     * build a grouping field for use on the frontend
     * 
     * @param array $gr
     * @return FormField
     */
    protected function buildGroupField($gr)
    {
        switch ($gr['form_field']) {
            case 'checkboxes':
                $groupDefaults = unserialize($this->DefaultGroupSelections);
                
                $source = array();
                foreach ($gr['groups'] as $opt) {
                    $source[$opt['bit']] = $opt['name'];
                }
                $name = 'SCG-' . preg_replace('/[^0-9A-Za-z]/', '-', $gr['name']);
                $values = (isset($groupDefaults[$name])) ? $groupDefaults[$name] : null;
                
                return  new CheckboxSetField('GROUPINGS[' . $gr['name'] . ']', $gr['name'], $source, $values);
                break;
        }
        
        return false;
    }
}

