<?php

require_once 'File/CSV/DataSource.php'; 

// MySQL Connection für mysql_real_escape_string nötig
mysql_connect('localhost','root','');

$test = 0;

$list = array(
	'oxarticles' => 'artikel'.($test?'_test':'').'.csv',
	'oxobject2category' => 'kategorie_produktzuweisung'.($test?'_test':'').'.csv',
	'oxcategories' => 'kategorien'.($test?'_test':'').'.csv',
	//'oxuser' => 'Kunden'.($test?'_test':'').'.csv',
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
	protected $_legacypics = array();
	protected $_splitsize = 1024; // kb
	protected $_splitnum = 1;
	
	protected $_attributes = array(
		array(
			'titel' => 'Typ',
			'cols' => array('Größe/Typ','Typ/Typ','Desoderm/Typ','Reinigungstücher/Typ'),
		),
		array(
			'titel' => 'Größe',
			'cols' => array('Größe/Größe','Art/Größe'),
		),
		array(
			'titel' => 'Art',
			'cols' => array('Art/Art','Typ/Art'),
		),
		array(
			'titel' => 'Schaft',
			'cols' => array('Art/Schaft'),
		),
		array(
			'titel' => 'Eigenschaft',
			'cols' => array('Eigenschaft/Eigenschaft'),
		),
		array(
			'titel' => 'Farbe',
			'cols' => array('Farbe/Farbe'),
		),
		array(
			'titel' => 'Schriftart',
			'cols' => array('Farbe/Schriftart'),
		),
		array(
			'titel' => 'Inhalt',
			'cols' => array('Inhalt/Inhalt'),
		),
		array(
			'titel' => 'Durchmesser',
			'cols' => array('Durchmesserinnenaußen/Durchmesser','Durchmesser/Durchmesser'),
		),
		array(
			'titel' => 'Figur',
			'cols' => array('Typ/Figur'),
		),
		array(
			'titel' => 'Breite',
			'cols' => array('Breite/Breite'),
		),
		array(
			'titel' => 'Körnung',
			'cols' => array('Körnung/Körnung'),
		),
		array(
			'titel' => 'Länge',
			'cols' => array('Länge/Länge'),
		),
		array(
			'titel' => 'Ref',
			'cols' => array('Ref/Ref'),
		),
	);
	
	protected $_varcount = array();
	protected $_varminprice = array();
	
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
			$splitfiles = preg_replace('/\.sql/','_*.sql',$this->_sqlout);
			foreach (glob($splitfiles) as $file) {
				unlink($file);
			}
		}
		$fkt = $this->_tab;
		$this->$fkt();
		print "\n\nSQL stored here: $this->_sqlout";
		if (!empty($this->_legacypics)) {
			print "\n\nLegacy-Pics:\n" . join("\n",$this->_legacypics) . "\n";
		}
		print "\nDONE " . $this->_tab . "!\n";
	}
	
	protected function _getCSV()
	{
		$csv = new File_CSV_DataSource();
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
		print "\nHeaders: ";
		print_r($this->_headers);
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
		$this->_sqlFilePutContents($sql . ";\n");
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
			$sql[] = "UPDATE " . $_tab . " SET $k = " . (empty($v) ? "0" : $v). " WHERE ($k = '' OR $k IS NULL) AND OXID=" . $oxid;
		}
		$this->_sqlFilePutContents(implode(";\n", $sql) . ";\n");		
		return $sql;
	}
	
	protected function _sqlFilePutContents($_sql)
	{
		$file = $this->_sqlout;
		if ($this->_splitnum > 0) {
			$file = preg_replace('/\.sql/','_'.$this->_splitnum.'.sql', $this->_sqlout);
		}
		clearstatcache();
		if (filesize($file) > ($this->_splitsize * 1024)) {
			//print "\n file $file size ".filesize($file)." -> neue SQL File ..";
			$this->_splitnum++;
			$file = preg_replace('/\.sql/','_'.$this->_splitnum.'.sql', $this->_sqlout);
			//print $file;
		}
		file_put_contents($file, $_sql, FILE_APPEND);
	}
	
	protected function _replacePlaceholderForSql($_val, $_data)
	{
		while (preg_match('/#([a-zäöüßA-Z0-9\/\.]+?)#/',$_val,$m)) {
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
		$this->_createAttributes();
		$csv = $this->_getCSV();
		$rows = $csv->getRows();
		$n = 0;
		$map = array(
			'OXID' => 'MD5("#Alias#")',
			'OXSHOPID' => '"oxbaseshop"',
			//'OXPARENTID' => '"#SuperProduct#"', // später gezielt ermitten
			'OXARTNUM' => '"#Alias#"',
			'OXTITLE' => '"#Name/de#"',
			'OXTITLE_1' => '"#Name/de#"',
			'OXSHORTDESC' => '"#Description/de#"',
			'OXSHORTDESC_1' => '"#Description/de#"',
			'OXPRICE' => '"#ListPrice/EUR/net#"',
			'OXACTIVE' => '#IsVisible#',
			'OXPIC1' => '"#ImageLarge#"',
			// OXPIC2-7 -> ImagesSlideShowString -> per Zusatzcode..
			//'OXPRICEC' => '#ManufacturerPrices/EUR/gross#', // wird nicht mehr gepflegt
			'OXSTOCK' => '#StockLevel#',
			'OXMINDELTIME' => '#DeliveryPeriod#', 
			'OXMAXDELTIME' => '(#DeliveryPeriod# + 1)',
			'OXDELTIMEUNIT' => '"DAY"',
			//'OXLENGTH' => '#Length#',
			//'OXHEIGHT' => '#Height#',
			//'OXWIDTH' => '#Width#',
			//'OXWEIGHT' => '#Weight#',
			'OXSORT' => '#Position#',
			//'OXMPN' => '#ManufacturerSKU#',
			// Text/de -> oxartextends.oxlongdesc -> per Zusatzcode
			'OXVARNAME' => '"#SelectedVariations#"',
			'OXVARNAME_1' => '"#SelectedVariations#"',
			'OXSEARCHKEYS' => '"#Keywords/de#"',
			'OXSEARCHKEYS_1' => '"#Keywords/de#"',
			'OXPICSGENERATED' => '0',
		);
		$map_longdesc = array(
			'OXID' => 'MD5("#Alias#")',
			'OXLONGDESC' => '"#LongDescription/de#"',
			'OXLONGDESC_1' => '"#LongDescription/de#"',
			'OXTAGS' => '"#Keywords/de#"',
			'OXTAGS_1' => '"#Keywords/de#"',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			$n++;
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			
			// ID
			$row['Alias'] = $this->_calcOXID($row['Alias']);
			
			// Zusatzbilder - gibts nicht
			/*$pics = split(";", $row['ImagesSlideShowString']);
			//print_r($pics);
			$has_pics = false;
			for ($i = 2; $i<=7; $i++) {
				if (!empty($pics[$i - 2])) {
					if (strtolower($pics[$i - 2]) !== $pics[$i - 2]) {
						print "\nWARN: Please rename ".$pics[$i - 2]." to LOWERCASE! OxidShop have problems with mixed case filenames!";
					}
					$map_curr['OXPIC' . $i] = '"'.strtolower($pics[$i - 2]).'"';
					$has_pics = true;
				}
			}*/
			/*// nur Bilder migrieren? dann aktivieren
			if (!$has_pics) {
				continue;
			}*/
			// Bild Kleinbuchstaben
			$row['ImageLarge'] = strtolower($row['ImageLarge']);
			
			// Hersteller mit in Kurzbeschreibung
			if (!empty($row['Manufacturer'])) {
				$map_curr['OXSHORTDESC'] = 'CONCAT("#Description/de#"," ","#Manufacturer#")';
			}
			// Beschreibungen
			if (empty($row['LongDescription/de'])) {
				$row['LongDescription/de'] = trim($row['Description/de']);
			}
			$row['Description/de'] = trim(strip_tags($row['Description/de']));
			// Parent..
			$row['SuperProduct'] = $this->_calcOXID($row['SuperProduct']);
			if (!empty($row['SuperProduct'])) {
				if ($row['SuperProduct'] == $row['Alias']) {
					print "\n!WARNUNG: Zirkelbezug! OXID (Alias, '" . $row['Alias']."' und OXPARENT (SuperProduct, '" . $row['SuperProduct'] . "') sind gleich! Ändere Alias!\n";
					$row['AliasAlt'] = $row['Alias'] . "_REKURSIV";
					$map_curr['OXID'] = "MD5('#AliasAlt#')";
				}			
				$map_curr['OXPARENTID'] = 'MD5("#SuperProduct#")';
				
				$oxparent = md5($row['SuperProduct']);
				// Varianten zählen
				if (empty($this->_varcount[$oxparent])) {
					$this->_varcount[$oxparent] = 1;
				}
				$this->_varcount[$oxparent]++;
				// Min-Preis tracken
				$varprice = floatval(preg_replace('/,/','.',$row['ListPrice/EUR/net']));
				if (empty($this->_varminprice[$oxparent])) {
					$this->_varminprice[$oxparent] = $varprice;
				} else if ($this->_varminprice[$oxparent] > $varprice) {
					//print "\n" . 'neuer Min-Preis: '.$varprice.' (war: '.$this->_varminprice[$oxparent].')'; sleep(1);
					$this->_varminprice[$oxparent] = $varprice;
				}
			}
			// Attribute ..
			$varname = $this->_oxarticlesProcessAttributes($row);
			if (!empty($varname)) {
				$map_curr['OXVARNAME'] = '"' . ($varname) . '"';
				$map_curr['OXVARNAME_1'] = '"' . ($varname) . '"';
				$map_curr['OXVARSELECT'] = '"' . ($varname) . '"';
				$map_curr['OXVARSELECT_1'] = '"' . ($varname) . '"';
			}
			
			// SQL erzeugen
			$sql = $this->_sql($this->_tab, $map_curr, $row);
			//print "\n". $sql;
			
			// lange Artikelbeschreibung
			$sql = $this->_sql("oxartextends", $map_longdesc, $row);
			//print "\n". $sql;
			
			// nur leere Felder aktualisieren
			//$sql = $this->_sqlUpdateIfEmpty($this->_tab, $map_curr, $row);
			//print "\n". implode("\n",$sql);
			
		}
		$this->_oxarticlesUpdateVarCount();
	}
	
	protected function _oxarticlesUpdateVarCount()
	{
		$map = array(
			'OXID' => '"#Oxid#"',
			'OXVARCOUNT' => '"#VarCount#"',
			'OXVARMINPRICE' => '"#VarMinPrice#"',
		);
		foreach ($this->_varcount as $oxid => $count) {
			$sql = "UPDATE oxarticles SET OXVARCOUNT=" . $count 
				 . (!empty($this->_varminprice[$oxid]) ? ", OXVARMINPRICE='" . number_format($this->_varminprice[$oxid],2,',','') . "'" : "")
				 . " WHERE OXID='" . $oxid . "';";
			file_put_contents($this->_sqlout, $sql . ";\n", FILE_APPEND);
			print "\n$sql";
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
			'OXCATNID' => 'MD5("#Category#")',
			'OXOBJECTID' => 'MD5("#Product#")',
			'OXSHOPID' => '"oxbaseshop"',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			$n++;
			//print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// Kategoriename
			$row['Category'] = $this->_oxcategoriesParseParent($row['Category']);
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
			'OXSHOPID' => '"oxbaseshop"',
			'OXSORT' => '"#Position#"',
			'OXACTIVE' => '"#IsVisible#"',
			'OXTITLE' => '"#Name/de#"',
			'OXTITLE_' => '"#Name/de#"',
			'OXDESC' => '"#Category/Text/de#"',
			'OXDESC_' => '"#Category/Text/de#"',
			'OXLONGDESC' => '"#Category/LongText/de#"',
			'OXLONGDESC_' => '"#Category/LongText/de#"',
			'OXTHUMB' => '"#Image#"',
		);
		foreach ($rows as $row) {
			$row = $this->_convertRowKeys($row);
			if ($row['Class'] != 'Category') {
				continue;
			}
			$n++;
			print "\n".str_repeat("-",80);
			print "\nprocess row $n .."; 
			//print_r($row);
			$map_curr = $map;
			// ID
			$row['Alias'] = $this->_calcOXID($row['Alias']);
			// Parent..
			$row['Parent'] = $this->_oxcategoriesParseParent($row['Parent']);
			if (!empty($row['Parent'])) {
				// Unterkategorie
				if ($row['Parent'] == $row['Alias']) {
					print "\n!WARNUNG: Zirkelbezug! OXID (Alias, '" . $row['Alias']."' und OXPARENT (Parent, '" . $row['Parent'] . "') sind gleich! Ändere Alias!\n";
					$row['Alias'].= "_REKURSIV";

				}
				$map_curr['OXPARENTID'] = 'MD5("#Parent#")';
			} else {
				// Hauptkategorie...
				$map_curr['OXPARENTID'] = '"oxrootid"';
				$map_curr['OXROOTID'] = 'MD5("#Alias#")'; // = OXID
			}
			// Texte: alte Bilder parsen
			$row['Category/Text/de'] = $this->_oxcategoriesProcessStratoPics($row['Category/Text/de']);
			$row['Category/LongText/de'] = $this->_oxcategoriesProcessStratoPics($row['Category/LongText/de']);
			// SQL erzeugen
			$sql = $this->_sql($this->_tab, $map_curr, $row);
			print "\n". $sql;
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
	
	protected function _createAttributes()
	{
		foreach ($this->_attributes as $attr) {
			$sets = array(
				"OXID = MD5('" . $attr['titel'] . "')",
				"OXTITLE = '" . $attr['titel'] . "'",
				"OXTITLE_1 = '" . $attr['titel'] . "'",
				"OXSHOPID = 'oxbaseshop'",
			);	 
			$sql = "REPLACE INTO oxattribute SET " . join(", ", $sets);
			file_put_contents($this->_sqlout, $sql . ";\n", FILE_APPEND);
			print "\n$sql";
		}
	}
	
	protected function _oxcategoriesParseParent($_parent)
	{
		$parent = $_parent;
		$parent = preg_replace('/ \/ /',' ', $parent);
		if (preg_match('/\//',$parent)) {
			$parent = preg_replace('/^.+\//','',$parent);
		}
		$parent = $this->_calcOXID($parent);
		//print "\nparent: $_parent -> $parent";
		return $parent;
	}
	
	protected function _oxcategoriesProcessStratoPics($_text)
	{
		$text = $_text;
		while (preg_match('/"\/WebRoot\/ncs1\/Shops\/9360188\/MediaGallery\/(.*?)"/',$text,$match)) {
			$text = preg_replace('/'.preg_quote($match[0],'/').'/','"/out/pictures/legacy/categories/' . $match[1] . '"', $text);
			$this->_legacypics[] = $match[0].' -> '.'/out/pictures/legacy/categories/' . $match[1];
		}
		return $text;
	}
	
	protected function _calcOXID($name)
	{
		$name = preg_replace('/[^a-zA-Z0-9]/','',$name);
		return $name;
	}
	
	protected function _oxarticlesProcessAttributes($row)
	{
		$map = array(
			'OXID' => 'MD5(CONCAT("#$col#","#Alias#"))', // $col + #Alias#
			'OXATTRID' => 'MD5("#$attr[titel]#")', // $attr['titel']
			'OXOBJECTID' => 'MD5("#Alias#")',
			'OXVALUE' => '"#$col#"',
		);
		$varname = '';
		foreach ($this->_attributes as $attr) {
			foreach ($attr['cols'] as $col) {
				//print "\n attr $col? ".(!empty($row[$col])?1:0);
				if (!empty($row[$col])) {
					$map_curr = $map;
					$map_curr['OXID'] = 'MD5(CONCAT("#' . $col . '#","#Alias#"))';
					$map_curr['OXATTRID'] = 'MD5("' . $attr['titel'] . '")';
					$map_curr['OXVALUE'] = '"#' . $col . '#"';
					$sql = $this->_sql('oxobject2attribute', $map_curr, $row);
					//print "\n". $sql;
					$varname.= (!empty($varname) ? ' | ' : '') . $row[$col];
				}
			}
		}
		return $varname;
	}
}

