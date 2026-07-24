<?php

$labels = [];

// Task / general
$labels['horus']            = 'Horus';
$labels['dashboardtitle']   = 'Horus · Email intelligence';
$labels['details']          = 'Details';
$labels['timeline']         = 'Timeline';
$labels['rawrequest']       = 'Raw request';
$labels['search']           = 'Search';
$labels['notfound']         = 'Not found';
$labels['invalidrequest']   = 'Invalid request';

// Compose
$labels['enabletracking']         = 'Enable tracking';
$labels['trackedattachments']     = 'Tracked attachments';
$labels['trackedattachmentshint'] = 'These files are not attached to the message. They are served from this webmail through a link, so you can see when each one is opened and downloaded.';
$labels['adddocument']            = 'Add file…';
$labels['removedoc']              = 'Remove';
$labels['nodocs']                 = 'No tracked attachments';
$labels['trackedattachmentsoff']  = 'Tracking is off, so these files will not be sent.';
$labels['uploading']              = 'Uploading…';
$labels['uploaderror']            = 'Upload failed';
$labels['uploadtoolarge']         = 'File is too large';
$labels['uploadblockedtype']      = 'File type not allowed';
$labels['uploadnotwritable']      = 'Storage directory is not writable — check horus_storage_dir';

// Status
$labels['opened']       = 'Opened';
$labels['maybeopened']  = 'Possibly opened';
$labels['noopens']      = 'No opens yet';
$labels['clicked']      = 'Clicked';
$labels['viewed']       = 'Viewed';
$labels['downloaded']   = 'Downloaded';
$labels['notopened']    = 'Not opened';
$labels['notdownloaded'] = 'Not downloaded';
$labels['nottracked']   = 'Not tracked';
$labels['timeonce']     = 'time';
$labels['timesmany']    = 'times';
$labels['noevents']     = 'No activity recorded yet';

// Event tags
$labels['eventconfirmed']   = 'Open';
$labels['eventbot']         = 'Bot open';
$labels['eventunknown']     = 'Unclear open';
$labels['eventclick']       = 'Click';
$labels['eventdocview']     = 'File viewed';
$labels['eventdocdownload'] = 'File downloaded';

// Classifier reasons
$labels['reasonconfigured']      = 'IP in a configured bot range';
$labels['reasongoogle']          = 'Gmail image proxy (real open, IP hidden)';
$labels['reasonscanner']         = 'Scanner or link-preview bot';
$labels['reasonscannerhost']     = 'Reverse DNS identifies a mail gateway';
$labels['reasonprefetch']        = 'Fetched instantly after sending (prefetch)';
$labels['reasonapplempp']        = 'Apple Mail Privacy Protection';
$labels['reasonapplenet']        = 'Apple network, real client (unclear)';
$labels['reasonrealclient']      = 'Recognised mail client';
$labels['reasongenericua']       = 'Anonymised user agent';
$labels['reasonunknown']         = 'Unrecognised client';
$labels['reasonnoua']            = 'No user agent';
$labels['reasonclickreinforced'] = 'Confirmed by a later click';
$labels['reasonhumanclick']      = 'Click well after sending';
$labels['reasonimmediateclick']  = 'Click immediately after sending';
$labels['reasondocview']         = 'Opened the file link';
$labels['reasondocdownload']     = 'Downloaded the file';

// Dashboard tiles
$labels['tilesent']       = 'Sent';
$labels['tiletrackedof']  = '%d tracked';
$labels['tileopenrate']   = 'Open rate';
$labels['tileopencount']  = '%d of %d confirmed';
$labels['tilemayberate']  = 'Possibly opened';
$labels['tilemaybecount'] = '%d bot or proxy only';
$labels['tileclickrate']  = 'Click rate';
$labels['tileclickcount'] = '%d clicked';
$labels['tiledocs']       = 'Files downloaded';
$labels['tiledocsviewed'] = '%d viewed';

// Chart
$labels['chartactivity'] = 'Activity';
$labels['seriessent']    = 'Sent';
$labels['seriesopened']  = 'Opened';
$labels['seriesclicked'] = 'Clicked';
$labels['nodatayet']     = 'Nothing sent in this period';

// Lists
$labels['messages']          = 'Messages';
$labels['nomessages']        = 'No messages match';
$labels['byrecipient']       = 'By recipient';
$labels['searchplaceholder'] = 'Search recipient or subject…';
$labels['colwhen']           = 'When';
$labels['colrecipient']      = 'Recipient';
$labels['colsubject']        = 'Subject';
$labels['colstatus']         = 'Status';
$labels['coldocs']           = 'Files down/view/total';
$labels['colsent']           = 'Sent';
$labels['colopened']         = 'Opened';
$labels['colmaybe']          = 'Possibly';
$labels['colclicked']        = 'Clicked';
$labels['collastsent']       = 'Last sent';

// Filters
$labels['filterall']        = 'All messages';
$labels['filtertracked']    = 'Tracked only';
$labels['filteruntracked']  = 'Untracked only';
$labels['filteropened']     = 'Opened (confirmed)';
$labels['filtermaybe']      = 'Possibly opened';
$labels['filterunopened']   = 'Not opened';
$labels['filterclicked']    = 'Clicked';
$labels['filternotclicked'] = 'Not clicked';

// Settings
$labels['prefstracking']          = 'Tracking';
$labels['prefsautotrack']         = 'Track new messages by default';
$labels['prefsprefetchwindow']    = 'Prefetch window (seconds)';
$labels['prefsprefetchwindowhint'] = 'An image fetched within this many seconds of sending is treated as a mail gateway prefetch, not a human open. Default: 10.';
$labels['prefsbots']              = 'Bot detection';
$labels['prefsbotranges']         = 'Always-bot IP ranges';
$labels['prefsbotrangeshint']     = 'One CIDR block per line (for example 10.0.0.0/8). Opens from these addresses are always classified as bots.';
$labels['prefsbadranges']         = 'Some ranges were not valid CIDR blocks and were discarded.';
$labels['prefsapplestatus']       = 'Apple relay ranges';
$labels['prefsapplecount']        = '%d ranges cached, updated %s';
$labels['prefsapplenone']         = 'Not downloaded yet — Apple MPP detection falls back to 17.0.0.0/8 only.';
$labels['prefsrefreshranges']     = 'Refresh Apple ranges now';
$labels['prefsrefreshrangeshint'] = 'Downloads Apple’s published iCloud Private Relay egress list.';
$labels['prefsrangesupdated']     = 'Apple ranges updated.';
$labels['prefsrangesfailed']      = 'Could not download the Apple ranges.';

// Coloured folders
$labels['prefsfolders']          = 'Folders';
$labels['prefsfoldercolors']     = 'Coloured folders';
$labels['prefsfoldercolorshint'] = 'Adds a colour picker to each folder’s settings. The colour is used for that folder in the sidebar only — messages are never coloured.';
$labels['foldercolor']       = 'Colour';
$labels['nofoldercolor']     = 'No colour';
$labels['customfoldercolor'] = 'Custom colour…';
$labels['foldercolorhex']    = 'Colour as a hex value, for example #8e4ec6';

// Scheduled messages
$labels['prefsscheduling']     = 'Scheduled send';
$labels['prefsschedulinghint'] = 'Adds a "Schedule" button to the compose screen and a Scheduled folder in the sidebar. A message you schedule is sent by a minute-by-minute background job, tracked exactly like an ordinary send.';
$labels['prefsscheduletz']     = 'Time zone';
$labels['prefsscheduletzhint'] = 'The clock scheduled times are shown and read in. Automatic uses your own time zone.';
$labels['scheduletzauto']      = 'Automatic (your time zone)';
$labels['scheduledfolder']   = 'Scheduled';
$labels['schedule']          = 'Schedule';
$labels['schedulesend']      = 'Schedule send';
$labels['scheduleat']        = 'Send on';
$labels['scheduleconfirm']   = 'Schedule';
$labels['scheduled']         = 'Scheduled';
$labels['scheduledfor']      = 'Scheduled for %s';
$labels['scheduledempty']    = 'No scheduled messages';
$labels['scheduledsent']     = 'Sent';
$labels['scheduledpending']  = 'Scheduled';
$labels['scheduledfailed']   = 'Failed';
$labels['reschedule']        = 'Reschedule';
$labels['cancelschedule']    = 'Cancel';
$labels['editschedule']      = 'Edit';
$labels['schedulepast']      = 'Pick a time in the future.';
$labels['scheduledone']      = 'Message scheduled.';
$labels['schedulecanceled']  = 'Scheduled message canceled.';
$labels['schedulemoved']     = 'Message rescheduled.';
$labels['deletetodrafts']    = 'Delete';
$labels['scheduledtodrafts'] = 'Moved to Drafts.';
$labels['scheduledpick']     = 'Select a scheduled message to preview it.';

// Chart range picker and interaction
$labels['range7']     = '7 days';
$labels['range30']    = '30 days';
$labels['range90']    = '90 days';
$labels['range365']   = '1 year';
$labels['rangefrom']  = 'From';
$labels['rangeto']    = 'To';
$labels['rangeapply'] = 'Apply';
$labels['legendhint'] = 'Click a series to isolate it';
$labels['showtable']  = 'Show as table';

// Self-view (the sender opening their own Sent copy)
$labels['eventself']          = 'Your own view';
$labels['reasonselfsession']  = 'Opened by you in this webmail (session cookie)';
$labels['reasonselfreferer']  = 'Opened by you in this webmail';
$labels['reasonselfip']       = 'Fetched from the address the message was sent from';
$labels['selfviews']          = 'Your own views';

// Wording that reaches the RECIPIENT. Nothing here may hint at tracking.
$labels['mailattachments'] = 'Attachments';
$labels['notopenedyet'] = 'Not opened';
$labels['files']        = 'files';

// Marking an address as a bot
$labels['markbot']       = 'Mark as bot';
$labels['markbottitle']  = 'Treat this address as a bot from now on, and re-classify what it already did';
$labels['markbotdone']   = '%s marked as a bot: %d events re-classified across %d messages.';
$labels['markbotfailed'] = 'Could not mark that address.';
$labels['reasonusermarked'] = 'Marked as a bot by you';
$labels['horusdetails']  = 'Horus';

// Per-recipient sending
$labels['splitrecipients']     = 'Track each recipient separately';
$labels['splitrecipientshint'] = 'Sends an individual copy to each recipient so you can see who opened it. Without this you learn that the message was opened, not by whom. Recipients cannot tell the difference.';
$labels['perrecipient']        = 'Per recipient';
$labels['prefssplit']          = 'Track each recipient separately by default';
$labels['prefssplithint']      = 'Only applies when a message has more than one recipient. It sends one copy per recipient instead of a single message.';

// Client / network summary table
$labels['clientsummary'] = 'Clients and addresses';
$labels['colclient']     = 'Client';
$labels['colip']         = 'Address';
$labels['collocation']   = 'Location';
$labels['colhost']       = 'Reverse DNS';
$labels['colhits']       = 'Hits';

$labels['clicksummary']    = 'Links clicked';
$labels['downloadsummary'] = 'Files downloaded';
$labels['collink']         = 'Link';
$labels['colfile']         = 'File';
$labels['colclicks']       = 'Clicks';
$labels['coldownloads']    = 'Downloads';
$labels['deletedfile']     = 'File no longer stored';
