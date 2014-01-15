<?php

$installer = $this;

/**
 * Add new column to table "sales_flat_order"
 */
$installer->getConnection()->addColumn(
    $installer->getTable('sales/order'),
    'channelengine_order_id',
    array(
        'type'      => Varien_Db_Ddl_Table::TYPE_TEXT,
        'length'    => 255,
        'comment'   => 'ChannelEngine Order Id'
    )
);