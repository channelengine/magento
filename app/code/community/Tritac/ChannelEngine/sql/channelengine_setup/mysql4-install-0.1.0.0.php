<?php

$installer = $this;

/**
 * Add new column to table "sales_flat_order" that storage "ChannelEngine Order Id"
 */
$installer->getConnection()->addColumn(
    $installer->getTable('sales/order'),
    'channelengine_order_id',
    array(
        'type'      => Varien_Db_Ddl_Table::TYPE_INTEGER,
        'comment'   => 'ChannelEngine Order Id'
    )
);

/**
 * Create new columns that storage "ChannelEngine Order Line Id"
 */
$tables = array(
    'sales/quote_item',
    'sales/order_item'
);
$args = array(
    'type'      => Varien_Db_Ddl_Table::TYPE_INTEGER,
    'comment'   => 'ChannelEngine Order Line Id'
);
foreach($tables as $tableName) {
    $installer->getConnection()->addColumn(
        $installer->getTable($tableName),
        'channelengine_order_line_id',
        $args
    );
}