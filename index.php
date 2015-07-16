<?php

/* -AFTERLOGIC LICENSE HEADER- */

class_exists('CApi') or die();

class CExternalServicesPlugin extends AApiPlugin
{
	public $aConnectors = array();
	public $aEnableConnectors = array();
	private $sTenantHash = '';
	
	/**
	 * @param CApiPluginManager $oPluginManager
	 */
	public function __construct(CApiPluginManager $oPluginManager)
	{
		\CApi::$bIsValid = true;
		parent::__construct('1.0', $oPluginManager);
		
		$this->AddHook('api-pre-app-data', 'PluginApiAppData');
		$this->AddHook('account-update-password', 'AccountUpdatePassword');
		
        $this->AddJsonHook('AjaxSocialAccountListGet', 'AjaxSocialAccountListGet');
	}
	
	public function Init()
	{
		parent::Init();
		
		$this->SetI18N(true);

		$this->AddJsFile('js/include.js');
		$this->AddJsFile('js/CExternalServicesViewModel.js');
        $this->AddTemplate('ExternalServicesSettings', 'templates/settings.html');
		
		$this->aEnableConnectors = CApi::GetConf('plugins.external-services.connectors', array());
		foreach ($this->aEnableConnectors as $sKey)
		{
			$this->AddCssFile('connectors/' . strtolower($sKey) . '/css/styles.css');
			$this->AddImageFile(strtolower($sKey) . '-icon.png', 'connectors/'.strtolower($sKey) . '/images/icon.png');
			
			if ($sKey === 'google')
			{
				$this->AddJsFile('js/GooglePickerPopup.js');
				$this->AddTemplate('GooglePickerPopup', 'templates/GooglePickerPopup.html', 'Layout', 'Screens-Bottom', 'plugin_external_services popup google');
			}  
		}

		$this->IncludeTemplate('Login_WrapLoginViewModel', 'Login-Before-Description', 'templates/login.html');
		$this->IncludeTemplate('Mail_ComposeViewModel', 'Compose-Attach-Buttons', 'templates/attach-from.html');

		$this->AddQueryHook('external-services', 'QueryHook');
	}
	
	public function PluginApiAppData(&$aAppData)
	{
		$oApiDomainsManager = /* @var $oApiDomainsManager \CApiDomainsManager */ \CApi::Manager('domains');

		$oInput = new api_Http();
		$oDomain = /* @var $oDomain \CDomain */ $oApiDomainsManager->GetDomainByUrl($oInput->GetHost());
		if ($oDomain && !$oDomain->IsDefaultDomain)
		{
			$this->sTenantHash = substr(md5($oDomain->IdTenant.CApi::$sSalt), 0, 8);
		}
		
		\CExternalServicesConnectors::Init($aAppData, $this->sTenantHash);

		$oTenant = CExternalServicesConnectors::GetTenantFromCookieOrHash($this->sTenantHash);
		if ($oTenant)
		{
			$aSocials = $oTenant->getSocials();
			$self = $this; // for PHP < 5.4
			array_walk($aSocials, function($oSocial, $sKey) use ($self) { 
				if ($oSocial->SocialAllow && in_array($sKey, $self->aEnableConnectors))
				{
					$self->aConnectors[] = $oSocial->toArray();
				}
			});			
		}
		
		if (!isset($aAppData['Plugins']))
		{
			$aAppData['Plugins'] = array();
		}

		$aExternalServices = array();
		if (is_array($this->aConnectors))
		{
			$aExternalServices['Connectors'] = $this->aConnectors;
		}

		$aAppData['Plugins']['ExternalServices'] = $aExternalServices;
		
		$oAccount /* @var $oAccount \CAccount */ = \api_Utils::GetDefaultAccount();
		if ($oAccount)
		{
			$oApiSocial /* @var $oApiSocial \CApiSocialManager */ = \CApi::Manager('social');
			$aSocials = $oApiSocial->getSocials($oAccount->IdAccount);
			
			$aUserServices = array();
			foreach ($aSocials as $oSocial)
			{
				if ($oSocial && $oSocial instanceof \CSocial)
				{
					$aSocial = $oSocial->toArray();
					$aSocial['ServiceName'] = '';
					$aSocial['UserScopes'] = array();
					$aSocial['Connected'] = true;
					
					if (in_array(strtolower($oSocial->TypeStr), $this->aEnableConnectors))
					{
						$aUserServices[strtolower($oSocial->TypeStr)] = $aSocial;
					}
				}
			}
			
			foreach($this->aConnectors as $aConnector)
			{
				$sServiceType = strtolower($aConnector['Name']);
				if (!$aUserServices[$sServiceType])
				{
					$oSocial = new \CSocial();
					$oSocial->TypeStr = $sServiceType;
					$aSocial = $oSocial->toArray();
					$aSocial['Connected'] = false;

					$aUserServices[$sServiceType] = $aSocial;
				}
			
				$aUserServices[$sServiceType]['ServiceName'] = $aConnector['Name'];
				$aUserServices[$sServiceType]['UserScopes'] = array();
				
				foreach ($aConnector['Scopes'] as $sScope)
				{
					if (trim($sScope) !== '')
					{
						$aUserServices[$sServiceType]['UserScopes'][$sScope] = (in_array($sScope, $aUserServices[$sServiceType]['Scopes']));
					}
				}
			}
			
			$aAppData['Plugins']['ExternalServices']['Users'] = array_values($aUserServices);
		}		
		
		$aAppData['AllowChangePassword'] = CApi::GetConf('plugins.external-services.allow-change-password', true);
	}	
	
	public function QueryHook($aQuery)
	{
		$sSocial = ucfirst($aQuery['external-services']);				
		$this->sTenantHash = isset($aQuery['hash']) ? $aQuery['hash'] : '';
		$mResult = @\CExternalServicesConnectors::$sSocial('Init', \CExternalServicesConnectors::GetTenantFromCookieOrHash($this->sTenantHash));
		if (false !== $mResult && is_array($mResult))
		{
			\CExternalServicesConnectors::Process($mResult);
		}
	}
	
	public function GetSupportedScopes($sConnector)
	{
		$sConnector = ucfirst($sConnector);
		return @\CExternalServicesConnectors::$sConnector('GetSupportedScopes');
	}
	
	public function AccountUpdatePassword(&$bAllowChangePassword)
	{
		$bAllowChangePassword = CApi::GetConf('plugins.external-services.allow-change-password', true);
	}
}

class CExternalServicesConnectors 
{
	public static function __callStatic($sConnector, $aArgs)
	{
		if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'connectors' . DIRECTORY_SEPARATOR .  strtolower($sConnector) . DIRECTORY_SEPARATOR . 'index.php'))
		{
			require_once __DIR__ . DIRECTORY_SEPARATOR .'connectors/' . strtolower($sConnector) . '/index.php';
			
			$sMethod = $aArgs[0];
			if (method_exists("CExternalServicesConnector" . $sConnector , $sMethod))
			{
				if ($sMethod === 'Init')
				{
					$oTenant = isset($aArgs[1]) ? $aArgs[1] : null;

					$mResult = call_user_func('\CExternalServicesConnector' . $sConnector . '::' . $sMethod, $oTenant);

					if (false !== $mResult && is_array($mResult))
					{
						self::Process($mResult);
					}
				}
				else if ($sMethod === 'GetSupportedScopes')
				{
					return call_user_func('\CExternalServicesConnector' . $sConnector . '::' . $sMethod);
				}
			}
			else
			{
				echo 'Connector does not exist';
			}
		}
		else
		{
			echo 'Connector does not exist';
		}
	}
	
	public static function Init(&$aAppData, $sTenantHash)
	{
		@setcookie('p7tenantHash', $sTenantHash);
		$oTenant = self::GetTenantFromCookieOrHash($sTenantHash);
		$oApiIntegratorManager = \CApi::Manager('integrator');

		if ($oTenant)
		{
			foreach ($oTenant->getSocials() as $oSocial)
			{
				$aAppData['Social' . $oSocial->SocialName] = $oSocial->SocialAllow;
				$aAppData['Social' . $oSocial->SocialName . 'Id'] = $oSocial->SocialId;
				$aAppData['Social' . $oSocial->SocialName . 'Key'] = $oSocial->SocialId;
				if (isset($oSocial->SocialApiKey))
				{
					$aAppData['Social' . $oSocial->SocialName . 'ApiKey'] = $oSocial->SocialApiKey;
				}
				$aAppData['Social' . $oSocial->SocialName . 'Secret'] = $oSocial->SocialSecret;
				$aAppData['Social' . $oSocial->SocialName . 'Scopes'] = $oSocial->SocialScopes;
			}
		}

		if(isset($_COOKIE['p7social']))
		{
			$aSocial = \CApi::DecodeKeyValues($_COOKIE['p7social']);
			$oUser = $oApiIntegratorManager->getAhdSocialUser($sTenantHash, $aSocial['id']);

			if(strlen($aSocial['email']))
			{
				$sSocialType = isset($aSocial['type']) ? $aSocial['type'] : '';
				$sSocialId = isset($aSocial['id']) ? $aSocial['id'] : '';
				$sSocialName = isset($aSocial['name']) ? $aSocial['name'] : '';
				$sNotificationEmail = isset($aSocial['email']) ? $aSocial['email'] : '';

				if(!$oUser)
				{
					$mIdTenant = $oApiIntegratorManager->getTenantIdByHash($sTenantHash);
					if (!is_int($mIdTenant))
					{
						throw new \ProjectCore\Exceptions\ClientException(\ProjectCore\Notifications::InvalidInputParameter);
					}
					$bResult = false;
					try
					{
						$bResult = $oApiIntegratorManager->registerSocialAccount($mIdTenant, $sTenantHash, $sNotificationEmail, $sSocialId, $sSocialType, $sSocialName);
					}
					catch (\Exception $oException)
					{
						$iErrorCode = \ProjectCore\Notifications::UnknownError;
						if ($oException instanceof \CApiManagerException)
						{
							switch ($oException->getCode())
							{
								case \Errs::HelpdeskManager_UserAlreadyExists:
									$iErrorCode = \ProjectCore\Notifications::HelpdeskUserAlreadyExists;
									break;
								case \Errs::HelpdeskManager_UserCreateFailed:
									$iErrorCode = \ProjectCore\Notifications::CanNotCreateHelpdeskUser;
									break;
								case \Errs::Db_ExceptionError:
									$iErrorCode = \ProjectCore\Notifications::DataBaseError;
									break;
							}
						}

						throw new \ProjectCore\Exceptions\ClientException($iErrorCode);
					}
				}

				$oUser = $oApiIntegratorManager->getAhdSocialUser($sTenantHash, $aSocial['id']);
			}

			if ($oUser)
			{
				$oApiIntegratorManager->setHelpdeskUserAsLoggedIn($oUser, false);
				@setcookie ('p7social', '', time() - 1);
			}
			else
			{
				$aAppData['SocialEmail'] = $aSocial['email'];
				$aAppData['SocialIsLoggedIn'] = true;
			}
		}
	}

	public static function GetTenantHashFromCookie()
	{
		return isset($_COOKIE['p7tenantHash']) ? $_COOKIE['p7tenantHash'] : '';
	}
	
	public static function GetTenantFromCookieOrHash($sTenantHash = '')
	{
		$oTenant = null;
		$sTenantHash = $sTenantHash ? $sTenantHash : self::GetTenantHashFromCookie();
		$oApiTenantsManager = /* @var $oApiTenantsManager \CApiTenantsManager */ \CApi::Manager('tenants');
		if ($oApiTenantsManager)
		{
			if ($sTenantHash)
			{
				$oTenant = $oApiTenantsManager->getTenantByHash($sTenantHash);
			}
			else
			{
				$oAccount /* @var $oAccount \CAccount */ = \api_Utils::GetDefaultAccount();
				if ($oAccount && 0 < $oAccount->IdTenant)
				{
					$oTenant = $oApiTenantsManager->getTenantById($oAccount->IdTenant);
				}
				else
				{
					$oTenant = $oApiTenantsManager->getDefaultGlobalTenant();
				}
			}
		}
		return $oTenant;
	}

	public static function Process($mResult)
	{
		$sExternalServicesRedirect = '';
		$sError = '';
		if (isset($_COOKIE["external-services-redirect"]))
		{
			$sExternalServicesRedirect = $_COOKIE["external-services-redirect"];
			@setcookie('external-services-redirect', null);
		}
		if ($sExternalServicesRedirect === 'helpdesk')
		{
			self::SetValuesToCookie($mResult);
			$sTenantHash = self::GetTenantHashFromCookie();
			\CApi::Location($sTenantHash ? './?helpdesk=' . $sTenantHash : './?helpdesk');
		}
		else
		{
			$oApiSocial = /* @var $oApiSocial \CApiSocialManager */ \CApi::Manager('social');
			$oSocial = new \CSocial();
			$oSocial->TypeStr = $mResult['type'];
			$oSocial->AccessToken = isset($mResult['access_token']) ? $mResult['access_token'] : '';
			$oSocial->RefreshToken = isset($mResult['refresh_token']) ? $mResult['refresh_token'] : '';
			$oSocial->IdSocial = $mResult['id'];
			$oSocial->Name = $mResult['name'];
			$oSocial->Email = $mResult['email'];
			
			if ($sExternalServicesRedirect === 'login')
			{
				self::SetValuesToCookie($mResult);
	
				$oApiUsers = /* @var $oApiUsers \CApiUsersManager */ \CApi::Manager('users');
				$oAccount = $oApiUsers->getAccountByEmail($mResult['email']);
				if (!$oAccount)
				{
					$oAccount = $oApiUsers->getAccountBySocialEmail($mResult['email']);
				}
				if ($oAccount)
				{
					$oSocial->IdAccount = $oAccount->IdAccount;
					$oSocialOld = $oApiSocial->getSocialById($oSocial->IdSocial, $oSocial->TypeStr);
					if ($oSocialOld)
					{
						if ($oSocial->IdAccount === $oSocialOld->IdAccount)
						{
							$oSocialOld->setScope('auth');
							$oSocial->Scopes = $oSocialOld->Scopes;
							$oApiSocial->updateSocial($oSocial);
						}
						else
						{
							$sError = '?error=' . \ProjectCore\Notifications::AuthError;
						}
					}
					else
					{
						$oSocial->setScopes($mResult['scopes']);
						$oApiSocial->createSocial($oSocial);
					}

					$oApiIntegrator = \CApi::Manager('integrator');
					$oApiIntegrator->setAccountAsLoggedIn($oAccount, true);
					
					$oApiUsers->updateAccountLastLoginAndCount($oAccount->IdUser);					
				}
				else
				{
					$sError = '?error=' . \ProjectCore\Notifications::AuthError;
				}
				\CApi::Location('./' . $sError);
			}
			else
			{
				$oAccount = \api_Utils::GetDefaultAccount();
				if ($oAccount)
				{
					$bResult = false;
					$oSocial->IdAccount = $oAccount->IdAccount;
					$oSocialOld = $oApiSocial->getSocialById($oSocial->IdSocial, $oSocial->TypeStr);
					
					if ($oSocialOld)
					{
						if ($oSocialOld->IdAccount === $oSocial->IdAccount)
						{
							if (in_array('null', $mResult['scopes']) || count($mResult['scopes']) === 0)
							{
								$bResult = $oSocial->deleteSocial($oAccount->IdAccount, $oSocial->TypeStr);
							}
							else
							{
								$oSocial->setScopes($mResult['scopes']);
								$bResult = $oApiSocial->updateSocial($oSocial);
							}
						}
						else
						{
							$bResult = false;
						}
					}
					else if (!in_array('null', $mResult['scopes']) && count($mResult['scopes']) > 0)
					{
						$oSocial->setScopes($mResult['scopes']);
						$bResult = $oApiSocial->createSocial($oSocial);
					}
					$sResult = $bResult ? 'true' : 'false';
					echo 
					"<script>"
						. "if (typeof(window.opener.servicesSettingsViewModelCallback) !== 'undefined') {"
						.		"window.opener.servicesSettingsViewModelCallback('".$mResult['type']."', " . $sResult . ");"
						.		"window.close();"
						. "}"
					. "</script>";					
				}
			}
		}
	}

	public static function SetValuesToCookie($aValues)
	{
		@setcookie("p7social", \CApi::EncodeKeyValues($aValues));
	}
	
	public static function ClearValuesFromCookie()
	{
		@setcookie("p7social", null);
	}
	
	public function AjaxSocialAccountListGet()
	{
		$mResult['Result'] = false;
		$oTenant = null;
		$oAccount /* @var $oAccount \CAccount */ = \api_Utils::GetDefaultAccount();
		$oApiTenants = /* @var $oApiTenants \CApiSocialManager */ \CApi::Manager('tenants');
		
		if ($oAccount && $oApiTenants)
		{
			$oTenant = (0 < $oAccount->IdTenant) ? $oApiTenants->getTenantById($oAccount->IdTenant) :
				$oApiTenants->getDefaultGlobalTenant();
		}
		if ($oTenant)
		{
			$oApiSocial /* @var $oApiSocial \CApiSocialManager */ = \CApi::Manager('social');
			$mResult['Result'] = $oApiSocial->getSocials($oAccount->IdAccount);
		}
		return $mResult;
	}
}

class CExternalServicesConnector
{
	public static $Debug = true;
	public static $Scopes = array();
	
	public static function Init($oTenant = null) 
	{
		self::$Scopes = isset($_COOKIE['external-services-scopes']) ? 
			explode('|', $_COOKIE['external-services-scopes']) : array();
	}

	public static function GetSupportedScopes() 
	{
		return array();
	}

	protected static function _socialError($oClientError, $sSocialName)
	{
		\CApi::Log($sSocialName, ' error');
		\CApi::LogObject($oClientError);
	}
}

return new CExternalServicesPlugin($this);
