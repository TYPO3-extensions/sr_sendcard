<?php
if (!defined ('TYPO3_MODE')) die ('Access denied.');
	
t3lib_extMgm::addStaticFile($_EXTKEY, 'static/css_styled/', 'Send-A-Card CSS-styled');
t3lib_extMgm::addStaticFile($_EXTKEY, 'static/old_style/', 'Send-A-Card Old Style');
	
$TCA['tx_srsendcard_card'] = Array (
	'ctrl' => Array (
	'title' => 'LLL:EXT:sr_sendcard/locallang_db.xml:tx_srsendcard_card',
		'label' => 'card',
		'default_sortby' => 'ORDER BY sorting',
		'sortby' => 'sorting',
		'delete' => 'deleted',
		'languageField' => 'sys_language_uid',
		'transOrigPointerField' => 'l18n_parent',
		'transOrigDiffSourceField' => 'l18n_diffsource',
		'enablecolumns' => Array (
			'disabled' => 'hidden',
			'starttime' => 'starttime',
			'endtime' => 'endtime',
		),
		'dynamicConfigFile' => t3lib_extMgm::extPath($_EXTKEY).'tca.php',
		'iconfile' => t3lib_extMgm::extRelPath($_EXTKEY).'ext_icon.gif',
		)
	);
	 
t3lib_extMgm::allowTableOnStandardPages('tx_srsendcard_card');
t3lib_extMgm::addToInsertRecords('tx_srsendcard_card');

if ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF'][$_EXTKEY]['keepCardLanguageOverlay']) {
	$TCA['tx_srsendcard_card_language_overlay'] = Array (
		'ctrl' => Array (
		'title' => 'LLL:EXT:sr_sendcard/locallang_db.xml:tx_srsendcard_card_language_overlay',
			'label' => 'card',
			'default_sortby' => 'ORDER BY card_uid',
			'sortby' => 'sorting',
			'delete' => 'deleted',
			'enablecolumns' => Array (
				'disabled' => 'hidden',
				'starttime' => 'starttime',
				'endtime' => 'endtime',
				),
				'dynamicConfigFile' => t3lib_extMgm::extPath($_EXTKEY).'tca.php',
				'iconfile' => t3lib_extMgm::extRelPath($_EXTKEY).'ext_icon.gif',
			)
		);
	t3lib_extMgm::allowTableOnStandardPages('tx_srsendcard_card_language_overlay');
	t3lib_extMgm::addToInsertRecords('tx_srsendcard_card_language_overlay');
}

t3lib_div::loadTCA('tt_content');
$TCA['tt_content']['types']['list']['subtypes_excludelist'][$_EXTKEY.'_pi1'] = 'layout,select_key';
t3lib_extMgm::addPlugin(Array('LLL:EXT:sr_sendcard/locallang_db.xml:tt_content.list_type', $_EXTKEY.'_pi1'), 'list_type');

if (TYPO3_MODE == 'BE') {
	$TBE_MODULES_EXT['xMOD_db_new_content_el']['addElClasses']['tx_srsendcard_pi1_wizicon'] = t3lib_extMgm::extPath($_EXTKEY).'pi1/class.tx_srsendcard_pi1_wizicon.php';
	t3lib_extMgm::addModule('web', 'txsrsendcardM1', '', t3lib_extMgm::extPath($_EXTKEY).'mod1/');
}

?>
