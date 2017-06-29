<?php
/*
* (c) 2015 Geis CZ s.r.o.
*/

if(!defined('_PS_VERSION_')) {
    exit();
}

require_once(dirname(__FILE__) . '/geispoint.php');

class AdminOrderGeispoint extends ModuleAdminController
{
    

    public function __construct()
    {
        $this->ensure_initialized();
        parent::__construct();
    }
    
    private $initialized = false;
    private function ensure_initialized()
    {
        if($this->initialized) return;
        
        $this->table = 'geispoint_order';
        
        $this->initialized = true;
    }

    
   
    
    public function renderList()
    {
        $content = "";
        $content .= '<h2>' . $this->l('Export objednávek do Geis Point') . '</h2>';

        
        $db = Db::getInstance();

        $content .= "<fieldset><legend>" . $this->l('Seznam objednávek') . "</legend>";
        $content .= "<form method='post' action='"._MODULE_DIR_."geispoint/export.php'>";
        $sql_from = '
            from
                `'._DB_PREFIX_.'orders` o
                join `'._DB_PREFIX_.'geispoint_order` go on(go.id_order=o.id_order)
                join `'._DB_PREFIX_.'customer` c on(c.id_customer=o.id_customer)';
        $items = $db->getValue('select count(*) ' . $sql_from);
        $per_page = 50;
        $page = (Tools::getIsset('geispoint_page') && Tools::getValue('geispoint_page') > 0 ? (int) Tools::getValue('geispoint_page') : 1);
        $paging = '';
        if($items > $per_page) {
            $paging .= "<p>" . $this->l('Stránky') . ": ";
            for($i = 1; $i <= ceil($items / $per_page); $i++) {
                if($i == $page) $paging .= '<strong>&nbsp;'.$i.'&nbsp;</strong> ';
                else $paging .= '<a href="'.$_SERVER[REQUEST_URI].'&geispoint_page='.$i.'">&nbsp;'.$i.'&nbsp;</a> ';
            }
            $paging .= "</p>";
        }
        $content .= $paging;

        $content .= "<table id='geispoint-order-export' class='table'>";
        $content .= "<tr><th>".$this->l('Obj.č.')."</th><th>".$this->l('Zákazník')."</th><th>".$this->l('Celková cena')."</th><th>".$this->l('Datum objednávky')."</th><th>" . $this->l('Výdejní místo') . "</th><th>" . $this->l('Exportováno') . "</th></tr>";
        $orders = $db->executeS('
            select
                o.id_order,
                o.id_currency,
                o.id_lang,
                concat(c.firstname, " ", c.lastname) customer,
                o.total_paid total,
                o.date_add date,
                go.id_gp,
                go.exported
            ' . $sql_from . ' order by o.date_add desc limit ' . (($page - 1) * $per_page) . ',' . $per_page
        );
        foreach($orders as $order) {
            $content .= "<tr" . ($order['exported'] == 1 ? " style='background-color: #ddd'" : '') . "><td><input name='geispoint_order_id[]' value='$order[id_order]' type='checkbox'> $order[id_order]</td><td>$order[customer]</td><td align='right'>" . Tools::displayPrice($order['total'], new Currency($order['id_currency'])) . "</td><td>" . Tools::displayDate($order['date'], null, true) . "</td>";
            $content .= "<td>$order[id_gp]</td><td>" . ($order['exported'] == 1 ? $this->l('Yes') : $this->l('No')) . "</td></tr>";
        }

        $content .= "</table>";
        $content .= $paging;
        $content .= "<br><input onclick ='setTimeout(reloadPage, 2000);' type='submit' value='" . htmlspecialchars($this->l('Exportovat vybrané'), ENT_QUOTES) . "' class='button'>";
        $content .= "</fieldset>";
        $content .= "</form>"."\r\n";
        $content .= "<script type='text/javascript'>"."\r\n";;
        $content .= "function reloadPage() {"."\r\n";;
        $content .= "window.location.href=window.location.href;"."\r\n";;
        $content .= "}"."\r\n";;
        $content .= "</script>"."\r\n";;
        
        return $content;
    }
}
