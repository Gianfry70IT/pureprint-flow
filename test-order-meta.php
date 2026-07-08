<?php
require_once 'wp-load.php';
$orders = wc_get_orders(array('limit' => 5, 'orderby' => 'date', 'order' => 'DESC'));
foreach ($orders as $order) {
    echo "Order ID: " . $order->get_id() . "\n";
    foreach ($order->get_items() as $item_id => $item) {
        echo "  Item ID: $item_id\n";
        echo "  _pureprint_file_name: " . $item->get_meta('_pureprint_file_name') . "\n";
        echo "  _pureprint_bleed_mm: " . $item->get_meta('_pureprint_bleed_mm') . "\n";
    }
}
