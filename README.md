# README

#### The purpose of the extension is to improve the Foundation’s existing workflows for child protection content.


Extension page:
[[https://www.mediawiki.org/wiki/Extension:MediaModeration]](https://www.mediawiki.org/wiki/Extension:MediaModeration)


## Functionality
MediaModeration provides the following:

- Check uploaded image against PhotoDNA
- Send email to pre-configured recipients if suspicious content found


## Pre-requisites
Before installation, the PhotoDNA subscription key should be obtained from Microsoft.

## Installation
- Download and place the file(s) in a directory called MediaModeration in your extensions/ folder.
- Add the following code at the bottom of your LocalSettings.php:

> wfLoadExtension( 'MediaModeration' );

- Done – Now Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

## Configuration
After it is installed, the extension must be configured.

> **$wgMediaModerationPhotoDNAUrl** - URL of PhotoDNA service endpoint.
> - Default Value = https://api.microsoftmoderator.com/photodna/v1.0/Match

> **$wgMediaModerationPhotoDNASubscriptionKey**  - Key for access to PhotoDNA service endpoint, obtained from Microsoft. Must be given a value.
> - Default Value = ""

> **$wgMediaModerationFrom**  - Email 'from' field to use for email notifications. Must be given a value.
> - Default Value = ""

> **$wgMediaModerationRecipientList**  - List of emails to be notified a hash match occurs. Must be an array. Must be given a value.
> - Default Value = []

> **$wgMediaModerationHttpProxy** - HTTP proxy to use when calling PhotoDNA service. Default is null, which means no proxy is used. Set to the URL of the proxy to use a proxy.
> - Default Value = null

> **$wgMediaModerationCheckOnUpload**  - 	Indicates whether files should be checked on upload. If false, checking will only be done by the ModerateExistingFiles.php maintenance script.
> - Default Value = false


