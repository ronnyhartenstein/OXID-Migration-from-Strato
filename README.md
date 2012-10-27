OXID-Migration-from-Strato
==========================

Blog-Post: http://rh-flow.de/php/2012/daten-migration-von-strato-epages-zu-oxid-eshop-ce

Wer die Aufgabe hat alle Daten von einen Strato ePages Webshop oder Onlineshop zu OXID eShop CE (Community Edition) zu migrieren, wird feststellen, dass man dazu kaum Infos im Netz finden kann. Auch im OXID Forum findet man nur Fragen aber keine Antworten. Klar, gegen Entgeld kann man sich Knowhow einkaufen bzw. auch die Migration machen lassen. Aber das entfällt, da der zu migrierende Shop aktuell noch kaum Gewinn abwirft.

Es gilt also folgende Ansätze zu verfolgen:

    OXIDs eigener Import-Assisten
    Migration über anderes Shopsystem wie Magento
    Eigenes Migrationsscript

OXID eShop build-in Import-Assistenten

OXID eShop CE bringt von Haus aus einen Import/Export-Assistenten mit.

Hier eine kleine Übersicht wie fummelig das ganze ist:

Schritt 1 - CSV-Datei hochladen

Schritt 2: Felder zuordnen

Bei den über 20 Feldern artet das ganz schön aus - und weitergehend umkonfigurieren kann man es leider auch nicht.
Idee: Zwischenschritt über anderes großes Shopsystem

Vielleicht unterstützten andere große Shopsysteme wie Magento od. Shopware einen direkten Import der CSV-Export-Dateien von Stratos ePages Webshop? Durch die noch größeren Communitys lag der Gedanke nahe. Aber auch da: keine Infos od. konkrete Ansätze sind zu finden.
Lösung: Eigenes Migrationsscript für höchste Customiziung-Ansprüche

Also blieb nur "selbermachen"....

Folgender Ansatz: Ein PHP-Script leitet aus den CSV-Daten über  ein Regelwerk und Umformungen die entsprechenden SQL-Statements ab. Diese werden dann direkt in die Datenbank gekippt.

Hinweis: Es werden REPLACE INTO Statements statt INSERT INTO verwendet, damit man diese auch mehrfach (für Tests) ausführen kann.

Das vollständige PHP-Script: migration.php (bei GitHub gehostet)

Das Script ist als CLI-Variante ausgelegt. Daher folgende Aufruf-Parameter:

migraton.php ["table"] ["source.csv"] ["output.sql"] [update]
    table: oxarticles | oxobject2category | oxcategories | oxuser
    source.csv: e.g.. "Kategorie-Produkt-Zuweisung.csv"
    output.sql: e.g. "oxobject2category.sql"
    update: just "update" - force UPDATE-SQL-statements instead of REPLACE-INTO

Wird es ohne Parameter aufgerufen greifen automatisch die Standard-Dateinamen von Strato zusammen mit den OXID-Tabellennamen.

$list = array(
    'oxarticles' => 'Produkte'.($test?'_test':'').'.csv',
    'oxobject2category' => 'Kategorie-Produkt-Zuweisung'.($test?'_test':'').'.csv',
    'oxcategories' => 'Inhalt__Kategorien__Seiten'.($test?'_test':'').'.csv',
    'oxuser' => 'Kunden'.($test?'_test':'').'.csv',
);

Konzeptionell wird je Tabelle ein Key-Value-Hash definiert, in dem je Key (OXID-Tabelle-Feldname) ein Value mit Platzhaltern für die Daten aus der CSV aufgebaut wird. Am Beispiel vom oxarticles sieht man wie das Mapping generell geregelt ist.

    public function oxarticles()
    {
        $csv = $this->_getCSV();
        $rows = $csv->getRows();
        $n = 0;
        $map = array(
            'OXID' => 'MD5("#Alias#")',
            'OXSHOPID' => '"oxbaseshop"',
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
                    if (strtolower($pics[$i - 2]) !== $pics[$i - 2]) {
                        print "\nWARN: Please rename ".$pics[$i - 2]." to LOWERCASE! OxidShop have problems with mixed case filenames!";
                    }
                    $map_curr['OXPIC' . $i] = '"'.strtolower($pics[$i - 2]).'"';
                    $has_pics = true;
                }
            }
            /*// nur Bilder migrieren? dann aktivieren
            if (!$has_pics) {
                continue;
            }*/

            // Hersteller mit in Kurzbeschreibung
            if (!empty($row['Manufacturer'])) {
                $map_curr['OXSHORTDESC'] = 'CONCAT("#Description/de#"," ","#Manufacturer#")';
            }
            // Parent..
            $row['SuperProduct'] = trim($row['SuperProduct']);
            if (!empty($row['SuperProduct'])) {
                $map_curr['OXPARENTID'] = 'MD5("#SuperProduct#")';
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
    }

Über die Funktion _sql wird dann die SQL aufgebaut und auch gleich in die SQL-Output-Datei geschrieben.

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

Die Platzhalter in den Value-Definitionen wird über die Fkt. _replacePlaceholderForSql ersetzt.

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

Als Ergebnis schreibt das Script SQL-Output-Dateien, die z.B. bei oxarticles folgenden Inhalt haben.

REPLACE INTO oxarticles SET OXID = MD5("TS_3922"), OXARTNUM = "TS_3922", OXTITLE = "Test 1", OXSHORTDESC = "",
     OXPRICE = "54.9", OXACTIVE = 1, OXPIC1 = "test1.jpg", OXSTOCK = 1, OXLENGTH = 0, OXHEIGHT = 0, OXWIDTH = 0,
     OXWEIGHT = 1, OXSORT = 0, OXMPN = 0, OXVARNAME = "";
REPLACE INTO oxartextends SET OXID = MD5("TS_3922"), OXLONGDESC = "<p>Eine lange Beschreibung zum Artikel</p>";
REPLACE INTO oxarticles SET OXID = MD5("TS_2289"), OXARTNUM = "TS_2289", OXTITLE = "Test 2", OXSHORTDESC = "",
     OXPRICE = "3.49", OXACTIVE = 1, OXPIC1 = "test2.jpg", OXSTOCK = 3, OXLENGTH = 0, OXHEIGHT = 0, OXWIDTH = 0,
     OXWEIGHT = 0, OXSORT = 10, OXMPN = 0, OXVARNAME = "";
REPLACE INTO oxartextends SET OXID = MD5("TS_2289"), OXLONGDESC = "<p>Mein Testartikel.</p>";

Viel Spaß beim Ausprobieren und Verwenden!
Wer einigermaßen fit in PHP ist wird sich schnell reinfinden und kann das Script auch für andere Quell-Shopsysteme umbauen.
Gern könnt ihr mir diese dann zuschicken und ich veröffentlich sie hier.
Zusatz: Variante für nachträgliches Bildernamen migrieren

Problem: Bildnamen müssen in OXID eShop in Kleinschreibung sein, da sie sonst a) nicht erkannt und b) beim Speichern eines Artikels auch gelöscht werden.

Lösung: migration_pics.php und files_to_lower.php

Das migrations-Script ist eine leicht modifizierte Variante vom Originalscript und erzeugt UPDATE-Statements je PIC und je Artikel. Das Ergebnis sind dann SQLs wie folgt.

UPDATE oxarticles SET OXPIC3 = "rotgrosskomp.jpg" WHERE (OXPIC3 = '' OR OXPIC3 IS NULL) AND OXID=MD5("K_64597");
UPDATE oxarticles SET OXPIC4 = "rotkomp.jpg" WHERE (OXPIC4 = '' OR OXPIC4 IS NULL) AND OXID=MD5("K_64597");
UPDATE oxarticles SET OXPIC2 = "indioschwarzblaukomp.jpg" WHERE (OXPIC2 = '' OR OXPIC2 IS NULL)
    AND OXID=MD5("K_64597-0001");

Platziert die files_to_lower.php im Verz. ./out/pictures/master/product
Ruft diese via Browser auf http://deinshop.tld/out/pictures/master/product/files_to_lower.php
Alle Bildnamen mit Großbuchstaben werden in Kleinschreibweise umbenannt.

Anschließen platziert die migration_pics.php in euern Migrations-Verz. mit den ganzen CSVs die ihr von Strato exportiert habt.
Dann folgender Aufruf:

php migration_pics.php oxarticles Produkte.csv oxarticles_Produkte.sql update

