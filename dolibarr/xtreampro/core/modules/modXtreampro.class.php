<?php
/**
 * Xtream UI Pro for Dolibarr - module descriptor.
 *
 * Sells IPTV lines and sub-reseller accounts through the Reseller API of an Xtream UI Pro panel.
 * Install: copy the folder `xtreampro/` into htdocs/custom/ and enable the module in
 * Home - Setup - Modules.
 *
 * @version 1.1.0
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modXtreampro extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Module number: any number from 500000 up is free for modules that are not registered on
		// Dolistore; it must not clash with another module on the same installation.
		$this->numero = 504760;
		$this->rights_class = 'xtreampro';
		$this->family = 'interface';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Sell IPTV lines and sub-reseller accounts through the Xtream UI Pro Reseller API';
		$this->descriptionlong = 'Provisions an IPTV line per unit, one sub-reseller account per customer and credit top-ups when an invoice is paid; disables them when it is reversed.';
		$this->editor_name = 'Xtream UI Pro';
		$this->editor_url = '';
		$this->version = '1.1.0';
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
		$this->picto = 'generic';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array('data' => array('invoicecard'), 'entity' => '0'),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@xtreampro');
		$this->hidden = false;
		$this->depends = array('modSociete', 'modFacture');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('xtreampro@xtreampro');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(18, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants (the API key is NOT here: it is created by the setup page, encrypted).
		// name, type, value, description, visible, entity, delete when the module is disabled
		$this->const = array(
			1 => array('XTREAMPRO_API_URL', 'chaine', '', 'Xtream UI Pro panel API URL', 0, 'current', 0),
			2 => array('XTREAMPRO_AUTO_PROVISION', 'chaine', '1', 'Provision when an invoice is paid', 0, 'current', 0),
		);

		// Tab on the third-party card (drawn by tab_thirdparty.php)
		$this->tabs = array();
		$this->tabs[] = array('data' => 'thirdparty:+xtreampro:XtreamproTab:xtreampro@xtreampro:$user->hasRight("xtreampro","read"):/xtreampro/tab_thirdparty.php?socid=__ID__');

		// Permissions
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Read Xtream UI Pro lines and accounts';
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';
		$r++;
		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'Manage Xtream UI Pro lines and accounts (renew, suspend, terminate, retry, email credentials)';
		$this->rights[$r][4] = 'write';
		$this->rights[$r][5] = '';
		$r++;

		// Menus: top menu "Xtream UI Pro" with the list of lines and of accounts
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'XtreamproModuleName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'xtreampro',
			'leftmenu' => '',
			'url' => '/xtreampro/list.php',
			'langs' => 'xtreampro@xtreampro',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("xtreampro")',
			'perms' => '$user->hasRight("xtreampro","read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=xtreampro',
			'type' => 'left',
			'titre' => 'XtreamproLine',
			'mainmenu' => 'xtreampro',
			'leftmenu' => 'xtreampro_lines',
			'url' => '/xtreampro/list.php?type=line',
			'langs' => 'xtreampro@xtreampro',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("xtreampro")',
			'perms' => '$user->hasRight("xtreampro","read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=xtreampro',
			'type' => 'left',
			'titre' => 'XtreamproResellerAccount',
			'mainmenu' => 'xtreampro',
			'leftmenu' => 'xtreampro_resellers',
			'url' => '/xtreampro/list.php?type=reseller',
			'langs' => 'xtreampro@xtreampro',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("xtreampro")',
			'perms' => '$user->hasRight("xtreampro","read")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Enable the module: create the tables (sql/*.sql) and the product extrafields.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <= 0 on error
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		$result = $this->_load_tables('/xtreampro/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->createProductExtrafields();

		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module. The tables and the extrafields stay, so no provisioning record is lost.
	 *
	 * @param string $options Options
	 * @return int 1 if OK, <= 0 on error
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}

	/**
	 * Extrafields of products and services: what the product sells. Created once; an existing one
	 * (re-enabling the module) is left as the administrator may have edited it.
	 */
	private function createProductExtrafields()
	{
		global $conf;
		require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('product');
		$existing = isset($extrafields->attributes['product']['label']) ? $extrafields->attributes['product']['label'] : array();

		// name => array(label key, type, size, default, param)
		$fields = array(
			'xtreampro_kind' => array('XtreamproExtraKind', 'select', '', '', array('options' => array('line' => 'IPTV line', 'reseller' => 'Sub-reseller account'))),
			'xtreampro_package_id' => array('XtreamproExtraPackage', 'int', '10', '', ''),
			'xtreampro_trial' => array('XtreamproExtraTrial', 'boolean', '', '', ''),
			'xtreampro_credits' => array('XtreamproExtraCredits', 'int', '10', '0', ''),
			'xtreampro_delete' => array('XtreamproExtraDelete', 'boolean', '', '', ''),
		);
		$pos = 900;
		foreach ($fields as $name => $def) {
			$pos++;
			if (isset($existing[$name])) {
				continue;
			}
			// attrname, label, type, pos, size, elementtype, unique, required, default, param, alwayseditable, perms, list, help, computed, entity, langfile, enabled
			$extrafields->addExtraField($name, $def[0], $def[1], $pos, $def[2], 'product', 0, 0, $def[3], $def[4], 1, '', '1', '', '', $conf->entity, 'xtreampro@xtreampro', '1');
		}
	}
}
