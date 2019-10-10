<?php

class Tritac_ChannelEngine_Helper_Feed extends Mage_Core_Helper_Abstract
{

    private $helper;
    private $feedDir;
    private $stores;
    private $config;

    const ATTRIBUTES_LIMIT = 30;

    public function __construct()
    {
        $this->feedDir = Mage::getBaseDir('media') . DS . 'channelengine' . DS;
        $this->stores = array();
        $this->config = array();

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
        foreach ($this->stores as $store) {
            $this->generateFeed($store);
        }
        return true;
    }

    public function generateFeed($store)
    {
        Mage::app()->setCurrentStore($store);
        $storeId = $store->getId();
        $config = $this->config[$storeId];

        if (!$this->helper->isFeedGenerationEnabled($storeId)) return;

        $memoryUsage = memory_get_usage();
        $tenant = $config['general']['tenant'];
        $name = $tenant . '_products.xml';
        $file = $this->feedDir . DS . $name;
        $date = date('c');
        $io = new Varien_Io_File();
        $io->setAllowCreateFolders(true);
        $io->open(array('path' => $this->feedDir));
        $io->streamOpen($file, 'w+');
        $io->streamLock(true);
        $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $io->streamWrite('<Products xmlns:xsd="http://www.w3.org/2001/XMLSchema"
			xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" GeneratedAt="' . $date . '">' . "\n");

        $flatCatalogEnabled = Mage::getResourceModel('catalog/product_collection')->isEnabledFlat();
        $attributesInfo = $this->getAttributes($storeId, $flatCatalogEnabled);
        $attributesToSelect = $attributesInfo['attributesToSelect'];
        $customAttributes = $attributesInfo['customAttributes'];
        $systemAttributes = $attributesInfo['systemAttributes'];
        $categories = $this->getCategories($store);
        $options = $this->getOptions($storeId);

        // Iterate all simple products, except the invisible ones (they are most probably children of configurable products)
        $select = $this->getProductCollection($store, $attributesToSelect, $flatCatalogEnabled, Mage_Catalog_Model_Product_Type::TYPE_SIMPLE);
        $this->iterateProductCollection($io, $categories, $customAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $select, false);

        // Iterate all configurable products, except the invisible ones
        $select = $this->getProductCollection($store, $attributesToSelect, $flatCatalogEnabled, Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE);
        //echo((string)$select); die();
        $this->iterateProductCollection($io, $categories, $customAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $select, true);

        $io->streamWrite('</Products>');
        $io->streamUnlock();
        $io->streamClose();

        return true;
    }

    private function getProductCollection($store, $attributesToSelect, $flatCatalogEnabled, $type = Mage_Catalog_Model_Product_Type::DEFAULT_TYPE)
    {
        $collection = Mage::getResourceModel('catalog/product_collection');
        $storeId = $store->getId();
        $rootCategoryId = $store->getRootCategoryId();

        // Make sure to create a new instance of our collection after setting the store ID
        // when using the flat catalog. Otherwise store ID will be ignored. This is a bug in magento.
        // https://magento.stackexchange.com/a/25908
        if ($flatCatalogEnabled) {
            // The flat product entity has a setStoreId method, the regular entity does not have one
            $collection->getEntity()->setStoreId($storeId);
            $collection = Mage::getResourceModel('catalog/product_collection');
        }

        $collection->addAttributeToSelect($attributesToSelect, 'left')
            ->addFieldToFilter('type_id', array('in' => array($type)))
            ->addStoreFilter($store)
            ->addAttributeToSort('entity_id', 'DESC');

        if ($type == Mage_Catalog_Model_Product_Type::TYPE_SIMPLE) {
            $collection->addAttributeToFilter('visibility', array('in' => array(
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH
            )));
        }

        $select = $collection->getSelect();

        // Add qty and category fields to select
        $select->joinLeft(
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
                'cce.entity_id = ccp.category_id AND cce.path LIKE ' . "'%/" . $rootCategoryId . "/%'",
                array()
            )
            ->group('e.entity_id');

        return $select;
    }

    public function iterateProductsCallback($args)
    {
        $io = $args['io'];
        $row = $args['row'];
        $customAttributes = $args['customAttributes'];
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

        $productData = $product->getData();
        if (!empty($config['general']['gtin'])) $productData['gtin'] = $productData[$config['general']['gtin']];
        $productData['url'] = $product->getProductUrl();
        $productData['images'] = $product->getMediaGalleryImages();
        $finalPrice = $product->getFinalPrice();

        // The final price as calculated by magento
        $productData['final_price'] = $finalPrice;
        // The product price field
        $productData['base_price'] = $product['price'];
        // The product special price field
        $productData['special_price'] = $product['special_price'];
        // The manufacturer suggested retail price field
        $productData['list_price'] = $product['msrp'];
        // The final price as calculated by magento, which might have additional option prices added later on
        $productData['price'] = $finalPrice;

        $productData['lowest_price'] = (
            !empty($product['special_price']) &&
            !empty($product['price']) &&
            $product['special_price'] < $product['price']
        ) ? $product['special_price'] : $product['price'];

        // Check whether this product has option variants
        if (isset($options[$productData['entity_id']])) {

            // If SKU is used as the merchant product number, options products are not supported
            if ($this->helper->useSkuInsteadOfId($storeId)) return;

            $productData['parent_id'] = $productData['entity_id'];
            $options = $options[$productData['entity_id']];
            $variantProducts = array();
            $requiredOptionCount = 0;

            foreach ($options as $option) {
                // We can only support options with predefined values and convert them to separate products
                // since most marketplaces don't support customization.

                // More than 1 required predefined value option, skip this product
                if ($requiredOptionCount > 1) return;

                if ($option->getType() != Mage_Catalog_Model_Product_Option::OPTION_TYPE_DROP_DOWN &&
                    $option->getType() != Mage_Catalog_Model_Product_Option::OPTION_TYPE_RADIO) {
                    // A required variable value attribute, skip this product
                    if ($option->getRequired()) return;
                    // An optional variable value attribute, ignore it
                    continue;
                } elseif ($option->getRequired()) {
                    // A required predefined value option, add to requiredOptionCount
                    $requiredOptionCount++;
                }

                foreach ($option->getValues() as $value) {
                    $variantData = $productData;
                    $variantData['sku'] = $variantData['sku'] . '_' . $value->getSku();
                    $variantData['id'] = $variantData['entity_id'] . '_' . $option['option_id'] . '_' . $value->getId();

                    if (isset($value['price'])) $variantData['price'] = $this->getOptionPrice($value, $variantData);

                    $optionAttribute = array(
                        'name' => preg_replace('/[^a-zA-Z0-9]/', '', str_replace(' ', '_', $option['default_title'])),
                        'value' => $value->getDefaultTitle(),
                        'id' => $option->getId(),
                        'value_id' => $value->getId(),
                        'value_sku' => $value->getSku()
                    );

                    $this->writeProduct($io, $store, $variantData, $categories, $customAttributes, $systemAttributes, $optionAttribute);
                }
            }
        } else {
            $productData['id'] = $productData['entity_id'];
            $this->writeProduct($io, $store, $productData, $categories, $customAttributes, $systemAttributes);
        }

        if (!$isConfigurable) return;

        $productAttributeOptions = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);

        $superAttributes = array();
        foreach ($productAttributeOptions as $superAttribute) {
            foreach ($superAttribute['values'] as $value) {
                $superAttributes[$superAttribute['attribute_code']][$value['value_index']] = $value;
            }
        }

        $childProductCollection = Mage::getModel('catalog/product_type_configurable')
            ->getUsedProductCollection($product)
            ->addAttributeToFilter('status', 1)
            ->addAttributeToSelect($attributesToSelect);

        $childProducts = $childProductCollection->getItems();

        foreach ($childProducts as $child) {
            $childData = $child->getData();

            $childData['id'] = $childData['entity_id'];
            $childData['parent_id'] = $productData['id'];
            //$childData['price'] = $productData['price'];
            $childData['url'] = $productData['url'];
            $childData['description'] = $productData['description'];

            // The product price field
            $childData['base_price'] = $childData['price'];
            // The product special price field
            $childData['special_price'] = $childData['special_price'];
            // The manufacturer suggested retail price field
            $childData['list_price'] = $childData['msrp'];

            if (!isset($childData['images'])) {
                $childData['images'] = $productData['images'];
            }

            if (!isset($childData['category_id'])) $childData['category_id'] = $productData['category_id'];

            if (isset($childData['stock_item']) && $childData['stock_item'] !== null) {
                $stock = $childData['stock_item']->getData();
                $childData['qty'] = $stock['qty'];
            }

            foreach ($superAttributes as $code => $superAttribute) {
                if (isset($childData[$code])) {
                    $priceValue = $superAttribute[$childData[$code]]['pricing_value'];
                    if ($superAttribute[$childData[$code]]['is_percent']) {
                        $newPrice = $childData['price'] + $childData['price'] * $priceValue / 100;
                    } else {
                        $newPrice = $childData['price'] + $priceValue;
                    }
                    $childData['price'] = $newPrice;
                }
            }

            $childData['lowest_price'] = (
                !empty($childData['special_price']) &&
                !empty($childData['price']) &&
                $childData['special_price'] < $childData['price']
            ) ? $childData['special_price'] : $childData['price'];

            $this->writeProduct($io, $store, $childData, $categories, $customAttributes, $systemAttributes);
        }
    }

    private function getOptionPrice($option, $variantData)
    {
        if ($option['price_type'] == 'fixed') return $variantData['price'] + $option['price'];
        if ($option['price_type'] == 'percent') return $variantData['price'] + ($variantData['price'] * $option['price'] / 100);
    }

    private function writeProduct($io, $store, $product, $categories, $customAttributes, $systemAttributes, $optionAttribute = null)
    {
        $storeId = $store->getId();


        $transportObject = new Varien_Object();
        $transportObject->setProduct($product);
        Mage::dispatchEvent('channelengine_feed_product_write_before', array('transport' => $transportObject));
        $product = $transportObject->getProduct();

        $io->streamWrite('<Product>');
        $io->streamWrite('<Id>' . $product['id'] . '</Id>');

        // Add group code with product id if product have custom options
        if (isset($product['group_code'])) $io->streamWrite('<GroupCode><![CDATA[' . $product['group_code'] . ']]></GroupCode>');
        if (isset($product['parent_id'])) $io->streamWrite('<ParentId><![CDATA[' . $product['parent_id'] . ']]></ParentId>');

        $strippedDescription = $this->stripHtml($product['description'], true);

        $io->streamWrite('<Type><![CDATA[' . $product['type_id'] . ']]></Type>');
        $io->streamWrite('<Name><![CDATA[' . $product['name'] . ']]></Name>');
        $io->streamWrite('<Description><![CDATA[' . $strippedDescription . ']]></Description>');
        $io->streamWrite('<DescriptionWithHtml><![CDATA[' . $strippedDescription . ']]></DescriptionWithHtml>');
        $io->streamWrite('<ShortDescription><![CDATA[' . $this->stripHtml($product['short_description']) . ']]></ShortDescription>');
        $io->streamWrite('<ShortDescriptionWithHtml><![CDATA[' . $this->stripHtml($product['short_description'], true) . ']]></ShortDescriptionWithHtml>');
        $io->streamWrite('<Manufacturer><![CDATA[' . $product['manufacturer'] . ']]></Manufacturer>');
        $io->streamWrite('<Price><![CDATA[' . $product['price'] . ']]></Price>');
        $io->streamWrite('<LowestPrice><![CDATA[' . $product['lowest_price'] . ']]></LowestPrice>');
        $io->streamWrite('<SpecialPrice><![CDATA[' . $product['special_price'] . ']]></SpecialPrice>');
        $io->streamWrite('<ListPrice><![CDATA[' . $product['list_price'] . ']]></ListPrice>');
        $io->streamWrite('<BasePrice><![CDATA[' . $product['base_price'] . ']]></BasePrice>');

        if (isset($product['cost'])) $io->streamWrite('<PurchasePrice><![CDATA[' . $product['cost'] . ']]></PurchasePrice>');

        // Add product stock qty
        $io->streamWrite('<Stock><![CDATA[' . $product['qty'] . ']]></Stock>');

        // Add product SKU and GTIN
        $io->streamWrite('<SKU><![CDATA[' . $product['sku'] . ']]></SKU>');
        if (!empty($product['gtin'])) $io->streamWrite('<GTIN><![CDATA[' . $product['gtin'] . ']]></GTIN>');

        // VAT and Shipping Time are pre configured in extension settings
        if (!empty($this->config[$storeId]['optional']['vat_rate'])) {
            $vat = $this->config[$storeId]['optional']['vat_rate'];
            $io->streamWrite('<VAT><![CDATA[".$vat."]]></VAT>');
        }

        $shippingTime = ($product['qty'] > 0) ? $this->config[$storeId]['optional']['shipping_time'] : $this->config[$storeId]['optional']['shipping_time_oos'];

        if ($shippingTime) {
            $io->streamWrite('<ShippingTime><![CDATA[' . $shippingTime . ']]></ShippingTime>');
        }

        $io->streamWrite('<Url><![CDATA[' . $product['url'] . ']]></Url>');
        $images = $product['images'];
        $i = 0;

        foreach ($product['images'] as $image) {
            if ($i == 0) {
                $io->streamWrite('<ImageUrl><![CDATA[' . $image->getUrl() . ']]></ImageUrl>');
            } else {
                $io->streamWrite('<ImageUrl' . $i . '><![CDATA[' . $image->getUrl() . ']]></ImageUrl' . $i . '>');
            }
            $i++;
        }

        // Prepare category path
        //$io->streamWrite('<CategoryId><![CDATA[' . $product['category_id'] . ']]></CategoryId>');
        if (isset($product['category_id'])) {
            $categoryId = $product['category_id'];
            if (isset($categories[$categoryId])) {
                $categoryPathIds = explode('/', $categories[$categoryId]['path']);
                $categoryPath = null;

                foreach ($categoryPathIds as $id) {
                    if ($id > 1 && $id != $store->getRootCategoryId() && isset($categories[$id])) {
                        $categoryPath .= !empty($categoryPath) ? ' > ' : '';
                        $categoryPath .= $categories[$id]['name'];
                    }
                }

                if ($categoryPath) $io->streamWrite('<Category><![CDATA[' . $categoryPath . ']]></Category>');
            }
        }

        if ($optionAttribute !== null) {
            $io->streamWrite('<OptionAttribute 
                Id="' . $optionAttribute['id'] . '"
                ValueId="' . $optionAttribute['value_id'] . '"
                ValueSku="' . $optionAttribute['value_sku'] . '"
                Name="' . $optionAttribute['name'] . '"
                ><![CDATA[' . $optionAttribute['value'] . ']]></OptionAttribute>');
        }

        $this->writeAttributes($io, $product, $customAttributes, 'Attributes');
        $this->writeAttributes($io, $product, $systemAttributes, 'SystemAttributes');

        $io->streamWrite('</Product>');
    }

    private function writeAttributes($io, $product, $attributes, $elementName)
    {
        $io->streamWrite('<' . $elementName . '>');
        foreach ($attributes as $code => $attribute) {
            if (!isset($product[$code])) continue;

            $value = $product[$code];

            if (!empty($attribute['values'])) {
                $valueList = array();
                foreach (explode(',', $value) as $key) {
                    $valueList[] = $attribute['values'][$key];
                }
                $value = implode(', ', $valueList);
            }

            $value = $this->stripHtml($value, true);

            $io->streamWrite('<' . $code . '><![CDATA[' . $value . ']]></' . $code . '>');
        }
        $io->streamWrite('</' . $elementName . '>');
    }

    private function iterateProductCollection($io, $categories, $customAttributes, $systemAttributes, $attributesToSelect, $options, $store, $memoryUsage, $select, $isConfigurable)
    {
        Mage::getSingleton('core/resource_iterator')->walk(
            $select,
            array(array($this, 'iterateProductsCallback')),
            array(
                'io' => $io,
                'categories' => $categories,
                'customAttributes' => $customAttributes,
                'systemAttributes' => $systemAttributes,
                'attributesToSelect' => $attributesToSelect,
                'options' => $options,
                'store' => $store,
                'startMemory' => $memoryUsage,
                'isConfigurable' => $isConfigurable
            )
        );
    }

    private function getCategories($store)
    {
        $categoryArray = array();
        $parent = $store->getRootCategoryId();

        $rootCategory = Mage::getModel('catalog/category')->load($parent);

        if ($rootCategory->getId()) {
            $categoryArray[$rootCategory->getId()] = $rootCategory->getData();
            $storeCategories = $rootCategory->getCategories($parent, 0, true, true, true);
            foreach ($storeCategories as $category) {
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

        foreach ($options as $option) {
            $productId = $option->getProductId();
            $optionId = $option->getOptionId();
            $optionsArray[$productId][$optionId] = $option;

            /*if($option->getType() == Mage_Catalog_Model_Product_Option::OPTION_TYPE_DROP_DOWN ||
                $option->getType() == Mage_Catalog_Model_Product_Option::OPTION_TYPE_RADIO)
            {
                //$optionsArray[$productId][$optionId]['values'] =
            }*/
        }
        return $optionsArray;
    }

    private function getAttributes($storeId, $flatCatalogEnabled)
    {
        $customAttributes = array();
        $systemAttributes = array();
        $attributesToSelect = array();

        $mappedSystemAttributes = array(
            'sku',
            'name',
            'description',
            'short_description',
            'price',
            'special_price',
            'cost',
            'visibility',
            'msrp'
        );

        $manufacturer = Mage::getSingleton('eav/config')
            ->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'manufacturer');
        if ($manufacturer->getId() !== null) {
            $mappedSystemAttributes[] = $manufacturer->getAttributeCode();
        }

        $hiddenSystemAttributes = array(
            'msrp_display_actual_price_type',
            'msrp_enabled',
            'required_options',
            'special_from_date',
            'special_to_date',
            'image',
            'url_key',
            'small_image',
            'thumbnail'
        );

        if (!empty($this->config[$storeId]['general']['gtin'])) {
            $gtinAttr = $this->config[$storeId]['general']['gtin'];
            $attributesToSelect[] = $gtinAttr;
        }

        $attributesToSelect = $mappedSystemAttributes;
        $totalAttributes = count($attributesToSelect);
        $attributes = Mage::getResourceModel('catalog/product_attribute_collection');

        foreach ($attributes as $attribute) {
            $code = $attribute->getAttributeCode();
            $isFlat = $flatCatalogEnabled && $attribute->getUsedInProductListing();
            $isRegular = !$flatCatalogEnabled && $attribute->getIsVisible() && $attribute->getIsVisibleOnFront();
            $isSystem = !$attribute->getIsUserDefined();

            // When the flat catalog is disabled, we are tied to a mysql join limit
            if (!$isFlat && !$isRegular || ($isRegular && $totalAttributes >= self::ATTRIBUTES_LIMIT)) continue;

            // Do not include system attributes that have already been mapped to specific CE fields separately
            if ($isSystem && !in_array($code, $mappedSystemAttributes) && !in_array($code, $hiddenSystemAttributes)) {
                $this->addAttributesToLookup($systemAttributes, $attribute, $code);
            } elseif (!$isSystem) {
                $this->addAttributesToLookup($customAttributes, $attribute, $code);
            }

            if (in_array($code, $attributesToSelect)) continue;

            $attributesToSelect[] = $code;
            $totalAttributes++;
        }

        return array(
            'systemAttributes' => $systemAttributes,
            'customAttributes' => $customAttributes,
            'attributesToSelect' => $attributesToSelect
        );
    }

    private function addAttributesToLookup(&$lookup, $attribute, $code)
    {
        $lookup[$code]['label'] = $attribute->getFrontendLabel();
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            $lookup[$code]['values'][$option['value']] = $option['label'];
        }
    }

    private function stripHtml($string, $soft = false)
    {
        if (!is_string($string)) return $string;

        if (!$soft) {
            $string = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
            return strip_tags($string);
        } else {
            return strip_tags($string, "<div><span><pre><p><br><hr><hgroup><h1><h2><h3><h4><h5><h6><ul><ol><li><dl><dt><dd><strong><em><b><i><u><img><a><abbr><address><blockquote><area><audio><video><caption><table><tbody><td><tfoot><th><thead><tr>");
        }

    }
}
