<?php

require_once 'File/CSV/DataSource.php'; 

// MySQL Connection für mysql_real_escape_string nötig
mysql_connect('localhost','root','');

$test = 0;

$list = array(
	'oxarticles' => 'Produkte'.($test?'_test':'').'.csv',
	'oxobject2category' => 'Kategorie-Produkt-Zuweisung'.($test?'_test':'').'.csv',
	'oxcategories' => 'Inhalt__Kategorien__Seiten'.($test?'_test':'').'.csv',
	'oxuser' => 'Kunden'.($test?'_test':'').'.csv',
);

if (!empty($_SERVER['argv'][1])) {
	// eine Tab migrieren
	$tab = $_SERVER['argv'][1];
	if ($tab == 'help') {
		print '
migraton.php ["table"] ["source.csv"] ["output.sql"] [update]
	table: oxarticles | oxobject2category | oxcategories | oxuser
	source.csv: e.g.. "Kategorie-Produkt-Zuweisung.csv"
	output.sql: e.g. "oxobject2category.sql"
	update: just "update" - force UPDATE-SQL-statements instead of REPLACE-INTO
';
		exit;
	}
	
	if (!empty($_SERVER['argv'][2])) {
		$file = $_SERVER['argv'][2];
	} else if (!empty($list[$tab])) {
		$file = $list[$tab];
	} else {
		print "\nERROR: please give csv-filename as 2nd arg!\n";
	}
	
	if (!empty($_SERVER['argv'][3])) {
		$sqlout = $_SERVER['argv'][3];
	} else {
		$sqlout = $tab . "_" . preg_replace('/\.csv/i','',$file) . '.sql';
	}
	
	$update = false;
	if (!empty($_SERVER['argv'][4])
	&& $_SERVER['argv'][4] == 'update'
	) {
		$update = true;
	}
	
	$proc = new Rh_OxShop_MigrationEPages($file, $tab, $sqlout, $update);
	$proc->migrate();

} else {

	foreach ($list as $tab => $file) {
		$sqlout = $tab . "_" . preg_replace('/\.csv/i','',$file) . '.sql';
		print str_repeat("-",80);
		$proc = new Rh_OxShop_MigrationEPages($file, $tab, $sqlout);
		$proc->migrate();		
	}
}

class Rh_OxShop_MigrationEPages
{
	protected $_file = '';
	protected $_tab = '';
	protected $_sqlout = '';
	protected $_update = false;
	protected $_csv_settings = array(
		'delimiter' => ';',
		'eol' => "\n",
		'length' => 999999,
		'escape' => '"'
	);
	protected $_headers = array();
	
	public function __construct($_file, $_tab, $_sqlout, $_update=false)
	{
		$this->_file = $_file;
		$this->_sqlout = $_sqlout;
		$this->_tab = $_tab;
		$this->_update = $_update;
	}
	
	public function migrate()
	{
		print "\nSTART migrate $this->_file to $this->_tab ... \n";
		if (file_exists($this->_sqlout)) {
			print "\nremove existing SQL: ".$this->_sqlout;
			unlink($this->_sqlout);
		}
		$fkt = $this->_tab;
		$this->$fkt();
		print "\n\nSQL stored here: $this->_sqlout";
		print "\nDONE " . $this->_tab . "!\n";
	}
	
	protected function _getCSV()
	{
		$csv = new File_CSV_DataSource;
		$csv->settings = $this->_csv_settings;
		if (!$csv->load($this->_file)) {
			print "\nERROR: file $this->_file not found!";
		}
		// Header Namen matchen - Ziel: technische Namen behalten, Beschreibung entfernen
		$headers = $csv->getHeaders();
		//print_r($headers);
		foreach ($headers as $k => $v) {
			$tmp = $v;
			if (preg_match('/\[(.*)\]"?$/',$tmp,$match)) {
				$tmp = preg_replace('/[^\w\d\/\.]/i','',$match[1]);
			}
			$this->_headers[$k] = $tmp;
		}
		//print "\nHeaders: ";
		//print_r($this->_headers);
		return $csv;
	}
	
	protected function _convertRowKeys($_row)
	{
		$new = array();
		foreach ($_row as $k => $v) {
			$new[$this->_headers[$k]] = $v;
		}
		return $new;
	}
	
	protected function _sql($_tab, $_map, $_data)
	{
		$sets = array();
		$oxid = 0;
		foreach ($_map as $k => $v) {
			$v = $this->_replacePlaceholderForSql($v, $_data);
			if ($this->_update 
			&& $k == 'OXID'
			) {
				$oxid = $v;
			} else {
				$sets[] = $k . " = " . (empty($v) ? "0" : $v);
			}
		}
		if ($this->_update) {
			if (!empty($oxid)) {
				$sql = "UPDATE " . $_tab . " SET " . join(", ", $sets) . " WHERE OXID=" . $oxid;
			} else {
				$sql = "# OXID MISSING! .. UPDATE " . $_tab . " SET " . join(", ", $sets) . " ???";
			}
		} else {
			$sql = "REPLACE INTO " . $_tab . " SET " . join(", ", $sets);
		}
		file_put_contents($this->_sqlout, $sql . ";\n", FILE_APPEND);
		return $sql;
	}
	
	protected function _sqlUpdateIfEmpty($_tab, $_map, $_data)
	{
		$sql = array();
		// OXID suchen
		$oxid = 0;
		foreach ($_map as $k => $v) {
			if ($k == 'OXID') {
				$v = $this->_replacePlaceholderForSql($v, $_data);
				$oxid = $v;
				break;
			}
		}
		if (empty($oxid)) {
			print "\nERROR: No OXID found! Can't build UPDATE statements!";
			return false;
		}
		unset($_map['OXID']);
		
		foreach ($_map as $k => $v) {
			$v = $this->_replacePlaceholderForSql($v, $_data);
			$sql[] = "UPDATE " . $_tab . " SET $k = " . (empty($v) ? "0" : $v) . " WHERE OXID=" . $oxid; // ($k = '' OR $k IS NULL) AND OXID=" . $oxid;
		}
		file_put_contents($this->_sqlout, implode(";\n", $sql) . ";\n", FILE_APPEND);
		return $sql;
	}
	
	protected function _replacePlaceholderForSql($_val, $_data)
	{
		while (preg_match('/#([a-zA-Z0-9\/\.]+?)#/',$_val,$m)) {
			$tmp_key = $m[1];
			$tmp_val = $_data[$tmp_key];
			// Fließkommazahlen korrigieren: 10,50 -> 10.50
			if (preg_match('/^(\d+),(\d+)$/', $tmp_val, $m)) {
				$tmp_val = $m[1] . '.' . $m[2];
			}
			$_val = str_replace('#'.$tmp_key.'#', mysql_real_escape_string($tmp_val), $_val);
		}
		return $_val;
	}
	
	
	public function oxarticles()
	{
		$csv = $this->_getCSV();
		$rows = $csv->getRows();
		$n = 0;
		$map = array(
			'OXID' => 'MD5("#Alias#")',
		/*	'OXSHOPID' => '"oxbaseshop"',
			'OXARTNUM' => '"#Alias#"',
			'OXTITLE' => '"#Name/de#"',
			'OXSHORTDESC' => '"#Description/de#"',
			'OXPRICE' => '"#ListPrices/EUR/gross#"',
			'OXACTIVE' => '#IsVisible#',
			'OXPIC1' => '"#ImageLarge#"',
			// OXPIC2-7 -> ImagesSlideShowString -> per Zusatzcode..
			//'OXPRICEC' => '#ManufacturerPrices/EUR/gross#', // wird nicht mehr gepflegt
			'OXSTOCK' => '#StockLevel#',
			'OXLENGTH' => '#Length#',
			'OXHEIGHT' => '#Height#',
			'OXWIDTH' => '#Width#',
			'OXWEIGHT' => '#Weight#',
			'OXSORT' => '#Position#',
			'OXMPN' => '#ManufacturerSKU#',
			// Text/de -> oxartextends.oxlongdesc -> per Zusatzcode
			'OXVARNAME' => '"#SelectedVariations#"',
		*/
		);
		$map_longdesc = array(
			'OXID' => 'MD5("#Alias#")',
			'OXLONGDESC' => '"#Text/de#"',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			$n++;
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// Zusatzbilder
			$pics = split(";", $row['ImagesSlideShowString']);
			//print_r($pics);
			$has_pics = false;
			for ($i = 2; $i<=7; $i++) {
				if (!empty($pics[$i - 2])) {
					$rep = array('/ä/'=>'ae','/ö/'=>'oe','/ü/'=>'ue','/ß/'=>'ss','/[\(\)]/'=>'');
					$tmp_name = strtolower($pics[$i - 2]);
					$tmp_name = preg_replace('/\.(JPE?G|jpeg)/i','.jpg', $tmp_name);
					$tmp_name = preg_replace(array_keys($rep),array_values($rep), $tmp_name);
					$tmp_name = preg_replace('/[^\/a-z0-9_\-\.]/','',$tmp_name);
					if (strtolower($pics[$i - 2]) !== $pics[$i - 2]) {
						print "\nWARN: Please rename ".$pics[$i - 2]." to LOWERCASE! OxidShop have problems with mixed case filenames!";
					}
					$map_curr['OXPIC' . $i] = '"'.$tmp_name.'"';
					$has_pics = true;
				}
			}
			// nur Bilder migrieren? dann aktivieren
			if (!$has_pics) {
				continue;
			}
			
			// Hersteller mit in Kurzbeschreibung
			if (!empty($row['Manufacturer'])) {
			//	$map_curr['OXSHORTDESC'] = 'CONCAT("#Description/de#"," ","#Manufacturer#")';
			}
			// Parent..
			$row['SuperProduct'] = trim($row['SuperProduct']);
			if (!empty($row['SuperProduct'])) {
			//	$map_curr['OXPARENTID'] = 'MD5("#SuperProduct#")';
			}
			
			// SQL erzeugen
			//$sql = $this->_sql($this->_tab, $map_curr, $row);
			//print "\n". $sql;
			
			// lange Artikelbeschreibung
			//$sql = $this->_sql("oxartextends", $map_longdesc, $row);
			//print "\n". $sql;
			
			// nur leere Felder aktualisieren
			$sql = $this->_sqlUpdateIfEmpty($this->_tab, $map_curr, $row);
			print "\n". implode("\n",$sql);
			
		}
	}
	
	public function oxobject2category()
	{
		$csv = $this->_getCSV();
		$rows = $csv->getRows();
		$n = 0;
		$map = array(
			'OXID' => 'MD5(CONCAT("#Category#","#Product#"))',
			'OXPOS' => '#Position#',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			$n++;
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// Kategoriename
			$tmp_cat = $row['Category'];
			$tmp_cat = preg_replace('/^Categories\//','',$tmp_cat);
			$map_curr['OXCATNID'] = 'MD5("' . mysql_real_escape_string($tmp_cat) . '")';
			// Produkt-ID
			$tmp_art = $row['Product'];
			$tmp_art = preg_replace('/^.*\//','',$tmp_art);
			$map_curr['OXOBJECTID'] = 'MD5("' . mysql_real_escape_string($tmp_art) . '")';
			// SQL erzeugen
			$sql = $this->_sql($this->_tab, $map_curr, $row);
			//print "\n". $sql;
		}
	}
	
	public function oxcategories()
	{
		$csv = $this->_getCSV();
		$rows = $csv->getRows();
		$n = 0;
		$map = array(
			'OXID' => 'MD5("#Alias#")',
			'OXSORT' => '"#Position#"',
			'OXACTIVE' => '"#IsVisible#"',
			'OXTITLE' => '"#Name/de#"',
			'OXDESC' => '"#Description/de#"',
			'OXLONGDESC' => '"#Text/de#"',
			'OXTHUMB' => '"#ImageMedium#"',
			//'OXTHUMB_1' => '"#ImageSmall#"',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			if ($row['Class'] != 'Category') {
				continue;
			}
			$n++;
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// Parent..
			$row['Parent'] = trim($row['Parent']);
			if (!empty($row['Parent'])) {
				$map_curr['OXPARENTID'] = 'MD5("#Parent#")';
			}
			// SQL erzeugen
			$sql = $this->_sql($this->_tab, $map_curr, $row);
			//print "\n". $sql;
		}
	}
	
	public function oxuser()
	{
		$csv = $this->_getCSV();
		$rows = $csv->getRows();
		$n = 0;
		$map = array(
			'OXID' => 'MD5("#Alias#")',
			'OXUSERNAME' => '"#BillingAddress.EMail#"',
			'OXSHOPID' => '"oxbaseshop"',
			'OXCUSTNR' => '#Alias#',
			'OXACTIVE' => '#IsDoOrderAllowed#',
			'OXFNAME' => '"#BillingAddress.FirstName#"',
			'OXLNAME' => '"#BillingAddress.LastName#"',
			'OXADDINFO' => '"#BillingAddress.AddressExtension#"',
			'OXZIP' => '"#BillingAddress.Zipcode#"',
			'OXCITY' => '"#BillingAddress.City#"',
			'OXCOUNTRYID' => '"a7c40f631fc920687.20179984"',
			'OXCOMPANY' => '"#BillingAddress.Company#"',
			'OXFON' => '"#BillingAddress.Phone#"',
			'OXRIGHTS' => '"user"',
			
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			$n++;
			if ($row['BillingAddress.EMail'] == 'k.hellmig@gmx.net'
			|| $row['BillingAddress.EMail'] == 'rha@gmx.li'
			) {
				continue;
			}
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// Geschlecht
			if ($row['BillingAddress.Gender'] == 1) {
				$map_curr['OXSAL'] = '"MR"';
			} else if ($row['BillingAddress.Gender'] == 2) {
				$map_curr['OXSAL'] = '"MRS"';
			}
			// Strasse-HNR trennen
			if (preg_match('/^([^\d]*)\s*(\d+.*?)$/', $row['BillingAddress.Street'], $match)) {
				//print_r($match);
				$map_curr['OXSTREET'] = '"'.mysql_real_escape_string(ucfirst(trim($match[1]))).'"';
				$map_curr['OXSTREETNR'] = '"'.mysql_real_escape_string(trim($match[2])).'"';
			} else {
				$map_curr['OXSTREET'] = '"#BillingAddress.Street#"';
			}
			// Benutzergruppe - Rechte
			/*if ($row['CustomerGroup'] == 'Abokunde') { // Futter-Abokunde
				$map_curr['OXRIGHTS'] = '"0037f0132089545436c8a0268a6557eb"';
			} else if ($row['CustomerGroup'] == 'Stammkunde') {
				$map_curr['OXRIGHTS'] = '"nand4838bef64e11bab726ce1f8488dd"';
			}*/
			// SQL erzeugen
			$sql = $this->_sql($this->_tab, $map_curr, $row);
			//print "\n". $sql;
		}
	}
}

