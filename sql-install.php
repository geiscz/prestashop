<?php
/*
* (c) 2015 Geis CZ s.r.o.
*/
  $sql = array(
      'create table if not exists `'._DB_PREFIX_.'geispoint_order` (
          `id_order` int,
          `id_cart` int,
          `id_gp` varchar(11) not null,
          `exported` tinyint(1) not null default 0,
          unique(id_order),
          unique(id_cart)
      ) engine='._MYSQL_ENGINE_.' default charset=utf8;',
      'create table if not exists `'._DB_PREFIX_.'geispoint_carrier` (
          `id_carrier` int not null primary key,
          `list_type` tinyint not null
      ) engine='._MYSQL_ENGINE_.' default charset=utf8;'
  );
?>