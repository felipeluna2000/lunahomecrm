<?php
/*+**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 ************************************************************************************/

return array(

    // Create project in https://console.cloud.google.com
    // Enable Oauth2 Web Client and update details below.
    "Google" => array(
        "clientId" => "867319045560-sk9prsbon35qt98ie3e83mf2pojh161r.apps.googleusercontent.com",
        "clientSecret" => "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",
    ),
	
    "Office365" => array(
        "clientId" => "408e92f9-6755-45f3-a3e4-6fdee2a2577e",
        "clientSecret" => "0be8Q~6Ud8AVJBZCOr3oitGX3VsKppJaGqO3~dv6",
		"redirectUri" => 'https://oauth2.360vew.com' 
    ),

    // Setup XOAUTH2 Imap Proxy Service
    // https://code.vtiger.com/vtiger/vtigercrm/-/issues/1914
    // Update host:port details here.
    "Proxies" => array(
        "imap.gmail.com" => "127.0.0.1:993"
    )
);
