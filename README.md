# README

#### The purpose of the extension is to improve the Foundation’s existing workflows for child protection content.

##### MediaWiki Extension Page
Extension page:
[[Extension:MediaModeration]](https://www.mediawiki.org/wiki/Extension:MediaModeration)

##### WikiTech Technical Page 
WikiTech page:
[[WikiTech:MediaModeration]](https://wikitech.wikimedia.org/wiki/MediaModeration)

## Functionality
MediaModeration provides the following:
- Check uploaded image against PhotoDNA
- Send email to pre-configured recipients if suspicious content found
## LIMIT NOTICE
- All calls to the PhotoDNA API are monitored. There is a limit of 10 million requests per month.
## To run a series of db images for production
Please note that all images tested must be located in the database being used.
### Before running this script
#### Get the last run timestamp
- Go to https://phabricator.wikimedia.org/T258603 and get the timestamp from the last run
  OR
- You can go on a prod box where kafkacat is installed (mwmaint has it) and run
> kafkacat -b kafka-main1001.eqiad.wmnet -t "eqiad.mediawiki.job.processMediaModeration" -c 1 -o -1

NOTE: This gives a JSON output summarizing the last job run, including the image’s timestamp. However, this only lasts for a month. This can be used while running the job, or if it needed to be stopped as well. When running this script once a quarter, searching in logstash for “processMediaModeration” over a long time period can also find the last file timestamp. However, the best idea is to update the task with the last timestamp when it’s finished running.

#### Set up your script variable
Parms which MUST be passed when running when doing full run (i.e. not single file)
- Always pass --wiki commonswiki
- Always pass --batch-size=1000 or some value larger than one
- Should pass --bash-count Control how many images are run using --batch-count. We’ve tended to use --batch-count=10000 to kick off a run for 10 million images (batch-size*batch-count), The recommended value for --batch-count is 10000 which will process 10 million images (batch-size * batch-count).
- Always pass --timestamp Specify the timestamp of the image to start at using --start=yourTimestamp
#### Connect to maintenance server:

>$ ssh mwmaint1002.eqiad.wmnet

OR

>$ ssh mwmaint2002.codfw.wmnet
>
~~~
 extensions/MediaModeration/maintenance/ModerateExistingFiles.php
 --wiki commonswiki
 --batch-size=1000
 --batch-count=10000
 --start=yourTimestamp
~~~
#### Run your script

- Use screen for convenience - documentation for screen
  - https://wikitech-static.wikimedia.org/wiki/Screen
- CTRL+A+D exits from the screen
- To get back to the screen (e.g. to stop the script or check for errors) do: screen -r

- Command to run script
~~~
  $ screen
  $ mwscript extensions/MediaModeration/maintenance/ModerateExistingFiles.php
    --wiki commonswiki --batch-size=1000 --batch-count=5000 --start=20220104105243
  $ CTRL+A+D
~~~
#### While your script runs
Watch progress on grafana:
- If using mwmaint1002.eqiad.wmnet: https://grafana.wikimedia.org/d/LSeAShkGz/jobqueue?viewPanel=15&orgId=1&from=now-90d&to=now
- If using mwmaint2002.codfw.wmnet: https://grafana.wikimedia.org/d/LSeAShkGz/jobqueue?viewPanel=22&orgId=1&var-dc=codfw%20prometheus%2Fk8s
- Filter this to ‘processMediaModeration_0’ by selecting it from the list below the graph
- Adjust the URL param from=now-30d to show older runs
- If the line suddenly moves downwards on grafana, either there are no more images to process, or there was an error. Check screen -r to check which (though this will only last so long, so won’t show old errors)

#### To see errors
go to logstash.wikimedia.org (you may need to log in)

Search for the term processmediamoderaion

To see the actual error logs, and you are running the script on mwmaint1002.eqiad.wmnet, ssh mwlog1002.eqiad.wmnet, per https://wikitech.wikimedia.org/wiki/Mwlog1002. You might also try to look in /srv/mw-log/mediamoderation.log

#### If there are errors
Kick off the script again using the above command, altering the timestamp of the image to start, and perhaps altering the batch count, depending on how many have been run.

#### FINALLY, after the script has finished running
>Log that it was run by commenting on this task: https://phabricator.wikimedia.org/T258603
>NOTE: You will need to have the timestamp displayed after the script is done running


## To run a single image locally
##### Pre-requisites
Before installation, the PhotoDNA subscription key should be obtained from Microsoft.

This is also available from mwmaint server:

yourName@mwmaint1002:~$ mwscript shell.php --wiki=commonswiki
Psy Shell v0.11.10 (PHP 7.4.33 — cli) by Justin Hileman
> $wgMediaModerationPhotoDNASubscriptionKey

#### LIMITATIONS
all images must be one of the following:
- Content-Type: image/gif  .gif
- Content-Type: image/jpeg .jpg or .jpeg
- Content-Type: image/png  .png
- Content-Type: image/bmp  .bmp
- Content-Type: image/tiff .tiff

## Installation
- Download and place the file(s) in a directory called MediaModeration in your extensions/ folder.
- Add the following code at the bottom of your LocalSettings.php:

> wfLoadExtension( 'MediaModeration' );

- Done – This may or may not be true - Now Navigate to Special:Version on your wiki to verify that the extension is successfully installed.

## Configuration
After it is installed, the extension must be configured in mediawiki/LocalSettings.php.

> **$wgMediaModerationPhotoDNAUrl** - URL of PhotoDNA service endpoint.
> - Default Value = https://api.microsoftmoderator.com/photodna/v1.0/Match

> **$wgMediaModerationPhotoDNASubscriptionKey**  - Key for access to PhotoDNA service endpoint, obtained from Microsoft. Must be given a value. Also available for lookup from wmaint server.
> - Default Value = ""

> **$wgMediaModerationFrom**  - Email 'from' field to use for email notifications. Must be given a value.
> - Default Value = ""

> **$wgMediaModerationRecipientList**  - List of emails to be used for notification if a hash match occurs. Must be an array. Must be given a value.
> - Default Value = []

> **$wgMediaModerationHttpProxy** - HTTP proxy to use when calling PhotoDNA service. Default is null, which means no proxy is used. Set to the URL of the proxy to use a proxy.
> - Default Value = null

> **$wgMediaModerationCheckOnUpload**  - 	Indicates whether files should be checked on upload. If false, checking will only be done by the ModerateExistingFiles.php maintenance script.
> - Default Value = false

#### Next
- Find an image on your local site
- open the image in a new window
- the end of the url will have the name of the image
  - FYI: the image name will be after the last forward slash
- run the following after you have determined the value for --file-name
  - Reminder, the file data is already located in your local database
~~~
 docker-compose exec mediawiki php
 maintenance/run.php ./extensions/MediaModeration/maintenance/ModerateExistingFiles.php
 --file-name=YOUR_FILE_NAME
~~~
> ALSO can run full process on local host by running

NOTE: this runs off the images on your local wiki database
~~~
 docker-compose exec mediawiki php
 maintenance/run.php ./extensions/MediaModeration/maintenance/ModerateExistingFiles.php
 --wiki localhost
 --batch-size=3
 --batch-count=2
 --start=20230417190152
~~~
