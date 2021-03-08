ConfirmAccount
==============

This is patched version of the ConfirmAccount extension, it
adds the following:

* `$wgConfirmAccountRequestFormItems`
	* new `Company` field
	* new `ReceiveEmails` field
	* new `ReceiveNewsletter` field
* new `$wgConfirmAccountRequestFormItemsRequired` setting:
	* allows to define mandatory flag on the following fields:
		* RealName
		* Company
		* Notes
		* Links
		* ReceiveEmails
		* ReceiveNewsletter
* new `$wgConfirmAccountApproveOnEmailConfirmation` setting:
	* if set to `true` will automatically approve account requests
	when user confirms the request by following the confirmation link
	  in email
* new `ConfirmAccountCompleteRequest` hook:
	* fires when `AccountConfirmSubmission` is marked as completed
