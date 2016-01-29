<?php
/*
require_once("include/Contact.php");
require_once("include/threads.php");
require_once("include/html2bbcode.php");
require_once("include/bbcode.php");
require_once("include/items.php");
require_once("mod/share.php");
require_once("include/enotify.php");
require_once("include/socgraph.php");
require_once("include/Photo.php");
require_once("include/Scrape.php");
require_once("include/follow.php");
require_once("include/api.php");
require_once("mod/proxy.php");
*/

define("NS_ATOM", "http://www.w3.org/2005/Atom");
define("NS_THR", "http://purl.org/syndication/thread/1.0");
define("NS_GEORSS", "http://www.georss.org/georss");
define("NS_ACTIVITY", "http://activitystrea.ms/spec/1.0/");
define("NS_MEDIA", "http://purl.org/syndication/atommedia");
define("NS_POCO", "http://portablecontacts.net/spec/1.0");
define("NS_OSTATUS", "http://ostatus.org/schema/1.0");
define("NS_STATUSNET", "http://status.net/schema/api/1/");

class dfrn2 {
	/**
	 * @brief Add new birthday event for this person
	 *
	 * @param array $contact Contact record
	 * @param string $birthday Birthday of the contact
	 *
	 */
	private function birthday_event($contact, $birthday) {

		logger('updating birthday: '.$birthday.' for contact '.$contact['id']);

		$bdtext = sprintf(t('%s\'s birthday'), $contact['name']);
		$bdtext2 = sprintf(t('Happy Birthday %s'), ' [url=' . $contact['url'].']'.$contact['name'].'[/url]' ) ;


		$r = q("INSERT INTO `event` (`uid`,`cid`,`created`,`edited`,`start`,`finish`,`summary`,`desc`,`type`)
			VALUES ( %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) ",
			intval($contact['uid']),
			intval($contact['id']),
			dbesc(datetime_convert()),
			dbesc(datetime_convert()),
			dbesc(datetime_convert('UTC','UTC', $birthday)),
			dbesc(datetime_convert('UTC','UTC', $birthday.' + 1 day ')),
			dbesc($bdtext),
			dbesc($bdtext2),
			dbesc('birthday')
		);
	}

	/**
	 * @brief Fetch the author data from head or entry items
	 *
	 * @param object $xpath XPath object
	 * @param object $context In which context should the data be searched
	 * @param array $importer Record of the importer contact
	 * @param string $element Element name from which the data is fetched
	 * @param array $contact The updated contact record of the author
	 * @param bool $onlyfetch Should the data only be fetched or should it update the contact record as well
	 *
	 * @return Returns an array with relevant data of the author
	 */
	private function fetchauthor($xpath, $context, $importer, $element, &$contact, $onlyfetch) {

		$author = array();
		$author["name"] = $xpath->evaluate($element.'/atom:name/text()', $context)->item(0)->nodeValue;
		$author["link"] = $xpath->evaluate($element.'/atom:uri/text()', $context)->item(0)->nodeValue;

		$r = q("SELECT `id`, `uid`, `network`, `avatar-date`, `name-date`, `uri-date`, `addr`,
				`name`, `nick`, `about`, `location`, `keywords`, `bdyear`, `bd`
				FROM `contact` WHERE `uid` = %d AND `nurl` IN ('%s', '%s') AND `network` != '%s'",
			intval($importer["uid"]), dbesc(normalise_link($author["author-link"])),
			dbesc(normalise_link($author["link"])), dbesc(NETWORK_STATUSNET));
		if ($r) {
			$contact = $r[0];
			$author["contact-id"] = $r[0]["id"];
			$author["network"] = $r[0]["network"];
		} else {
			$author["contact-id"] = $contact["id"];
			$author["network"] = $contact["network"];
		}

		// Until now we aren't serving different sizes - but maybe later
		$avatarlist = array();
		// @todo check if "avatar" or "photo" would be the best field in the specification
		$avatars = $xpath->query($element."/atom:link[@rel='avatar']", $context);
		foreach($avatars AS $avatar) {
			$href = "";
			$width = 0;
			foreach($avatar->attributes AS $attributes) {
				if ($attributes->name == "href")
					$href = $attributes->textContent;
				if ($attributes->name == "width")
					$width = $attributes->textContent;
				if ($attributes->name == "updated")
					$contact["avatar-date"] = $attributes->textContent;
			}
			if (($width > 0) AND ($href != ""))
				$avatarlist[$width] = $href;
		}
		if (count($avatarlist) > 0) {
			krsort($avatarlist);
			$author["avatar"] = current($avatarlist);
		}

$onlyfetch = true; // Test

		if ($r AND !$onlyfetch) {

			// When was the last change to name or uri?
			$name_element = $xpath->query($element."/atom:name", $context)->item(0);
			foreach($name_element->attributes AS $attributes)
				if ($attributes->name == "updated")
					$contact["name-date"] = $attributes->textContent;

			$link_element = $xpath->query($element."/atom:link", $context)->item(0);
			foreach($link_element->attributes AS $attributes)
				if ($attributes->name == "updated")
					$contact["uri-date"] = $attributes->textContent;

			// Update contact data
			$value = $xpath->evaluate($element.'/dfrn:handle/text()', $context)->item(0)->nodeValue;
			if ($value != "")
				$contact["addr"] = $value;

			$value = $xpath->evaluate($element.'/poco:displayName/text()', $context)->item(0)->nodeValue;
			if ($value != "")
				$contact["name"] = $value;

			$value = $xpath->evaluate($element.'/poco:preferredUsername/text()', $context)->item(0)->nodeValue;
			if ($value != "")
				$contact["nick"] = $value;

			$value = $xpath->evaluate($element.'/poco:note/text()', $context)->item(0)->nodeValue;
			if ($value != "")
				$contact["about"] = $value;

			$value = $xpath->evaluate($element.'/poco:address/poco:formatted/text()', $context)->item(0)->nodeValue;
			if ($value != "")
				$contact["location"] = $value;

			/// @todo Add support for the following fields that we don't support by now in the contact table:
			/// - poco:utcOffset
			/// - poco:ims
			/// - poco:urls
			/// - poco:locality
			/// - poco:region
			/// - poco:country

			// Save the keywords into the contact table
			$tags = array();
			$tagelements = $xpath->evaluate($element.'/poco:tags/text()', $context);
			foreach($tagelements AS $tag)
				$tags[$tag->nodeValue] = $tag->nodeValue;

			if (count($tags))
				$contact["keywords"] = implode(", ", $tags);

			// "dfrn:birthday" contains the birthday converted to UTC
			$old_bdyear = $contact["bdyear"];

			$birthday = $xpath->evaluate($element.'/dfrn:birthday/text()', $context)->item(0)->nodeValue;

			if (strtotime($birthday) > time()) {
				$bd_timestamp = strtotime($birthday);

				$contact["bdyear"] = date("Y", $bd_timestamp);
			}

			// "poco:birthday" is the birthday in the format "yyyy-mm-dd"
			$value = $xpath->evaluate($element.'/poco:birthday/text()', $context)->item(0)->nodeValue;

			if (!in_array($value, array("", "0000-00-00"))) {
				$bdyear = date("Y");
				$value = str_replace("0000", $bdyear, $value);

				if (strtotime($value) < time()) {
					$value = str_replace($bdyear, $bdyear + 1, $value);
					$bdyear = $bdyear + 1;
				}

				$contact["bd"] = $value;
			}

			if ($old_bdyear != $contact["bdyear"])
				self::birthday_event($contact, $birthday);

			// Get all field names
			$fields = array();
			foreach ($r[0] AS $field => $data)
				$fields[$field] = $data;

			unset($fields["id"]);
			unset($fields["uid"]);

			foreach ($fields AS $field => $data)
				if ($contact[$field] != $r[0][$field])
					$update = true;

			if ($update) {
				logger("Update contact data for contact ".$contact["id"], LOGGER_DEBUG);

				q("UPDATE `contact` SET `name` = '%s', `nick` = '%s', `about` = '%s', `location` = '%s',
					`addr` = '%s', `keywords` = '%s', `bdyear` = '%s', `bd` = '%s'
					`avatar-date`  = '%s', `name-date`  = '%s', `uri-date` = '%s'
					WHERE `id` = %d AND `network` = '%s'",
					dbesc($contact["name"]), dbesc($contact["nick"]), dbesc($contact["about"]), dbesc($contact["location"]),
					dbesc($contact["addr"]), dbesc($contact["keywords"]), dbesc($contact["bdyear"]),
					dbesc($contact["bd"]), dbesc($contact["avatar-date"]), dbesc($contact["name-date"]), dbesc($contact["uri-date"]),
					intval($contact["id"]), dbesc($contact["network"]));
			}

			update_contact_avatar($author["avatar"], $importer["uid"], $contact["id"], ($contact["avatar-date"] != $r[0]["avatar-date"]));

			$contact["generation"] = 2;
			$contact["photo"] = $author["avatar"];
			update_gcontact($contact);
		}

		return($author);
	}

	private function transform_activity($xpath, $activity, $element) {
		if (!is_object($activity))
			return "";

		$obj_doc = new DOMDocument('1.0', 'utf-8');
		$obj_doc->formatOutput = true;

		$obj_element = $obj_doc->createElementNS(NS_ATOM, $element);

		$activity_type = $xpath->query('activity:object-type/text()', $activity)->item(0)->nodeValue;
		xml_add_element($obj_doc, $obj_element, "type", $activity_type);

		$id = $xpath->query('atom:id', $activity)->item(0);
		if (is_object($id))
			$obj_element->appendChild($obj_doc->importNode($id, true));

		$title = $xpath->query('atom:title', $activity)->item(0);
		if (is_object($title))
			$obj_element->appendChild($obj_doc->importNode($title, true));

		$link = $xpath->query('atom:link', $activity)->item(0);
		if (is_object($link))
			$obj_element->appendChild($obj_doc->importNode($link, true));

		$content = $xpath->query('atom:content', $activity)->item(0);
		if (is_object($content))
			$obj_element->appendChild($obj_doc->importNode($content, true));

		$obj_doc->appendChild($obj_element);

		$objxml = $obj_doc->saveXML($obj_element);

		// @todo This isn't totally clean. We should find a way to transform the namespaces
		$objxml = str_replace('<'.$element.' xmlns="http://www.w3.org/2005/Atom">', "<".$element.">", $objxml);
		return($objxml);
	}

	private function process_mail($header, $xpath, $mail, $importer, $contact) {

		$msg = array();
		$msg["uid"] = $importer['importer_uid'];
		$msg["from-name"] = $xpath->query('dfrn:sender/dfrn:name/text()', $mail)->item(0)->nodeValue;
		$msg["from-url"] = $xpath->query('dfrn:sender/dfrn:uri/text()', $mail)->item(0)->nodeValue;
		$msg["from-photo"] = $xpath->query('dfrn:sender/dfrn:avatar/text()', $mail)->item(0)->nodeValue;
                $msg["contact-id"] = $importer["id"];
		$msg["uri"] = $xpath->query('dfrn:id/text()', $mail)->item(0)->nodeValue;
		$msg["parent-uri"] = $xpath->query('dfrn:in-reply-to/text()', $mail)->item(0)->nodeValue;
		$msg["created"] = $xpath->query('dfrn:sentdate/text()', $mail)->item(0)->nodeValue;
		$msg["title"] = $xpath->query('dfrn:subject/text()', $mail)->item(0)->nodeValue;
		$msg["body"] = $xpath->query('dfrn:content/text()', $mail)->item(0)->nodeValue;
                $msg["seen"] = 0;
                $msg["replied"] = 0;

		dbesc_array($msg);

                //$r = dbq("INSERT INTO `mail` (`" . implode("`, `", array_keys($msg))
                //        . "`) VALUES ('" . implode("', '", array_values($msg)) . "')" );

print_r($msg);

                // send notifications.

                require_once('include/enotify.php');

                $notif_params = array(
                        'type' => NOTIFY_MAIL,
                        'notify_flags' => $importer['notify-flags'],
                        'language' => $importer['language'],
                        'to_name' => $importer['username'],
                        'to_email' => $importer['email'],
                        'uid' => $importer['importer_uid'],
                        'item' => $msg,
                        'source_name' => $msg['from-name'],
                        'source_link' => $importer['url'],
                        'source_photo' => $importer['thumb'],
                        'verb' => ACTIVITY_POST,
                        'otype' => 'mail'
                );

//                notification($notif_params);
print_r($notif_params);

	}

	private function process_suggestion($header, $xpath, $suggestion, $importer, $contact) {
	}

	private function process_relocation($header, $xpath, $relocation, $importer, $contact) {
	}

	private function process_entry($header, $xpath, $entry, $importer, $contact) {
		$item = $header;

		// Fetch the owner
		$owner = self::fetchauthor($xpath, $entry, $importer, "dfrn:owner", $contact, true);

		$item["owner-name"] = $owner["name"];
		$item["owner-link"] = $owner["link"];
		$item["owner-avatar"] = $owner["avatar"];

		if ($header["contact-id"] != $owner["contact-id"])
			$item["contact-id"] = $owner["contact-id"];

		if (($header["network"] != $owner["network"]) AND ($owner["network"] != ""))
			$item["network"] = $owner["network"];

		// fetch the author
		$author = self::fetchauthor($xpath, $entry, $importer, "atom:author", $contact, true);

		$item["author-name"] = $author["name"];
		$item["author-link"] = $author["link"];
		$item["author-avatar"] = $author["avatar"];

		if ($header["contact-id"] != $author["contact-id"])
			$item["contact-id"] = $author["contact-id"];

		if (($header["network"] != $author["network"]) AND ($author["network"] != ""))
			$item["network"] = $author["network"];

		// Now get the item
		$item["uri"] = $xpath->query('atom:id/text()', $entry)->item(0)->nodeValue;

		$r = q("SELECT `id` FROM `item` WHERE `uid` = %d AND `uri` = '%s'",
			intval($importer["uid"]), dbesc($item["uri"]));
		if ($r) {
			//logger("Item with uri ".$item["uri"]." for user ".$importer["uid"]." already existed under id ".$r[0]["id"], LOGGER_DEBUG);
			//return false;
		}

		// Is it a reply?
		$inreplyto = $xpath->query('thr:in-reply-to', $entry);
		if (is_object($inreplyto->item(0))) {
			$objecttype = ACTIVITY_OBJ_COMMENT;
			$item["type"] = 'remote-comment';
			$item["gravity"] = GRAVITY_COMMENT;

			foreach($inreplyto->item(0)->attributes AS $attributes) {
				if ($attributes->name == "ref")
					$item["parent-uri"] = $attributes->textContent;
			}
		} else {
			$objecttype = ACTIVITY_OBJ_NOTE;
			$item["parent-uri"] = $item["uri"];
		}

		$item["title"] = $xpath->query('atom:title/text()', $entry)->item(0)->nodeValue;

		$item["created"] = $xpath->query('atom:published/text()', $entry)->item(0)->nodeValue;
		$item["edited"] = $xpath->query('atom:updated/text()', $entry)->item(0)->nodeValue;

		$item["body"] = $xpath->query('dfrn:env/text()', $entry)->item(0)->nodeValue;
		$item["body"] = str_replace(array(' ',"\t","\r","\n"), array('','','',''),$item["body"]);
		// make sure nobody is trying to sneak some html tags by us
		$item["body"] = notags(base64url_decode($item["body"]));

		$item["body"] = limit_body_size($item["body"]);

		/// @todo Do we need the old check for HTML elements?

		// We don't need the content element since "dfrn:env" is always present
		//$item["body"] = $xpath->query('atom:content/text()', $entry)->item(0)->nodeValue;

		$item["last-child"] = $xpath->query('dfrn:comment-allow/text()', $entry)->item(0)->nodeValue;
		$item["location"] = $xpath->query('dfrn:location/text()', $entry)->item(0)->nodeValue;

		$georsspoint = $xpath->query('georss:point', $entry);
		if ($georsspoint)
			$item["coord"] = $georsspoint->item(0)->nodeValue;

		$item["private"] = $xpath->query('dfrn:private/text()', $entry)->item(0)->nodeValue;

		$item["extid"] = $xpath->query('dfrn:extid/text()', $entry)->item(0)->nodeValue;

		if ($xpath->query('dfrn:extid/text()', $entry)->item(0)->nodeValue == "true")
			$item["bookmark"] = true;

		$notice_info = $xpath->query('statusnet:notice_info', $entry);
		if ($notice_info AND ($notice_info->length > 0)) {
			foreach($notice_info->item(0)->attributes AS $attributes) {
				if ($attributes->name == "source")
					$item["app"] = strip_tags($attributes->textContent);
			}
		}

		$item["guid"] = $xpath->query('dfrn:diaspora_guid/text()', $entry)->item(0)->nodeValue;

		// We store the data from "dfrn:diaspora_signature" in a later step. See some lines below
		$signature = $xpath->query('dfrn:diaspora_signature/text()', $entry)->item(0)->nodeValue;

		$item["verb"] = $xpath->query('activity:verb/text()', $entry)->item(0)->nodeValue;

		if ($xpath->query('activity:object-type/text()', $entry)->item(0)->nodeValue != "")
			$objecttype = $xpath->query('activity:object-type/text()', $entry)->item(0)->nodeValue;

		$item["object-type"] = $objecttype;

		// I have the feeling that we don't do anything with this data
		$object = $xpath->query('activity:object', $entry)->item(0);
		$item["object"] = self::transform_activity($xpath, $object, "object");

		// Could someone explain what this is for?
		$target = $xpath->query('activity:target', $entry)->item(0);
		$item["target"] = self::transform_activity($xpath, $target, "target");

		$categories = $xpath->query('atom:category', $entry);
		if ($categories) {
			foreach ($categories AS $category) {
				foreach($category->attributes AS $attributes)
					if ($attributes->name == "term") {
						$term = $attributes->textContent;
						if(strlen($item["tag"]))
							$item["tag"] .= ',';

						$item["tag"] .= "#[url=".$a->get_baseurl()."/search?tag=".$term."]".$term."[/url]";
					}
			}
		}

		$enclosure = "";

		$links = $xpath->query('atom:link', $entry);
		if ($links) {
			$rel = "";
			$href = "";
			$type = "";
			$length = "0";
			$title = "";
			foreach ($links AS $link) {
				foreach($link->attributes AS $attributes) {
					if ($attributes->name == "href")
						$href = $attributes->textContent;
					if ($attributes->name == "rel")
						$rel = $attributes->textContent;
					if ($attributes->name == "type")
						$type = $attributes->textContent;
					if ($attributes->name == "length")
						$length = $attributes->textContent;
					if ($attributes->name == "title")
						$title = $attributes->textContent;
				}
				if (($rel != "") AND ($href != ""))
					switch($rel) {
						case "alternate":
							$item["plink"] = $href;
							break;
						case "enclosure":
							$enclosure = $href;
							if(strlen($item["attach"]))
								$item["attach"] .= ',';

							$item["attach"] .= '[attach]href="'.$href.'" length="'.$length.'" type="'.$type.'" title="'.$title.'"[/attach]';
							break;
					}
			}
		}

		print_r($item);
		//$item_id = item_store($item);

		return;

		if (!$item_id) {
			logger("Error storing item", LOGGER_DEBUG);
			return false;
		} else {
			logger("Item was stored with id ".$item_id, LOGGER_DEBUG);

			if ($signature) {
				$signature = json_decode(base64_decode($signature));

				// Check for falsely double encoded signatures
				$signature->signature = diaspora_repair_signature($signature->signature, $signature->signer);

				// Store it in the "sign" table where we will read it for comments that we relay to Diaspora
				q("INSERT INTO `sign` (`iid`,`signed_text`,`signature`,`signer`) VALUES (%d,'%s','%s','%s')",
					intval($item_id),
					dbesc($signature->signed_text),
					dbesc($signature->signature),
					dbesc($signature->signer)
				);
			}
		}
		return $item_id;
	}

	function import($xml,$importer,&$contact, &$hub) {

		$a = get_app();

		logger("Import DFRN message", LOGGER_DEBUG);

		if ($xml == "")
			return;

		$doc = new DOMDocument();
		@$doc->loadXML($xml);

		$xpath = new DomXPath($doc);
		$xpath->registerNamespace('atom', "http://www.w3.org/2005/Atom");
		$xpath->registerNamespace('thr', "http://purl.org/syndication/thread/1.0");
		$xpath->registerNamespace('at', "http://purl.org/atompub/tombstones/1.0");
		$xpath->registerNamespace('media', "http://purl.org/syndication/atommedia");
		$xpath->registerNamespace('dfrn', "http://purl.org/macgirvin/dfrn/1.0");
		$xpath->registerNamespace('activity', "http://activitystrea.ms/spec/1.0/");
		$xpath->registerNamespace('georss', "http://www.georss.org/georss");
		$xpath->registerNamespace('poco', "http://portablecontacts.net/spec/1.0");
		$xpath->registerNamespace('ostatus', "http://ostatus.org/schema/1.0");
		$xpath->registerNamespace('statusnet', "http://status.net/schema/api/1/");

		$header = array();
		$header["uid"] = $importer["uid"];
		$header["network"] = NETWORK_DFRN;
		$header["type"] = "remote";
		$header["wall"] = 0;
		$header["origin"] = 0;
		$header["gravity"] = GRAVITY_PARENT;
		$header["contact-id"] = $importer["id"];

		// Update the contact table if the data has changed
		// Only the "dfrn:owner" in the head section contains all data
		$dfrn_owner = self::fetchauthor($xpath, $doc->firstChild, $importer, "dfrn:owner", $contact, false);

		// is it a public forum? Private forums aren't supported by now with this method
		$forum = intval($xpath->evaluate('/atom:feed/dfrn:community/text()', $context)->item(0)->nodeValue);

		if ($forum AND ($dfrn_owner["contact-id"] != 0))
			q("UPDATE `contact` SET `forum` = %d WHERE `forum` != %d AND `id` = %d",
				intval($forum), intval($forum),
				intval($dfrn_owner["contact-id"])
			);

		$mails = $xpath->query('/atom:feed/dfrn:mail');
		foreach ($mails AS $mail)
			self::process_mail($header, $xpath, $mail, $importer, $contact);

		$suggestions = $xpath->query('/atom:feed/dfrn:suggest');
		foreach ($suggestions AS $suggestion)
			self::process_suggestion($header, $xpath, $suggestion, $importer, $contact);

		$relocations = $xpath->query('/atom:feed/dfrn:relocate');
		foreach ($relocations AS $relocation)
			self::process_relocation($header, $xpath, $relocation, $importer, $contact);

		$entries = $xpath->query('/atom:feed/atom:entry');
		foreach ($entries AS $entry)
			self::process_entry($header, $xpath, $entry, $importer, $contact);
	}
}
?>
