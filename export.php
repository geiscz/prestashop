<?php
/*
* (c) 2015 Geis CZ s.r.o.
*/
  require_once('../../config/config.inc.php');
  require_once('../../init.php');
  require_once('./AdminOrderGeispoint.php');
  
    function csv_escape($s)
    {
        return str_replace('"', '""', $s);
    }
  
        header('Content-Encoding: UTF-8');
        header('Content-type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"export-" . date("Ymd-His") . ".csv\"");
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        $db = Db::getInstance();
        
        $ids = array_map('floor', is_array(Tools::getValue('geispoint_order_id')) && count(Tools::getValue('geispoint_order_id')) > 0 ? Tools::getValue('geispoint_order_id') : array(0));
        
        $query = '
SELECT
        o.id_order
        ,a.firstname
        ,a.lastname
        ,a.phone
        ,a.phone_mobile
        ,c.email
        ,o.total_paid total
        ,go.id_gp
        ,o.id_currency
        ,a.company
        ,a.address1
        ,a.address2
        ,a.postcode
        ,a.city
        ,coun.iso_code
        ,a.phone
        ,a.phone_mobile
        ,o.reference
        ,sum(product.weight * cartproduct.quantity) totalweight
    FROM
        `'._DB_PREFIX_.'orders` o
            JOIN `'._DB_PREFIX_.'geispoint_order` go
                ON (go.id_order = o.id_order)
            JOIN `'._DB_PREFIX_.'customer` c
                ON (c.id_customer = o.id_customer)
            JOIN `'._DB_PREFIX_.'address` a
                ON (a.id_address = o.id_address_delivery)
            JOIN `'._DB_PREFIX_.'country` coun
                ON (a.id_country = coun.id_country)
            JOIN `'._DB_PREFIX_.'cart` cart
                ON (go.id_cart = cart.id_cart)
            JOIN `'._DB_PREFIX_.'cart_product` cartproduct
                ON (cart.id_cart = cartproduct.id_cart)
            JOIN `'._DB_PREFIX_.'product` product
                ON (product.id_product = cartproduct.id_product)
                WHERE o.id_order in (' . implode(',', $ids) . ')'.
    'GROUP BY
        o.id_order
        ,a.firstname
        ,a.lastname
        ,a.phone
        ,a.phone_mobile
        ,c.email
        ,o.total_paid
        ,go.id_gp
        ,o.id_currency
        ,a.company
        ,a.address1
        ,a.address2
        ,a.postcode
        ,a.city
        ,coun.iso_code
        ,a.phone
        ,a.phone_mobile
        ,o.reference
        ,o.total_products_wt';
    
        
        $data = $db->executeS($query);
        
        $cnb_rates = null;
        echo 'Číslo dokladu;Příjemce - název;Příjemce - stát;Příjemce - město;Příjemce - ulice;Příjemce - PSČ;Příjemce - kont.osoba;Příjemce - kont.email;Příjemce - kont.tel.;Datum svozu;Reference;EXW (1-ano);Dobírka(1-ano);Hodnota dobírky;Variabilní symbol;Hmotnost;Objem;Počet;Popis zboží;Typ obalu;Typ zakázky (1-parcel,0-cargo);Pozn.příjemce;Pozn.řidič;Připojištění (1-ano);Hodnota připojištění;Avízo doruč.zás.;Avízo doruč.zás.tel.em;Avízo.pošk.zás;Avízo.pošk.zás.tel.em;Avízo.probl.zás.;Avízo.probl.zás.tel.em;B2C;Doručení do 12h;Gar.doruč.;POD avízo;POD email;SMS avízo;Tel.avízo;Příjemce-č.p.;Příjemce-č.o.;CD jméno;CD Typ CD;CD Č.auta;CD Datum dodání do DC;Email přijemci;Platba kartou;Kod VM;Nevyplnovat adr. prijemce'."\r\n";
        foreach($data as $order) {
            $phone = "";
            foreach(array('phone', 'phone_mobile') as $field) {
                if(preg_match('/^(((?:\+|00)?420)?[67][0-9]{8}|((?:\+|00)?421)?9[0-9]{8})$/', preg_replace('/\s+/', '', $order[$field]))) {
                    $phone = trim($order[$field]);
                }
            }
            $currency = new Currency($order['id_currency']);
            $total = $order['total'];
 
            echo ''
                    .'"'.csv_escape($order['id_order']).'";'//1.Číslo dokladu
                    .'"'.csv_escape($order['company']).' '.csv_escape($order['firstname']).' '.csv_escape($order['lastname']).'";'//2.Příjemce-název
                    .'"'.csv_escape($order['iso_code']).'";'//3.Příjemce-stát
                    .'"'.csv_escape($order['city']).'";'//4.Příjemce město
                    .'"'.csv_escape($order['address1'] . ($order['address2'] ? ", " . $order['address2'] : "")).'";'//5.Příjemce ulice
                    .'"'.csv_escape(str_replace(' ','',$order['postcode'])).'";'//6.Příjemce PSČ
                    .'"";'//7.Příjemce kontaktní osoba
                    .'"'.csv_escape($order['email']).'";'//8.Příjemce kontaktní email
                    .'"'.csv_escape($order['phone'] . ($order['phone_mobile'] && $order['phone'] ? ", " . $order['phone_mobile'] : $order['phone_mobile'] ? $order['phone_mobile'] : "")).'";'//9.Příjemce kontaktní telefon
                    .';'//10.Datum svozu (když nebude vyplněno, nastaví se aktuální den)
                    .'"'.csv_escape($order['reference']).'";'//11.Reference
                    .'"0";'//12.EXW (ano 1, ne 0)
                    .'"0";'//13.Dobírka (ano 1, ne 0)
                    .';'//14.Hodnota dobírky
                    .';'//15.Variabilní symbol
                    .'"'.csv_escape(str_replace('.',',',$order['totalweight'])).'";'//16.Hmotnost
                    .';'//17.Objem
                    .'"1";'//18.Počet
                    .'"";'//19.Popis zboží
                    .'"";'//20.Typ obalu jedna z hodnot uvedených v tabulce typů obalů viz níže
                    .'"1";'//21.Typ zakázky (parcel 1, cargo 0)
                    .'"";'//22.Poznámka pro příjemce (volitelné, nemusí být vyplněno)
                    .'"";'//23.Poznámka pro řidiče (volitelné, nemusí být vyplněno)
                    .'"0";'//24.Připojištění (ano 1, ne 0)
                    .'"";'//25.Hodnota připojištění
                    .'"0";'//26.Avízo doručené zásilky (ano 1, ne 0)
                    .'"";'//27.Avízo doručené zásilky telefonní číslo nebo E mail
                    .'"0";'//28.Avízo poškozené zásilky (ano 1, ne 0)
                    .'"";'//29.Avízo poškozené zásilky telefonní číslo nebo E mail
                    .'"0";'//30.Avízo problémové zásilky (ano 1, ne 0)
                    .'"";'//31.Avízo problémové zásilky telefonní číslo nebo E mail
                    .'"0";'//32.B2C soukromá adresa (ano 1, ne 0)
                    .'"0";'//33.Doručení do 12 hod (ano 1, ne 0)
                    .'"0";'//34.Garantované doručení (ano 1, ne 0)
                    .'"0";'//35.POD avízo (ano 1, ne 0)
                    .'"";'//36.POD E mail
                    .'"0";'//37.SMS avízo (ano 1, ne 0)
                    .'"0";'//38.Telefonické avízo (ano 1, ne 0)
                    .'"";'//39.Příjemce číslo popisné (volitelné, nemusí být vyplněno)
                    .'"";'//40.Příjemce číslo orientační (volitelné, nemusí být vyplněno)
                    .'"";'//41.CrossDock jméno (pouze pro CD)
                    .'"";'//42.CrossDock Typ CD svozu (vlastní 1, geis 2, pouze pro CD)
                    .'"";'//43.CrossDock Číslo auta v případě vlastního dodání(pouze pro CD)
                    .';'//44.CrossDock Datum dodání do DC (pouze pro CD)
                    .'"0";'//45.Email příjemce (ano 1, ne 0)
                    .'"";'//46.Platba kartou (ano 1, ne 0)
                    .'"'.csv_escape($order['id_gp']).'";'//47.Zásilka na výdejní místo - kód VM
                    .'"0";'//48.Zásilka VM - nevyplňovat adresu příjemce(0 nebo prázdné - ne, 1 - ano)
                    ."\r\n";
        }
        $db->execute('update `'._DB_PREFIX_.'geispoint_order` set exported=1 where id_order in(' . implode(',', $ids) . ')');
        
        exit();
?>