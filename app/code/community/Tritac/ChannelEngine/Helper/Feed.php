<?php

class Tritac_ChannelEngine_Helper_Feed extends Mage_Core_Helper_Abstract {
	
	private $helper;
	private $feedDir;
	private $stores;
	private $config;

	const ATTRIBUTES_LIMIT = 30;
	
	public function __construct()
	{
		$this->feedDir = Mage::getBaseDir('media') . DS . 'channelengine' . DS;
		$this->stores = [];
		$this->config = [];

		$this->helper = Mage::helper('channelengine');
		$this->stores = Mage::app()->getStores();
		$this->config = $this->helper->getConfig();

		Mage::app()->loadAreaPart(Mage_Core_Model_App_Area::AREA_FRONTEND, Mage_Core_Model_App_Area::PART_EVENTS);
	}

	/**
	 * Generate products feed for ChannelEngine
	 */
	public function generateFeeds()
	{
		@set_time_limit(15 * 60);

		foreach($this->stores as $store)
		{
			$this->generateFeed($store);
		}

		return true;
	}

	public function generateFeed($store)
	{
		Mage::app()->setCurrentStore($store);
		$storeId = $store->getId();

		$config = $this->config[$storeId];

		if(!$this->helper->isConnected($storeId)) return;

		$memoryUsage = memory_get_usage();
		$tenant = $config['general']['tenant'];

		$name = $tenant.'_products.xml';
		$file = $this->feedDir . DS . $name;

		$date = date('c');

		$io = new Varien_Io_File();
		$io->setAllowCreateFolders(true);
		$io->open(array('path' => $this->feedDir));
		$io->streamOpen($file, 'w+');
		$io->streamLock(true);
		$io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
		$io->streamWrite('<Products xmlns:xsd="http://www.w3.org/2001/XMLSchema"
			xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" GeneratedAt="'.$date.'">' . "\n");

		$collection = Mage::getResourceModel('catalog/product_collection');
		$flatCatalogEnabled = $collection->isEnabledFlat();

		$attributesInfo = $this->getAttributes($storeId, $flatCatalogEnabled);
		$attributesToSelect = $attributesInfo['attributesToSelect'];
		$visibleAttributes = $attributesInfo['visibleAttributes'];
		$systemAttributes = $attributesInfo['systemAttributes'];

		$categories = $this->getCategories($store);
		$options = $this->getOptions($storeId);

		// Make sure to create a new instance of our collection after setting the store ID
		// when using the flat catalog. Otherwise store ID will be ignored. This is a bug in magento.
		// https://magento.stackexchange.com/a/25908
		if($flatCatalogEnabled)
		{
			// The flat product entity has a setStoreId method, the regular entity does not have one
			$collection->getEntity()->setStoreId($storeId);
			$collection = Mage::getResourceModel('catalog/product_collection');
		} 

		// Only get simple products,
		$collection->addAttributeToSelect($attributesToSelect, 'left')
			->addFieldToFilter('type_id', array('in' => array('simple')))
			->addStoreFilter($store)
			->addAttributeToFilter('status', 1)
			->addAttributeToFilter('visibility', array('in' => array(
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)))
			->addAttributeToSort('entity_id', 'DESC');

		// Add qty and category fields to select
		$collection->getSelect()
			->joinLeft(
				array('csi' => Mage::getSingleton('core/resource')->getTableName('cataloginventory/stock_item')),
				'e.entity_id = csi.product_id',
				array('qty' => 'COALESCE(qty, 0)')
			)
			->joinLeft(
				array('ccp' => Mage::getSingleton('core/resource')->getTableName('catalog/category_product_index')),
				'e.entity_id = ccp.product_id AND ccp.store_id = ' . $storeId . ' AND is_parent = 1',
				array('category_id' => 'MAX(`ccp`.`category_id`)')
			)
			->joinInner(
				array('cce' => Mage::getSingleton('core/resource')->getTableName('catalog_category_entity')),
				'cce.entity_id = ccp.category_id AND cce.path LIKE ' . "'%/" . $store->getRootCategoryId() . "/%'",
				array()
			)
			->group('e.entity_id');

		// Iterate all simple products, except the invisible ones (they are most probably children of configurable products)
		$this->iterateProductCollection($io, $categories, $visibleAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $collection->getSelect(), false);

		// Update the query to select configurable products
		$collection->clear()->getSelect()->reset('where');
		$collection->addFieldToFilter('type_id', array('in' => array('configurable')))
			->addStoreFilter($store)
			->addAttributeToFilter('status', 1)
			->addAttributeToFilter('visibility', array('in' => array(
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
				Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)))
			->addAttributeToSort('entity_id', 'DESC');

		$this->iterateProductCollection($io, $categories, $visibleAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $collection->getSelect(), true);

		$io->streamWrite('</Products>');
		$io->streamUnlock();
		$io->streamClose();

		return true;
	}

	public function iterateProductsCallback($args)
	{
		$io = $args['io'];
		$row = $args['row'];
		$attributes = $args['visibleAttributes'];
		$systemAttributes = $args['systemAttributes'];
		$attributesToSelect = $args['attributesToSelect'];
		$categories = $args['categories'];
		$options = $args['options'];
		$store = $args['store'];
		$isConfigurable = $args['isConfigurable'];

		$storeId = $store->getId();
		$productResource = Mage::getResourceModel('catalog/product');
		$product = Mage::getModel('catalog/product');
		$product->setData($row);

		$config = $this->config[$storeId];

		$mediaGalleryAttr = $productResource->getAttribute('media_gallery');
		$mediaGalleryBackend = $mediaGalleryAttr->getBackend();
		$mediaGalleryBackend->afterLoad($product); 

		$additional = array(
			'systemAttributes' => $systemAttributes,
			'attributes' => $attributes,
			'store' => $store
		);


		$productData = $product->getData();
		if(!empty($config['general']['gtin'])) $productData['gtin'] = $productData[$config['general']['gtin']];
		$productData['url'] = $product->getProductUrl();
		$productData['images'] = $product->getMediaGalleryImages();
		$productData['price'] = $product->getFinalPrice();

		if(isset($options[$productData['entity_id']]))
		{
			$productData['parent_id'] = $productData['entity_id'];

			foreach($options[$productData['entity_id']] as $option)
			{
				if(isset($option['values']))
				{
					foreach($option['values'] as $value)
					{
						$variantData = $productData;
						$variantData['id'] = $variantData['entity_id'] . '_' . $option['option_id'] . '_' . $value->getId();

						if(isset($value['price']))
						{
							if($value['price_type'] == 'fixed')
							{
								$variantData['price'] = $variantData['price'] + $value['price'];
							}
							elseif($value['price_type'] == 'percent')
							{
								$variantData['price'] = $variantData['price'] + ($variantData['price'] * $value['price'] / 100);
							}
						}

						$additional['title'] = str_replace(' ', '_', $option['default_title']);
						$additional['value'] = $value->getDefaultTitle();

						$this->writeProduct($io, $variantData, $categories, $additional);
					}
				}
				else
				{
					$variantData = $productData;
					$variantData['id'] = $variantData['entity_id'] . '_' . $option['option_id'];
					$additional['title'] = str_replace(' ', '_', $option['default_title']);
					$additional['value'] = '';
					if(isset($option['price']))
					{
						if($option['price_type'] == 'fixed')
						{
							$variantData['price'] = $variantData['price'] + $option['price'];
						}
						elseif($option['price_type'] == 'percent')
						{
							$variantData['price'] = $variantData['price'] + ($variantData['price'] * $option['price'] / 100);
						}
					}
					$this->writeProduct($io, $variantData, $categories, $additional);
				}
			}
		}
		else
		{
			$productData['id'] = $productData['entity_id'];

			$this->writeProduct($io, $productData, $categories, $additional);
		}

		if(!$isConfigurable) return;

		$productAttributeOptions = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);

		$superAttributes = array();
		foreach($productAttributeOptions as $superAttribute)
		{
			foreach($superAttribute['values'] as $value)
			{
				$superAttributes[$superAttribute['attribute_code']][$value['value_index']] = $value;
			}
		}

		$childProducts = Mage::getModel('catalog/product_type_configurable')
			->getUsedProductCollection($product)
			->addAttributeToSelect($attributesToSelect)
			->getItems();

		foreach($childProducts as $child)
		{
			$childData = $child->getData();

			$childData['id'] = $childData['entity_id'];
			$childData['parent_id'] = $productData['id'];
			//$childData['price'] = $productData['price'];
			$childData['url'] = $productData['url'];
			$childData['description'] = $productData['description'];

			if(!isset($childData['images'])) {
				$childData['images'] = $productData['images'];
			} 

			if(!isset($childData['category_id'])) $childData['category_id'] = $productData['category_id'];

			if(isset($childData['stock_item']) && $childData['stock_item'] !== null) {
				$stock = $childData['stock_item']->getData();
				$childData['qty'] = $stock['qty'];
			}

			if(!isset($childData['image']) || $childData['image'] == 'no_slection') $childData['image'] = $productData['image'];

			foreach($superAttributes as $code => $superAttribute)
			{
				if(isset($childData[$code]))
				{
					$priceValue = $superAttribute[$childData[$code]]['pricing_value'];
					if($superAttribute[$childData[$code]]['is_percent']) {
						$newPrice = $childData['price'] + $childData['price'] * $priceValue / 100;
					} else {
						$newPrice = $childData['price'] + $priceValue;
					}
					$childData['price'] = $newPrice;
				}
			}

			$this->writeProduct($io, $childData, $categories, $additional);
		}
	}

	private function writeProduct($io, $product, $categories, $additional = null)
	{
		$store = $additional['store'];
		$storeId = $store->getId();

		$io->streamWrite('<Product>');
		$io->streamWrite('<Id>' . $product['id'] . '</Id>');

		// Add group code with product id if product have custom options
		if(isset($product['group_code'])) {
			$io->streamWrite('<GroupCode><![CDATA[' . $product['group_code'] . ']]></GroupCode>');
		}

		if(isset($product['parent_id'])) {
			$io->streamWrite('<ParentId><![CDATA[' . $product['parent_id'] . ']]></ParentId>');
		}

		$io->streamWrite('<Type><![CDATA[' . $product['type_id'] . ']]></Type>');
		$io->streamWrite('<Name><![CDATA[' . $product['name'] . ']]></Name>');
		$io->streamWrite('<Description><![CDATA['. $this->stripHtml($product['description']) . ']]></Description>');
		$io->streamWrite('<Price><![CDATA['. $product['price'] . ']]></Price>');
		$io->streamWrite('<ListPrice><![CDATA[' . $product['msrp'] . ']]></ListPrice>');
		if(isset($product['cost'])) {
			$io->streamWrite('<PurchasePrice><![CDATA[' . $product['cost'] . ']]></PurchasePrice>');
		}

		// Add product stock qty
		$io->streamWrite('<Stock><![CDATA[' . $product['qty'] . ']]></Stock>');

		// Add product SKU and GTIN
		$io->streamWrite('<SKU><![CDATA[' . $product['sku'] . ']]></SKU>');
		if(!empty($product['gtin'])) {
			$io->streamWrite('<GTIN><![CDATA[' . $product['gtin'] . ']]></GTIN>');
		}

		// VAT and Shipping Time are pre configured in extension settings
		if(!empty($this->config[$storeId]['optional']['vat_rate'])) {
			$vat = $this->config[$storeId]['optional']['vat_rate'];
			$io->streamWrite('<VAT><![CDATA[".$vat."]]></VAT>');
		}

		$shippingTime = ($product['qty'] > 0) ? $this->config[$storeId]['optional']['shipping_time'] : $this->config[$storeId]['optional']['shipping_time_oos'];

		if($shippingTime) {
			$io->streamWrite('<ShippingTime><![CDATA[' . $shippingTime . ']]></ShippingTime>');
		}

		$io->streamWrite('<Url><![CDATA[' . $product['url'] . ']]></Url>');
		$images = $product['images'];
		$i = 0;

		foreach($product['images'] as $image) {
			if($i == 0){ 
				$io->streamWrite('<ImageUrl><![CDATA[' . $image->getUrl() . ']]></ImageUrl>');
			} else {
				$io->streamWrite('<ImageUrl' . $i .'><![CDATA[' . $image->getUrl() . ']]></ImageUrl' . $i . '>');
			}
			$i++;
		}

		// Prepare category path
		//$io->streamWrite('<CategoryId><![CDATA[' . $product['category_id'] . ']]></CategoryId>');
		if(isset($product['category_id'])) {
			$categoryId = $product['category_id'];
			if(isset($categories[$categoryId])) {
				$categoryPathIds = explode('/', $categories[$categoryId]['path']);
				$categoryPath = null;

				foreach($categoryPathIds as $id) {
					if($id > 1 && $id != $store->getRootCategoryId()) {
						$categoryPath .= !empty($categoryPath) ? ' > ' : '';
						$categoryPath .= $categories[$id]['name'];
					}
				}

				if($categoryPath) {
					$io->streamWrite('<Category><![CDATA[' . $categoryPath . ']]></Category>');
				}
			}
		}

		if(isset($additional['title']) && isset($additional['value']))
		{
			$title = preg_replace("/[^a-zA-Z0-9]/", "", $additional['title']);
			$io->streamWrite(sprintf("<%1\$s><![CDATA[%2\$s]]></%1\$s>",
				$title,
				$additional['value']
			));
		}

		/*
		 * Prepare product visible attributes
		 */
		if(isset($additional['attributes']))
		{
			$io->streamWrite('<Attributes>');

			foreach($additional['attributes'] as $code => $attribute)
			{

				if(isset($product[$code]) && !in_array($code, $additional['systemAttributes']))
				{
					$io->streamWrite('<' . $code . '>');

					if(!empty($attribute['values']))
					{
						$io->streamWrite('<![CDATA[' . $attribute['values'][$product[$code]] . ']]>');
					}
					else
					{
						$io->streamWrite('<![CDATA[' . $product[$code] . ']]>');
					}

					$io->streamWrite('</' . $code . '>');
				}
			}
			$io->streamWrite('</Attributes>');
		}

		$io->streamWrite('</Product>');
	}

	private function iterateProductCollection($io, $categories, $visibleAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $select, $isConfigurable)
	{
		Mage::getSingleton('core/resource_iterator')->walk(
			$select,
			array(array($this, 'iterateProductsCallback')),
			array(
				'io'            => $io,
				'categories'    => $categories,
				'visibleAttributes'    => $visibleAttributes,
				'systemAttributes' => $systemAttributes,
				'attributesToSelect' => $attributesToSelect,
				'options'       => $options,
				'store'         => $store,
				'startMemory'   => $memoryUsage,
				'isConfigurable' => $isConfigurable
			)
		);
	}

	private function getCategories($store)
	{
		$categoryArray = array();
		$parent = $store->getRootCategoryId();

		$rootCategory = Mage::getModel('catalog/category')->load($parent);

		if ($rootCategory->getId())
		{
			$categoryArray[$rootCategory->getId()] = $rootCategory->getData();
			$storeCategories = $rootCategory->getCategories($parent, 0, true, true, true);
			foreach($storeCategories as $category)
			{
				$categoryArray[$category->getId()] = $category->getData();
			}
		}

		return $categoryArray;
	}

	private function getOptions($storeId)
	{
		$optionsArray = array();
		$options = Mage::getModel('catalog/product_option')
			->getCollection()
			->addTitleToResult($storeId)
			->addPriceToResult($storeId)
			->addValuesToResult($storeId)
			->setOrder('sort_order', 'asc');

		foreach($options as $options)
		{
			$productId = $options->getProductId();
			$optionId = $options->getOptionId();
			$optionsArray[$productId][$optionId] = $options->getData();
			if($options->getType() == Mage_Catalog_Model_Product_Option::OPTION_TYPE_DROP_DOWN)
			{
				$optionsArray[$productId][$optionId]['values'] = $options->getValues();
			}
		}
		return $optionsArray;
	}

	private function getAttributes($storeId, $flatCatalogEnabled)
	{
		$visibleAttributes = array();
	    $systemAttributes = array();

	    $attributesToSelect = array(
	        'sku',
	        'name',
	        'manufacturer',
	        'description',
	        'image',
	        'url_key',
	        'price',
	        'cost',
	        'special_price',
	        'special_from_date',
	        'special_to_date',
	        'visibility',
	        'msrp'
	    );

	    if(!empty($this->config[$storeId]['general']['gtin'])) $attributesToSelect[] = $this->config[$storeId]['general']['gtin'];
	    $attributes = Mage::getResourceModel('catalog/product_attribute_collection');

	    $totalAttributes = count($attributesToSelect);

	    foreach($attributes as $attribute)
	    {
	        $code = $attribute->getAttributeCode();
	        $isFlat = $flatCatalogEnabled && $attribute->getUsedInProductListing();
	        $isRegular = !$flatCatalogEnabled && $attribute->getIsVisible() && $attribute->getIsVisibleOnFront();

	        // Only allow a subset of system attributes
	        $isSystem = !$attribute->getIsUserDefined();

	        if(!$isFlat && !$isRegular || ($isRegular && $totalAttributes >= self::ATTRIBUTES_LIMIT)) continue;

	        $visibleAttributes[$code]['label'] = $attribute->getFrontendLabel();  
	        foreach($attribute->getSource()->getAllOptions(false) as $option)
	        {
	            $visibleAttributes[$code]['values'][$option['value']] = $option['label'];
	        }

	        if($isSystem)
	        {
	            $systemAttributes[] = $code;
	            continue;
	        }

	        if(in_array($code, $attributesToSelect)) continue;

	        $attributesToSelect[] = $code;
	        $totalAttributes++;
	    }

	    return array(
	    	'systemAttributes' => $systemAttributes,
	    	'visibleAttributes' => $visibleAttributes,
	    	'attributesToSelect' => $attributesToSelect
	    );
	}

	private function stripHtml($string)
	{
		$string = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
		return strip_tags($string);
	}  
}