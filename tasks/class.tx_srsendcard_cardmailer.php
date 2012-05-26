<?php
/***************************************************************
*  Copyright notice
*	
*  (c) 2012 Stanislas Rolland <typo3(arobas)sjbr.ca>
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
 * This is the card mailer task of extension Send-A-Card (sr_sendcard) that sends the deferred cards
 *
 */
class tx_srsendcard_cardMailer extends tx_scheduler_Task {

	/**
	 * Page id on which the card will be viewed
	 *
	 * @var integer $viewCardPid
	 */
	public $viewCardPid = 0;

	/**
	 * Invokes the deferred card mailing class
	 *
	 */
	public function execute() {
		$success = FALSE;
		if (!empty($this->viewCardPid)) {
			$GLOBALS['TT'] = new t3lib_timeTrack;
			// ***********************************
			// Creating a fake $TSFE object
			// ***********************************
			$GLOBALS['TSFE'] = t3lib_div::makeInstance('tslib_fe', $GLOBALS['TYPO3_CONF_VARS'], $this->viewCardPid, '0', 1, '', '', '', '');
			$GLOBALS['TSFE']->connectToDB();
			$GLOBALS['TSFE']->initFEuser();
			$GLOBALS['TSFE']->fetch_the_id();
			$GLOBALS['TSFE']->getPageAndRootline();
			$GLOBALS['TSFE']->initTemplate();
			$GLOBALS['TSFE']->tmpl->getFileName_backPath = PATH_site;
			$GLOBALS['TSFE']->forceTemplateParsing = 1;
			$GLOBALS['TSFE']->getConfigArray();
			$sendingCards = t3lib_div::makeInstance('tx_srsendcard_pi1_deferred');
			$sendingCards->cObj = t3lib_div::makeInstance('tslib_cObj');
			$conf = $GLOBALS['TSFE']->tmpl->setup['plugin.'][$sendingCards->prefixId . '.'];
			$success = $sendingCards->main($conf);
		}
		return $success;
	}
}
if (defined('TYPO3_MODE') && $GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/sr_sendcard/tasks/class.tx_srsendcard_cardmailer.php']) {
	include_once($GLOBALS['TYPO3_CONF_VARS'][TYPO3_MODE]['XCLASS']['ext/sr_sendcard/tasks/class.tx_srsendcard_cardmailer.php']);
}
?>