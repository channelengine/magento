<?php

$installer = $this;
$installer->startSetup();

/**
 * Create table 'channelengine/order'
 */
$table = $installer->getConnection()
    ->newTable($installer->getTable('channelengine/order'))
    ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
        'identity'  => true,
        'nullable'  => false,
        'primary'   => true,
    ), 'Entity ID')
    ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
        'nullable'  => false,
        'unsigned'  => true,
    ), 'Order ID')
    ->addColumn('channel_order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array(
        'nullable'  => false,
        'unsigned'  => true,
    ), 'Channel Order ID')
    ->addColumn('channel_name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(), 'Channel Name')
    ->addColumn('do_send_mails', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
        'nullable'  => false,
        'default'   => '0'
    ), 'Do Send Mails')
    ->addColumn('can_ship_partial', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array(
        'nullable'  => false,
        'default'   => '1'
    ), 'Can Ship Partial Order Lines')
    ->setComment('ChannelEngine Order Table');
$installer->getConnection()->createTable($table);

///**
// * Add new column to table "sales_flat_order" that storage "ChannelEngine Order Id"
// */
//$installer->getConnection()->addColumn(
//    $installer->getTable('sales/order'),
//    'channelengine_order_id',
//    array(
//        'type'      => Varien_Db_Ddl_Table::TYPE_INTEGER,
//        'comment'   => 'ChannelEngine Order Id'
//    )
//);

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

$installer->endSetup();