<?php
/**
 * Xtream UI Pro for Dolibarr - database access for the module's own tables.
 *
 * Turns table rows into the plain records the provisioner works on (and back).
 * Passwords are encrypted at rest with dolEncrypt() (Dolibarr's own mechanism,
 * keyed from conf.php) and decrypted when a record is read.
 */

class XtreamproStore
{
	/** @var DoliDB */
	private $db;

	/** record key => column, per kind. */
	private static $columns = array(
		'line' => array(
			'socid' => 'fk_soc', 'invoice_id' => 'fk_facture', 'invoice_line_id' => 'fk_facturedet', 'unit' => 'unit',
			'package_id' => 'package_id', 'trial' => 'trial', 'delete_on_terminate' => 'delete_on_terminate',
			'panel_line_id' => 'panel_line_id', 'username' => 'username', 'password' => 'password', 'status' => 'status',
			'revoked' => 'revoked', 'generation' => 'generation', 'renewals' => 'renewals', 'error' => 'error_msg',
		),
		'reseller' => array(
			'socid' => 'fk_soc', 'invoice_id' => 'fk_facture', 'panel_user_id' => 'panel_user_id', 'username' => 'username',
			'password' => 'password', 'email' => 'email', 'status' => 'status', 'revoked' => 'revoked',
			'generation' => 'generation', 'error' => 'error_msg',
		),
		'credit' => array(
			'socid' => 'fk_soc', 'invoice_id' => 'fk_facture', 'invoice_line_id' => 'fk_facturedet', 'credits' => 'credits',
			'credited' => 'credited', 'revoked' => 'revoked', 'cycles' => 'cycles', 'error' => 'error_msg',
		),
	);

	/** Record keys that hold text; every other key is an integer. */
	private static $textKeys = array('username', 'password', 'status', 'error', 'email', 'panel_user_id');

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function table($kind)
	{
		return MAIN_DB_PREFIX . 'xtreampro_' . $kind;
	}

	/**
	 * Insert or update a record. Returns the record with its `id`, or false on a database error.
	 * (The provisioner's $persist callback.)
	 */
	public function save($kind, array $record)
	{
		global $conf;
		$map = self::$columns[$kind];
		$values = array();
		foreach ($map as $key => $column) {
			if (!array_key_exists($key, $record)) {
				continue;
			}
			$value = $record[$key];
			if ($key === 'password' && $value !== '') {
				$value = dolEncrypt((string) $value);
			}
			if (in_array($key, self::$textKeys, true) || $key === 'error') {
				$values[$column] = "'" . $this->db->escape((string) $value) . "'";
			} else {
				$values[$column] = (int) $value;
			}
		}

		if (!empty($record['id'])) {
			$set = array();
			foreach ($values as $column => $sql) {
				$set[] = $column . ' = ' . $sql;
			}
			$sql = 'UPDATE ' . $this->table($kind) . ' SET ' . implode(', ', $set) . ' WHERE rowid = ' . ((int) $record['id'])
				. ' AND entity = ' . ((int) $conf->entity);
			if (!$this->db->query($sql)) {
				dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
				return false;
			}
			return $record;
		}

		$values['entity'] = (int) $conf->entity;
		$values['date_creation'] = "'" . $this->db->idate(dol_now()) . "'";
		$sql = 'INSERT INTO ' . $this->table($kind) . ' (' . implode(', ', array_keys($values)) . ') VALUES (' . implode(', ', $values) . ')';
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
			return false;
		}
		$record['id'] = (int) $this->db->last_insert_id($this->table($kind));
		return $record;
	}

	/** Row object -> record. */
	private function toRecord($kind, $obj)
	{
		$record = array('id' => (int) $obj->rowid, 'date_creation' => $this->db->jdate($obj->date_creation));
		foreach (self::$columns[$kind] as $key => $column) {
			$value = $obj->$column;
			if ($key === 'password') {
				$value = $value === '' ? '' : (string) dolDecrypt($value);
			}
			$record[$key] = in_array($key, self::$textKeys, true) || $key === 'error' ? (string) $value : (int) $value;
		}
		return $record;
	}

	/** @return array[] records of $kind matching an SQL condition (already escaped by the caller). */
	private function fetchWhere($kind, $where, $order = 'rowid DESC')
	{
		global $conf;
		$sql = 'SELECT * FROM ' . $this->table($kind) . ' WHERE entity = ' . ((int) $conf->entity)
			. ($where !== '' ? ' AND ' . $where : '') . ' ORDER BY ' . $order;
		$resql = $this->db->query($sql);
		$out = array();
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $this->db->lasterror(), LOG_ERR);
			return $out;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$out[] = $this->toRecord($kind, $obj);
		}
		return $out;
	}

	private function first(array $rows)
	{
		return $rows ? $rows[0] : null;
	}

	// ---- lines ------------------------------------------------------------

	public function fetchLine($id)
	{
		return $this->first($this->fetchWhere('line', 'rowid = ' . ((int) $id)));
	}

	public function fetchLineByUnit($invoiceLineId, $unit)
	{
		return $this->first($this->fetchWhere('line', 'fk_facturedet = ' . ((int) $invoiceLineId) . ' AND unit = ' . ((int) $unit)));
	}

	/** @param int $socid 0 = all; @param int $invoiceId 0 = all; @param string $status '' = all */
	public function listLines($socid = 0, $invoiceId = 0, $status = '')
	{
		$where = array('1 = 1');
		if ($socid > 0) {
			$where[] = 'fk_soc = ' . ((int) $socid);
		}
		if ($invoiceId > 0) {
			$where[] = 'fk_facture = ' . ((int) $invoiceId);
		}
		if ($status !== '') {
			$where[] = "status = '" . $this->db->escape($status) . "'";
		}
		return $this->fetchWhere('line', implode(' AND ', $where));
	}

	// ---- reseller accounts ------------------------------------------------

	public function fetchReseller($id)
	{
		return $this->first($this->fetchWhere('reseller', 'rowid = ' . ((int) $id)));
	}

	public function fetchResellerBySoc($socid)
	{
		return $this->first($this->fetchWhere('reseller', 'fk_soc = ' . ((int) $socid)));
	}

	public function listResellers($socid = 0, $status = '')
	{
		$where = array('1 = 1');
		if ($socid > 0) {
			$where[] = 'fk_soc = ' . ((int) $socid);
		}
		if ($status !== '') {
			$where[] = "status = '" . $this->db->escape($status) . "'";
		}
		return $this->fetchWhere('reseller', implode(' AND ', $where));
	}

	// ---- credits ------------------------------------------------------------

	public function fetchCredit($id)
	{
		return $this->first($this->fetchWhere('credit', 'rowid = ' . ((int) $id)));
	}

	public function fetchCreditByLine($invoiceLineId)
	{
		return $this->first($this->fetchWhere('credit', 'fk_facturedet = ' . ((int) $invoiceLineId)));
	}

	public function listCredits($socid = 0, $invoiceId = 0)
	{
		$where = array('1 = 1');
		if ($socid > 0) {
			$where[] = 'fk_soc = ' . ((int) $socid);
		}
		if ($invoiceId > 0) {
			$where[] = 'fk_facture = ' . ((int) $invoiceId);
		}
		return $this->fetchWhere('credit', implode(' AND ', $where));
	}
}
