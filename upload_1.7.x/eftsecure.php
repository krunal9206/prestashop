<?php
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class EftSECURE extends PaymentModule
{
    private $_html = '';
    private $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;
    
    public function __construct()
    {
        $this->name = 'eftsecure';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'WCST';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 0;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(array('EFT_SECURE_DETAILS', 'EFT_SECURE_USERNAME', 'EFT_SECURE_PASSWORD'));
        if (!empty($config['EFT_SECURE_USERNAME'])) {
            $this->owner = $config['EFT_SECURE_USERNAME'];
        }
        if (!empty($config['EFT_SECURE_DETAILS'])) {
            $this->details = $config['EFT_SECURE_DETAILS'];
        }
        if (!empty($config['EFT_SECURE_PASSWORD'])) {
            $this->address = $config['EFT_SECURE_PASSWORD'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('EFTSecure', array(), 'Modules.EftSECURE.Admin');
        $this->description = $this->trans('Accept payments for your products via EFTSecure transfer.', array(), 'Modules.EftSECURE.Admin');
        $this->confirmUninstall = $this->trans('Are you sure about removing these details?', array(), 'Modules.EftSECURE.Admin');

        if (!isset($this->owner) || !isset($this->details) || !isset($this->address)) {
            $this->warning = $this->trans('Account owner and account details must be configured before using this module.', array(), 'Modules.EftSECURE.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->trans('No currency has been set for this module.', array(), 'Modules.EftSECURE.Admin');
        }

        $this->extra_mail_vars = array(
            '{eftsecure_owner}' => Configuration::get('EFT_SECURE_USERNAME'),
            '{eftsecure_details}' => nl2br(Configuration::get('EFT_SECURE_DETAILS')),
            //'{eftsecure_address}' => nl2br(Configuration::get('EFT_SECURE_ADDRESS'))
        );
    }

    public function install()
    {
		if (!parent::install() || !$this->registerHook('paymentReturn') || !$this->registerHook('paymentOptions') || !$this->registerHook('header')) {
            return false;
        }

        // TODO : Cek insert new state, Custom CSS
        $newState = new OrderState();
        
        $newState->send_email = true;
        $newState->module_name = $this->name;
        $newState->invoice = false;
        $newState->color = "#002F95";
        $newState->unremovable = false;
        $newState->logable = false;
        $newState->delivery = false;
        $newState->hidden = false;
        $newState->shipped = false;
        $newState->paid = false;
        $newState->delete = false;

        $languages = Language::getLanguages(true);
        foreach ($languages as $lang) {
            if ($lang['iso_code'] == 'id') {
                $newState->name[(int)$lang['id_lang']] = 'Menunggu pembayaran via EFTSecure';
            } else {
                $newState->name[(int)$lang['id_lang']] = 'Awaiting EFTSecure Payment';
            }
            $newState->template = "eftsecure";
        }

        if ($newState->add()) {
            Configuration::updateValue('PS_OS_EFTSECURE', $newState->id);
            copy(dirname(__FILE__).'/logo.gif', _PS_IMG_DIR_.'tmp/order_state_mini_'.(int)$newState->id.'_1.gif');
        } else {
            return false;
        }

        return true;
    }

    public function uninstall()
    {

        if (!Configuration::deleteByName('EFT_SECURE_DETAILS')
                || !Configuration::deleteByName('EFT_SECURE_USERNAME')
                || !Configuration::deleteByName('EFT_SECURE_PASSWORD')
                || !parent::uninstall()) {
            return false;
        }
        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('EFT_SECURE_USERNAME')) {
                $this->_postErrors[] = $this->trans('API username is required.', array(), 'Modules.EftSECURE.Admin');
            } elseif (!Tools::getValue('EFT_SECURE_PASSWORD')) {
                $this->_postErrors[] = $this->trans('API password is required.', array(), "Modules.EftSECURE.Admin");
            } else {
				$eftsecure_username = Tools::getValue('EFT_SECURE_USERNAME');
				$eftsecure_password = Tools::getValue('EFT_SECURE_PASSWORD');
				$response_data = $this->chkAuthorization($eftsecure_username, $eftsecure_password);
				if(!isset($response_data->token)){
					$this->_postErrors[] = $this->trans($response_data->message, array(), "Modules.EftSECURE.Admin");
				}
			}
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('EFT_SECURE_DETAILS', Tools::getValue('EFT_SECURE_DETAILS'));
            Configuration::updateValue('EFT_SECURE_USERNAME', Tools::getValue('EFT_SECURE_USERNAME'));
            Configuration::updateValue('EFT_SECURE_PASSWORD', Tools::getValue('EFT_SECURE_PASSWORD'));
        }
        $this->_html .= $this->displayConfirmation($this->trans('Settings updated', array(), 'Admin.Global'));
    }

    private function _displayEftSECURE()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }
	
	public function chkAuthorization($eftsecure_username, $eftsecure_password)
    {
		$curl = curl_init('https://services.callpay.com/api/v1/token');
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_POST, 1);
		curl_setopt($curl, CURLOPT_USERPWD, $eftsecure_username . ":" . $eftsecure_password);

		$response = curl_exec($curl);
		curl_close($curl);
		$response_data = json_decode($response);
		return $response_data;
	}
	
	public function hookHeader($params)
    {
        if ((int)Tools::getValue('eft_iframe') == 1) {
            $this->addJsRC(__PS_BASE_URI__.'modules/eftsecure/views/js/jquery.blockUI.min.js');
			$this->addJsRC(__PS_BASE_URI__.'modules/eftsecure/views/js/eftsecure_checkout.js');
        }
    }
	
	public function addJsRC($js_uri)
    {
        $this->context->controller->addJS($js_uri);
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->_displayEftSECURE();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
		$eftsecure_username = Configuration::get('EFT_SECURE_USERNAME');
		$eftsecure_password = Configuration::get('EFT_SECURE_PASSWORD');
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }
		
		if (!$this->checkCurrencyzar($params['cart'])) {
            return;
        }
		
		if($eftsecure_username == '' AND $eftsecure_password == ''){
			return;
		}
		
		$eft_iframe = 0;
        if ((int)Tools::getValue('eft_iframe') == 1) {
            $eft_iframe = 1;
			$response_data = $this->chkAuthorization($eftsecure_username, $eftsecure_password);
			
			if(isset($response_data->token)){
				$token = $response_data->token;
				$organisation_id = $response_data->organisation_id;
			} else {
				$token = '';
				$organisation_id = '';
			}
			
			$cart = $this->context->cart;
			$amount = $cart->getOrderTotal(true, Cart::BOTH);
			
			$params = array(
				"reference" 		=> 'order_'.$params['cart']->id,
				"organisation_id" 	=> $organisation_id,
				"token" 			=> $token,
				"amount" 			=> number_format($amount, 2),
				"pcolor" 			=> '',
				"scolor" 			=> '',
			);
			
            $this->context->smarty->assign(array(
                'eft_iframe' => 1,
                'params'	 => $params,
                'form_url' 		=> $this->context->link->getModuleLink($this->name, 'eftsuccess', array(), true),
            ));
        }

        $this->context->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($this->trans('Pay by EFTSecure', array(), 'Modules.EftSECURE.Shop'))
                      ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
                      ->setAdditionalInformation($this->context->smarty->fetch('module:eftsecure/views/templates/hook/intro.tpl'))
					  ->setInputs(array(
						'wcst_iframe' => array(
							'name' =>'wcst_iframe',
							'type' =>'hidden',
							'value' =>'1',
						)
					));
		if ($eft_iframe == 1) {
            $newOption->setAdditionalInformation(
                $this->context->smarty->fetch('module:eftsecure/views/templates/front/embedded.tpl')
            );
        }
		
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $state = $params['order']->getCurrentState();
        if (in_array(
            $state,
            array(
                Configuration::get('PS_OS_EFTSECURE'),
                Configuration::get('PS_OS_OUTOFSTOCK'),
                Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
            )
        )) {
            $eftsecureOwner = $this->owner;
            if (!$eftsecureOwner) {
                $eftsecureOwner = '___________';
            }

            $eftsecureDetails = Tools::nl2br($this->details);
            if (!$eftsecureDetails) {
                $eftsecureDetails = '___________';
            }

            $eftsecureAddress = Tools::nl2br($this->address);
            if (!$eftsecureAddress) {
                $eftsecureAddress = '___________';
            }

            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'total' => Tools::displayPrice(
                    $params['order']->getOrdersTotalPaid(),
                    new Currency($params['order']->id_currency),
                    false
                ),
                'eftsecureDetails' => $eftsecureDetails,
                'eftsecureAddress' => $eftsecureAddress,
                'eftsecureOwner' => $eftsecureOwner,
                'status' => 'ok',
                'reference' => $params['order']->reference,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ));
        } else {
            $this->smarty->assign(
                array(
                    'status' => 'failed',
                    'contact_url' => $this->context->link->getPageLink('contact', true),
                )
            );
        }

        return $this->fetch('module:eftsecure/views/templates/hook/payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }
	
	public function checkCurrencyzar($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        if ($currency_order->iso_code == 'ZAR') {
			return true;
        }
        return false;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('User details', array(), 'Modules.EftSECURE.Admin'),
                    'icon' => 'icon-user'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans('API Username', array(), 'Modules.EftSECURE.Admin'),
                        'name' => 'EFT_SECURE_USERNAME',
                        'required' => true
                    ),
					 array(
                        'type' => 'text',
                        'label' => $this->trans('API Password', array(), 'Modules.EftSECURE.Admin'),
                        'name' => 'EFT_SECURE_PASSWORD',
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->trans('Details', array(), 'Modules.EftSECURE.Admin'),
                        'name' => 'EFT_SECURE_DETAILS',                  
                    ),        
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );
        $fields_form_customization = array();

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='
            .$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form, $fields_form_customization));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'EFT_SECURE_DETAILS' => Tools::getValue('EFT_SECURE_DETAILS', Configuration::get('EFT_SECURE_DETAILS')),
            'EFT_SECURE_USERNAME' => Tools::getValue('EFT_SECURE_USERNAME', Configuration::get('EFT_SECURE_USERNAME')),
            'EFT_SECURE_PASSWORD' => Tools::getValue('EFT_SECURE_PASSWORD', Configuration::get('EFT_SECURE_PASSWORD')),
        );
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->trans('%1$s (tax incl.)', array(), 'Modules.EftSECURE.Shop'),
            Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH))
        );

         $eftsecureOwner = $this->owner;
        if (!$eftsecureOwner) {
            $eftsecureOwner = '___________';
        }

        $eftsecureDetails = Tools::nl2br($this->details);
        if (!$eftsecureDetails) {
            $eftsecureDetails = '___________';
        }

        return array(
            'total' => $total,
            'eftsecureDetails' => $eftsecureDetails,
            'eftsecureAddress' => $eftsecureAddress,
            'eftsecureOwner' => $eftsecureOwner,
        );
    }
}
