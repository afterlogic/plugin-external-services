<?php

class CExternalServicesConnectorFacebook extends CExternalServicesConnector
{
	public static function GetSupportedScopes()
	{
		return array('auth');
	}

	public static function CreateClient($oTenant)
	{
		$oClient = null;
		$oSocial = $oTenant->getSocialByName('facebook');
		
		if(isset($oSocial) && $oSocial->SocialAllow)
		{
			$sRedirectUrl = rtrim(\MailSo\Base\Http::SingletonInstance()->GetFullUrl(), '\\/ ').'/?external-services=facebook';
			
			require(PSEVEN_APP_ROOT_PATH.'libraries/OAuthClient/http.php');
			require(PSEVEN_APP_ROOT_PATH.'libraries/OAuthClient/oauth_client.php');

			$oClient = new \oauth_client_class;
			$oClient->debug = self::$Debug;
			$oClient->debug_http = self::$Debug;
			$oClient->server = 'Facebook';
			$oClient->redirect_uri = $sRedirectUrl;
			$oClient->client_id = $oSocial->SocialId;
			$oClient->client_secret = $oSocial->SocialSecret;
			$oClient->scope = 'email';
		}
		
		return $oClient;
	}
	
	public static function Init($oTenant = null)
	{
		parent::Init($oTenant);

		$bResult = false;
		$oUser = null;

		$oClient = self::CreateClient($oTenant);
		if($oClient)
		{
			if(($success = $oClient->Initialize()))
			{
				if(($success = $oClient->Process()))
				{
					if (strlen($oClient->access_token))
					{
						$success = $oClient->CallAPI(
							'https://graph.facebook.com/me',
							'GET',
							array(),
							array('FailOnAccessError' => true),
							$oUser
						);
					}
				}

				$success = $oClient->Finalize($success);
			}

			if($oClient->exit)
			{
				$bResult = false;
				exit;
			}

			if ($success && $oUser)
			{
				$oClient->ResetAccessToken();

				$aSocial = array(
					'type' => 'facebook',
					'id' => $oUser->id,
					'name' => $oUser->name,
					'email' => isset($oUser->email) ? $oUser->email : '',
					'scopes' => self::$Scopes
				);

				\CApi::Log('social_user_facebook');
				\CApi::LogObject($oUser);

				$bResult = $aSocial;
			}
			else
			{
				$oClient->ResetAccessToken();
				self::_socialError($oClient->error, 'facebook');
				$bResult = false;
			}
		}
		else
		{
			echo 'Connector is not allowed';
		}
		
		return $bResult;
	}
}