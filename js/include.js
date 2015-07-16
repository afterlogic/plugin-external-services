(function () {
	AfterLogicApi.addPluginHook('view-model-defined', function (sViewModelName, oViewModel) {
		var
			oExternalServices = AfterLogicApi.getPluginSettings('ExternalServices')/*,*/
//			servicesAccounts = ko.observableArray(oExternalServices ? oExternalServices.Users : []),
//			servicesConnectors = ko.observableArray(oExternalServices ? oExternalServices.Connectors : [])
		;
		
		if (oViewModel && ('CWrapLoginViewModel' === sViewModelName || 
				'CExternalServicesViewModel' === sViewModelName ||
					'CComposeViewModel' === sViewModelName))
		{
			oViewModel.servicesAccounts = ko.observableArray(oExternalServices ? oExternalServices.Users : []);

			_.each(oViewModel.servicesAccounts(), function (oItem){
				var aScopes = [];
				_.each(oItem.UserScopes, function (sValue, sKey){
					aScopes.push({
						'name': sKey, 
						'displayName': AfterLogicApi.i18n('PLUGIN_EXTERNAL_SERVICES/SCOPE_' + sKey.toUpperCase()),
						'value': ko.observable(sValue)
					});
				}, oViewModel);
				
				if (!oItem.isFilestorage)
				{
					oItem.isFilestorage =  ko.observable(false);
					oItem.userScopes = ko.observableArray(aScopes);
					oItem.userScopes.subscribe(function(aValue) {
						oItem.isFilestorage(false);
						_.each(aValue, function(oScopeItem) {
							if (oScopeItem.name === 'filestorage' && oScopeItem.value())
							{
								oItem.isFilestorage(true);
							}
						}, oItem);
					}, oItem);
					oItem.userScopes.valueHasMutated();
					oItem.connected = ko.observable(oItem.Connected);
					oItem.userName = ko.observable(oItem.Connected ? '(' + oItem.Name + ')' : '');
				}
			}, oViewModel);
			
			if ('CWrapLoginViewModel' === sViewModelName)
			{
				oViewModel.servicesConnectors = ko.observableArray(oExternalServices ? oExternalServices.Connectors : []);
				oViewModel.externalAuthClick = function (sSocialName) {
					$.cookie('external-services-redirect', 'login');
					$.cookie('external-services-scopes', 'auth');
					window.location.href = '?external-services=' + sSocialName;
				};
			}
			if ('CComposeViewModel' === sViewModelName)
			{
				AfterLogicApi.loadScript('https://www.dropbox.com/static/api/2/dropins.js', null, {
					'id': 'dropboxjs',
					'data-app-key': AfterLogicApi.getAppDataItem('SocialDropboxKey')
				});
				
				oViewModel.onShowAttachFromClick = function (sService)
				{
					if (sService === 'google')
					{
						this.onShowGoogleDriveClick();
					}
					else if (sService === 'dropbox')
					{
						this.onShowDropBoxClick();
					}
				};

				// Google Drive
				oViewModel.onShowGoogleDriveClick = function ()
				{
					/*global GooglePickerPopup:true */
					AfterLogicApi.showPopup(GooglePickerPopup, [_.bind(this.googlePickerCallback, this)]);
					/*global GooglePickerPopup:false */
				};

				oViewModel.googlePickerCallback = function (aPickerItems, sAccessToken)
				{
					var
						oAttach = null,
						aUrls = [],
						oParameters = {},
						self = this
					;

					_.each(aPickerItems, function (oPickerItem) {
						oAttach = AfterLogicApi.createObjectInstance('CMailAttachmentModel');
						if (oAttach)
						{
							oAttach
								.fileName(oPickerItem.name)
								.hash(oPickerItem.id)
								.size(oPickerItem.sizeBytes)
								.uploadStarted(true)
								.type(oPickerItem.mimeType);

							if (oAttach.type().substr(0, 5) === 'image')
							{
								oAttach.thumb(true);
							}
						}

						self.attachments.push(oAttach);
						aUrls.push(oPickerItem.id);

					}, this);

					oParameters = {
						'Action': 'FilesUploadByLink',
						'Links': aUrls,
						'LinksAsIds': true,
						'AccessToken': sAccessToken
					};

					oViewModel.messageUploadAttachmentsStarted(true);

					AfterLogicApi.sendAjaxRequest(oParameters, oViewModel.onFilesUpload, this);
				};
				// ----------------

				// DropBox
				oViewModel.onShowDropBoxClick = function ()
				{
					var
						oAttach = null,
						aUrls = [],
						oParameters = {},
						self = this,

						options = {
							success: function(files) {
								_.each(files, function (oFile) {
									oAttach = AfterLogicApi.createObjectInstance('CMailAttachmentModel');
									if (oAttach)
									{
										oAttach
											.fileName(oFile.name)
											.hash(oFile.link)
											.size(oFile.bytes)
											.uploadStarted(true)
											.thumb(!!oFile.thumbnailLink);

										self.attachments.push(oAttach);
									}
									aUrls.push(oFile.link);
								}, self);


								oParameters = {
									'Action': 'FilesUploadByLink',
									'Links': aUrls
								};

								self.messageUploadAttachmentsStarted(true);

								AfterLogicApi.sendAjaxRequest(oParameters, self.onFilesUpload, self);
							},
							cancel: function() {

							},
							linkType: "direct",
							multiselect: true
						};

					if (window.Dropbox)
					{
						window.Dropbox.choose(options);
					}
				};
				// ----------------				
			}
		}
	});
}());
