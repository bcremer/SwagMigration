<?php
/**
 * (c) shopware AG <info@shopware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Shopware\SwagMigration\Components\Migration\Import\Resource;

use Shopware\SwagMigration\Components\Migration;
use Shopware\SwagMigration\Components\DbServices\Import\Import;
use Shopware\SwagMigration\Components\Migration\Import\Progress;

/**
 * Shopware SwagMigration Components - Product
 *
 * Product import wrapper
 *
 * @category  Shopware
 * @package Shopware\Plugins\SwagMigration\Components\Migration\Import\Resource
 * @copyright Copyright (c) 2012, shopware AG (http://www.shopware.de)
 */
class Product extends AbstractResource
{
    /** @var \Enlight_Components_Db_Adapter_Pdo_Mysql */
    private $db = null;

    /**
     * Returns the default error message for this import class
     *
     * @return mixed
     */
    public function getDefaultErrorMessage()
    {
        return $this->getNameSpace()->get('errorImportingProducts', "An error occurred while importing products");
    }

    /**
     * Returns the progress message for the current import step. A Progress-Object will be passed, so
     * you can get some context info for your snippet
     *
     * @param Progress $progress
     * @return string
     */
    public function getCurrentProgressMessage($progress)
    {
        return sprintf(
            $this->getNameSpace()->get('progressProducts', "%s out of %s products imported"),
            $progress->getOffset(),
            $progress->getCount()
        );
    }

    /**
     * Returns the default 'all done' message
     *
     * @return mixed
     */
    public function getDoneMessage()
    {
        return $this->getNameSpace()->get('importedProducts', "Products successfully imported!");
    }

    /**
     * @return \Enlight_Components_Db_Adapter_Pdo_Mysql
     * @throws \Exception
     */
    public function getDb()
    {
        if ($this->db === null) {
            $this->db = Shopware()->Container()->get('db');
        }

        return $this->db;
    }

    /**
     * Main run method of each import adapter. The run method will query the source profile, iterate
     * the results and prepare the data for import via the old Shopware API.
     *
     * If you want to import multiple entities with one import-class, you might want to check for
     * $this->getInternalName() in order to distinct which (sub)entity you where called for.
     *
     * The run method may only return instances of Progress
     * The calling instance will use those progress object to communicate with the ExtJS backend.
     * If you want this to work properly, think of calling:
     * - $this->initTaskTimer() at the beginning of your run method
     * - $this->getProgress()->setCount(222) to set the total number of data
     * - $this->increaseProgress() to increase the offset/progress
     * - $this->getProgress()->getOffset() to get the current progress' offset
     * - return $this->getProgress()->error("Message") in order to stop with an error message
     * - return $this->getProgress() in order to be called again with the current offset
     * - return $this->getProgress()->done() in order to mark the import as finished
     *
     *
     * @return Progress
     */
    public function run()
    {
        $numberValidationMode = $this->Request()->getParam('number_validation_mode', 'complain');

        /* @var \Enlight_Components_Db_Adapter_Pdo_Mysql $db */
        $db = $this->getDb();

        /* @var Import $import */
        $import = Shopware()->Container()->get('swagmigration.import');


        $offset = $this->getProgress()->getOffset();
        $products = $this->Source()->queryProducts($offset);

        $this->getProgress()->setCount($products->rowCount() + $offset);

        $this->initTaskTimer();

        $configuratorMapping = $this->Request()->get('configurator_mapping');
        $attributes = $this->Request()->get('attribute');
        $taxRate = $this->Request()->get('tax_rate');
        $supplier = $this->Request()->get('supplier');

        $numberSnippet = $this->getNameSpace()->get(
            'numberNotValid',
            "The product number %s is not valid. A valid product number must:<br>
            * not be longer than 40 chars<br>
            * not contain other chars than: 'a-zA-Z0-9-_.' and SPACE<br>
            <br>
            You can force the migration to continue. But be aware that this will: <br>
            * Truncate ordernumbers longer than 40 chars and therefore result in 'duplicate keys' exceptions <br>
            * Will not allow you to modify and save articles having an invalid ordernumber <br>
            "
        );

        while ($product = $products->fetch()) {
            $existingOrderNumber = true;

            // Select additional data for the article if needed
            $additionalProductInfo = $this->Source()->getAdditionalProductInfo($product['productID']);
            if (!empty($additionalProductInfo)) {
                // Merge the results with the pre-existing product array
                $product = array_merge($product, $additionalProductInfo);
            }

            // If no group name for the variants' options was specified
            // try to get it from the initial mapping
            if (!empty($product['additionaltext']) && empty($product['variant_group_names'])) {
                $additional = ucwords(strtolower($product['additionaltext']));
                if (isset($configuratorMapping[$additional])) {
                    $product['variant_group_names'] = $configuratorMapping[$additional];
                }
            }

            // Check the ordernumber
            $number = isset($product['ordernumber']) ?: '';
            if ($numberValidationMode !== 'ignore'
                && (empty($number) || strlen($number) > 30 || preg_match('/[^a-zA-Z0-9-_. ]/', $number))
            ) {
                switch ($numberValidationMode) {
                    case 'complain':
                        return $this->getProgress()->error(sprintf($numberSnippet, $number));
                        break;
                    case 'make_valid':
                        $product['ordernumber'] = $this->makeInvalidNumberValid($number, $product['productID']);
                        $existingOrderNumber = false;
                        break;
                }
            }

            //Attribute
            if (!empty($attributes)) {
                foreach ($attributes as $source => $target) {
                    if (!empty($target) && isset($product[$source])) {
                        $product[$target] = $product[$source];
                        unset($product[$source]);
                    }
                }
            }

            //TaxRate
            if (!empty($taxRate) && isset($product['taxID'])) {
                if (isset($taxRate[$product['taxID']])) {
                    $product['taxID'] = $taxRate[$product['taxID']];
                } else {
                    unset($product['taxID']);
                }
            }

            //Supplier
            if (empty($product['supplierID']) && empty($product['supplier'])) {
                $product['supplier'] = $supplier;
            }

            //Parent
            if (!empty($product['parentID'])) {
                $sql = 'SELECT `targetID` FROM `s_plugin_migrations` WHERE `typeID`=? AND `sourceID`=?';
                $product['maindetailsID'] = $db->fetchOne(
                    $sql,
                    [
                        Migration::MAPPING_ARTICLE,
                        $product['parentID']
                    ]
                );
            }

            //Long Description
            if (isset($product['description_long'])) {
                $product_description = $product['description_long'];
                unset($product['description_long']);
            } else {
                $product_description = null;
            }

            //Description
            if (isset($product['description'])) {
                $product['description'] = strip_tags($product['description']);
            }

            //Article
            $product_result = $import->article($product);
            if (!empty($product_result)) {
                $product = array_merge($product, $product_result);
                /**
                 * Check if the parent article's detail has configurator options associated
                 *
                 * If this is not the case, it was a dummy master article in the source system and
                 * needs to be replaced by another variant
                 */
                if ($product['maindetailsID']) {
                    // Get options of the old main detail
                    $sql = 'SELECT id FROM s_article_configurator_option_relations WHERE article_id = ?';
                    $hasOptions = $db->fetchOne($sql, [$product['maindetailsID']]);

                    // If non is available remove the odl detail and set the new one as main detail
                    if (!$hasOptions) {
                        $this->replaceProductDetail(
                            $product['maindetailsID'],
                            $product['articledetailsID'],
                            $product['articleID']
                        );
                    }
                }

                // In some cases we need to make sure, that the article configurator is
                // generated for the master article of master/child article architectures
                if (isset($product['masterWithAttributes']) && $product['masterWithAttributes'] == 1 && !empty($product['additionaltext'])) {
                    $product['maindetailsID'] = $product['articledetailsID'];
                    $import->setArticleConfigurationData($product);
                }

                // Meta-title... if is import the meta-title set them
                if (!empty($product['meta_title'])) {
                    $metaTitle = $product['meta_title'];
                }

                if ($product['kind'] == 1 && $product_description !== null) {
                    if ($metaTitle) {
                        $array = ['description_long' => $product_description, 'metaTitle' => $metaTitle];
                    } else {
                        $array = ['description_long' => $product_description];
                    }

                    $db->update(
                        's_articles',
                        $array,
                        ['id=?' => $product_result['articleID']]
                    );
                }

                //Price
                if (isset($product['net_price'])) {
                    if (empty($product['tax'])) {
                        $product['price'] = $product['net_price'];
                        unset($product['net_price'], $product['tax']);
                    } else {
                        $product['price'] = round($product['net_price'] * (100 + $product['tax']) / 100, 2);
                        unset($product['net_price']);
                    }
                }
                if (isset($product['price'])) {
                    $product['articlepricesID'] = $import->setArticlePriceData($product);
                }

                //Link
                if (isset($product['link'])) {
                    $import->deleteArticleLinks($product);
                    if (!empty($product['link'])) {
                        $product['articlelinkID'] = $import->addArticleLink(
                            [
                                'articleID' => $product['articleID'],
                                'link' => $product['link'],
                                'description' => empty($product['link_description']) ? $product['link'] : $product['link_description']
                            ]
                        );
                    }
                }

                //If we create valid order number don't insert it again.
                if ($existingOrderNumber) {
                    $sql = '
                        INSERT INTO `s_plugin_migrations` (`typeID`, `sourceID`, `targetID`)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE `targetID`=VALUES(`targetID`);
                    ';
                    $db->query(
                        $sql,
                        [
                            Migration::MAPPING_ARTICLE,
                            $product['productID'],
                            $product['articledetailsID']
                        ]
                    );
                }
            }

            $this->increaseProgress();
            if ($this->newRequestNeeded()) {
                return $this->getProgress();
            }
        }

        return $this->getProgress()->done();
    }

    /**
     * Helper function to remove an old article detail ans set another detail instead of it. Will also update
     * s_plugin_migrations in order to link other child-products to the new detail instead of the old one
     *
     * @param $oldMainDetail
     * @param $newMainDetail
     * @param $articleId
     */
    public function replaceProductDetail($oldMainDetail, $newMainDetail, $articleId)
    {
        /* @var \Enlight_Components_Db_Adapter_Pdo_Mysql $db */
        $db = $this->getDb();

        // Delete old main detail
        $sql = 'DELETE FROM s_articles_details WHERE id = ?';
        $db->query($sql, [$oldMainDetail]);

        // Set the new mainDetail for the article
        $sql = 'UPDATE s_articles SET main_detail_id = ? WHERE id = ?';
        $db->query($sql, [$newMainDetail, $articleId]);

        // Update kind of the new main detail
        $sql = 'UPDATE s_articles_details SET kind = 1 WHERE id = ?';
        $db->query($sql, [$newMainDetail]);

        // Update mapping so that references to the old dummy article point to this article
        $sql = 'UPDATE s_plugin_migrations SET targetID = ? WHERE typeID = ? AND targetID = ?';
        $db->query($sql, [$newMainDetail, Migration::MAPPING_ARTICLE, $oldMainDetail]);
    }
}
