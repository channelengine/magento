<?php

$installer = $this;
$installer->startSetup();

/**
 * Create table 'channelengine/order'
 */
$table = $installer->getConnection()
    ->newTable($installer->getTable('channelengine/order'))
    ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array('identity' => true, 'nullable' => false, 'primary' => true), 'Entity ID')
    ->addColumn('order_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, array('nullable' => false, 'unsigned' => true), 'Order ID')
    ->addColumn('channel_order_id', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(), 'Channel Order ID')
    ->addColumn('channel_name', Varien_Db_Ddl_Table::TYPE_TEXT, 255, array(), 'Channel Name')
    ->addColumn('can_ship_partial', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, array('nullable' => false, 'default' => '1'), 'Can Ship Partial Order Lines')
    ->setComment('ChannelEngine Order Table');

$installer->getConnection()->createTable($table);

$installer->endSetup();