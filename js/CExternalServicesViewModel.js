/**
 * @constructor
 */
function CExternalServicesViewModel()
{
	this.servicesAccounts =  ko.observableArray([]);
	if (AfterLogicApi.runPluginHook)
	{
		AfterLogicApi.runPluginHook('view-model-defined', [this.__name, this]);
	}	
}

CExternalServicesViewModel.prototype.__name = 'CExternalServicesViewModel';
CExternalServicesViewModel.prototype.TemplateName = 'Plugin_ExternalServicesSettings';
CExternalServicesViewModel.prototype.TabName = 'services';
CExternalServicesViewModel.prototype.TabTitle = AfterLogicApi.i18n('PLUGIN_EXTERNAL_SERVICES/TAB_SERVICES');

CExternalServicesViewModel.prototype.onApplyBindings = function ()
{
	window.servicesSettingsViewModelCallback = _.bind(function (sType, bResult) {
		this.getServiceFromServer(sType);
	}, this);
};

CExternalServicesViewModel.prototype.getService = function (sType)
{
	return _.find(this.servicesAccounts(), function (oItem){
		return oItem.Type === sType;
	}, this);
};

CExternalServicesViewModel.prototype.getServiceFromServer = function (sType)
{
	AfterLogicApi.sendAjaxRequest({
			'Action': 'SocialAccountGet',
			'Type': sType
		},
		this.onGetServiceFromServerResponse,
		this
	);	
};

CExternalServicesViewModel.prototype.onGetServiceFromServerResponse = function (oResponse, oRequest)
{
	if (oResponse.Result)
	{
		var oService = this.getService(oRequest.Type);
		if (oService)
		{
			oService.connected(true);
			oService.userName('(' + oResponse.Result.Name + ')');
			oService.userScopes.valueHasMutated();
			this.servicesAccounts.valueHasMutated();
		}
	}
};				

CExternalServicesViewModel.prototype.onSaveClick = function (oConnector) 
{
	var aScopes = [];

	$.removeCookie('external-services-scopes');

	_.each(oConnector.userScopes(), function (oScope) {
		if (oScope.value())
		{
			aScopes.push(oScope.name);
		}
	});
/*
	if (aScopes.length === 0)
	{
		this.serviceDelete(oConnector.Type);
		oConnector.userScopes.valueHasMutated();

	}
	else
	{
*/	
	$.cookie('external-services-scopes', aScopes.join('|'));
	oWin = AfterLogicApi.WindowOpener.open(AfterLogicApi.getAppPath() + '?external-services=' + oConnector.Type + '&scopes=' + aScopes.join('|'), oConnector.Name);
/*	} */
};

CExternalServicesViewModel.prototype.onSignOutClick = function (oConnector)
{
	AfterLogicApi.sendAjaxRequest({
			'Action': 'SocialAccountDelete',
			'Type': oConnector.Type
		},
		this.onServiceDeleteResponse,
		this
	);	
};

CExternalServicesViewModel.prototype.onServiceDeleteResponse = function (oResponse, oRequest)
{
	if (oResponse.Result)
	{
		var oService = this.getService(oRequest.Type);
		if (oService)
		{
			_.each(oService.userScopes(), function (oScope) {
				oScope.value(false);
			});

			oService.userScopes.valueHasMutated();
			oService.userName('');
			oService.connected(false);
		}
	}
};		

AfterLogicApi.addSettingsTab(CExternalServicesViewModel);

