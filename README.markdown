# Field: Amazon S3 File Upload

- Version: 0.7.2
- Author: Symphonists
- Build Date: 2013-09-07
- Requirements: Symphony 2.3.1 or higher

## Origin

This extension is a variation of the 'Unique File Upload Field' extension by Michael Eichelsdoerfer and the Akismet extension (for System Preferences) by  Alistair Kerney. It uses the Amazon S3 PHP class written by Donovan Schonknecht, http://undesigned.org.za/2007/10/22/amazon-s3-php-class. This extension was started by Brian Zerangue, taken over by Andrew Shooner, and some slight modifications to get it working with Symphony 2.2 were made by Scott Tesoriere.


## Overview
This extension functions as a basic replacement for file uploads, allowing hosting on Amazon S3 (it requires an [Amazon S3 account](http://aws.amazon.com/s3/)). Uploaded files are world readable. It is not considered feature-complete; there is much additional functionality that could be added . If you have input, please contact us at the Symphony forum, at getsymphony.com. 


## Installation

1. Upload the 's3upload_field' folder in this archive to your Symphony 
   'extensions' folder.

2. Enable it by selecting the "Field: Amazon S3 Upload", choose Enable from 
   the with-selected menu, then click Apply.

3. Under Preferences, add your S3 Access Key ID and Secret Access Key.

3. You can now add the "Amazon S3 File Upload" field to your sections. Select the bucket you wish to store files in from the dropdown.


## Change Log

___0.7.2 - BZ ___

- Fixed extension.meta.xml; Updated domain name in xmlns... removed www which caused conflicts.

___0.7.1 - BZ ___

- Fixed extension.meta.xml; added required symphony attribute for the author/name node.

___0.7 - korelogic and twiro___

- Added compatibility for Symphony 2.3.1 and PHP 5.4.x; Requirement: Symphony 2.3.1 or higher.

___0.6.7 - WN___

- Added extension.meta.xml, and added compatibility for Symphony 2.3 - wjn

___0.6.6 - ME___

- Requirement: Symphony 2.2
- Fix: Added sanitizing of filename upon uploading.

___0.6.5 - ST___

- Changed algorithm for generating unique filename id to the one provided by Michael
- Merged secret key changes (to password input field)
- Cache-control is now allowed to be null

___0.6.4 - ST___

- Properly removes old files when adding a new one
- Added SSL option
- Added Unique File option
- Added Cache Control option in preferences, needs to be in seconds, defaults to 10 days

___0.6.3 - ST___

- Wasn't setting the Content-Type for S3 files, thanks Michael!
- Accidentally removed bucket if the filename was empty and it was the last file in the bucket
- Wasn't properly removing files when a file was removed then saved

___0.6.2 - ST___

- If the bucket gets deleted, it won't throw an exception
- Dealing with the way files stored in the database, so even if you change the CNAME (or remove it), the proper URL will be displayed
- Fixed editing an entry, it wasn't working with some legacy code!

___0.6.1 - ST___

- Fixed a problem where an entry wouldn't properly remove itself completely

___0.6 - ST___

- Now deletes a file from S3 upon deleting an Entry (this is optional and set upon creating a S3 field within a section)
- You can specify an optional CNAME so the files will generate the proper URL without intervention
- Bug fixes to get it working with Symphony 2.2
- You can't add a field to a section until you setup your API keys
- Added mimetype and size to the XML output

___.5.1 alpha - AS___

- Corrected fatal error in field commit() function.

___.5 alpha - A.S.___

- Added S3 class functionality.
- Decided on public-read permissions for simplest usage.
- Put bucket selection in Section editing view.
- Stripped out some extraneous code from upload field this field is based on (Needs more pruning).

___.1.0 alpha - B.Z___

- Initial release - posting code to work off of base. Need to integrate Amazon S3 class now.

## To do

- If the CNAME is removed or added, update all the files and fields so it has or doesn't have the CNAME, otherwise it will be kind of wonky
- Proper error checking, there are still a few uncaught exceptions.

