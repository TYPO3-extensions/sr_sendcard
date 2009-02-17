<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2003-2008 Stanislas Rolland <typo3(arobas)sjbr.ca>
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
*  Plugin 'Send-A-Card' for the 'sr_sendcard' extension.
*
*  @author Stanislas Rolland <typo3(arobas)sjbr.ca>
*
*  Credits:
*
*  The general idea of this plugin is based on the sendcard php script authored by Peter Bowyer.
*  The plugin is a complete remake within the Typo3 framework,
*  leaving little resemblance with the code of the original sendcard script that inspired it.
*  Yet, this plugin is made available in the Typo3 public online extension repository with the agreement of Peter Bowyer.
*
*  See also sendcard:
*  Copyright Peter Bowyer <peter@sendcard.org> 2000, 2001, 2002
*  This script is released under the Artistic License
*
*  Some initial code was taken from Luke Chiam <luke@webesse.com>'s Webesse E-Card extension to build the image selector.
*
*/

require_once(PATH_tslib.'class.tslib_pibase.php');
require_once(PATH_t3lib.'class.t3lib_page.php');			// To get the pid language overlay
require_once(PATH_t3lib.'class.t3lib_htmlmail.php');			// To send HTML mails
require_once(PATH_t3lib.'class.t3lib_stdgraphic.php');			// To brand the images
require_once(PATH_t3lib.'class.t3lib_extmgm.php');

class tx_srsendcard_pi1 extends tslib_pibase {
	
	var $cObj;							// The backReference to the mother cObj object set at call time
	var $prefixId = 'tx_srsendcard_pi1';  				// Same as class name
	var $scriptRelPath = 'pi1/class.tx_srsendcard_pi1.php';		// Path to this script relative to the extension dir.
	var $extKey = 'sr_sendcard';					// The extension key.
	var $selectorId = 'tx-srsendcard-pi1';
	var $conf = array();
	var $siteUrl;
	var $tbl_name = 'tx_srsendcard_sendcard';			// Card instances table
	var $card_tbl_name = 'tx_srsendcard_card';			// Card table
	var $overlay_tbl_name = 'tx_srsendcard_card_language_overlay';	// Card language overlay table (DEPRECATED)
	
	/**
	 * Main class of Send-A-Card plugin for Typo3 CMS (sr_sendcard)
	 *
	 * @param	string		$content: content to be displayed
	 * @param	array		$conf: TS setup for the plugin
	 * @return	string		content produced by the plugin
	 */
	function main($content, $conf) {
		global $TYPO3_DB,$TSFE;
		
		$this->conf = $conf;
		$this->pi_setPiVarDefaults();
		$this->pi_loadLL();
		$this->pi_USER_INT_obj = 1;  // Disable caching
		
			// Load template
		$this->templateCode = $this->cObj->fileResource($this->conf['templateFile']);
		
			// Substitute global markers, fonts and colors
		$globalMarkerArray = array();
		if ($this->conf['templateStyle'] == 'old-style') {	
			$splitMark = md5(microtime());
			list($globalMarkerArray['###GW1B###'], $globalMarkerArray['###GW1E###']) = explode($splitMark, $this->cObj->stdWrap($splitMark, $this->conf['wrap1.']));
			list($globalMarkerArray['###GW2B###'], $globalMarkerArray['###GW2E###']) = explode($splitMark, $this->cObj->stdWrap($splitMark, $this->conf['wrap2.']));
			list($globalMarkerArray['###GW3B###'], $globalMarkerArray['###GW3E###']) = explode($splitMark, $this->cObj->stdWrap($splitMark, $this->conf['wrap3.']));
			list($globalMarkerArray['###GW4B###'], $globalMarkerArray['###GW4E###']) = explode($splitMark, $this->cObj->stdWrap($splitMark, $this->conf['wrap4.']));
			$globalMarkerArray['###GC1###'] = $this->cObj->stdWrap($this->conf['color1'], $this->conf['color1.']);
			$globalMarkerArray['###GC2###'] = $this->cObj->stdWrap($this->conf['color2'], $this->conf['color2.']);
			$globalMarkerArray['###GC3###'] = $this->cObj->stdWrap($this->conf['color3'], $this->conf['color3.']);
			$globalMarkerArray['###GC4###'] = $this->cObj->stdWrap($this->conf['color4'], $this->conf['color4.']);
		}
		$globalMarkerArray['###CHARSET###'] = $TSFE->metaCharset;
		
			// Setting CSS style markers if required
		if ($this->conf['enableHTMLMail']) {
			$globalMarkerArray['###CSS_STYLES###'] = $this->cObj->fileResource($this->conf['HTMLMailCSS']);
		}
		$this->templateCode = $this->cObj->substituteMarkerArray($this->templateCode, $globalMarkerArray);
		
		$wrappedSubpartArray = array();
		$subpartArray = array();
		$markerArray = array();
		
			// Get get and post parameters
		$cardData = t3lib_div::_GP($this->prefixId);
			// Sanitize incoming data
		if (is_array($cardData)) {
			foreach ($cardData as $name => $value) {
				$cardData[$name] = htmlspecialchars(strip_tags($value));
			}
			if (is_array(parse_url($cardData['card_image_path']))) {
				$cardData['card_image_path'] = '';
			}
		}
		
			// Set paths and url's
		$id = $GLOBALS['TSFE']->id;
		$type = $GLOBALS['TSFE']->type;
		$workingDir = $this->conf['dir'];
		$music_path = $this->conf['musicDir'].'/';
		if (substr($music_path,0,4) == 'EXT:')      {       // extension
			list($extKey,$local) = explode('/',substr($music_path,4),2);
			if (strcmp($extKey,'') &&  t3lib_extMgm::isLoaded($extKey)) {
				$music_path = t3lib_extMgm::siteRelPath($extKey) . $local;
			}
		}
		$site_url = t3lib_div::getIndpEnv('TYPO3_SITE_URL');
		$this->siteUrl = $site_url;
		$sentCardsFolderPID = 0;
		
		$createPID = $this->conf['createPID'] ? $this->conf['createPID'] : $GLOBALS['TSFE']->id;
		$createType = ($this->conf['createType'] == '') ? $GLOBALS['TSFE']->type : $this->conf['createType'];
		$create_url = $this->get_url('', $createPID.','.$Type);
		$markerArray['###FORM_URL###'] = $create_url;
		
		$this->formPID = $this->conf['formPID'] ? $this->conf['formPID'] : $GLOBALS['TSFE']->id;
		$this->formType = ($this->conf['formType'] == '') ? $GLOBALS['TSFE']->type : $this->conf['formType'];
		$this->form_url = $this->get_url('', $this->formPID.','.$this->formType, array(), array(), FALSE);
		
		$previewPID = $this->conf['previewPID'] ? $this->conf['previewPID'] : $GLOBALS['TSFE']->id;
		$previewType = ($this->conf['previewType'] == '') ? $GLOBALS['TSFE']->type : $this->conf['previewType'];
		$preview_url = $this->get_url('', $previewPID.','.$previewType, array(), array(), FALSE);
		
		$viewPID = $this->conf['viewPID'] ? $this->conf['viewPID'] : $GLOBALS['TSFE']->id;
		$viewType = ($this->conf['viewType'] == '') ? $GLOBALS['TSFE']->type : $this->conf['viewType'] ;
		
		$printPID = $this->conf['printPID'] ? $this->conf['printPID'] : $viewPID;
		$printType = ($this->conf['printType'] == '') ? $viewType : $this->conf['printType'];
		
			// Initialise image parameters and functions
		$imgInfo = '';
		$this->imgObj = t3lib_div::makeInstance('tx_srsendcard_pi1_t3lib_stdGraphic' );
		$this->imgObj->init();
		$this->imgObj->mayScaleUp = 0;
		$this->imgObj->tempPath = $cardData['card_image_path'] ? $cardData['card_image_path'] : $this->conf['dir'].'/';
		$this->imgObj->filenamePrefix = $this->extKey.'_';
		
			// Set language and locale
		$language = $GLOBALS['TSFE']->lang;
		$isoLanguage = strtolower($GLOBALS['TSFE']->sys_language_isocode);
		if ($this->conf['locale_all']) {
			setlocale(LC_ALL, $this->conf['locale_all']);
		}
		if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['t3lib_cs_utils'] == 'mbstring' && $TSFE->metaCharset != 'windows-1250' ) {
			$current_internal_encoding = mb_internal_encoding();
			mb_internal_encoding($TSFE->metaCharset);
			$this->date = strftime($this->conf['date_stdWrap']);
			mb_internal_encoding($current_internal_encoding);
		} else {
			$this->date = strftime($this->conf['date_stdWrap']);
		}
		
			// Initialise language overlay of cards series
		$this->pidRecord = t3lib_div::makeInstance('t3lib_pageSelect');
		$this->pidRecord->init(0);
		$this->pidRecord->sys_language_uid = (trim($GLOBALS['TSFE']->config['config']['sys_language_uid'])) ? trim($GLOBALS['TSFE']->config['config']['sys_language_uid']):0;
		
			// We are not going to send if the captcha string is incorrect
		if ($cardData['cmd'] == 'send' && t3lib_extMgm::isLoaded('sr_freecap') && $this->conf['useCAPTCHA']) {
			require_once(t3lib_extMgm::extPath('sr_freecap').'pi2/class.tx_srfreecap_pi2.php');
			$freeCap = t3lib_div::makeInstance('tx_srfreecap_pi2');
			if (!$freeCap->checkWord($cardData['captcha_response'])) {
				$cardData['cmd'] = 'preview';
				$invalid_captcha_response = 1;
			}
		}
		
			// We are not going to preview if there are errors
		if ($cardData['cmd'] == 'preview' ) {
			if (trim($cardData['to_name']) == '' ) {
				$cardData['cmd'] = 'prompt';
				$missing_to_name = 1;
			}
			if (!(t3lib_div::validEmail(trim($cardData['to_email']))) ) {
				$cardData['cmd'] = 'prompt';
				$invalid_to_email = 1;
			}
			if (trim($cardData['from_name']) == '' ) {
				$cardData['cmd'] = 'prompt';
				$missing_from_name = 1;
			}
			if (!(t3lib_div::validEmail(trim($cardData['from_email']))) ) {
				$cardData['cmd'] = 'prompt';
				$invalid_from_email = 1;
			}
			if (trim($cardData['card_message']) == '' ) {
				$cardData['cmd'] = 'prompt';
				$missing_card_message = 1;
			}
		}
		
		switch ($cardData['cmd']) {
			case '':
				/*
				* Display choice of cards
				*/
				
					// Get cards series
				$cardSeriesUid = array();
				$cardSeriesTitle = array();
				if ($this->cObj->data['pages'] ) {
					$cardSeriesUid = explode(',', $this->cObj->data['pages']);
				} else {
					$cardSeriesUid[0] = $id;
				}
				
					// Determine presentation order
				if (trim($this->conf['cardPresentationOrder']) == 'manual' )  {
					$cardPresentationOrder = 'sorting';
				} else {
					$cardPresentationOrder = 'card';
				}
				
					// Prepare to build the series subpart
				if ($this->conf['enableAlternateSelectionTemplate'] ) {
					$cardSeriesUidCount = count($cardSeriesUid);
					$this->seriesTemplateCode = $this->templateCode;
					$this->seriesSubpart = $this->cObj->getSubpart($this->seriesTemplateCode, '###TEMPLATE_ALTERNATE_SELECTION_PAGE###');
					$this->seriesSubpart = $this->cObj->getSubpart($this->seriesSubpart, '###TEMPLATE_SINGLE_CARDS_SERIES###');
				} else {
					$cardSeriesUidCount = 1;
				}
				$seriesOut = '';
				$seriesMarkerArray = array();
				$seriesSubpartArray = array();
				
				for($cardSeriesIndex = 0; $cardSeriesIndex < $cardSeriesUidCount; $cardSeriesIndex++ ) {
					
						// Get card series title
					$row = $this->pidRecord->getPage($cardSeriesUid[$cardSeriesIndex]);
					$cardSeriesTitle = $row['title'];

						// Get available card models from the database
					$whereClause = 'pid = ' . intval($cardSeriesUid[$cardSeriesIndex])
							. ' AND sys_language_uid <= 0'
							. $this->cObj->enableFields($this->card_tbl_name);
					$res = $TYPO3_DB->exec_SELECTquery(
						'*',
						$this->card_tbl_name,
						$whereClause,
						'',
						$cardPresentationOrder
						);
					
						// Prepare image selector as array of thumbnails
					$image_selector = $this->imageSelector($res);
					
					if ($this->conf['enableAlternateSelectionTemplate'] ) {
							// we build multiple selectors for multiple series
						$seriesMarkerArray['###CARDS_SERIES_TITLE###'] = $cardSeriesTitle;
						$seriesMarkerArray['###IMAGE_SELECTOR###'] = $image_selector;
						$seriesOut .= $this->cObj->substituteMarkerArrayCached($this->seriesSubpart, $seriesMarkerArray, $seriesSubpartArray, $wrappedSubpartArray);
					}
				} // end of cards series loop

					// Display form
				if ($this->conf['enableAlternateSelectionTemplate'] ) {
					$this->subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_ALTERNATE_SELECTION_PAGE###');
					$markerArray['###SELECT_IMAGE_PROMPT###'] = $this->pi_getLL('select_image_prompt');
					$content = $this->cObj->substituteSubpart($this->subpart, '###TEMPLATE_SINGLE_CARDS_SERIES###', $seriesOut, 0);
					$content = $this->cObj->substituteMarkerArray($content, $markerArray);
				} else {
					$this->subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_SELECTION_PAGE###');
					$markerArray['###IMAGE_SELECTOR###'] = $image_selector;
					$markerArray['###SELECT_IMAGE_PROMPT###'] = $this->pi_getLL('select_image_prompt');
					$content = $this->cObj->substituteMarkerArrayCached($this->subpart, $markerArray, $subpartArray, $wrappedSubpartArray);
				}
				break;
				
			case 'prompt':
				/*
				* Display form and prompt for recipent information and card options
				*/
				
					// Initialise values
				$notfirsttime = ($cardData['notfirsttime'] == 1);
				($cardData['day']) ? $card_send_time = mktime(0, 0, 0, $cardData['month'], $cardData['day'], $cardData['year']) :$card_send_time = time() ;
				$cardData['day'] = intval(date('d', $card_send_time));
				$cardData['month'] = intval(date('m', $card_send_time));
				$cardData['year'] = intval(date('Y', $card_send_time));
				for ($day = 1 ; $day <= 31; $day++) {
					$days[$day] = $day;
				}
				$month_labels = t3lib_div::trimExplode(',' , $this->pi_getLL('month_names'));
				for ($month = 1 ; $month <= 12; $month++) {
					$months[$month] = $month;
					 $month_names[$month] = $month_labels[$month-1];
				}
				$years[1] = intval(date('Y', time()));
				$years[2] = $years[1] + 1;

				$fontface_labels = t3lib_div::trimExplode(',' , $this->pi_getLL('fontface_labels'));
				$fontface_values = t3lib_div::trimExplode(';' , $this->conf['cardFontfaces']);
				$cardData['fontface'] = ($cardData['fontface']) ? $cardData['fontface'] : htmlspecialchars($fontface_values[0]);
				if ($this->conf['graphicMess'] ) {
					$fontfile_values = t3lib_div::trimExplode(',' , $this->conf['graphicMessFontFiles']);
					$fontsize_values = t3lib_div::trimExplode(',' , $this->conf['graphicMessFontSizes']);
					$fontfile_labels = t3lib_div::trimExplode(',' , $this->pi_getLL('fontfile_labels'));
					$cardData['fontfile'] = ($cardData['fontfile']) ? $cardData['fontfile'] : htmlspecialchars($fontfile_values[0]);
					$cardData['fontsize'] = ($cardData['fontsize']) ? $cardData['fontsize'] : htmlspecialchars($fontsize_values[0]);
				}
				$card_music_labels = t3lib_div::trimExplode(',' , $this->pi_getLL('card_music_labels'));
				$card_music_values = t3lib_div::trimExplode(',' , $this->conf['cardMusicFiles']);
				$cardData['card_music'] = ($cardData['card_music']) ? $cardData['card_music'] : htmlspecialchars($card_music_values[0]);
				$bgcolor_values = t3lib_div::trimExplode(',' , $this->conf['cardBgcolors']);
				$cardData['bgcolor'] = ($cardData['bgcolor']) ? $cardData['bgcolor'] : htmlspecialchars($bgcolor_values[0]);
				$fontcolor_values = t3lib_div::trimExplode(',' , $this->conf['cardFontcolors']);
				$cardData['fontcolor'] = ($cardData['fontcolor']) ? $cardData['fontcolor'] : htmlspecialchars($fontcolor_values[0]);
				if (!($cardData['notfirsttime'] == 1) ) {
					$cardData['card_delivery_notify'] = 1;
				}
				
					// Display form
				$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_CARD_FORM###');
				$markerArray['###FORM_URL###'] = $preview_url;
				
					// Select the correct image insert
				$markerArray['###CARD_IMAGE###'] = $cardData['card_image'];
				$markerArray['###SELECTION_IMAGE###'] = $cardData['selection_image'];
				$markerArray['###CARD_IMAGE_PATH###'] = htmlspecialchars($this->imgObj->tempPath);
				$fileInfo = pathinfo($this->imgObj->tempPath . $cardData['card_image']);
				if ($fileInfo['extension'] == 'jpg' || $fileInfo['extension'] == 'jpeg' || $fileInfo['extension'] == 'gif' || $fileInfo['extension'] == 'png') {
					$markerArray['###CARD_IMAGE###'] = htmlspecialchars($this->addLogo(t3lib_div::deHSCentities($cardData['card_image']), $cardData['image_width'], $cardData['image_height'], $fileInfo['extension']));
				} else {
					$subpartArray['###IMG_INSERT###'] = '';
				}
				if (!($fileInfo['extension'] == 'mov' ) ) {
					$subpartArray['###QUICKTIME_INSERT###'] = '';
				} else {
					$markerArray['###NEED_QUICKTIME_MESSAGE###'] = $this->pi_getLL('need_quicktime_message');
					$markerArray['###LOADING_VIDEO_CLIP###'] = $this->pi_getLL('loading_video_clip');
				}
				if (!($fileInfo['extension'] == 'swf' ) ) {
					$subpartArray['###SHOCKWAVE_INSERT###'] = '';
				} else {
					$markerArray['###NEED_FLASH_MESSAGE###'] = $this->pi_getLL('need_flash_message');
					$markerArray['###LOADING_FLASH_ANIMATION###'] = $this->pi_getLL('loading_flash_animation');
				}
				if ($fileInfo['extension'] == 'mov' || $fileInfo['extension'] == 'swf' ) {
					$selectionFileInfo = pathinfo($this->imgObj->tempPath . t3lib_div::deHSCentities($cardData['selection_image']));
					$markerArray['###SELECTION_IMAGE###'] = htmlspecialchars($this->addLogo(t3lib_div::deHSCentities($cardData['selection_image']), $cardData['selection_image_width'], $cardData['selection_image_height'], $selectionFileInfo['extension']));
				}
				
					// Disable music, font faces, font colors, background colors if requested
				if (($this->conf['disableMusic']) == 1 ) {
					$subpartArray['###MUSIC_SELECTOR_INSERT###'] = '';
				}
				if ((($this->conf['disableFontfaces']) == 1) && !$this->conf['graphicMess'] ) {
					$subpartArray['###ANY_FONTFACE_SELECTOR_INSERT###'] = '';
				}
				if (($this->conf['disableFontcolors']) == 1 ) {
					$subpartArray['###FONTCOLOR_SELECTOR_INSERT###'] = '';
				}
				if (($this->conf['disableBgcolors']) == 1 ) {
					$subpartArray['###BGCOLOR_SELECTOR_INSERT###'] = '';
				}
				if (($this->conf['disableSendDate']) == 1 ) {
					$subpartArray['###SEND_DATE_INSERT###'] = '';
				}
				if ((($this->conf['disableCardOptions']) == 1) && !$this->conf['graphicMess'] ) {
					$subpartArray['###CARD_OPTIONS_INSERT###'] = '';
				}
				
					// Pre-fill form data if FE user in logged in
				if ($GLOBALS['TSFE']->loginUser) {
					if (!$cardData['from_name']) {
						$cardData['from_name'] = htmlspecialchars($GLOBALS['TSFE']->fe_user->user['name']);
					}
					if (!$cardData['from_email']) {
						$cardData['from_email'] = htmlspecialchars($GLOBALS['TSFE']->fe_user->user['email']);
					}
				}

					// Set markers
				$markerArray['###SELECTION_IMAGE_WIDTH###'] = $cardData['selection_image_width'];
				$markerArray['###SELECTION_IMAGE_HEIGHT###'] = $cardData['selection_image_height'];
				$markerArray['###SELECTION_IMAGE_ALTTEXT###'] = $cardData['selection_imagealtText'];
				$markerArray['###IMAGE_WIDTH###'] = $cardData['image_width'];
				$markerArray['###IMAGE_HEIGHT###'] = $cardData['image_height'];
				$markerArray['###IMAGEALTTEXT###'] = $cardData['cardaltText'];
				$markerArray['###CARD_CAPTION###'] = $cardData['card_caption'];
				if($this->conf['doNotShowCardCaptions'] && $this->conf['doNotShowCardCaptions'] != '0') {
					$markerArray['###CARD_CAPTION_PRESENT###'] = '';
				} else {
					$markerArray['###CARD_CAPTION_PRESENT###'] = $cardData['card_caption'];
				}
				$markerArray['###LANGUAGE###'] = $isoLanguage ? $isoLanguage : $language;
				$markerArray['###LINK_PID###'] = $cardData['link_pid'];
				$markerArray['###TO_WHO_PROMPT###'] = $this->pi_getLL('to_who_prompt');
				$markerArray['###TO_NAME_LABEL###'] = $this->pi_getLL('to_name_label');
				$markerArray['###TO_NAME###'] = $cardData['to_name'];
				$markerArray['###TO_EMAIL_LABEL###'] = $this->pi_getLL('to_email_label');
				$markerArray['###TO_EMAIL###'] = $cardData['to_email'];
				$markerArray['###FROM_WHO_PROMPT###'] = $this->pi_getLL('from_who_prompt');
				$markerArray['###FROM_NAME_LABEL###'] = $this->pi_getLL('from_name_label');
				$markerArray['###FROM_NAME###'] = $cardData['from_name'];
				$markerArray['###FROM_EMAIL_LABEL###'] = $this->pi_getLL('from_email_label');
				$markerArray['###FROM_EMAIL###'] = $cardData['from_email'];
				$markerArray['###SEND_DATE_PROMPT###'] = $this->pi_getLL('send_date_prompt');
				$markerArray['###DAY_LABEL###'] = $this->pi_getLL('day_label');
				$markerArray['###CARD_SEND_DAY_SELECTOR###'] = $this->dropDownSelector($cardData['day'], $days, $days, 1);
				$markerArray['###MONTH_LABEL###'] = $this->pi_getLL('month_label');
				$markerArray['###CARD_SEND_MONTH_SELECTOR###'] = $this->dropDownSelector($cardData['month'], $month_names, $months, 1);
				$markerArray['###YEAR_LABEL###'] = $this->pi_getLL('year_label');
				$markerArray['###CARD_SEND_YEAR_SELECTOR###'] = $this->dropDownSelector($cardData['year'], $years, $years, 1);
				$markerArray['###CARD_OPTIONS_PROMPT###'] = $this->pi_getLL('card_options_prompt');
				$markerArray['###CARD_BGCOLOR_LABEL###'] = $this->pi_getLL('card_bgColor_label');
				$markerArray['###BGCOLOR_SELECTOR###'] = $this->colorSelector('tx_srsendcard_pi1[bgcolor]', $cardData['bgcolor'], $bgcolor_values);
				$markerArray['###CARD_FONTCOLOR_LABEL###'] = $this->pi_getLL('card_fontColor_label');
				$markerArray['###FONTCOLOR_SELECTOR###'] = $this->colorSelector('tx_srsendcard_pi1[fontcolor]', $cardData['fontcolor'], $fontcolor_values);
				$markerArray['###CARD_FONT_LABEL###'] = $this->pi_getLL('card_font_label');
				$markerArray['###FONTFACE_SELECTOR###'] = $this->dropDownSelector($cardData['fontface'], $fontface_labels, $fontface_values);
				if ($this->conf['graphicMess'] ) {
					$subpartArray['###FONTFACE_SELECTOR_INSERT###'] = '';
					$fontSelector = $this->fontFileSelector('tx_srsendcard_pi1[fontfile]', $cardData['fontfile'], $fontfile_values, $fontfile_labels, $fontsize_values, $cardData['bgcolor'], $cardData['fontcolor']);
					$markerArray['###FONTFILE_SELECTOR###'] = $fontSelector;
					$markerArray['###MESSAGE_LINK_INSTRUCTION###'] = '';
				} else {
					$markerArray['###FONTFILE_SELECTOR###'] = '';
					$markerArray['###MESSAGE_LINK_INSTRUCTION###'] = $this->pi_getLL('message_link_instruction');
				}
				$markerArray['###MUSIC_PROMPT###'] = $this->pi_getLL('music_prompt');
				$markerArray['###CARD_MUSIC_SELECTOR###'] = $this->dropDownSelector($cardData['card_music'], $card_music_labels, $card_music_values);
				$markerArray['###MESSAGE_PROMPT###'] = $this->pi_getLL('message_prompt');
				$markerArray['###CARD_TITLE_LABEL###'] = $this->pi_getLL('card_title_label');
				$markerArray['###CARD_TITLE###'] = $cardData['card_title'];
				$markerArray['###CARD_MESSAGE_LABEL###'] = $this->pi_getLL('card_message_label');
				$markerArray['###CARD_MESSAGE###'] = $cardData['card_message'];
				$markerArray['###CARD_SIGNATURE_LABEL###'] = $this->pi_getLL('card_signature_label');
				$markerArray['###CARD_SIGNATURE###'] = $cardData['card_signature'];
				$markerArray['###NOTIFY_PROMPT###'] = $this->pi_getLL('notify_prompt');
				$markerArray['###CARD_DELIVERY_NOTIFY###'] = ($cardData['card_delivery_notify']) ? 'checked="checked"' :'';
				$markerArray['###PREVIEW_BUTTON_LABEL###'] = $this->pi_getLL('preview_button_label');

					// Select error messsage inserts
				$markerArray['###MISSING_TO_NAME###'] = $this->pi_getLL('missing_to_name');
				$markerArray['###INVALID_TO_EMAIL###'] = $this->pi_getLL('invalid_to_email');
				$markerArray['###MISSING_FROM_NAME###'] = $this->pi_getLL('missing_from_name');
				$markerArray['###INVALID_FROM_EMAIL###'] = $this->pi_getLL('invalid_from_email');
				$markerArray['###MISSING_CARD_MESSAGE###'] = $this->pi_getLL('missing_card_message');
				if (!(isset($missing_to_name)) ) {
					$subpartArray['###ERROR_MSG_TO_NAME###'] = '';
				}
				if (!(isset($invalid_to_email)) ) {
					$subpartArray['###ERROR_MSG_TO_EMAIL###'] = '';
				}
				if (!(isset($missing_from_name)) ) {
					$subpartArray['###ERROR_MSG_FROM_NAME###'] = '';
				}
				if (!(isset($invalid_from_email)) ) {
					$subpartArray['###ERROR_MSG_FROM_EMAIL###'] = '';
				}
				if (!(isset($missing_card_message)) ) {
					$subpartArray['###ERROR_MSG_CARD_MESSAGE###'] = '';
				}

				$content = $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, $subpartArray, $wrappedSubpartArray);
				break;

			case 'preview':
				/*
				* Preview the card
				*/

				$markerArray['###FORM_URL###'] = $this->form_url;

				if (($this->conf['disableSendDate']) == 1 ) {
					$card_send_time = time() ;
					$cardData['day'] = intval(date('d', $card_send_time));
					$cardData['month'] = intval(date('m', $card_send_time));
					$cardData['year'] = intval(date('Y', $card_send_time));
				} else {
					$card_send_time = mktime(0, 0, 0, $cardData['month'], $cardData['day'], $cardData['year']);
				}

				$card_message_present = $this->linksInText($cardData['card_message']);

					// Prepare the card caption
				$link_vars = array();
				$link_unsetVars = array();
				$link_usePiVars = false;
				if ($cardData['link_pid']) {
					 $card_caption_present = '<a href="'.$site_url.htmlspecialchars($this->get_url('', $cardData['link_pid'], $link_vars, $link_unsetVars, $link_usePiVars)).'">'.$cardData['card_caption'].'</a>' ;
				} else {
					$card_caption_present = $cardData['card_caption'];
				}

					// Display preview
				$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_PREVIEW_CARD###');

					// Default image data
				$markerArray['###CARD_IMAGE_PATH###'] = htmlspecialchars($this->imgObj->tempPath);
				$markerArray['###CARD_IMAGE###'] = $cardData['card_image'];
				$markerArray['###IMAGEALTTEXT###'] = $cardData['cardaltText'];
				$markerArray['###SELECTION_IMAGE###'] = $cardData['selection_image'];
				$markerArray['###SELECTION_IMAGE_ALTTEXT###'] = $cardData['selection_imagealtText'] ? $cardData['selection_imagealtText'] : $cardData['cardaltText'];

					// Select the correct image insert
				$fileInfo = pathinfo($this->imgObj->tempPath.$cardData['card_image'] );
				if (!($fileInfo['extension'] == 'jpg' || $fileInfo['extension'] == 'jpeg' || $fileInfo['extension'] == 'gif' || $fileInfo['extension'] == 'png' ) ) {
					$subpartArray['###IMG_INSERT###'] = '';
				} else {
					if ($this->conf['logo'] && !$this->conf['disableImageScaling']) {
						$logoResource = $GLOBALS['TSFE']->tmpl->getFileName($this->conf['logo']);
						$logoImgInfo = $this->imgObj->getImageDimensions($logoResource);
						if ($logoImgInfo) {
							$img_path = $this->imgObj->tempPath;
							$fileInfo = pathinfo($img_path.$row['image'] );
							if (!$fileInfo ) {
								$img_path = 'typo3temp/';
								$fileInfo = pathinfo($img_path.$row['image'] );
							}
						}
						$markerArray['###CARD_IMAGE_PATH###'] = htmlspecialchars($img_path);
					}
				}
				if (!($fileInfo['extension'] == 'mov' ) ) {
					$subpartArray['###QUICKTIME_INSERT###'] = '';
				} else {
					$markerArray['###NEED_QUICKTIME_MESSAGE###'] = $this->pi_getLL('need_quicktime_message');
					$markerArray['###LOADING_VIDEO_CLIP###'] = $this->pi_getLL('loading_video_clip');
				}
				if (!($fileInfo['extension'] == 'swf' ) ) {
					$subpartArray['###SHOCKWAVE_INSERT###'] = '';
				} else {
					$markerArray['###NEED_FLASH_MESSAGE###'] = $this->pi_getLL('need_flash_message');
					$markerArray['###LOADING_FLASH_ANIMATION###'] = $this->pi_getLL('loading_flash_animation');
				}

				$markerArray['###LANGUAGE###'] = $isoLanguage ? $isoLanguage : $language;
				$markerArray['###SELECTION_IMAGE_WIDTH###'] = $cardData['selection_image_width'];
				$markerArray['###SELECTION_IMAGE_HEIGHT###'] = $cardData['selection_image_height'];
				$markerArray['###IMAGE_WIDTH###'] = $cardData['image_width'];
				$markerArray['###IMAGE_HEIGHT###'] = $cardData['image_height'];
				$markerArray['###CARD_CAPTION###'] = $cardData['card_caption'];
				if($this->conf['doNotShowCardCaptions'] && $this->conf['doNotShowCardCaptions'] != '0') {
					$markerArray['###CARD_CAPTION_PRESENT###'] = '';
				} else {
					$markerArray['###CARD_CAPTION_PRESENT###'] = $card_caption_present;
				}
				$markerArray['###LINK_PID###'] = $cardData['link_pid'];
				$markerArray['###TO_NAME###'] = $cardData['to_name'];
				$markerArray['###TO_EMAIL###'] = $cardData['to_email'];
				$markerArray['###TO_EMAIL_URL###'] = $this->get_url('', $cardData['to_email']);
				$markerArray['###FROM_NAME###'] = $cardData['from_name'];
				$markerArray['###FROM_EMAIL###'] = $cardData['from_email'];
				$markerArray['###FROM_EMAIL_URL###'] = $this->get_url('', $cardData['from_email']);
				$markerArray['###BGCOLOR###'] = $cardData['bgcolor'];
				$markerArray['###FONTCOLOR###'] = $cardData['fontcolor'];
				$markerArray['###FONTFACE###'] = $cardData['fontface'];
				$markerArray['###CARD_MUSIC###'] = $cardData['card_music'];
				$markerArray['###CARD_MUSIC_PATH###'] = $music_path;
				$markerArray['###CARD_TITLE###'] = $cardData['card_title'];
				$markerArray['###CARD_TITLE_PRESENT###'] = nl2br($cardData['card_title']);
				$markerArray['###CARD_MESSAGE###'] = $cardData['card_message'];
				$markerArray['###CARD_MESSAGE_PRESENT###'] = nl2br($card_message_present);
				$markerArray['###CARD_SIGNATURE###'] = $cardData['card_signature'];
				$markerArray['###CARD_SIGNATURE_PRESENT###'] = nl2br($cardData['card_signature']);
				$markerArray['###CARD_STAMP###'] = $this->cObj->fileResource($this->conf['cardStamp'], 'alt="' . htmlspecialchars($this->pi_getLL('stamp_altText')) . '" title="' . htmlspecialchars($this->pi_getLL('stamp_title')) . '"');
				if ($this->conf['graphicMess'] ) {
					$markerArray['###FONTFILE###'] = $cardData['fontfile'];
					$fontfile_values = t3lib_div::trimExplode(',' , $this->conf['graphicMessFontFiles']);
					$fontsize_values = t3lib_div::trimExplode(',' , $this->conf['graphicMessFontSizes']);
					$cardData['fontsize'] = $this->getFontSize($cardData['fontfile'], $fontfile_values, $fontsize_values);
					$markerArray['###FONTSIZE###'] = $cardData['fontsize'];
					$cardFontFile = substr(t3lib_div::getFileAbsFileName($this->conf['fontDir'].'/'.$cardData['fontfile']), strlen(PATH_site));
					$cardTitleImage = $this->makeTextImage($cardData['card_title'], $cardData['fontsize'], $cardFontFile, $cardData['fontcolor'], $this->conf['graphicMessWidth']-100, $cardData['bgcolor']);
					$markerArray['###CARD_TITLE_PRESENT###'] = '<img src="'.$cardTitleImage[3].'" style="width: ' . $cardTitleImage[0] . 'px; height: ' . $cardTitleImage[1] . 'px; border-style: none;" alt="' . $cardData['card_title'] . '" />';
					$cardMessageImage = $this->makeTextImage($cardData['card_message'], $cardData['fontsize'], $cardFontFile, $cardData['fontcolor'], $this->conf['graphicMessWidth'], $cardData['bgcolor']);
					$markerArray['###CARD_MESSAGE_PRESENT###'] = '<img src="'.$cardMessageImage[3].'" style="width: ' . $cardMessageImage[0] . 'px; height: ' . $cardMessageImage[1] . 'px; border-style: none;" alt="' . $cardData['card_message'] . '" />';
					$cardSignatureImage = $this->makeTextImage($cardData['card_signature'], $cardData['fontsize'], $cardFontFile, $cardData['fontcolor'], $this->conf['graphicMessWidth'], $cardData['bgcolor']);
					$markerArray['###CARD_SIGNATURE_PRESENT###'] = '<img src="'.$cardSignatureImage[3].'" style="width: ' . $cardSignatureImage[0] . 'px; height: ' . $cardSignatureImage[1] . 'px; border-style: none;" alt="' . $cardData['card_signature'] . htmlspecialchars(chr(10).'<'.$cardData['from_email'].'>') . '" />';
				} else {
					$markerArray['###FONTFILE###'] = '';
					$markerArray['###FONTSIZE###'] = '';
				}
				$markerArray['###CARD_DELIVERY_NOTIFY###'] = $cardData['card_delivery_notify'];
				$markerArray['###CARD_SEND_TIME###'] = $card_send_time;
				$markerArray['###CARD_SEND_DAY###'] = $cardData['day'];
				$markerArray['###CARD_SEND_MONTH###'] = $cardData['month'];
				$markerArray['###CARD_SEND_YEAR###'] = $cardData['year'];
				$markerArray['###SEND_BUTTON_LABEL###'] = $this->pi_getLL('send_button_label');
				$markerArray['###MODIFY_BUTTON_LABEL###'] = $this->pi_getLL('modify_button_label');
				if ($cardData['card_music'] == '' ) {
					$subpartArray['###MUSIC_INSERT###'] = '';
				} else {
					$markerArray['###LOADING_CARD_MUSIC###'] = $this->pi_getLL('loading_card_music');
				}
				
				if (t3lib_extMgm::isLoaded('sr_freecap') && $this->conf['useCAPTCHA']) {
					if (!is_object($freeCap)) {
						require_once(t3lib_extMgm::extPath('sr_freecap').'pi2/class.tx_srfreecap_pi2.php');
						$freeCap = t3lib_div::makeInstance('tx_srfreecap_pi2');
					}
					if ($invalid_captcha_response) {
						$markerArray['###CAPTCHA_TRY_AGAIN###'] = $this->pi_getLL('captcha_try_again');
					} else {
						$subpartArray['###ERROR_MSG_CAPTCHA###'] = '';
					}
					$markerArray = array_merge($markerArray, $freeCap->makeCaptcha());
				} else {
					$subpartArray['###CAPTCHA_INSERT###'] = '';
				}

				$content = $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, $subpartArray, $wrappedSubpartArray);

					// Dynamically generate some CSS selectors
				if($this->conf['templateStyle'] == 'css-styled') {
					$CSSSubpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_VIEW_CARD_CSS###');
					$markerArray['###FONTFAMILY###'] = $cardData['fontface'] ? 'font-family: ' . $cardData['fontface'] . ';' : '';
					$GLOBALS['TSFE']->additionalCSS['css-' . $this->pi_getClassName('preview-card')] = $this->cObj->substituteMarkerArrayCached($CSSSubpart, $markerArray, array(), array());
				}
				break;

			case 'send':
				/*
				* Send the card
				*/

					// Set create time and check when to send
				$today = getdate();
				$cardData['time_created'] = mktime($today['hours'], $today['minutes'], $today['seconds'], $today['mon'], $today['mday'], $today['year']);
				$cardData['emailsent'] = ($cardData['card_send_time'] > $cardData['time_created']) ? 0 :1;

					// Generate card id and url
				$cardData['uid'] = $this->make_cardid();
				$vars[$this->prefixId . '[cardid]'] = $cardData['uid'];
				$vars[$this->prefixId . '[cmd]'] = 'view';
				$cardData['card_url'] = $this->cObj->getTypoLink_URL($viewPID.','.$viewType, $vars);
				if (!trim($GLOBALS['TSFE']->config['config']['absRefPrefix'])) {
					$cardData['card_url'] = $site_url.$cardData['card_url'];
				}
				$cardData['pid'] = $sentCardsFolderPID;
				$cardData['language'] = $language;
				$cardData['charset'] = $TSFE->metaCharset;
				
					// Collect ip address in case we want to investigate some possile abuse
				$cardData['ip_address'] = t3lib_div::getIndpEnv('REMOTE_ADDR');
				
					// Reconcile post and db field names
				$cardData['image'] = $cardData['card_image'];
				$cardData['img_width'] = $cardData['image_width'];
				$cardData['img_height'] = $cardData['image_height'];
				$cardData['caption'] = $cardData['card_caption'];
				$cardData['towho'] = $cardData['to_name'];
				$cardData['fromwho'] = $cardData['from_name'];
				$cardData['message'] = $cardData['card_message'];
				$cardData['music'] = $cardData['card_music'];
				$cardData['notify'] = $cardData['card_delivery_notify'];
				$cardData['send_time'] = $cardData['card_send_time'];

					// Reconcile both ways...
				$tableColumns = "uid, pid, image, card_image_path, selection_image, img_width, img_height, selection_image_width, selection_image_height, caption, cardaltText, selection_imagealtText, link_pid, towho, to_email, fromwho, from_email, bgcolor, fontcolor, fontface, fontfile, fontsize, message, card_title, card_signature, music, card_url, notify, emailsent, send_time, time_created, ip_address, language, charset";
				$tableColumnsArr = t3lib_div::trimExplode(',', $tableColumns, 1);
				reset($cardData);
				while (list($key, $value) = each($cardData)) {
					if( !in_array($key, $tableColumnsArr)) {
						unset($cardData[$key]);
					}
				}
					// Insert card instance into database
				$res = $TYPO3_DB->exec_INSERTquery($this->tbl_name, $cardData);
				
					// Send email to recipient... if this is the time
				$this->notifyRecipient($cardData, 'TEMPLATE_EMAIL_CARD_SENT');
				
					// Display card sent thank you message
				$createcard_url = $site_url.$this->cObj->getTypoLink_URL($createPID);
				$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_CARD_SENT###');
				$markerArray['###CARDSENT_THANK_YOU###'] = $this->pi_getLL('cardSent_thank_you');
				$markerArray['###CARDSENT_SEND_ANOTHER###'] = $this->pi_getLL('cardSent_send_another');
				$markerArray['###FORM_URL###'] = htmlspecialchars($createcard_url);
				
				$content = $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, $subpartArray, $wrappedSubpartArray);
				break;

			case 'view':
				/*
				* View the card!
				*/
				
					// Get the card instance from the database
				$row = $this->getCard($cardData['cardid']);
				
				if ($row ) {
						// Display the card... if it is there!
					$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_VIEW_CARD###');
					$cardid = $row['uid'];
					$print_vars['cardid'] = $cardid;
					$print_vars['cmd'] = 'print';
					$print_unsetVars = array();
					$print_usePiVars = true;
					$savedLinkVars = $GLOBALS['TSFE']->linkVars;
					$linkVarsArr = t3lib_div::trimExplode('&', $GLOBALS['TSFE']->linkVars,1);
					reset($linkVarsArr);
					while (list($key, $value) = each($linkVarsArr)) {
						if( strstr($value, $this->prefixId) ) {
							unset($linkVarsArr[$key]);
						}
					}
					$GLOBALS['TSFE']->linkVars = implode('&', $linkVarsArr);
					$printcard_url = $site_url.htmlspecialchars($this->get_url('', $printPID.','.$printType, $print_vars, $print_unsetVars, $print_usePiVars));
					$create_vars['cmd'] = '';
					$create_usePiVars = 0;
					$createcard_url = $site_url.htmlspecialchars($this->get_url('', $createPID, $create_vars, $create_unsetVars, $create_usePiVars));
					$GLOBALS['TSFE']->linkVars = $savedLinkVars;

					$card_message_present = $this->linksInText($row['message']);

						// Select the correct image insert
					$img_path = $row['card_image_path'] ? $row['card_image_path'] : $this->imgObj->tempPath;
					$fileInfo = pathinfo($img_path.$row['image']);
					if (!$fileInfo) {
						$img_path = $this->imgObj->tempPath;
						$fileInfo = pathinfo($img_path.$row['image']);
						if (!$fileInfo) {
							$img_path = 'typo3temp/';
							$fileInfo = pathinfo($img_path.$row['image']);
						}
					}
					if (!($fileInfo['extension'] == 'jpg' || $fileInfo['extension'] == 'jpeg' || $fileInfo['extension'] == 'gif' || $fileInfo['extension'] == 'png' ) ) {
						$subpartArray['###IMG_INSERT###'] = '';
					} else {
						if ($this->conf['logo'] && !$this->conf['disableImageScaling']) {
							$logoResource = $GLOBALS['TSFE']->tmpl->getFileName($this->conf['logo']);
							$logoImgInfo = $this->imgObj->getImageDimensions($logoResource);
							if ($logoImgInfo) {
								$img_path = $this->imgObj->tempPath;
								$fileInfo = pathinfo($img_path.$row['image'] );
								if (!$fileInfo ) {
									$img_path = 'typo3temp/';
									$fileInfo = pathinfo($img_path.$row['image'] );
								}
							}
						}
					}
					if (!($fileInfo['extension'] == 'mov' ) ) {
						$subpartArray['###QUICKTIME_INSERT###'] = '';
					} else {
						$markerArray['###NEED_QUICKTIME_MESSAGE###'] = $this->pi_getLL('need_quicktime_message');
						$markerArray['###LOADING_VIDEO_CLIP###'] = $this->pi_getLL('loading_video_clip');
					}
					if (!($fileInfo['extension'] == 'swf' ) ) {
						$subpartArray['###SHOCKWAVE_INSERT###'] = '';
					} else {
						$markerArray['###NEED_FLASH_MESSAGE###'] = $this->pi_getLL('need_flash_message');
						$markerArray['###LOADING_FLASH_ANIMATION###'] = $this->pi_getLL('loading_flash_animation');
					}

						// Prepare the card caption
					$link_vars = array();
					$link_unsetVars = array();
					$link_usePiVars = false;
					if ($row['link_pid']) {
						 $card_caption_present = '<a href="'.$site_url.htmlspecialchars($this->get_url('', $row['link_pid'], $link_vars, $link_unsetVars, $link_usePiVars)).'">'.$row['caption'].'</a>' ;
					} else {
						$card_caption_present = $row['caption'];
					}

					$markerArray['###CARD_IMAGE###'] = htmlspecialchars($row['image']);
					$markerArray['###SELECTION_IMAGE###'] = htmlspecialchars($row['selection_image']);
					$markerArray['###CARD_IMAGE_PATH###'] = htmlspecialchars($img_path);
					$markerArray['###IMAGE_WIDTH###'] = $row['img_width'];
					$markerArray['###IMAGE_HEIGHT###'] = $row['img_height'];
					$markerArray['###SELECTION_IMAGE_WIDTH###'] = $row['selection_image_width'];
					$markerArray['###SELECTION_IMAGE_HEIGHT###'] = $row['selection_image_height'];
					$markerArray['###CARD_CAPTION###'] = htmlspecialchars($row['caption']);
					$markerArray['###IMAGEALTTEXT###'] = htmlspecialchars($row['cardaltText']);
					$markerArray['###SELECTION_IMAGE_ALTTEXT###'] = $row['selection_imagealtText'] ? htmlspecialchars($row['selection_imagealtText']) : htmlspecialchars($row['cardaltText']);
					if($this->conf['doNotShowCardCaptions'] && $this->conf['doNotShowCardCaptions'] != '0') {
						$markerArray['###CARD_CAPTION_PRESENT###'] = '';
					} else {
						$markerArray['###CARD_CAPTION_PRESENT###'] = $card_caption_present;
					}
					$markerArray['###LANGUAGE###'] = $isoLanguage ? $isoLanguage : $language;
					$markerArray['###TO_NAME###'] = $row['towho'];
					$markerArray['###TO_EMAIL###'] = $row['to_email'];
					$markerArray['###TO_EMAIL_URL###'] = $this->get_url('', $row['to_email']);
					$markerArray['###FROM_NAME###'] = $row['fromwho'];
					$markerArray['###FROM_EMAIL###'] = $row['from_email'];
					$markerArray['###FROM_EMAIL_URL###'] = $this->get_url('', $row['from_email']);
					$markerArray['###BGCOLOR###'] = htmlspecialchars($row['bgcolor']);
					$markerArray['###FONTCOLOR###'] = htmlspecialchars($row['fontcolor']);
					$markerArray['###FONTFACE###'] = htmlspecialchars($row['fontface']);
					$markerArray['###CARD_MUSIC###'] = htmlspecialchars($row['music']);
					$markerArray['###CARD_MUSIC_PATH###'] = $music_path;
					$markerArray['###CARD_TITLE###'] = nl2br($row['card_title']);
					$markerArray['###CARD_MESSAGE###'] = nl2br($card_message_present);
					$markerArray['###CARD_SIGNATURE###'] = nl2br($row['card_signature']);
					if ($row['fontfile'] ) {
						$cardFontFile = substr(t3lib_div::getFileAbsFileName($this->conf['fontDir'].'/'.$row['fontfile']), strlen(PATH_site));
						$cardTitleImage = $this->makeTextImage($row['card_title'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth']-100, $row['bgcolor']);
						$markerArray['###CARD_TITLE###'] = '<img src="'.$cardTitleImage[3].'" style="width: ' . $cardTitleImage[0] . 'px; height: ' . $cardTitleImage[1] . 'px;" alt="' . htmlspecialchars($row['card_title']) . '" />';
						$cardMessageImage = $this->makeTextImage($row['message'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth'], $row['bgcolor']);
						$markerArray['###CARD_MESSAGE###'] = '<img src="'.$cardMessageImage[3].'" style="width: ' . $cardMessageImage[0] . 'px; height: ' . $cardMessageImage[1] . 'px;" alt="' . htmlspecialchars($card_message_present) . '" />';
						$cardSignatureImage = $this->makeTextImage($row['card_signature'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth'], $row['bgcolor']);
						$markerArray['###CARD_SIGNATURE###'] = '<img src="'.$cardSignatureImage[3].'" style="width: ' . $cardSignatureImage[0] . 'px; height: ' . $cardSignatureImage[1] . 'px;" alt="' . htmlspecialchars($row['card_signature']) . htmlspecialchars(chr(10).'<'.$row['from_email'].'>'). '" />';
					}
					$markerArray['###CARD_STAMP###'] = $this->cObj->fileResource($this->conf['cardStamp'], 'alt="' . htmlspecialchars($this->pi_getLL('stamp_altText')) . '" title="' . htmlspecialchars($this->pi_getLL('stamp_title')) . '"');
					$markerArray['###PRINTCARD_PROMPT###'] = $this->pi_getLL('printCard_prompt');
					$markerArray['###PRINT_CARD_URL###'] = htmlspecialchars($printcard_url);
					$markerArray['###PRINT_ICON###'] = $this->cObj->fileResource($this->conf['printIcon']);
					$markerArray['###PRINT_WINDOW_PARAMS###'] = $this->conf['printWindowParams'];
					$markerArray['###SENDCARD_PROMPT###'] = $this->pi_getLL('sendCard_prompt');
					$markerArray['###FORM_URL###'] = htmlspecialchars($createcard_url);
					if ($row['music'] == '' ) {
						$subpartArray['###MUSIC_INSERT###'] = '';
					} else {
						$markerArray['###LOADING_CARD_MUSIC###'] = $this->pi_getLL('loading_card_music');
					}
					
					$content = $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, $subpartArray, $wrappedSubpartArray);
					
						// Dynamically generate some CSS selectors
					if($this->conf['templateStyle'] == 'css-styled') {
						$CSSSubpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_VIEW_CARD_CSS###');
						$markerArray['###FONTFAMILY###'] = $row['fontface'] ? 'font-family: ' . $row['fontface'] . ';' : '';
						$GLOBALS['TSFE']->additionalCSS['css-' . $this->pi_getClassName('view-card')] = $this->cObj->substituteMarkerArrayCached($CSSSubpart, $markerArray, array(), array());
					}
						// Notify the sender that the card was viewed
					$this->notifySender($row, 'TEMPLATE_EMAIL_VIEWED');

						// Cleanup old card instances
					$this->cleanupOldCards();
				} else {
					$content = $this->cardNotFound();
				}
				break;

			case 'print':
				/*
				* Display printer-friendly card
				*/
				$content = $this->printCard($cardData['cardid']);
				break;

			default:
				/*
				* Really? Nothing we can do
				*/
				$content = $this->cardNotFound();
				break;
		}
		
		return $this->pi_wrapInBaseClass($content);
	}  // end of function main

	/**
	 * Display card.
	 *
	 * @param	string		uid of card instance
	 * @return	string		content to be displayed
	 */
	function viewCard($uid) {
			// Get the card instance from the database
		$row = $this->getCard($uid);

			// Display the card... if it is there!
		if($row) {
			
		} else {
			return $this->cardNotFound();
		}
	}
	
	/**
	 * Cleanup old card instances.
	 *
	 * @return	void
	 */
	function cleanupOldCards() {
		global $TYPO3_DB;
		
		$whereClause = 'send_time < ' . mktime(0, 0, 0, $this->conf['oldMonth'], $this->conf['oldDay'], $this->conf['oldYear']);
		$fields_values = array();
		$fields_values['deleted'] = '1';
		$res = $TYPO3_DB->exec_UPDATEquery(
			$this->tbl_name,
			$whereClause,
			$fields_values
			);
	}
	
	/**
	 * Display printer-friendly card.
	 *
	 * @param	string		uid of card instance
	 * @return	string		content to be displayed
	 */
	function printCard($uid) {

			// Get the card instance from the database
		$row = $this->getCard($uid);

			// Display the card... if it is there!
		if ($row ) {
			$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_PRINT_CARD###');
			$markerArray = array();
			$subpartArray = array();

			$card_message_present = $this->linksInText($row['message']);
			$card_image_present = $row['image'];

			$img_path = $row['card_image_path'] ? $row['card_image_path'] : $this->imgObj->tempPath;
			if ($this->conf['logo'] && !$this->conf['disableImageScaling']) {
				$logoResource = $GLOBALS['TSFE']->tmpl->getFileName($this->conf['logo']);
				$logoImgInfo = $this->imgObj->getImageDimensions($logoResource);
				if ($logoImgInfo) {
					$img_path = $this->imgObj->tempPath;
					$imgInfo = $this->imgObj->getImageDimensions($img_path.$row['image'] );
					if (!$imgInfo) {
						$img_path = 'typo3temp/';
					}
				}
			}
			$markerArray['###IMAGEALTTEXT###'] = htmlspecialchars($row['cardaltText']);
			$img_width = $row['img_width'];
			$img_height = $row['img_height'];
			$fileInfo = pathinfo($img_path.$row['image']);
			if (!($fileInfo['extension'] == 'jpg' || $fileInfo['extension'] == 'jpeg' || $fileInfo['extension'] == 'gif' || $fileInfo['extension'] == 'png' ) || $this->conf['useAlternateImageOnPrint']) {
				if ($row['selection_image'] ) {
					$card_image_present = $row['selection_image'];
					$selectImgInfo = $this->imgObj->getImageDimensions($img_path.$row['selection_image'] );
					if (!$selectImgInfo) {
						$img_path = $this->imgObj->tempPath;
						$selectImgInfo = $this->imgObj->getImageDimensions($img_path.$row['selection_image'] );
						if (!$selectImgInfo) {
							$img_path = 'typo3temp/';
						}
					}
					$img_width = $row['selection_image_width'];
					$img_height = $row['selection_image_height'];
					$markerArray['###IMAGEALTTEXT###'] = $row['selection_imagealtText'] ? htmlspecialchars($row['selection_imagealtText']) : htmlspecialchars($row['cardaltText']);
				} elseif ( !($fileInfo['extension'] == 'jpg' || $fileInfo['extension'] == 'jpeg' || $fileInfo['extension'] == 'gif' || $fileInfo['extension'] == 'png' ) ) {
					$subpartArray['###IMG_INSERT###'] = '';
				}
			}

			$markerArray['###CARD_IMAGE###'] = htmlspecialchars($card_image_present);
			$markerArray['###CARD_IMAGE_PATH###'] = $img_path;
			$markerArray['###IMAGE_WIDTH###'] = $img_width;
			$markerArray['###IMAGE_HEIGHT###'] = $img_height;
			if($this->conf['doNotShowCardCaptions'] && $this->conf['doNotShowCardCaptions'] != '0') {
				$markerArray['###CARD_CAPTION###'] = '';
			} else {
				$markerArray['###CARD_CAPTION###'] = $row['caption'];
			}
			$markerArray['###TO_NAME###'] = $row['towho'];
			$markerArray['###TO_EMAIL###'] = $row['to_email'];
			$markerArray['###TO_EMAIL_URL###'] = $this->get_url('', $row['to_email']);
			$markerArray['###FROM_NAME###'] = $row['fromwho'];
			$markerArray['###FROM_EMAIL###'] = $row['from_email'];
			$markerArray['###FROM_EMAIL_URL###'] = $this->get_url('', $row['from_email']);
			$markerArray['###BGCOLOR###'] = htmlspecialchars($row['bgcolor']);
			$markerArray['###FONTCOLOR###'] = htmlspecialchars($row['fontcolor']);
			$markerArray['###FONTFACE###'] = htmlspecialchars($row['fontface']);
			$markerArray['###CARD_TITLE###'] = nl2br($row['card_title']);
			$markerArray['###CARD_MESSAGE###'] = nl2br($card_message_present);
			$markerArray['###CARD_SIGNATURE###'] = nl2br($row['card_signature']);

			if ($row['fontfile'] && $this->conf['useGraphicalMessageEvenOnCardPrint']) {
				$cardFontFile = substr(t3lib_div::getFileAbsFileName($this->conf['fontDir'].'/'.$row['fontfile']), strlen(PATH_site));
				$cardTitleImage = $this->makeTextImage($row['card_title'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth']-100, $row['bgcolor']);
				$markerArray['###CARD_TITLE###'] = '<img src="'.$cardTitleImage[3].'" style="width: ' . $cardTitleImage[0] . 'px; height: ' . $cardTitleImage[1] . 'px;" alt="' . htmlspecialchars($row['card_title']) . '" />';
				$cardMessageImage = $this->makeTextImage($row['message'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth'], $row['bgcolor']);
				$markerArray['###CARD_MESSAGE###'] = '<img src="'.$cardMessageImage[3].'" style="width: ' . $cardMessageImage[0] . 'px; height: ' . $cardMessageImage[1] . 'px;" alt="' . htmlspecialchars($card_message_present) . '" />';
				$cardSignatureImage = $this->makeTextImage($row['card_signature'], $row['fontsize'], $cardFontFile, $row['fontcolor'], $this->conf['graphicMessWidth'], $row['bgcolor']);
				$markerArray['###CARD_SIGNATURE###'] = '<img src="'.$cardSignatureImage[3].'" style="width: ' . $cardSignatureImage[0] . 'px; height: ' . $cardSignatureImage[1] . 'px;" alt="' . htmlspecialchars($row['card_signature']) . '" />';
			}

			$markerArray['###CARD_STAMP###'] = $this->cObj->fileResource($this->conf['cardStamp'], 'alt="' . htmlspecialchars($this->pi_getLL('stamp_altText')) . '" title="' . htmlspecialchars($this->pi_getLL('stamp_title')) . '"');
			
				// Dynamically generate some CSS selectors
			if($this->conf['templateStyle'] == 'css-styled') {
				$CSSSubpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_PRINT_CARD_CSS###');
				$markerArray['###FONTFAMILY###'] = $row['fontface'] ? 'font-family: ' . $row['fontface'] . ';' : '';
				$GLOBALS['TSFE']->additionalCSS['css-' . $this->pi_getClassName('print-card')] = $this->cObj->substituteMarkerArrayCached($CSSSubpart, $markerArray, array(), array());
			}
				
			return $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, $subpartArray, array());
		} else {
			return $this->cardNotFound();
		}
	}
	
	/**
	 * Get the card instance.
	 *
	 * @param	string		uid of card instance
	 * @return	array		table row of card instance
	 */
	
	function getCard($uid) {
		global $TYPO3_DB;
		
		$res = $TYPO3_DB->exec_SELECTquery(
			'*',
			$this->tbl_name,
			'uid=' . $TYPO3_DB->fullQuoteStr($uid, $this->tbl_name)
			);
		return $TYPO3_DB->sql_fetch_assoc($res);
	}
	
	/**
	 * Display card not found message.
	 *
	 * @return	string		content to be displayed
	 */
	function cardNotFound() {
		$markerArray = array();
		$subpart = $this->cObj->getSubpart($this->templateCode, '###TEMPLATE_CARD_NOT_FOUND###');
		$markerArray['###CARDNOTFOUND_SORRY###'] = $this->pi_getLL('cardNotFound_sorry');
		$markerArray['###CARDNOTFOUND_INSTRUCTIONS###'] = $this->pi_getLL('cardNotFound_instructions');
		return $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, array(), array());
	}

	/**
	 * Notify recipient that he has a card.
	 *
	 * @param	array		$row: card instance row
	 * @param	string		$emailTemplateKey: key to compose the HTML template name
	 * @return	void
	 */
	function notifyRecipient($row,$emailTemplateKey) {
		
		if ($row['emailsent']) {
			$emailData = array();
			$emailData['from_name'] = $row['fromwho'];
			$emailData['from_email'] = $row['from_email'];
			$emailData['to_name'] = $row['towho'];
			$emailData['to_email'] = $row['to_email'];
			$emailData['card_url'] = $row['card_url'];
			$emailData['date'] = $this->date;
			$this->sendEmail($emailData, $emailTemplateKey);
		}
	}

	/**
	 * Notify sender that the card was viewed.
	 *
	 * @param	array		$row: card instance row
	 * @param	string		$emailTemplateKey: key to compose the HTML template name
	 * @return	void
	 */
	function notifySender($row,$emailTemplateKey) {
		global $TYPO3_DB;
		
		if ($row['notify'] == 1) {
			
			$emailData = array();
			$emailData['from_name'] = $row['towho'];
			$emailData['from_email'] = $row['to_email'];
			$emailData['to_name'] = $row['fromwho'];
			$emailData['to_email'] = $row['from_email'];
			$emailData['card_url'] = $row['card_url'];
			$emailData['date'] = $this->date;
			$this->sendEmail($emailData, $emailTemplateKey);
			
			$fields_values = array();
			$fields_values['notify'] = '0';
			$res = $TYPO3_DB->exec_UPDATEquery(
				$this->tbl_name,
				'uid=' . $TYPO3_DB->fullQuoteStr($row['uid'], $this->tbl_name),
				$fields_values
				);
		}
	}
	
	/**
	 * Send an email message, invokink htmlmail class
	 *
	 * @param	array		$emailData: email data variables
	 * @param	string		$emailTemplateKey: key to compose the HTML template name
	 * @return	void
	 */
	function sendEmail($emailData, $emailTemplateKey) {
		global $TSFE,$TYPO3_CONF_VARS;
		
			// Get templates
		$subpart = $this->cObj->getSubpart($this->templateCode, '###'.$emailTemplateKey.'###');
		if ($this->conf['enableHTMLMail']) {
			$HTMLSubpart = $this->cObj->getSubpart($this->templateCode, '###'.$emailTemplateKey.'_HTML'.'###');
		}
		
			// Set markers
		$markerArray = array();
		$markerArray['###EMAIL_CARDSENT_SUBJECT1###'] = $this->pi_getLL('email_cardSent_subject1');
		$markerArray['###EMAIL_CARDSENT_SUBJECT2###'] = $this->pi_getLL('email_cardSent_subject2');
		$markerArray['###EMAIL_CARDSENT_TITLE1###'] = $this->pi_getLL('email_cardSent_title1');
		$markerArray['###EMAIL_CARDSENT_TITLE2###'] = $this->pi_getLL('email_cardSent_title2');
		$markerArray['###EMAIL_CARDSENT_TEXT1###'] = $this->pi_getLL('email_cardSent_text1');
		$markerArray['###EMAIL_CARDSENT_TEXT2###'] = $this->pi_getLL('email_cardSent_text2');
		$markerArray['###EMAIL_CARDSENT_TEXT3###'] = $this->pi_getLL('email_cardSent_text3');
		$markerArray['###EMAIL_CARDSENT_TEXT4###'] = $this->pi_getLL('email_cardSent_text4');
		$markerArray['###EMAIL_CARDVIEWED_SUBJECT1###'] = $this->pi_getLL('email_cardViewed_subject1');
		$markerArray['###EMAIL_CARDVIEWED_SUBJECT2###'] = $this->pi_getLL('email_cardViewed_subject2');
		$markerArray['###EMAIL_CARDVIEWED_TITLE1###'] = $this->pi_getLL('email_cardViewed_title1');
		$markerArray['###EMAIL_CARDVIEWED_TITLE2###'] = $this->pi_getLL('email_cardViewed_title2');
		$markerArray['###EMAIL_CARDVIEWED_TEXT1###'] = $this->pi_getLL('email_cardViewed_text1');
		$markerArray['###EMAIL_CARDVIEWED_TEXT2###'] = $this->pi_getLL('email_cardViewed_text2');
		$markerArray['###EMAIL_CARDVIEWED_TEXT3###'] = $this->pi_getLL('email_cardViewed_text3');
		$markerArray['###EMAIL_SIGNATURE###'] = $this->pi_getLL('email_signature');
		$markerArray['###TO_NAME###'] = $emailData['to_name'];
		$markerArray['###TO_EMAIL###'] = $emailData['to_email'];
		$markerArray['###FROM_EMAIL###'] = $emailData['from_email'];
		$markerArray['###FROM_NAME###'] = $emailData['from_name'];
		$markerArray['###CARD_URL###'] = $emailData['card_url'];
		$markerArray['###DATE###'] = $emailData['date'];
		$markerArray['###SITE_NAME###'] = $this->conf['siteName'];
		$markerArray['###SITE_WWW###'] = t3lib_div::getIndpEnv('TYPO3_HOST_ONLY');
		$markerArray['###SITE_URL###'] = $this->siteUrl;
		$markerArray['###SITE_EMAIL###'] = $this->conf['siteEmail'];
		
			// Substitute in template
		$content = $this->cObj->substituteMarkerArrayCached($subpart, $markerArray, array(), array());
		if ($this->conf['enableHTMLMail']) {
			$content = $TSFE->csConvObj->conv($content, $TSFE->renderCharset, $TSFE->metaCharset);
			$HTMLContent = $this->cObj->substituteMarkerArrayCached($HTMLSubpart, $markerArray, $subpartArray, $wrappedSubpartArray);
			$HTMLContent = $TSFE->csConvObj->conv($HTMLContent, $TSFE->renderCharset, $TSFE->metaCharset,1);
		} else {
			$content = $TSFE->csConvObj->conv($content, $TSFE->renderCharset, ($TSFE->config['config']['notification_email_charset'] ? $TSFE->config['config']['notification_email_charset'] : ($TYPO3_CONF_VARS['BE']['forceCharset'] ? $TYPO3_CONF_VARS['BE']['forceCharset'] : 'iso-8859-1')));
		}
		
			// Set subject, content and headers
		$headers = array();
		$headers[] = 'FROM: '.$this->conf['siteName'].' <'.$this->conf['siteEmail'].'>';
		list($subject, $plain_message) = explode(chr(10), trim($content), 2);
		if ($this->conf['enableHTMLMail']) {
			$parts = spliti('<title>|</title>', $HTMLContent, 3);
			$subject = trim($parts[1]) ? strip_tags(trim($parts[1])) : 'Send-A-Card message';
		}
		
		if ($this->conf['enableHTMLMail']) {
			
			$Typo3_htmlmail = t3lib_div::makeInstance('t3lib_htmlmail');
			$Typo3_htmlmail->start();
// t3lib_htmlmail checks $TSFE->config['metaCharset'] instead of $TSFE->metaCharset
			$Typo3_htmlmail->charset = $TSFE->metaCharset;
			$Typo3_htmlmail->useQuotedPrintable();
//
			if ($this->conf['forceBase64Encoding']) {
				$Typo3_htmlmail->useBase64();
			}
			$Typo3_htmlmail->mailer = 'Typo3 HTMLMail';
			$Typo3_htmlmail->subject = $subject;
			$Typo3_htmlmail->from_email = $this->conf['siteEmail'];
			$Typo3_htmlmail->from_name = $this->conf['siteName'];
			$Typo3_htmlmail->replyto_email = $this->conf['siteEmail'];
			$Typo3_htmlmail->replyto_name = $this->conf['siteName'];
			$Typo3_htmlmail->organisation = '';
			$Typo3_htmlmail->priority = 3;
				// HTML
			if (trim($HTMLContent)) {
				$Typo3_htmlmail->theParts['html']['content'] = $HTMLContent;
				$Typo3_htmlmail->theParts['html']['path'] = '';
				$Typo3_htmlmail->extractMediaLinks();
				$Typo3_htmlmail->extractHyperLinks();
				$Typo3_htmlmail->fetchHTMLMedia();
				$Typo3_htmlmail->substMediaNamesInHTML(0); // 0 = relative
				$Typo3_htmlmail->substHREFsInHTML();
				$Typo3_htmlmail->setHTML($Typo3_htmlmail->encodeMsg($Typo3_htmlmail->theParts['html']['content']));
			}

				// PLAIN
			$Typo3_htmlmail->addPlain($plain_message);
			$Typo3_htmlmail->setHeaders();
			$Typo3_htmlmail->setContent();
			$Typo3_htmlmail->setRecipient($emailData['to_email']);
			$Typo3_htmlmail->sendtheMail();
		} else {
			$this->cObj->sendNotifyEmail($content, $emailData['to_email'], '', $this->conf['siteEmail'], $this->conf['siteName']);
		}
	}

	/**
	 * Generates the HTML option tags of a dropdown selector box
	 *
	 * @param	string		$valueSelected: pre-selected value
	 * @param	array		$texts: array of labels to display
	 * @param	array		$values: array of values
	 * @param	integer		$start: index to start from in $texts and $values arrays
	 * @return	string		the generated HTML option tags of the dropdown selector box
	 */
	function dropDownSelector($valueSelected, $texts, $values, $start = 0) {
		
		$selector = chr(10);
		for($currentValue = $start; $currentValue < (count($values) + $start) ; $currentValue++) {
			$selector .= '<option value="' . $values[$currentValue] . '"';
			if ($valueSelected == $values[$currentValue]) {
				$selector .= ' selected="selected"';
			}
			$selector .= '>' . $texts[$currentValue] . '</option>' . chr(10);
		}
		return $selector;
	}
	
	/**
	 * Generates an image selector
	 *
	 * @param	array		$res: result array of card table rows
	 * @return	string		the generated HTML image selector
	 */
	function imageSelector($res) {
		global $TYPO3_DB,$TYPO3_CONF_VARS;
		
		$maxCol = ($this->conf['maxCol'] < 1) ? 1 : $this->conf['maxCol'];
		$colCount = 0;
		$selectorRow = '';
		$selectorRowHeight = 0;
		$thum_vars = array();
		$thum_vars['cmd'] = 'prompt';
		$thum_unsetVars = array();
		$thum_usePiVars = false;
		
		$selector .= (($this->conf['templateStyle'] == 'css-styled') ? '<div class="' . $this->pi_getClassName('image-selector') . '">': '<table cellspacing="5" cellpadding="0" width="100%" border="0"><tr>') . chr(10);
		while ($row = $TYPO3_DB->sql_fetch_assoc($res)) {
			
			$row = $this->pidRecord->getRecordOverlay($this->card_tbl_name, $row, $this->pidRecord->sys_language_uid);
			if ($TYPO3_CONF_VARS['EXTCONF'][$this->extKey]['keepCardLanguageOverlay']) {
				$row = $this->getCardOverlay($row);
			}
			
			if ($colCount++ >= $maxCol) {
				$selector .= ($this->conf['templateStyle'] == 'css-styled') ? str_replace('###SELECTOR_ROW_HEIGHT###', $selectorRowHeight, $selectorRow) : chr(10) . '</tr><tr>' . chr(10);
				$colCount = 1;
				$selectorRow = '';
				$selectorRowHeight = 0;
			}
			
			$thumbnail_image = $row['image'];
			$thum_vars['card_caption'] = htmlspecialchars($row['card']);
			$thum_vars['cardaltText'] = $row['cardaltText'] ? htmlspecialchars($row['cardaltText']) : htmlspecialchars($row['card']);
			$cardAltText = $thum_vars['cardaltText'];
			if ($row['link_pid']) {
				$thum_vars['link_pid'] = $row['link_pid'];
				unset($thum_unsetVars['link_pid']);
			} else {
				$thum_unsetVars['link_pid'] = 'link_pid';
			}

				// Set main image
			$thum_vars['card_image'] = $row['image'];
			$thum_vars['card_image_path'] = $this->imgObj->tempPath;
			$thum_vars['image_width'] = $row['img_width'];
			$thum_vars['image_height'] = $row['img_height'];
			$imgInfo = $this->imgObj->getImageDimensions($this->imgObj->tempPath . $row['image'] );
			if ($imgInfo) {
				$thum_vars['image_width'] = $thum_vars['image_width'] ? $thum_vars['image_width'] : $imgInfo[0];
				$thum_vars['image_height'] = $thum_vars['image_height'] ? $thum_vars['image_height'] : $imgInfo[1];
			}
			$thum_vars['image_width'] = $this->conf['imageBigWidth'] ? min($this->conf['imageBigWidth'], $thum_vars['image_width']) : $thum_vars['image_width'];
			$thum_vars['image_height'] = $this->conf['imageBigHeight'] ? min($this->conf['imageBigHeight'], $thum_vars['image_height']) : $thum_vars['image_height'];
			$fileInfo = pathinfo($this->imgObj->tempPath . $row['image']);
			if (!($fileInfo['extension'] == 'mov') && !($fileInfo['extension'] == 'swf') && !($this->conf['disableImageScaling'])) {
				$mainImgInfo_scaled = $this->imgObj->imageMagickConvert($thum_vars['card_image_path'] . $thum_vars['card_image'], '',
					$thum_vars['image_width'].'m', $thum_vars['image_height'].'m', '', '', '', 1 );
				$thum_vars['image_width'] = $mainImgInfo_scaled[0];
				$thum_vars['image_height'] = $mainImgInfo_scaled[1];
				$thum_vars['card_image_path'] = $this->imgObj->tempPath;
				$thum_vars['card_image'] = substr($mainImgInfo_scaled[3], strlen($this->imgObj->tempPath));
			}
			
				// Set alternate image
			$selectImgInfo = $this->imgObj->getImageDimensions($this->imgObj->tempPath . $row['selection_image']);
			if ($selectImgInfo) {
				$thum_vars['selection_image'] = $row['selection_image'];
				$thum_vars['selection_image_width'] = $row['selection_image_width'];
				$thum_vars['selection_image_height'] = $row['selection_image_height'];
				$thum_vars['selection_imagealtText'] = htmlspecialchars($row['selection_imagealtText']);
				unset($thum_unsetVars['selection_image']);
				unset($thum_unsetVars['selection_image_width']);
				unset($thum_unsetVars['selection_image_height']);
				unset($thum_unsetVars['selection_imagealtText']);
			} else {
				$thum_vars['selection_image'] = '';
				$thum_vars['selection_image_width'] = 0;
				$thum_vars['selection_image_height'] = 0;
				$thum_vars['selection_imagealtText'] = '';
				$thum_unsetVars['selection_image'] = 'selection_image';
				$thum_unsetVars['selection_image_width'] = 'selection_image_width';
				$thum_unsetVars['selection_image_height'] = 'selection_image_height';
				$thum_unsetVars['selection_imagealtText'] = 'selection_imagealtText';
			}
			if ($selectImgInfo) {
				$thum_vars['selection_image_width'] = $thum_vars['selection_image_width'] ? $thum_vars['selection_image_width'] : $selectImgInfo[0];
				$thum_vars['selection_image_height'] = $thum_vars['selection_image_height'] ? $thum_vars['selection_image_height'] : $selectImgInfo[1];
				$thum_vars['selection_image_width'] = $this->conf['imageBigWidth'] ? min($thum_vars['selection_image_width'], $this->conf['imageBigWidth']) : $thum_vars['selection_image_width'];
				$thum_vars['selection_image_height'] = $this->conf['imageBigHeight'] ? min($thum_vars['selection_image_height'], $this->conf['imageBigHeight']) : $thum_vars['selection_image_height'];
				if (!$this->conf['disableImageScaling'] ) {
					$selectImgInfo_scaled = $this->imgObj->imageMagickConvert($this->imgObj->tempPath . $thum_vars['selection_image'], '',
						$thum_vars['selection_image_width'].'m', $thum_vars['selection_image_height'].'m', '', '', '', 1 );
					$thum_vars['selection_image_width'] = $selectImgInfo_scaled[0];
					$thum_vars['selection_image_height'] = $selectImgInfo_scaled[1];
					$thum_vars['selection_image'] = substr($selectImgInfo_scaled[3], strlen($this->imgObj->tempPath));
				}
				$cardAltText = $thum_vars['selection_imagealtText'] ? $thum_vars['selection_imagealtText'] : $cardAltText;
			}
			
				// Set video clip
			$fileInfo = pathinfo($this->imgObj->tempPath . $row['image']);
			if (!(($fileInfo['extension'] == 'mov' || $fileInfo['extension'] == 'swf') && !($selectImgInfo)) ) {
				if ($fileInfo['extension'] == 'mov') {
					$thumbnail_image = $row['selection_image'];
					$videoClip = ($this->conf['templateStyle'] == 'css-styled') ? '<br /><span' . $this-> pi_classParam('video-clip-highlight') . '>'.$this->pi_getLL('video_clip_highlight').'</span>' : '<br /><font color="'.$this->cObj->stdWrap($this->conf['color1'], $this->conf['color1.']).'">'.$this->pi_getLL('video_clip_highlight').'</font>';
					if ($row['img_width'] == 0) {
						$thum_vars['image_width'] = $this->conf['imageSmallWidth'];
					}
					if ($row['img_height'] == 0) {
						$thum_vars['image_height'] = $this->conf['imageSmallHeight'];
					}

				} elseif ($fileInfo['extension'] == 'swf') {
					$thumbnail_image = $row['selection_image'];
					$videoClip = ($this->conf['templateStyle'] == 'css-styled') ? '<br /><span' . $this-> pi_classParam('flash-clip-highlight') . '>'.$this->pi_getLL('flash_clip_highlight').'</span>' : '<br /><font color="'.$this->cObj->stdWrap($this->conf['color1'], $this->conf['color1.']).'">'.$this->pi_getLL('flash_clip_highlight').'</font>';
					if ($row['img_width'] == 0) {
						$thum_vars['image_width'] = $this->conf['imageSmallWidth'];
					}
					if ($row['img_height'] == 0) {
						$thum_vars['image_height'] = $this->conf['imageSmallHeight'];
					}
				} else {
					$videoClip = '';
				}

				$imgInfo_scaled = $this->imgObj->imageMagickConvert($this->imgObj->tempPath . $thumbnail_image, 'web',
					$this->conf['imageSmallWidth'].'m', $this->conf['imageSmallHeight'].'m', '', '', '', 1 );
				
				if ($this->conf['doNotShowCardCaptions'] && $this->conf['doNotShowCardCaptions'] != '0') {
					$cardCaption = '';
				}  else {
					$cardCaption = ($this->conf['templateStyle'] == 'css-styled') ? ('<a href="' . htmlspecialchars($this->get_url('', $this->formPID.',' . $this->formType, $thum_vars, array_values($thum_unsetVars), $thum_usePiVars)) . '" title="' . htmlspecialchars($row['card']) . '">'. htmlspecialchars($row['card']) . $videoClip . '</a>') : ('<br />' . htmlspecialchars($row['card']) . $videoClip);
				}
				
				if ($this->conf['templateStyle'] == 'css-styled') {
					$selectorRow .= '<dl class="' . $this->pi_getClassName('image-selector-column') . (($colCount == 1) ? ' ' . $this->pi_getClassName('first-column') : '') . '" style="width:'. ((96 / $maxCol)). '%;">' . chr(10);
					$selectorRow .= '<dt style="height:###SELECTOR_ROW_HEIGHT###px;"><a href="'.htmlspecialchars($this->get_url('', $this->formPID.','.$this->formType, $thum_vars, array_values($thum_unsetVars), $thum_usePiVars)).' "title="' . htmlspecialchars($row['card']) . '"><img src="'.$imgInfo_scaled[3].'" style="width: ' . $imgInfo_scaled[0] . 'px; height: '.$imgInfo_scaled[1] . 'px;" alt="' . $cardAltText . '" /></a></dt>' . chr(10);
					$selectorRow .=  '<dd>' . $cardCaption . '</dd></dl>' . chr(10);
					$selectorRowHeight = max($selectorRowHeight, intval($imgInfo_scaled[1]));
				} else {
					$selector .= '<td width="'.(100 / $maxCol).'%" align="center" valign="top"><p>'. '<a href="'.htmlspecialchars($this->get_url('', $this->formPID.','.$this->formType, $thum_vars, array_values($thum_unsetVars), $thum_usePiVars)).'"><img src="'.$imgInfo_scaled[3].'" style="width: ' . $imgInfo_scaled[0] . 'px; height: '.$imgInfo_scaled[1] . 'px; border-style: none;" alt="' . $cardAltText . '" title="' . htmlspecialchars($row['card']) . '" /><br />'.$cardCaption . '</a><br />'. '</p></td>';
				}
			}
		}
		if ($this->conf['templateStyle'] == 'css-styled') {
			$selectorRow = str_replace('###SELECTOR_ROW_HEIGHT###', $selectorRowHeight, $selectorRow);
			$selector .= $selectorRow;
		} else {
			for($i = $colCount; $i < $maxCol-1; $i++ ) {
				$selector .= '<td>&nbsp;</td>';
			}
		}
		$selector .= chr(10) . (($this->conf['templateStyle'] == 'css-styled') ? '</div><div ' . $this-> pi_classParam('clear-float') . '></div>' . chr(10) : '</tr></table>') . chr(10);
		return $selector;
	}

	/**
	 * Generates an HTML radio button color selector
	 *
	 * @param	string		$name: name of the piVar
	 * @param	string		$valueChecked: pre-selected color
	 * @param	array		$values: array of colors
	 * @return	string		the generated HTML radio button color selector
	 */
	function colorSelector($name, $valueChecked, $values) {
		
		if($this->conf['templateStyle'] == 'css-styled') {
		
			$newName = str_replace('[', '-', $name);
			$newName = str_replace(']', '', $newName);
			$newName = str_replace('_', '-', $newName);
			
			$selector = '<ul id="' . $this->pi_getClassName('color-selector-' . $newName) . '"' . $this-> pi_classParam('color-selector') . '>' . chr(10);
			for($currentValue = 0; $currentValue < count($values); $currentValue++) {
				$selector .= '<li>' .chr(10);
				$selector .= '<label for="' . $this->pi_getClassName('color-selector-' . $newName. '-' . str_replace('#', '', $values[$currentValue]?$values[$currentValue]:'transparent')) . '" ' . 'class="' . $this->pi_getClassName('color-selector-' . $newName. '-' . str_replace('#', '', $values[$currentValue]?$values[$currentValue]:'transparent')) . ' ' . $this->pi_getClassName($values[$currentValue]?'non-transparent':'transparent') . '"></label>' . chr(10);
				$selector .= '<input type="radio" id="' . $this->pi_getClassName('color-selector-' . $newName. '-' . str_replace('#', '', $values[$currentValue]?$values[$currentValue]:'transparent')) . '" name="' . $name . '" value="' . $values[$currentValue] . '"';
				if ($valueChecked == $values[$currentValue]) {
					$selector .= ' checked="checked"';
				}
				$selector .= ' />' .chr(10);
				$selector .= '</li>' .chr(10);
				$selectorStyle .= chr(10) . '.' . $this->pi_getClassName('color-selector-' . $newName. '-' . str_replace('#', '', $values[$currentValue]?$values[$currentValue]:'transparent')) . ' { background-color:' . ($values[$currentValue]?$values[$currentValue]:'transparent') . '; }';
			}
			
			$selector .= '</ul>' . chr(10);
			$GLOBALS['TSFE']->additionalCSS['css-' . $this->pi_getClassName('color-selector-' . $newName)] = $selectorStyle;
		
		} else {
		
			$selector = "<table width=\"275\" border=\"0\" cellspacing=\"3\" cellpadding=\"0\">\n";
			$selector .= "<tr align=\"center\">\n";
			for($currentValue = 0; $currentValue < count($values); $currentValue++) {
				$selector .= "<td bgcolor=\"$values[$currentValue]\"><font color=\"#FFFFFF\" size=\"-1\"><img src=\"clear.gif\" width=\"1\" height=\"15\"> </font></td>\n";
			}
			$selector .= "</tr><tr align=\"center\">\n";
			for($currentValue = 0; $currentValue < count($values); $currentValue++) {
				$selector .= "<td class=\"tx-srsendcard-pi1-radio\"><input class=\"tx-srsendcard-pi1-radio\" type=\"radio\" name=\"$name\" value=\"$values[$currentValue]\"";

				if ($valueChecked == $values[$currentValue]) {
					$selector .= ' checked';
				}

				$selector .= " /></td>\n";
			}
			$selector .= "</tr></table>\n\n";
			
		}
		return $selector;
	}

	/**
	 * Generates an HTML radio button font selector
	 *
	 * @param	string		$name: name of the piVar
	 * @param	string		$valueChecked: pre-selected font file name
	 * @param	array		$fontFiles: array of font file names
	 * @param	array		$fontLabels: array of font names
	 * @param	array		$fontSizes: array of font sizes
	 * @param	string		$bgColor: background color of the generated selector
	 * @param	string		$fontColor: font color of  the generated selector
	 * @return	string		the generated HTML radio button font selector
	 */
	function fontFileSelector($name, $valueChecked, $fontFiles, $fontLabels, $fontSizes, $bgColor, $fontColor) {
		
		if($this->conf['templateStyle'] == 'css-styled') {

			$newName = str_replace('[', '-', $name);
			$newName = str_replace(']', '', $newName);
			$newName = str_replace('_', '-', $newName);

			$selector = '<ul  id="' . $this->pi_getClassName('font-selector') . '"' . $this-> pi_classParam('font-selector') . '>' . chr(10);
			for($currentValue = 0; $currentValue < count($fontFiles); $currentValue++) {
				$selector .= '<li>';
				$fontLabelImage = $this->makeTextImage($fontLabels[$currentValue], $fontSizes[$currentValue], substr(t3lib_div::getFileAbsFileName($this->conf['fontDir'].'/'.$fontFiles[$currentValue]), strlen(PATH_site)), $fontColor, 130, $bgColor, 'left');
				$selector .= '<input type="radio" id="' . $this->pi_getClassName('font-selector-' . $newName . '-' . htmlspecialchars($fontLabels[$currentValue])) . '" name="' . $name . '" value="' . $fontFiles[$currentValue] . '"';
				if ($valueChecked == $fontFiles[$currentValue]) {
					$selector .= ' checked="checked"';
				}
				$selector .= ' />';
				$selector .= '<label for="' . $this->pi_getClassName('font-selector-' . $newName . '-' . htmlspecialchars($fontLabels[$currentValue])) . '"><span' . $this-> pi_classParam('text-font-label') . '>' . htmlspecialchars($fontLabels[$currentValue]) . ':</span><img src="' . $fontLabelImage[3] . '" style="width: ' . $fontLabelImage[0] . 'px; height: ' . $fontLabelImage[1] . 'px;" alt="' . htmlspecialchars($fontLabels[$currentValue]) . '" title="' . htmlspecialchars($fontLabels[$currentValue]) . '" /></label>' . chr(10);
				$selector .= '</li>' . chr(10);
			}
			$selector .= '</ul>' . chr(10);
		} else {
			
			$selector = "<table width=\"275\" border=\"0\" cellspacing=\"3\" cellpadding=\"0\">\n";
			for($currentValue = 0; $currentValue < count($fontFiles); $currentValue++) {
				$selector .= "<tr>\n";
				$fontLabelImage = $this->makeTextImage($fontLabels[$currentValue], $fontSizes[$currentValue], substr(t3lib_div::getFileAbsFileName($this->conf['fontDir'].'/'.$fontFiles[$currentValue]), strlen(PATH_site)), $fontColor, 130, $bgColor, 'left');
				$selector .= "<td align=\"left\" class=\"tx-srsendcard-pi1-radio\"><input class=\"tx-srsendcard-pi1-radio\" type=\"radio\" name=\"$name\" value=\"$fontFiles[$currentValue]\"";
				if ($valueChecked == $fontFiles[$currentValue]) {
					$selector .= ' checked';
				}
				$selector .= " /></td>\n";
				$selector .= "<td align=\"left\"><img src=\"$fontLabelImage[3]\" width=\"$fontLabelImage[0]\" height=\"$fontLabelImage[1]\"></td></tr>\n";
			}
			$selector .= "</table>\n\n";
		}
		return $selector;
	}
	
	/**
	 * Looks up in an array of font file names and returns required font size in corresponding font sizes array
	 *
	 * @param	string		$name: name of font
	 * @param	array		$fontFiles: array of font file names
	 * @param	array		$fontSizes: array of font sizes
	 * @return	string		font size corresponding to input file name
	 */
	function getFontSize($name, $fontFiles, $fontSizes) {
		
		for($currentValue = 0; $currentValue < count($fontFiles); $currentValue++) {
			if ($name == $fontFiles[$currentValue] ) {
				return $fontSizes[$currentValue];
			}
		}
		return $fontSizes[0];
	}
	
	/**
	 * Generates a pibase-compliant typolink
	 *
	 * @param	string		$tag: string to include within <a>-tags; if empty, only the url is returned
	 * @param	string		$id: page id (could of the form id,type )
	 * @param	array		$vars: extension variables to add to the url ($key, $value)
	 * @param	array		$unsetVars: extension variables (piVars to unset)
	 * @param	boolean		$usePiVars: if set, input vars and incoming piVars arrays are merge
	 * @return	string		generated link or url
	 */
	function get_url($tag = '', $id, $vars = array(), $unsetVars = array(), $usePiVars = true) {
		
		$vars = (array) $vars;
		$unsetVars = (array)$unsetVars;
		
		if ($usePiVars) {
			$vars = array_merge($this->piVars, $vars);	//vars override pivars
			while (list(, $key) = each($unsetVars)) {
				unset($vars[$key]);	// unsetvars override anything
			}
		}
		
		while (list($key, $val) = each($vars)) {
			$piVars[$this->prefixId . '['. $key . ']'] = $val;
		}
		
		if ($tag) {
			return $this->cObj->getTypoLink($tag, $id, $piVars);
		} else {
			return $this->cObj->getTypoLink_URL($id, $piVars);
		}
	}
	
	/**
	 * Process links in message text.
	 *
	 * @param	string		$text: input text
	 * @param	boolean		$stripslashes: apply stripslashes() or not
	 * @return	string		cleaned text
	 */
	function linksInText($text, $stripslashes = false) {
		$cleanedText = $stripslashes ? stripslashes($text) : $text;
		$cleanedText = eregi_replace("\[([http|news|ftp]+://[^ >\n\t]+)\]", "<a href=\"\\1\" target=\"_blank\">\\1</a>", $cleanedText);
		$cleanedText = eregi_replace("\[(mailto:)([^ >\n\t]+)\]", "<a href=\"\\1\\2\">\\2</a>", $cleanedText);
		return $cleanedText;
	}
	
	/**
	 * Breaks textarea text into lines of specified width in order to feed Gifbuilder (see function makeTextImage)
	 *
	 * @param	string		$text: input text 
	 * @param	integer		$size: specified font size
	 * @param	string		$font: absolute path to specified font file
	 * @param	integer		$width: specified width of the image box
	 * @return	array		array of lines of text
	 */
	function text_to_lines($text, $size, $font, $width) {
		
		$words = t3lib_div::trimExplode(' ', str_replace(chr(13), ' <br> ', str_replace(chr(10).chr(13), ' <br> ', $text)), 0);
		$p = 0;
		$lines = array();
		$lines[0] = '';
		
		for($i = 0; $i < count($words); $i++) {
			($lines[$p] ) ? $test = $lines[$p].' '.$words[$i] : $test = $words[$i];
			$bbox = imagettfbbox($size, 0, $font, $test);
			if ($words[$i] == '<br>') {
				$p++;
				$lines[$p] = '';
			} elseif (($bbox[4] - $bbox[6]) > $width ) {
				$p++;
				$lines[$p] = $words[$i];
			} else {
				$lines[$p] = $test;
			}
		}
		
		return $lines;
	}
	
	/**
	 * Outputs an image of a specified width and background color displaying an input text with specified font size, file and color
	 *
	 * @param	string		$text: text to display on he image
	 * @param	integer		$size: specified font size
	 * @param	string		$font: specified font file name
	 * @param	string		$color: specified font color
	 * @param	integer		$width: specified width of the image
	 * @param	string		$bgColor: specified background color
	 * @param	string		$align: left or right alignment of text
	 * @return	array		image file info array
	 */
	function makeTextImage($text, $size, $font, $color, $width, $bgColor = "white", $align = 'left') {
		
		$lines = $this->text_to_lines($text, t3lib_div::freetypeDpiComp($size), t3lib_stdGraphic::prependAbsolutePath($font), $width);
		$lineCount = count($lines);
		$gifObjArray['backColor'] = $bgColor;
		$gifObjArray['transparentBackground'] = 0;
		$gifObjArray['reduceColors'] = '';
		$gifObjArray['XY'] = $width.','.(5+$lineCount * $size);
		
		for($textLine = 1; $textLine < $lineCount+1; $textLine++) {
			$gifObjArray[$textLine.'0'] = 'TEXT';
			$gifObjArray[$textLine.'0.']['text'] = $lines[$textLine-1];
			$gifObjArray[$textLine.'0.']['niceText'] = 0;
			$gifObjArray[$textLine.'0.']['antiAlias'] = 1;
			$gifObjArray[$textLine.'0.']['align'] = $align;
			$gifObjArray[$textLine.'0.']['fontSize'] = $size;
			$gifObjArray[$textLine.'0.']['fontFile'] = $font;
			$gifObjArray[$textLine.'0.']['fontColor'] = $color;
			$gifObjArray[$textLine.'0.']['offset'] = '1,'.($textLine * $size);
		}
		
		$textImageArray = $this->cObj->getImgResource('GIFBUILDER', $gifObjArray);
		return $textImageArray;
	}
	
	/**
	 * Add logo on image (masi).
	 *
	 * @param	string		$imageFile: input image file
	 * @param	integer		$width: image width
 	 * @param	integer		$height: image height
 	 * @param	string		$extension: image file extension
	 * @return	string		output image file
	 */
	function addLogo($imageFile,$width,$height,$extension) {
		
		if ($this->conf['logo'] && !$this->conf['disableImageScaling']) {
			$logoResource = $GLOBALS['TSFE']->tmpl->getFileName($this->conf['logo']);
			$logoImgInfo = $this->imgObj->getImageDimensions($logoResource);
			if ($logoImgInfo) {
				if ($this->conf['logoAlignHor'] == 'left') {
					$geometry = '+0';
				} else {
					$geometry = '+'.t3lib_div::intval_positive($width-$logoImgInfo[0]);
				}
				if ($this->conf['logoAlignVert'] == 'top') {
					$geometry .= '+0';
				} else {
					$geometry .= '+'.t3lib_div::intval_positive($height-$logoImgInfo[1]);
				}
				$ifile = $this->imgObj->tempPath.$imageFile;
				$ofile = $this->extKey.'_'.t3lib_div::shortMD5($this->extKey.$ifile.filemtime($ifile).$logoResource.$geometry).'.'.$extension;
				if (!$this->imgObj->file_exists_typo3temp_file($this->imgObj->tempPath.$ofile, $ifile)) {
					$this->imgObj->combineExec($logoResource, $ifile, '', $this->imgObj->tempPath.$ofile, $geometry);
				}
				return $ofile;
			} else {
				return $ifile;
			}
		} else {
			return $imageFile;
		}
	}
	
	/**
	 * Generates a random cardid
	 *
	 * @param	integer		$cardid_length: le length of the id to generate
	 * @return	string		the generated random cardid
	 */
	function make_cardid($cardid_length = 12) {
		
			// Seed the generator
		mt_srand (hexdec(substr(md5(microtime()), -8)) & 0x7fffffff);
		
			// Set ASCII range for random character generation
		$lower_ascii_bound = 50;	// "2"
		$upper_ascii_bound = 122;	// "z"
		
			// Exclude special characters and some confusing alphanumerics
			// o,O,0,I,1,l
		$donotuse = array (58, 59, 60, 61, 62, 63, 64, 73, 79, 91, 92, 93, 94, 95, 96, 108, 111);
		while ($i < $cardid_length) {
				//  mt_srand ((double)microtime() * 1000000);
				// random limits within ASCII table
			$randnum = mt_rand ($lower_ascii_bound, $upper_ascii_bound);
			if (!in_array ($randnum, $donotuse)) {
				$cardid = $cardid . chr($randnum);
				$i++;
			}
		}
		return $cardid;
	}
	
	/**
	 * Returns the relevant card overlay record fields
	 * Adapted from t3lib_page.php
	 *
	 * @param	mixed		If $cardInput is an integer, it's the pid of the card overlay record and thus the card overlay record is returned. If $cardInput is an array, it's a card-record and based on this card record the language record is found and OVERLAYED before the card record is returned.
	 * @param	integer		Language UID if you want to set an alternative value to $this->sys_language_uid which is default. Should be >=0
	 * @return	array		Card row which is overlayed with language_overlay record (or the overlay record alone)
	 */
	function getCardOverlay($cardInput, $lUid = -1) {
		global $TYPO3_DB;
		
			// Initialize:
		if ($lUid < 0) {
			$lUid = $this->pidRecord->sys_language_uid;
		}
		
			// If language UID is different from zero, do overlay:
		if ($lUid) {
			$fieldArr = array('card', 'cardaltText', 'selection_imagealtText');
			if (is_array($cardInput)) {
					// Was the whole record
				$card_uid = $cardInput['uid'];
					// Make sure that only fields which exist in the incoming record are overlaid!
				$fieldArr = array_intersect($fieldArr, array_keys($cardInput));
			} else {
					// Was the uid
				$card_uid = $cardInput;
			}
			if (count($fieldArr)) {
				$whereClause = 'card_uid=' . intval($card_uid) . ' ' .
					'AND sys_language_uid='.intval($lUid). ' ' .
					$this->cObj->enableFields($this->overlay_tbl_name);
				$res = $TYPO3_DB->exec_SELECTquery(
					implode(',', $fieldArr),
					$this->overlay_tbl_name,
					$whereClause
					);
				$row = $TYPO3_DB->sql_fetch_assoc($res);
			}
		}
		
			// Create output:
		if (is_array($cardInput)) {
				// If the input was an array, simply overlay the newfound array and return.
			return is_array($row) ? array_merge($cardInput, $row) : $cardInput;
		} else {
				// always an array in return.
			return is_array($row) ? $row : array();
		}
	}
	
	/**
	 * From the 'salutationswitcher' extension.
	 *
	 * @author	Oliver Klee <typo-coding@oliverklee.de>
	 */
	    // list of allowed suffixes
	var $allowedSuffixes = array('formal', 'informal');
	
	/**
	 * Returns the localized label of the LOCAL_LANG key, $key
	 * In $this->conf['salutation'], a suffix to the key may be set (which may be either 'formal' or 'informal').
	 * If a corresponding key exists, the formal/informal localized string is used instead.
	 * If the key doesn't exist, we just use the normal string.
	 *
	 * Example: key = 'greeting', suffix = 'informal'. If the key 'greeting_informal' exists, that string is used.
	 * If it doesn't exist, we'll try to use the string with the key 'greeting'.
	 *
	 * Notice that for debugging purposes prefixes for the output values can be set with the internal vars ->LLtestPrefixAlt and ->LLtestPrefix
	 *
	 * @param    string        The key from the LOCAL_LANG array for which to return the value.
	 * @param    string        Alternative string to return IF no value is found set for the key, neither for the local language nor the default.
	 * @param    boolean        If true, the output label is passed through htmlspecialchars()
	 * @return    string        The value from LOCAL_LANG.
	 */
	function pi_getLL($key, $alt = '', $hsc = FALSE) {
			// If the suffix is allowed and we have a localized string for the desired salutation, we'll take that.
		if (isset($this->conf['salutation']) && in_array($this->conf['salutation'], $this->allowedSuffixes, 1)) {
			$expandedKey = $key.'_'.$this->conf['salutation'];
			if (isset($this->LOCAL_LANG[$this->LLkey][$expandedKey])) {
				$key = $expandedKey;
			}
		}
		return parent::pi_getLL($key, $alt, $hsc);
	}
} // end of class

/**
 * Extension of t3lib_stdGraphic.
 *
 * This version of the combineExec function provided by Martin Kutschker (Martin.Kutschker@blackbox.at)
 * Used in tx_srsendcard_pi1 to brand images
 */

class tx_srsendcard_pi1_t3lib_stdGraphic extends t3lib_stdGraphic {

	function combineExec($input, $overlay, $mask, $output, $geometry = '') {
		if (!$this->NO_IMAGE_MAGICK) {
			if ($geometry) {
				$geometry = "-geometry $geometry ";
			}
			$cmd = $this->imageMagickPath.$this->combineScript.' -compose over '.$geometry.$this->wrapFileName($input).' '.$this->wrapFileName($overlay).' '.$this->wrapFileName($mask).' '.$this->wrapFileName($output);
			$this->IM_commands[] = Array ($output, $cmd);
			exec($cmd);
		}
	}

} // end of class

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/sr_sendcard/pi1/class.tx_srsendcard_pi1.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/sr_sendcard/pi1/class.tx_srsendcard_pi1.php']);
}

?>