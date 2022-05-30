<?php
/**
* Super rough and tumble WordPress import script for Dokuwiki.
* Based on a very old DW install that was using the default theme. Probably won't work for anything else.
* You will want to change some things if your wiki is installed anywhere other than /wiki/.
* Also check out the wp_insert_post() stuff to see if you want to change it.
* 
* First version by Beau Lebens
* http://dentedreality.com.au/2013/01/29/import-dokuwiki-pages-into-wordpress/
*
* Matti Lattu added 2014 these features:
*  - Named DW tags to WB categories
*  - Links to attachments are moved
*  - Some DW HTML is cleaned
*/
 
require 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
// for the function wp_generate_attachment_metadata() to work
require_once ABSPATH . 'wp-admin/includes/image.php';

include 'search.php';
 
// List of Index URLs (one for each namespace is required)
// These will be crawled, all pages will be listed out, then crawled and imported
$DW_INDEXES = array(
	// Root namespace
    "http://10.248.2.4/wikipge/doku.php?id=wikini:wikini"
);

$DW_FETCH_URL = 'http://10.248.2.4/wikipge/lib/exe/fetch.php?media=';

$AUTHOR_ID = 1; // The user_ID of the author to create pages as
 
// This maps some DW tags to WP categories (category IDs)
// Use low-case DW tag names!
$TAG_MAP = Array(
	'dw_tag_one' => 1,
	'dw_tag_two' => 4,
	);
	
$PAGE_NAME_PREFIX = 'dw-old-';

function dokuwiki_link_fix( $matches ) {
    return '<a href="/' . str_replace( '_', '-', $matches[1] ) . '" class="wikilink1"';
}
//This is the name the wiki is using in its url. e.g.: host/WIKI_NAME/doku.php?id=something
$WIKI_NAME = 'wikipge';
//Your wiki might have a prefix before a page id. e.g.: host/WIKI_NAME/doku.php?id=prefix:something. This variable is useful to fix links of file attachments
//Leave an empty string if there's none or if there is, include a colon at the end. e.g. 'prefix:'
$URL_COLON_PREFIX = 'wikini:';

$search = new Search($DW_INDEXES, $DW_FETCH_URL, $AUTHOR_ID, $WIKI_NAME,$URL_COLON_PREFIX);
$search->importWikiPages();
