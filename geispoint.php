<?php
/*
* (c) 2016 Geis CZ s.r.o.
*/


if (!defined('_PS_VERSION_'))
	exit;
require_once dirname(__FILE__) . '/webService/GeisPointWebService.php';

class Geispoint extends CarrierModule
{
	protected $config_form = false;

	public function __construct()
	{
		$this->name = 'geispoint';
		$this->tab = 'shipping_logistics';
		$this->version = '1.0.4';
		$this->author = 'Geis CZ s.r.o.';
		$this->need_instance = 0;

		/**
		 * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
		 */
		$this->bootstrap = false;

		parent::__construct();

		$this->displayName = $this->l('Geis Point');
		$this->description = $this->l('Geis Point module for PrestaShop');
	}

	/**
	 * Don't forget to create update methods if needed:
	 * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
	 */
	public function install()
	{
		Configuration::updateValue('GEISPOINT_LIVE_MODE', false);
		$db = Db::getInstance();

		// backup possible old order table
		if(count($db->executeS('show tables like "'._DB_PREFIX_.'geispoint_order"')) > 0) {
			$db->execute('rename table `'._DB_PREFIX_.'geispoint_order` to `'._DB_PREFIX_.'geispoint_order_old`');
			$have_old_table = true;
		}
		else {
			$have_old_table = false;
		}




		// create tables
		if(!defined('_MYSQL_ENGINE_')) define('_MYSQL_ENGINE_', 'MyISAM');
		include(dirname(__FILE__).'/sql-install.php');
		foreach($sql as $s) {
			if(!$db->execute($s)) {
				return false;
			}
		}

		// copy data from old order table
		if($have_old_table) {
			$fields = array();
			foreach($db->executeS('show columns from `'._DB_PREFIX_.'geispoint_order_old`') as $field) {
				$fields[] = $field['Field'];
			}
			$db->execute('insert into `'._DB_PREFIX_.'geispoint_order`(`' . implode('`, `', $fields) . '`) select * from `'._DB_PREFIX_.'geispoint_order_old`');
			$db->execute('drop table `'._DB_PREFIX_.'geispoint_order_old`');
		}




		if(!parent::install()
			|| !$this->registerHook('header')
			|| !$this->registerHook('extraCarrier')
			|| !$this->registerHook('updateCarrier')
			|| !$this->registerHook('newOrder')
			|| !$this->registerHook('adminOrder')
			|| !$this->registerHook('backOfficeHeader')
                        || !$this->registerHook('displayOrderConfirmation')

		) {
			return false;
		}

		// for PrestaShop >= 1.4.0.2 there is one-page-checkout, more hooks are required
		$v = explode('.', _PS_VERSION_);
		if(_PS_VERSION_ > '1.4.0' || (array_slice($v, 0, 3) == array(1, 4, 0) && $v[3] >= 2)) {
			if(!$this->registerHook('processCarrier')
				|| !$this->registerHook('paymentTop')
			) {
				return false;
			}
		}

		$this->registerHook('orderDetailDisplayed');
		$this->registerHook('backOfficeTop');
		$this->registerHook('beforeCarrier');

		// create admin tab under Orders
		$db->execute('
            insert into `' . _DB_PREFIX_ . 'tab` (id_parent, class_name, module, position)
            select id_parent, "AdminOrderGeispoint", "geispoint", coalesce(max(position) + 1, 0)
            from `' . _DB_PREFIX_ . 'tab` pt where id_parent=(select if(id_parent>0, id_parent, id_tab) from `' . _DB_PREFIX_ . 'tab` as tp where tp.class_name="AdminOrders") group by id_parent'
		);
		$tab_id = $db->insert_id();

		$tab_name = array('en' => 'Geispoint', 'cs' => 'Geispoint', 'sk' => 'Geispoint');
		foreach(Language::getLanguages(false) as $language) {
			$db->execute('
                insert into `' . _DB_PREFIX_ . 'tab_lang` (id_tab, id_lang, name)
                values('.$tab_id.', '.$language['id_lang'].', "' . pSQL($tab_name[$language['iso_code']] ? $tab_name[$language['iso_code']] : $tab_name['en']) . '")'
			);
		}

		if(!Tab::initAccess($tab_id)) {
			return false;
		}

		return true;

	}

	public function uninstall()
	{
		Configuration::deleteByName('GEISPOINT_LIVE_MODE');

		// remove admin tab
		$db = Db::getInstance();
		if($tab_id = $db->getValue('select id_tab from `' . _DB_PREFIX_ . 'tab` where class_name="AdminOrderGeispoint"')) {
			$db->execute('delete from `' . _DB_PREFIX_ . 'tab` WHERE id_tab='.$tab_id);
			$db->execute('delete from `' . _DB_PREFIX_ . 'tab_lang` WHERE id_tab='.$tab_id);
			$db->execute('delete from `' . _DB_PREFIX_ . 'access` WHERE id_tab='.$tab_id);
		}

		// mark carriers deleted
		$db->execute('update `' . _DB_PREFIX_.'carrier` set deleted=1 where external_module_name="geispoint" or id_carrier in (select id_carrier from `'._DB_PREFIX_.'geispoint_carrier`)');

		$db->execute('drop table if exists `'._DB_PREFIX_.'geispoint_carrier`');

		if(!parent::uninstall()
			|| !$this->unregisterHook('beforeCarrier')
			|| !$this->unregisterHook('extraCarrier')
			|| !$this->unregisterHook('updateCarrier')
			|| !$this->unregisterHook('newOrder')
			|| !$this->unregisterHook('header')
			|| !$this->unregisterHook('processCarrier')
			|| !$this->unregisterHook('orderDetailDisplayed')
			|| !$this->unregisterHook('adminOrder')
			|| !$this->unregisterHook('paymentTop')
			|| !$this->unregisterHook('backOfficeTop')
                        || !$this->unregisterHook('displayOrderConfirmation')
		) {
			return false;
		}
	}

	/**
	 * Load the configuration form
	 */
	public function getContent()
	{
		/**
		 * If values have been submitted in the form, process.
		 */
		$this->_postProcess();
                $this->c_list_carriers_post();

		$this->context->smarty->assign('module_dir', $this->_path);
		$this->c_add_carrier_post();



		$html = "<br>";
		$html .= $this->c_add_carrier();
                $html .= "<br>";
                $html .= $this->c_list_carriers();
                
		return $html;
	}

         private function c_list_carriers()
        {
            $db = Db::getInstance();
            $html = "";
            $html .= "<fieldset><legend>" . $this->l('Carrier List') . "</legend>";
            if($list = $db->executeS('select c.id_carrier, c.name,  gc.list_type from `'._DB_PREFIX_.'carrier` c join `'._DB_PREFIX_.'geispoint_carrier` gc on(gc.id_carrier=c.id_carrier) where c.deleted=0')) {
                $html .= "<table class='table' cellspacing='0'>";
                $html .= "<tr><th>" . $this->l('Carrier Name') . "</th><th>" . $this->l('Selection Type') . "</th><th>" . $this->l('Action') . "</th></tr>";
                $list_types = $this->list_types();
                foreach($list as $carrier) {
                    $html .= "<tr><td>$carrier[name]</td><td>" . $list_types[$carrier['list_type']] . "</td><td><form method='post'><input type='hidden' name='geispoint_remove_carrier' value='$carrier[id_carrier]'><input type='submit' class='button' value='" . htmlspecialchars($this->l('Remove'), ENT_QUOTES) . "'></form></td></tr>";
                }
                $html .= "</table>";
                $html .= "<p>".$this->l('If you want to set price, use standard PrestaShop functions (see Shipping in top menu).')."</p>";
            }
            else {
                $html .= "<p>" . $this->l('There are no carriers created yet. Please create some.') . "</p>";
            }
            $html .= "</fieldset>";
            return $html;
        }
        
        private function c_list_carriers_post()
        {
            if(Tools::getValue('geispoint_remove_carrier')) {
                $db = Db::getInstance();
                $db->execute('update `' . _DB_PREFIX_.'carrier` set deleted=1 where external_module_name="geispoint" and id_carrier=' . ((int) Tools::getValue('geispoint_remove_carrier')));
            }
        }
        
	private function list_types()
	{
		return array(1 => $this->l('Selection box only'), $this->l('Selection in map'));
	}

	private function c_add_carrier()
	{
		$html = "";
		$html .= "<fieldset><legend>" . $this->l('Add Carrier') . "</legend>";
		$html .= "<form method='post'>";
		$html .= "<input type='hidden' name='geispoint_add_carrier' value='1' />";

		$html .= "<label>" . $this->l('Carrier Name') . ": </label>";
		$html .= "<div class='margin-form'><input type='text' name='geispoint_carrier_name' size='41' value='" . htmlspecialchars($this->l('Osobní odběr - Geispoint'), ENT_QUOTES) . "' /></div>";
		$html .= "<div class='clear'></div>";

		$html .= "<label>" . $this->l('Delay') . ": </label>";
		$html .= '<div class="margin-form">';

		$delay = array('en' => '1-3 days when in stock', 'cs' => "Do 1-3 dní je-li skladem", 'sk' => "Do 1-3 dní ak je skladom");
		foreach(Language::getLanguages(false) as $language) {
			$html .= '<div id="delay_'.$language['id_lang'].'"><input type="text" size="41" maxlength="128" name="delay_'.$language['id_lang'].'" value="'.htmlspecialchars(array_key_exists($language['iso_code'], $delay) ? $delay[$language['iso_code']] : $delay['en'], ENT_QUOTES).'" />'.$language['iso_code'].'</div>';
		}

		$html .= '<p class="clear"></p></div>';
		$html .= "<div class='clear'></div>";


		$html .= "<label>" . $this->l('Selection Type') . ": </label>";
		$html .= "<div class='margin-form'><select name='geispoint_carrier_list_type'>";
		foreach($this->list_types() as $code => $country) {
			$html .= "<option value='$code'>$country</option>\n";
		}
		$html .= "</select></div>";
		$html .= "<div class='clear'></div>";

		$html .= "<label>" . $this->l('Install Logo') . ": </label>";
		$html .= "<div class='margin-form'>";
                $first = true;
		foreach(array("logo" => "<img style='vertical-align: top; ' src='"._MODULE_DIR_."geispoint/logo.png'>","" => $this->l('No')) as $k => $v) {
			$html .= "<input ".($first ? "checked='checked'" :"" )." type='radio' name='geispoint_carrier_logo' value='$k' id='geispoint_carrier_logo_$k'><label for='geispoint_carrier_logo_$k' style='width: auto; height: auto; float: none; display: inline; '>$v</label> &nbsp; &nbsp; ";
                        $first = false;
		}
		$html .= "</div>";
		$html .= "<div class='clear'></div>";


		$html .= "<div class='margin-form'><input class='button' type='submit' value='" . htmlspecialchars($this->l('Add'), ENT_QUOTES) . "' /></div>";
		$html .= "</form>";
		$html .= "</fieldset>";

		return $html;
	}

	public function c_add_carrier_post()
	{
		$db = Db::getInstance();
		if(!Tools::getValue('geispoint_add_carrier')) return;

		$carrier = new Carrier();

		$carrier->name = Tools::getValue('geispoint_carrier_name');
		$carrier->active = true;
		$carrier->shipping_method = defined('Carrier::SHIPPING_METHOD_WEIGHT') ? Carrier::SHIPPING_METHOD_WEIGHT : 1;
		$carrier->deleted = 0;

		$carrier->range_behavior = true; // true disables this carrier if outside weight range
		$carrier->is_module = false;
		$carrier->external_module_name = "geispoint";
		$carrier->need_range = true;

		foreach(Language::getLanguages(true) as $language) {
			if(Tools::getValue('delay_'.$language['id_lang'])) {
				$carrier->delay[$language['id_lang']] = Tools::getValue('delay_'.$language['id_lang']);
			}
		}

		if(!$carrier->add()) {
			return false;
		}
                $query = 'insert into '._DB_PREFIX_.'geispoint_carrier (id_carrier,list_type) values ('. ((int) $carrier->id) . ', ' . ((int) Tools::getValue('geispoint_carrier_list_type')) . ')';
		$db->execute($query);
                echo $query;
                
		foreach(Group::getGroups(true) as $group) {
			$db->autoExecute(_DB_PREFIX_.'carrier_group', array('id_carrier' => (int) $carrier->id, 'id_group' => (int) $group['id_group']), 'INSERT');
		}

		$rangeWeight = new RangeWeight();
		$rangeWeight->id_carrier = $carrier->id;
		$rangeWeight->delimiter1 = '0';
		$rangeWeight->delimiter2 = '5';
		$rangeWeight->add();

		$zones = Zone::getZones(true);
		foreach($zones as $zone) {
			$db->autoExecute(_DB_PREFIX_.'carrier_zone', array('id_carrier' => (int) $carrier->id, 'id_zone' => (int) $zone['id_zone']), 'INSERT');
			$db->autoExecuteWithNullValues(_DB_PREFIX_.'delivery', array('id_carrier' => (int) $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int) $rangeWeight->id, 'id_zone' => (int) $zone['id_zone'], 'price' => '0'), 'INSERT');
		}

		if(Tools::strlen(Tools::getValue('geispoint_carrier_logo')) == 4) {
			copy(dirname(__FILE__).'/img/logo.jpg', _PS_SHIP_IMG_DIR_.'/'.((int) $carrier->id).'.jpg');
		}

		Tools::redirect($_SERVER[REQUEST_URI]);
	}

	
	public function getOrderShippingCost($params, $shipping_cost)
	{
	 
	  return false; // carrier is not known
	}
	
	public function getOrderShippingCostExternal($params)
	{
	   

		return false;
	}
	/**
	 * Create the form that will be displayed in the configuration of your module.
	 */
	protected function renderForm()
	{
		$helper = new HelperForm();

		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$helper->module = $this;
		$helper->default_form_language = $this->context->language->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

		$helper->identifier = $this->identifier;
		$helper->submit_action = 'submitGeispointModule';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
			.'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');

		$helper->tpl_vars = array(
			'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
			'languages' => $this->context->controller->getLanguages(),
			'id_language' => $this->context->language->id,
		);

		return $helper->generateForm(array($this->getConfigForm()));
	}

	/**
	 * Create the structure of your form.
	 */
	protected function getConfigForm()
	{
		return array(
			'form' => array(
				'legend' => array(
				'title' => $this->l('Settings'),
				'icon' => 'icon-cogs',
				),
				'input' => array(
					array(
						'type' => 'switch',
						'label' => $this->l('Live mode'),
						'name' => 'GEISPOINT_LIVE_MODE',
						'is_bool' => true,
						'desc' => $this->l('Use this module in live mode'),
						'values' => array(
							array(
								'id' => 'active_on',
								'value' => true,
								'label' => $this->l('Enabled')
							),
							array(
								'id' => 'active_off',
								'value' => false,
								'label' => $this->l('Disabled')
							)
						),
					),
					array(
						'col' => 3,
						'type' => 'text',
						'prefix' => '<i class="icon icon-envelope"></i>',
						'desc' => $this->l('Enter a valid email address'),
						'name' => 'GEISPOINT_ACCOUNT_EMAIL',
						'label' => $this->l('Email'),
					),
					array(
						'type' => 'password',
						'name' => 'GEISPOINT_ACCOUNT_PASSWORD',
						'label' => $this->l('Password'),
					),
				),
				'submit' => array(
					'title' => $this->l('Save'),
				),
			),
		);
	}

	/**
	 * Set values for the inputs.
	 */
	protected function getConfigFormValues()
	{
		return array(
			'GEISPOINT_LIVE_MODE' => Configuration::get('GEISPOINT_LIVE_MODE', true),
			'GEISPOINT_ACCOUNT_EMAIL' => Configuration::get('GEISPOINT_ACCOUNT_EMAIL', 'contact@prestashop.com'),
			'GEISPOINT_ACCOUNT_PASSWORD' => Configuration::get('GEISPOINT_ACCOUNT_PASSWORD', null),
		);
	}

	/**
	 * Save form data.
	 */
	protected function _postProcess()
	{
		$form_values = $this->getConfigFormValues();

		foreach (array_keys($form_values) as $key)
			Configuration::updateValue($key, Tools::getValue($key));
	}

	/**
	* Add the CSS & JavaScript files you want to be loaded in the BO.
	*/
	public function hookBackOfficeHeader()
	{
            //$this->context->controller->addJquery();            
            $this->context->controller->addJqueryUI('ui.dialog');            
            $this->context->controller->addJS($this->_path.'js/semantic.min.js');
            $this->context->controller->addCSS($this->_path.'css/semantic.min.css');
            $this->context->controller->addJS($this->_path.'js/back.js');
            $this->context->controller->addCSS($this->_path.'css/back.css');

            
   $script = "";
            $script .= "function showPointDetail(geisPoint,elementId) {"."\n";
            $script .= "    var html = '';"."\n";
            $script .= "    html +='    <div class=\"inner\">'"."\n";
            $script .= "    html +='        <div>'"."\n";
            $script .= "    html +='            <div>'"."\n";
            $script .= "    html +='                <div style=\"float:left;\">'"."\n";
			$script .= "    html +='                    <img alt=\"FOTO\" src=\"'+geisPoint.photoUrl+'\" height=\"166px\">'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"float:right\">'"."\n";
            $script .= "    html +='                    <a target=\"_blank\" href=\"http://maps.google.com/maps?&z=16&q='+geisPoint.street+','+geisPoint.city+'\">'"."\n";
			$script .= "    html +='                        <img alt=\"MAPA\" src=\"http://maps.googleapis.com/maps/api/staticmap?center='+geisPoint.gpsn+','+geisPoint.gpse+'&zoom=16&size=250x166&markers='+geisPoint.gpsn+','+geisPoint.gpse+'\">'"."\n";
            $script .= "    html +='                    </a>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='            <div style=\"border: 1px solid rgb(214, 212, 212); float: left; width: 100%; margin-top: 5px; padding: 10px;\">'"."\n";
            $script .= "    html +='            <div style=\"\">'"."\n";
            $script .= "    html +='                    <span class=\"\" style=\"font-weight: bold;background: none repeat scroll 0px 0px rgb(247, 196, 27); color: white; border-radius: 0.2857rem; padding: 5px 10px;\">'+geisPoint.idGP+'</span>'"."\n";
            $script .= "    html +='			<span style=\"font-weight: bold; font-size: 20px; position: relative; top: 2px; left: 5px;\">'+geisPoint.name+'</span>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"margin-top: 10px;\">'"."\n";
            $script .= "    html +='                    <table>'"."\n";
            $script .= "    html +='                        <tbody>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Address")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.street+',&nbsp;'"."\n";
            $script .= "    html +='                                '+geisPoint.postcode+'&nbsp;&nbsp;'+geisPoint.city+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Phone")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.phone+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("E-mail")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.email+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Opening hours")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.openiningHours+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                        </tbody>'"."\n";
            $script .= "    html +='                    </table>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='        </div>'"."\n";
            $script .= "    html +='    </div>'"."\n";
            $script .= "   $(\"#\"+elementId+\"_detail\").html(html);"."\n";
            $script .= "}"."\n";
            
            
            $script .= "function showGeispointDialogDetail(geisPoint) {"."\n";
            $script .= "    var html = '';"."\n";
            $script .= "    html +='    <div class=\"inner\">'"."\n";
            $script .= "    html +='        <div>'"."\n";
            $script .= "    html +='            <div>'"."\n";
            $script .= "    html +='                <div style=\"float:left;\">'"."\n";
            $script .= "    html +='                    <img alt=\"FOTO\" src=\"'+geisPoint.photoUrl+'\" height=\"166px\">'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"float:right\">'"."\n";
            $script .= "    html +='                    <a href=\"http://maps.google.com/maps?&z=16&q='+geisPoint.street+','+geisPoint.city+'\">'"."\n";
            $script .= "    html +='                        <img alt=\"MAPA\" src=\"http://maps.googleapis.com/maps/api/staticmap?center='+geisPoint.gpsn+','+geisPoint.gpse+'&zoom=16&size=250x166&markers='+geisPoint.gpsn+','+geisPoint.gpse+'\">'"."\n";
            $script .= "    html +='                    </a>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='            <div style=\"border: 1px solid rgb(214, 212, 212); float: left; width: 100%; margin-top: 5px; padding: 10px;\">'"."\n";
            $script .= "    html +='            <div style=\"\">'"."\n";
            $script .= "    html +='                    <span class=\"\" style=\"font-weight: bold;background: none repeat scroll 0px 0px rgb(247, 196, 27); color: white; border-radius: 0.2857rem; padding: 5px 10px;\">'+geisPoint.idGP+'</span>'"."\n";
            $script .= "    html +='			<span style=\"font-weight: bold; font-size: 20px; position: relative; top: 2px; left: 5px;\">'+geisPoint.name+'</span>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"margin-top: 10px;\">'"."\n";
            $script .= "    html +='                    <table>'"."\n";
            $script .= "    html +='                        <tbody>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Address")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.street+',&nbsp;'"."\n";
            $script .= "    html +='                                '+geisPoint.postcode+'&nbsp;&nbsp;'+geisPoint.city+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Phone")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.phone+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("E-mail")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.email+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Opening hours")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.openiningHours+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                        </tbody>'"."\n";
            $script .= "    html +='                    </table>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='        </div>'"."\n";
            $script .= "    html +='    </div>'"."\n";
            
            $script .= "    $('<div style=\"display:none;\"></div>').html(html).dialog({"."\n";
            $script .= "           autoOpen: false,"."\n";
            $script .= "           modal: true,"."\n";
            $script .= "           height: 500,"."\n";
            $script .= "           width: 650,"."\n";
            $script .= "           title: '".$this->l("Detail")."'"."\n";
            $script .= "       }).dialog('open').parent('.ui-dialog').css('zIndex',9999);"."\n";
            
            $script .= "}"."\n";            
            return '<script type="text/javascript">'.$script.'</script>';
	}

	/**
	 * Add the CSS & JavaScript files you want to be added on the FO.
	 */
	public function hookHeader()
	{
            //$this->context->controller->addJquery();            
            $this->context->controller->addJqueryUI('ui.dialog');            
            $this->context->controller->addJS($this->_path.'js/semantic.min.js');
            $this->context->controller->addCSS($this->_path.'css/semantic.min.css');
            $this->context->controller->addJS($this->_path.'js/dropdown.min.js');
            $this->context->controller->addCSS($this->_path.'css/dropdown.min.css');

            $this->context->controller->addJS($this->_path.'/js/front.js');
            $this->context->controller->addJS($this->_path.'/js/geispoint.js');
            $this->context->controller->addCSS($this->_path.'/css/front.css');
            
            
            $script = "";
            $script .= "function showPointDetail(geisPoint,elementId) {"."\n";
            $script .= "    var html = '';"."\n";
            $script .= "    html +='    <div class=\"inner\">'"."\n";
            $script .= "    html +='        <div>'"."\n";
            $script .= "    html +='            <div>'"."\n";
            $script .= "    html +='                <div style=\"float:left;\">'"."\n";
            $script .= "    html +='                    <img alt=\"FOTO\" src=\"'+geisPoint.photoUrl+'\" height=\"166px\">'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"float:right\">'"."\n";
            $script .= "    html +='                    <a target=\"_blank\" href=\"http://maps.google.com/maps?&z=16&q='+geisPoint.street+','+geisPoint.city+'\">'"."\n";
          $script .= "    html +='                        <img alt=\"MAPA\" src=\"http://maps.googleapis.com/maps/api/staticmap?center='+geisPoint.gpsn+','+geisPoint.gpse+'&zoom=16&size=250x166&markers='+geisPoint.gpsn+','+geisPoint.gpse+'\">'"."\n";
            $script .= "    html +='                    </a>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='            <div style=\"border: 1px solid rgb(214, 212, 212); float: left; width: 100%; margin-top: 5px; padding: 10px;\">'"."\n";
            $script .= "    html +='            <div style=\"\">'"."\n";
            $script .= "    html +='                    <span class=\"\" style=\"font-weight: bold;background: none repeat scroll 0px 0px rgb(247, 196, 27); color: white; border-radius: 0.2857rem; padding: 5px 10px;\">'+geisPoint.idGP+'</span>'"."\n";
            $script .= "    html +='			<span style=\"font-weight: bold; font-size: 20px; position: relative; top: 2px; left: 5px;\">'+geisPoint.name+'</span>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"margin-top: 10px;\">'"."\n";
            $script .= "    html +='                    <table>'"."\n";
            $script .= "    html +='                        <tbody>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Address")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.street+',&nbsp;'"."\n";
            $script .= "    html +='                                '+geisPoint.postcode+'&nbsp;&nbsp;'+geisPoint.city+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Phone")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.phone+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("E-mail")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.email+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Opening hours")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.openiningHours+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                        </tbody>'"."\n";
            $script .= "    html +='                    </table>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='        </div>'"."\n";
            $script .= "    html +='    </div>'"."\n";
            $script .= "   $(\"#\"+elementId+\"_detail\").html(html);"."\n";
            $script .= "}"."\n";
            
            
            $script .= "function showGeispointDialogDetail(geisPoint) {"."\n";
            $script .= "    var html = '';"."\n";
              $script .= "    html +='    <div class=\"inner\">'"."\n";
            $script .= "    html +='        <div>'"."\n";
            $script .= "    html +='            <div>'"."\n";
            $script .= "    html +='                <div style=\"float:left;\">'"."\n";
            $script .= "    html +='                    <img alt=\"FOTO\" src=\"'+geisPoint.photoUrl+'\" height=\"166px\">'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"float:right\">'"."\n";
            $script .= "    html +='                    <a href=\"http://maps.google.com/maps?&z=16&q='+geisPoint.street+','+geisPoint.city+'\">'"."\n";
			$script .= "    html +='                        <img alt=\"MAPA\" src=\"http://maps.googleapis.com/maps/api/staticmap?center='+geisPoint.gpsn+','+geisPoint.gpse+'&zoom=16&size=250x166&markers='+geisPoint.gpsn+','+geisPoint.gpse+'\">'"."\n";
            $script .= "    html +='                    </a>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='            <div style=\"border: 1px solid rgb(214, 212, 212); float: left; width: 100%; margin-top: 5px; padding: 10px;\">'"."\n";
            $script .= "    html +='            <div style=\"\">'"."\n";
            $script .= "    html +='                    <span class=\"\" style=\"font-weight: bold;background: none repeat scroll 0px 0px rgb(247, 196, 27); color: white; border-radius: 0.2857rem; padding: 5px 10px;\">'+geisPoint.idGP+'</span>'"."\n";
            $script .= "    html +='			<span style=\"font-weight: bold; font-size: 20px; position: relative; top: 2px; left: 5px;\">'+geisPoint.name+'</span>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='                <div style=\"margin-top: 10px;\">'"."\n";
            $script .= "    html +='                    <table>'"."\n";
            $script .= "    html +='                        <tbody>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Address")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.street+',&nbsp;'"."\n";
            $script .= "    html +='                                '+geisPoint.postcode+'&nbsp;&nbsp;'+geisPoint.city+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Phone")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.phone+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("E-mail")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.email+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                            <tr>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);background:#fbfbfb;\">'"."\n";
            $script .= "    html +='                                ".$this->l("Opening hours")."'"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                                <td style=\"border: 1px solid rgb(214, 212, 212);\">'"."\n";
            $script .= "    html +='                                '+geisPoint.openiningHours+''"."\n";
            $script .= "    html +='                                </td>'"."\n";
            $script .= "    html +='                            </tr>'"."\n";
            $script .= "    html +='                        </tbody>'"."\n";
            $script .= "    html +='                    </table>'"."\n";
            $script .= "    html +='                </div>'"."\n";
            $script .= "    html +='            </div>'"."\n";
            $script .= "    html +='        </div>'"."\n";
            $script .= "    html +='    </div>'"."\n";
            
            $script .= "    $('<div style=\"display:none;\"></div>').html(html).dialog({"."\n";
            $script .= "           autoOpen: false,"."\n";
            $script .= "           modal: true,"."\n";
            $script .= "           height: 500,"."\n";
            $script .= "           width: 650,"."\n";
            $script .= "           title: '".$this->l("Detail")."'"."\n";
            $script .= "       }).dialog('open').parent('.ui-dialog').css('zIndex',9999);"."\n";
            
            $script .= "}"."\n";
            
            
            
            return '<script type="text/javascript">'.$script.'</script>';
	}

        public function hookNewOrder($params)
        {
            $db = DB::getInstance();
            $db->execute('update `'._DB_PREFIX_.'geispoint_order` set id_order=' . ((int) $params['order']->id) . ' where id_cart=' . ((int) $params['cart']->id));
        }
        
        public function hookAdminOrder($params)
        {
            if(!($res = Db::getInstance()->getRow('SELECT o.idGP FROM `'._DB_PREFIX_.'geispoint_order` o WHERE o.id_order = '. ((int) $params['id_order'])))) {
                return "";
            }
            $geisPointWebService = new GeisPointWebService();
            $geispoint = $geisPointWebService->GetDetail($res['idGP']);

            return "<p>".sprintf($this->l('Selected Geispoint: %s'), "<strong>".$res['idGP']."</strong>")."<span style='cursor:pointer;' onclick='showGeispointDialogDetail(".Tools::jsonEncode($geispoint).");'>&nbsp;(<b>".$this->l('Detail')."</b>)</span>".  "</p>";
        }

        public function hookOrderDetailDisplayed($params)
        {
            
            if(!($res = Db::getInstance()->getRow('SELECT o.idGP FROM `'._DB_PREFIX_.'geispoint_order` o WHERE o.id_order = '. ((int) $params['order']->id)))) {
                return;
            }
            
            $geisPointWebService = new GeisPointWebService();
            $geispoint = $geisPointWebService->GetDetail($res['idGP']);
            
            return "<p>".sprintf($this->l('Selected Geispoint: %s'), "<strong>".$res['idGP']."</strong>")."<span style='cursor:pointer;' onclick='showGeispointDialogDetail(".Tools::jsonEncode($geispoint).");'>&nbsp;(<b>".$this->l('Detail')."</b>)</span>".  "</p>";
        }
        
        public function hookUpdateCarrier($params)
        {
            if($params['id_carrier'] != $params['carrier']->id) {
                Db::getInstance()->execute($sq='
                    update `'._DB_PREFIX_.'geispoint_carrier`
                    set id_carrier=' . ((int) $params['carrier']->id) . '
                    where id_carrier=' . ((int) $params['id_carrier'])
                );
            }
        }
        
        public function hookDisplayOrderConfirmation($params) {
            if(!($res = Db::getInstance()->getRow('SELECT o.idGP FROM `'._DB_PREFIX_.'geispoint_order` o WHERE o.id_order = '. ((int) $params['objOrder']->id)))) {
                return;
            }
          
            $geisPointWebService = new GeisPointWebService();
            $geispoint = $geisPointWebService->GetDetail($res['idGP']);
            
            return "<p>".sprintf($this->l('Selected Geispoint: %s'), "<strong>".$res['idGP']."</strong>")."<span style='cursor:pointer;' onclick='showGeispointDialogDetail(".Tools::jsonEncode($geispoint).");'>&nbsp;(<b>".$this->l('Detail')."</b>)</span>".  "</p>";
        }
        
	public function hookExtraCarrier($params)
	{
		$is_opc = Configuration::get('PS_ORDER_PROCESS_TYPE');
		
            $db = Db::getInstance();

            
            $carriersList = $db->executeS('select c.id_carrier, gc.list_type from `'._DB_PREFIX_.'carrier` c join `'._DB_PREFIX_.'geispoint_carrier` gc on(gc.id_carrier=c.id_carrier) where c.deleted=0');
            
            $grouped = array();
            foreach ($carriersList as $carrier) {
              $type = (string)$carrier['list_type'];
              if (isset($grouped[$type])) {
                 $grouped[$type][] = $carrier;
              } else {
                 $grouped[$type] = array($carrier);
              }
            }
            
			$script = "";
			
			if ($is_opc && strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') {
				//nothing
			} else {
				
				$geisPointWebService = new GeisPointWebService();
				$geispoints = $geisPointWebService->GetAllGeispoints();
				
				$script .= "<div id='someEmptyDiv'></div>"."\n";
				$script .= "<script type='text/javascript'>"."\n";
				$script .= "var data = ";
				$script .= Tools::jsonEncode($geispoints);
				$script .= ";"."\n";
				$script .= "</script>"."\n";
            }
			
            $address = $params['address'];
            
            $allValuesArray = "[";
            $mapValuesArray = "[";
            foreach($grouped as $type => $carriers) {
                foreach($carriers as $carrier) {
                    $allValuesArray .=$carrier['id_carrier'];
                    $allValuesArray .=",";
                    if ($type == 2) {
                        $mapValuesArray .=$carrier['id_carrier'];
                    }
                    $mapValuesArray .=",";
                }
            }
            if (Tools::substr($allValuesArray, -1) == ",") {
                $allValuesArray = rtrim($allValuesArray,",");
            }
            if (Tools::substr($mapValuesArray, -1) == ",") {
                    $mapValuesArray = rtrim($mapValuesArray,",");
            }
            
            $mapValuesArray .="]";
            $allValuesArray .="]";
            
            $script .= "<script type='text/javascript'>"."\n";
            $script .= "var inSubmit = false;"."\n";
            $script .= "$('#form').unbind('submit.geispointBeforeSubmit').bind('submit.geispointBeforeSubmit',function(e) {"."\n";
            $script .= "    if (!inSubmit){ "."\n";
            $script .= "    var selectedCarrierId = parseInt($('input.delivery_option_radio:checked').val().replace(',',''));"."\n";
            $script .= "if(".$allValuesArray.".indexOf(selectedCarrierId) < 0) {"."\n";
            $script .= " return; "."\n";
            $script .= "}"."\n";
            $script .= "    e.preventDefault();"."\n";
            $script .= "    if (".$allValuesArray.".indexOf(selectedCarrierId) >= 0 && (selectedGeispointId == 0 || selectedGeispointId=='' || selectedGeispointId == undefined)){ "."\n";
            $script .= "if (!!$.prototype.fancybox) {"."\n";
            $script .= "    $.fancybox.open(["."\n";
            $script .= "    {"."\n";
            $script .= "        type: 'inline',"."\n";
            $script .= "        autoScale: true,"."\n";
            $script .= "        minHeight: 30,"."\n";
            $script .= "        content: '<p class=\"fancybox-error\">".$this->l('Choose Geispoint')."</p>'"."\n";
            $script .= "    }],"."\n";
            $script .= "        {"."\n";
            $script .= "        padding: 0"."\n";
            $script .= "    });"."\n";
            $script .= "} else {"."\n";
            $script .= "    alert('".$this->l('Choose Geispoint')."');"."\n";
            $script .= "}"."\n";
            $script .= "    return;"."\n";
            $script .= "    }"."\n";
            $script .= "    var actualForm = this;"."\n";
            $script .= "    inSubmit = true;"."\n";
            $script .= "    $.ajax({"."\n";
            $script .= "        url: '"._MODULE_DIR_."/geispoint/ajax_saveGeispoint.php',"."\n";
            $script .= "        data: {selectedGeispointId:selectedGeispointId },"."\n";
            $script .= "        type: 'POST',"."\n";
            $script .= "        success: function (data) {"."\n";
            $script .= "            $(actualForm).submit();"."\n";
            $script .= "            inSubmit = false;"."\n";
            $script .= "        },"."\n";
            $script .= "        cache: false,"."\n";
            $script .= "        error: function (jqXHR, textStatus, errorThrown) {"."\n";
            $script .= "            inSubmit = false;"."\n";
            $script .= "            alert(textStatus)"."\n";
            $script .= "        }"."\n";
            $script .= "    });"."\n";
            $script .= "    }"."\n";
            //$script .= "    return false;"."\n";
            $script .= "});"."\n";
            
            $script .= "</script>"."\n";
            
            
            foreach($grouped as $type => $carriers) {
                //$carriers = $grouped[$type];
                $values = "";
                
                
                foreach($carriers as $carrier) {
                    $values .= "[value='".$carrier['id_carrier'].",']";
                  
                    
                    if ($carrier != end($carriers)) {
                        $values .=",";
                    }
                    
                    
                }

                
                
                

                
                
                
                switch ($type){
                    //Selection box only
                    case 1:
                        $script .= "<script type='text/javascript'>"."\n";
                        $script .= " $(\".delivery_option input.delivery_option_radio[type='radio']\").filter(\"".$values."\").parents('.delivery_option').each(function(i, val) {"."\n";
                        $script .= "if ($('#geispointSelect_'+i+'_parent').length == 0) {"."\n";
                        $script .= "var carrierId = $(val).find(\"input.delivery_option_radio[type='radio']\").val()"."\n";
                        $script .= "var carrierIdInt = parseInt(carrierId.replace(',',''));"."\n";
                        
                        $script .= "$(val).find(\"input.delivery_option_radio\").unbind('change.geispointDeliveryOption_'+i).bind('change.geispointDeliveryOption_'+i,function(e) {"."\n";
                        
                        
                        $script .= "$('div.geispointParentElement').css('display','none')"."\n";
                        $script .= "$('div#geispointSelect_'+i+'_parent').css('display','block')"."\n";
                        $script .= "selectedGeispointId = $(\"div[carrierId='\"+carrierIdInt+\"']>input\").val();"."\n";
                        $script .= "});"."\n";
                        
                        $script .= "var selectElementHtml ='<div carrierId=\"'+carrierIdInt+'\" id=\"geispointSelect_'+i+'_parent\" class=\"ui fluid search selection dropdown geispointParentElement geispointSelectboxParent\" style=\"display:none;\"><input name=\"geispointSelect_Input\" type=\"hidden\"><i class=\"dropdown icon\"></i><div class=\"default text\">Vybrat Geis Point</div><div class=\"menu\">';"."\n";                            
                        $script .= "for(key in data) {"."\n";
                        $script .= "var geisPoint = data[key];"."\n";
                        $script .= "selectElementHtml +='<div class=\"item\" data-value=\"'+geisPoint.idGP+'\">'+geisPoint.city+' - '+geisPoint.name+', '+geisPoint.street+', '+geisPoint.postcode+' '+'</div>';"."\n";
                        $script .= "}"."\n";
                        
                        
                        $script .= "selectElementHtml +='</div></div>';"."\n";                            

                        $script .= "var selectElement = $(selectElementHtml);"."\n";
                        
                        $script .= "selectElement.dropdown({fullTextSearch:true}); "."\n";
                        
                        $script .= "$(val).after(selectElement);"."\n";
                        $script .= "$(selectElement).after('<div>&nbsp;</div>');"."\n";
                        
                        $script .= "selectElement.dropdown('setting', 'onChange', function(id,text){"."\n";
                        $script .= "selectedGeispointId = id;"."\n";
                        $script .= "});"."\n";
                        
                        //$script .= "debugger;//sbox"."\n";
                        $script .= "if($(val).find('input[type=\"radio\"].delivery_option_radio').is(':checked')) {"."\n";
                        $script .= " "."\n";
                        $script .= " selectedGeispointId = $($(selectElement).children(\"input\")[0]).val();"."\n";
                        $script .= "$('div#geispointSelect_'+i+'_parent').css('display','block')"."\n";
                        $script .= "}"."\n";

                        //$script .= "$(selectElement).children(\"input\").unbind('change.geispointChoosePoint_'+i).bind('change.geispointChoosePoint_'+i,function(e) {"."\n";
                        
                     //   $script .= " var deliveryOption = $(\".delivery_option input[name='id_carrier'][value='\"+$(this).parent().attr('carrierid')+\"']\").parents('.delivery_option'); "."\n";
//                        $script .= "if(!$(deliveryOption).find('.delivery_option_radio input[type=\"radio\"]').is(':checked')) {"."\n";
//                        $script .= " $(deliveryOption).find('.delivery_option_radio input[type=\"radio\"]').attr('checked','checked')"."\n";
//                        $script .= "}"."\n";
                        //$script .= "selectedGeispointId = $(this).val();"."\n";
                        //$script .= "});"."\n";
                        
                        $script .= "}"."\n";
                        $script .= "});"."\n";
                        $script .= "</script>"."\n";
                        break;
                    //Selection in map
                    case 2:
						if (!$is_opc || strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {					
							$script .= "<script src='https://maps.googleapis.com/maps/api/js?v=3.exp'></script>"."\n";
							$script .= "<script type='text/javascript'>"."\n";
							$script .= "function initialize(elementId) {"."\n";
							$script .= " var myLatlng = new google.maps.LatLng(49.815041, 15.397085);"."\n";
							$script .= " var mapOptions = {"."\n";
							$script .= "     zoom: 7,"."\n";
							$script .= "     center: myLatlng"."\n";
							$script .= " };"."\n";
							
							$script .= " var map = new google.maps.Map(document.getElementById(elementId), mapOptions);"."\n";
							$script .= " for(key in data) {"."\n";
							$script .= "     var geisPoint = data[key];"."\n";
							$script .= "     var marker = new google.maps.Marker({"."\n";
							$script .= "         position: new google.maps.LatLng(geisPoint.gpsn,geisPoint.gpse),"."\n";
							$script .= "         map: map,"."\n";
							$script .= "         icon: '"._MODULE_DIR_."/geispoint/img/geispoint.png',"."\n";
							$script .= "         title: geisPoint.name"."\n";
							$script .= "     });"."\n";

							$script .= "     google.maps.event.addListener(marker,'click', (function(geisPoint,elementId){ "."\n";
							$script .= "     	return function() {"."\n";
							$script .= "     		$(\"#\"+elementId+\"_detail\").attr('geisPointId',geisPoint.idGP)"."\n";
							$script .= "     		showPointDetail(geisPoint,elementId,'"._MODULE_DIR_."');"."\n";
							$script .= "                    selectedGeispointId = geisPoint.idGP;"."\n";
							$script .= "     	};"."\n";
							$script .= "     })(geisPoint,elementId)); "."\n";						
							$script .= " }"."\n";
							$script .= "window[elementId] = map;"."\n";
							
							$script .= "$('#form').on('keyup keypress', function(e) {"."\n";
							$script .= "var code = e.keyCode || e.which; "."\n";
							$script .= "if (code  == 13) {               "."\n";
							$script .= "e.preventDefault();"."\n";
							$script .= "return false;"."\n";
							$script .= "}"."\n";
							$script .= "});"."\n";                                                                   
							
							$script .= "codeAddress('".$address->address1.";".$address->city.";".$address->postcode."',map);"."\n";
							$script .= "}"."\n";
							$script .= "</script>"."\n";						
						}
						
                        $script .= "<script type='text/javascript'>"."\n";
                        $script .= " $(\".delivery_option input.delivery_option_radio[type='radio']\").filter(\"".$values."\").parents('.delivery_option').each(function(i, val) {"."\n";
                        $script .= "if ($('#geispointMap_'+i+'_parent').length == 0) {"."\n";
                        $script .= "var carrierId = $(val).find(\"input.delivery_option_radio[type='radio']\").val()"."\n";
                        $script .= "var carrierIdInt = parseInt(carrierId.replace(',',''));"."\n";                        
                        
                        $script .= "var parentElement =$('<div class=\"geispointParentElement geispointMapParent\" id=\"geispointMap_'+i+'_parent\" carrierId=\"'+carrierIdInt+'\" style=\"width:100%;height:420px;display:none;\"></div>');"."\n";
                        $script .= "var selectElement =$('<div id=\"geispointMap_'+i+'\" style=\"width:100%;height:365px;\"></div>');"."\n";
                        $script .= "var input = $('<div style=\"width:100%; height: 35px;\" class=\"ui right labeled input\"><input placeholder=\"Vyhledat lokalitu (např. Plzeň)\"  class=\"geispointMapSearchInput\" style=\"font-size: 14px;\" type=\"text\"/><div style=\"background: #3c83c1;color:white;cursor:pointer;font-size: 14px;\" class=\"geispointMapSearchButton ui label\" >".$this->l('Search')."</div>');"."\n";

                        $script .= "$(val).find(\"input.delivery_option_radio\").unbind('change.geispointDeliveryOption_'+i).bind('change.geispointDeliveryOption_'+i,function(e) {"."\n";
                        $script .= "$('div.geispointParentElement').css('display','none')"."\n";
                        $script .= "$('div#geispointMap_'+i+'_parent').css('display','block')"."\n";
                        $script .= "var center = window[selectElement[0].id].getCenter();  "."\n";
                        $script .= "google.maps.event.trigger(window[selectElement[0].id], 'resize');"."\n";
                        $script .= "window[selectElement[0].id].setCenter(center);"."\n";
                        $script .= "selectedGeispointId = $(\"div[carrierId='\"+carrierIdInt+\"'] div#geispointMap_\"+i+\"_detail\").attr('geisPointId');"."\n";
                        $script .= "});"."\n";

                        $script .= "var leftDiv = $('<div style=\"width:50%;height:100%;float:left;\"></div>');"."\n";
                        $script .= "var rightDiv = $('<div style=\"width:50%;height:100%;float:right;\"></div>');"."\n";
                        
                        $script .= "$(leftDiv).append(input);"."\n";
                        $script .= "$(leftDiv).append(selectElement);"."\n";
                        
                        $script .= "$(parentElement).append(leftDiv);"."\n";
                        $script .= "$(parentElement).append(rightDiv);"."\n";
                        
                        $script .= "$(input).find('.geispointMapSearchButton').on('click', function(e) {"."\n";
                            $script .= "var query = $(input).find('.geispointMapSearchInput').val()"."\n";
                            $script .= "codeAddress(query,window[selectElement[0].id]);"."\n";
                        $script .= "});"."\n";
                        
                        $script .= "$(input).find('.geispointMapSearchInput').on('keyup keypress', function(e) {"."\n";
                            $script .= "var code = e.keyCode || e.which; "."\n";
                            $script .= "if (code  == 13) {               "."\n";
                            $script .= "var query = $(input).find('.geispointMapSearchInput').val()"."\n";
                            $script .= "codeAddress(query,window[selectElement[0].id]);"."\n";
							$script .= "google.maps.event.trigger(window[selectElement[0].id], 'resize');"."\n";
                            $script .= "}"."\n";
                        $script .= "});"."\n";
                        
                        $script .= "$(val).after('<div style=\"clear:both;\"></div>')"."\n";
                        $script .= "$(val).after(parentElement);"."\n";
                        $script .= "$(rightDiv).append('<div id=\"geispointMap_'+i+'_detail\" style=\"margin-left: 10px;\"></div>');"."\n";
						

						if ($is_opc && strtoupper($_SERVER['REQUEST_METHOD']) == 'POST') {
							$script .= "initialize(selectElement[0].id);"."\n";																	
						} else {
							$script .= "google.maps.event.addDomListener(window, 'load', function () {initialize(selectElement[0].id)});"."\n";

						}    
						
                        $script .= "if($(val).find('input[type=\"radio\"].delivery_option_radio').is(':checked')) {"."\n";
                        $script .= " "."\n";
                        $script .= "$('div#geispointMap_'+i+'_parent').css('display','block')"."\n";
						$script .= "google.maps.event.trigger(window[selectElement[0].id], 'resize');"."\n";						
                        $script .= "}"."\n";
                        
                        $script .= "}"."\n";
                        $script .= "});"."\n";

                        $script .= "</script>";
                        break;
                }

                
            }
                    
            $script .= "<script type='text/javascript'>"."\n";
            $script .= "$(\".delivery_option\").filter(function(i,el){ return $(el).next(\".geispointParentElement\").length == 0}).unbind('change.geispointDeliveryOptionPre').bind('change.geispointDeliveryOptionPre',function(e) {"."\n";
            $script .= "$('div.geispointParentElement').css('display','none')"."\n";
            $script .= "selectedGeispointId = 0;"."\n";
            $script .= "});"."\n";
            $script .= "</script>";
            
		return $script;
	}

}
