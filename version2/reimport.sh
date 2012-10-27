#!/bin/bash

for FILE in ox_cleanup 	oxarticles_artikel 	oxarticles_artikel_1 	oxarticles_artikel_2 	oxarticles_artikel_3 	oxarticles_artikel_4 	oxcategories_kategorien_1	oxobject2category_kategorie_produktzuweisung 
do
	echo "process $FILE ...";
	mysql -uroot ??? --default-character-set=latin1 < $FILE.sql
done
